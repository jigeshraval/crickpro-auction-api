<?php

namespace App\Services\Payments;

use App\Models\Auction;
use App\Models\AuctionInvitation;
use App\Models\AuctionPlayer;
use App\Models\AuctionSetting;
use App\Models\PlayerRegistration;
use App\Models\RegistrationPayment;
use App\Repositories\AuctionPlayerRepository;
use App\Services\ImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Paid / reviewed self-registration lifecycle (spec §3). */
class RegistrationService
{
    public function __construct(
        private readonly FeeCalculator $fees,
        private readonly RazorpayClient $razorpay,
        private readonly AuctionPlayerRepository $players,
        private readonly ImageStorage $images,
    ) {}

    /**
     * none unless a mode is set, a positive fee is configured AND the auction is
     * still eligible (India owner + INR). If the owner's country or the auction
     * currency changed after setup, registration silently becomes free.
     */
    public static function effectiveMode(AuctionSetting $settings): string
    {
        if ($settings->registration_mode === PlayerRegistration::MODE_NONE
            || (int) $settings->registration_fee <= 0
            || ! RegistrationPaymentsEligibility::forAuction($settings->auction)) {
            return PlayerRegistration::MODE_NONE;
        }

        return (string) $settings->registration_mode;
    }

    /** Normalised camelCase payload stored on the registration (spec §3.1). */
    public static function payloadFrom(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'role' => $data['role'] ?? 'batter',
            'battingStyle' => $data['battingStyle'] ?? null,
            'bowlingStyle' => isset($data['bowlingStyle']) ? (trim($data['bowlingStyle']) ?: null) : null,
            'city' => $data['city'] ?? null,
            'phone' => $data['phone'] ?? null,
            // Relative ImageStorage path of the self-uploaded photo (player-photos).
            'photoUrl' => $data['photoUrl'] ?? null,
        ];
    }

    /** camelCase payload → `players` columns (same mapping today's join used). */
    public static function playerAttributes(array $payload): array
    {
        $payload = self::payloadFrom($payload);

        return array_filter([
            'name' => $payload['name'],
            'role' => $payload['role'],
            'batting_style' => $payload['battingStyle'],
            'bowling_style' => $payload['bowlingStyle'],
            'city' => $payload['city'],
            'phone' => $payload['phone'],
            'photo_url' => $payload['photoUrl'],
        ], fn ($v) => $v !== null);
    }

    public function start(Auction $auction, AuctionInvitation $invitation, AuctionSetting $settings, array $data): PlayerRegistration
    {
        $mode = self::effectiveMode($settings);

        $registration = PlayerRegistration::create([
            'id_auction' => $auction->id,
            'id_invitation' => $invitation->id,
            'payload' => self::payloadFrom($data),
            'mode' => $mode,
            'status' => $mode === PlayerRegistration::MODE_MANUAL
                ? PlayerRegistration::STATUS_AWAITING_REVIEW
                : PlayerRegistration::STATUS_AWAITING_PAYMENT,
            'public_token' => Str::random(40),
        ]);

        if ($mode === PlayerRegistration::MODE_MANUAL) {
            $fee = (int) $settings->registration_fee;
            RegistrationPayment::create([
                'id_registration' => $registration->id,
                'id_auction' => $auction->id,
                'id_owner' => $auction->id_owner,
                'provider' => RegistrationPayment::PROVIDER_MANUAL,
                'base_amount' => $fee,
                'platform_fee' => 0,
                'gateway_fee' => 0,
                'total_amount' => $fee,
                'organiser_net' => $fee,
                'status' => RegistrationPayment::STATUS_CREATED,
            ]);
        } else {
            $this->issueQr($registration, (int) $settings->registration_fee, (string) $settings->fee_bearer);
        }

        return $registration->fresh(['auction.settings', 'latestPayment']);
    }

    /**
     * New payment attempt + single-use fixed-amount UPI QR. Base/bearer default
     * to the previous attempt so a regenerated QR keeps the price the player saw.
     * A Razorpay failure leaves the attempt `expired` with no QR → page offers Retry.
     *
     * Idempotent + race-safe (review 2, C2/M1): the registration row is locked
     * (FOR UPDATE, first statement of the transaction → fresh snapshot) around
     * the status / open-QR check and the creation, so a double tap returns the
     * same QR and a registration completed meanwhile can never be re-opened.
     * No wallet lock is involved; only the NEW payment row (invisible to every
     * other path until commit) is written inside, so this never takes an
     * existing payment row lock after the registration lock (reverse order).
     * Stale `created` attempts are expired BEFORE the transaction, conditionally.
     */
    public function issueQr(PlayerRegistration $registration, ?int $base = null, ?string $bearer = null): RegistrationPayment
    {
        foreach ($registration->payments()->where('status', RegistrationPayment::STATUS_CREATED)->whereNull('razorpay_payment_id')->get() as $attempt) {
            if (! $attempt->hasOpenQr()) {
                $this->expireAttempt($attempt->id);
            }
        }

        return DB::transaction(function () use ($registration, $base, $bearer) {
            $registration = PlayerRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if (! in_array($registration->status, [PlayerRegistration::STATUS_AWAITING_PAYMENT, PlayerRegistration::STATUS_EXPIRED], true)) {
                throw new PaymentRuleException('This registration is already '.str_replace('_', ' ', $registration->status).'.', 409);
            }

            $previous = $registration->payments()->latest('id')->first();
            if ($previous?->hasOpenQr()) {
                return $previous; // double tap — the other request already issued a live QR
            }

            $registration->loadMissing('auction');
            $auction = $registration->auction;
            $base ??= $previous?->base_amount;
            $bearer ??= $previous?->fee_bearer ?? FeeCalculator::BEARER_ORGANISER;
            $breakdown = $this->fees->breakdown((int) $base, $bearer);

            $payment = RegistrationPayment::create([
                'id_registration' => $registration->id,
                'id_auction' => $auction->id,
                'id_owner' => $auction->id_owner,
                'provider' => RegistrationPayment::PROVIDER_RAZORPAY,
                'fee_bearer' => $breakdown->feeBearer,
                'base_amount' => $breakdown->baseAmount,
                'platform_fee' => $breakdown->platformFee,
                'gateway_fee' => $breakdown->estimatedGatewayFee,
                'total_amount' => $breakdown->totalAmount,
                'organiser_net' => $breakdown->organiserNet,
                'status' => RegistrationPayment::STATUS_CREATED,
            ]);

            $closeBy = now()->addMinutes((int) config('payments.qr_ttl_minutes'));

            try {
                $qr = $this->razorpay->createQr([
                    'type' => 'upi_qr',
                    'name' => Str::limit($auction->name, 40, ''),
                    'usage' => 'single_use',
                    'fixed_amount' => true,
                    'payment_amount' => $breakdown->totalAmount,
                    'description' => Str::limit('Registration: '.$auction->name, 100, ''),
                    'close_by' => $closeBy->timestamp,
                    'notes' => [
                        'registration_id' => (string) $registration->id,
                        'payment_id' => (string) $payment->id,
                        'auction_id' => (string) $auction->id,
                    ],
                ]);

                $payment->update([
                    'razorpay_qr_id' => $qr['id'],
                    'qr_image_url' => $qr['image_url'] ?? null,
                    'qr_expires_at' => isset($qr['close_by']) ? Carbon::createFromTimestamp((int) $qr['close_by']) : $closeBy,
                ]);
            } catch (RazorpayException $e) {
                Log::warning('Razorpay QR creation failed', ['registrationId' => $registration->id, 'error' => $e->getMessage()]);
                $payment->update(['status' => RegistrationPayment::STATUS_EXPIRED]);
            }

            // Conditional: only an expired registration is re-opened (never a completed one).
            PlayerRegistration::whereKey($registration->id)
                ->where('status', PlayerRegistration::STATUS_EXPIRED)
                ->update(['status' => PlayerRegistration::STATUS_AWAITING_PAYMENT, 'updated_at' => now()]);

            return $payment->refresh();
        });
    }

    /**
     * created → expired, atomically and only if no payment has landed on it
     * (review 2, C1). The registration follows — in one conditional UPDATE —
     * only while it is still awaiting_payment AND this is its latest attempt.
     * Returns true when this call expired the attempt.
     */
    public function expirePayment(RegistrationPayment $payment): bool
    {
        if (! $this->expireAttempt($payment->id)) {
            return false;
        }

        PlayerRegistration::whereKey($payment->id_registration)
            ->where('status', PlayerRegistration::STATUS_AWAITING_PAYMENT)
            ->whereNotExists(fn ($q) => $q->from('registration_payments')
                ->whereColumn('registration_payments.id_registration', 'player_registrations.id')
                ->where('registration_payments.id', '>', $payment->id))
            ->update(['status' => PlayerRegistration::STATUS_EXPIRED, 'updated_at' => now()]);

        return true;
    }

    /** Conditional created → expired of one attempt; false when it was paid / already settled meanwhile. */
    private function expireAttempt(int $paymentId): bool
    {
        return RegistrationPayment::whereKey($paymentId)
            ->where('status', RegistrationPayment::STATUS_CREATED)
            ->whereNull('razorpay_payment_id')
            ->update(['status' => RegistrationPayment::STATUS_EXPIRED, 'updated_at' => now()]) === 1;
    }

    /**
     * qr_code.closed webhook. Returns false for an unknown QR. A QR closed
     * because it was paid (close_reason "paid") is ignored by the router — the
     * payment's own qr_code.credited / poll completes it.
     */
    public function expireQr(string $qrId): bool
    {
        $payment = $qrId !== '' ? RegistrationPayment::where('razorpay_qr_id', $qrId)->first() : null;
        if (! $payment) {
            return false;
        }

        $this->expirePayment($payment);

        return true;
    }

    /**
     * After a registration completes (review 2, M2): close its other still-open
     * QR attempts at Razorpay (best-effort) and expire them conditionally. A
     * payment that still lands on one is honoured as a credit + ops alert.
     * Call OUTSIDE any transaction (HTTP).
     */
    public function closeOtherAttempts(RegistrationPayment $paid): void
    {
        $others = RegistrationPayment::where('id_registration', $paid->id_registration)
            ->whereKeyNot($paid->id)
            ->where('status', RegistrationPayment::STATUS_CREATED)
            ->whereNull('razorpay_payment_id')
            ->get();

        foreach ($others as $attempt) {
            if ($attempt->razorpay_qr_id) {
                try {
                    $this->razorpay->closeQr($attempt->razorpay_qr_id);
                } catch (RazorpayException $e) {
                    Log::warning('Razorpay QR close failed', ['registrationPaymentId' => $attempt->id, 'error' => $e->getMessage()]);
                }
            }

            $this->expireAttempt($attempt->id);
        }
    }

    /** Add the registrant to the pool exactly once (category from the invite) → completed. */
    public function addToPool(PlayerRegistration $registration): AuctionPlayer
    {
        return DB::transaction(function () use ($registration) {
            $registration = PlayerRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if ($registration->id_auction_player && ($existing = AuctionPlayer::find($registration->id_auction_player))) {
                if ($registration->status !== PlayerRegistration::STATUS_COMPLETED) {
                    $registration->update(['status' => PlayerRegistration::STATUS_COMPLETED]);
                }

                return $existing;
            }

            $invitation = $registration->invitation;
            $auctionPlayer = $this->players->add(
                $registration->auction,
                self::playerAttributes($registration->payload),
                array_filter(['category_code' => $invitation?->category_code], fn ($v) => $v !== null),
            );

            $invitation?->increment('joined_count');
            $registration->update([
                'status' => PlayerRegistration::STATUS_COMPLETED,
                'id_auction_player' => $auctionPlayer->id,
            ]);

            return $auctionPlayer;
        });
    }

    /**
     * The invitee's own photo — stored exactly like the organiser's player-photo
     * upload (same kind + 512 max edge, WebP with alpha kept). Returns the
     * relative path that goes into the payload as `photoUrl`.
     */
    public function storePlayerPhoto(UploadedFile $file): string
    {
        return $this->images->store($file, 'player-photos', 512);
    }

    /** Manual mode: attach (or replace) the payment screenshot + optional UTR. */
    public function submitProof(PlayerRegistration $registration, UploadedFile $file, ?string $utr): RegistrationPayment
    {
        $path = $this->images->store($file, 'registration-proofs', 1600);

        $payment = $registration->payments()
            ->where('provider', RegistrationPayment::PROVIDER_MANUAL)
            ->latest('id')
            ->firstOrFail();

        $utr = $utr !== null ? trim($utr) : null;
        $payment->update(['proof_url' => $path, 'utr' => $utr !== '' ? $utr : null]);

        return $payment;
    }

    /** Manual mode: organiser confirms the payment → player joins the pool. */
    public function approve(PlayerRegistration $registration): PlayerRegistration
    {
        return DB::transaction(function () use ($registration) {
            $registration = PlayerRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if ($registration->mode !== PlayerRegistration::MODE_MANUAL
                || $registration->status !== PlayerRegistration::STATUS_AWAITING_REVIEW) {
                throw new PaymentRuleException('Only registrations awaiting review can be approved.', 409);
            }

            $registration->payments()
                ->where('provider', RegistrationPayment::PROVIDER_MANUAL)
                ->latest('id')->first()
                ?->update(['status' => RegistrationPayment::STATUS_PAID, 'paid_at' => now()]);

            $this->addToPool($registration);

            return $registration->fresh(['latestPayment']);
        });
    }

    public function reject(PlayerRegistration $registration, string $reason): PlayerRegistration
    {
        return DB::transaction(function () use ($registration, $reason) {
            $registration = PlayerRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if ($registration->mode !== PlayerRegistration::MODE_MANUAL
                || $registration->status !== PlayerRegistration::STATUS_AWAITING_REVIEW) {
                throw new PaymentRuleException('Only registrations awaiting review can be rejected.', 409);
            }

            $registration->update(['status' => PlayerRegistration::STATUS_REJECTED, 'reject_reason' => trim($reason)]);

            return $registration->fresh(['latestPayment']);
        });
    }
}
