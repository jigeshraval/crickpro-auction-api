<?php

namespace App\Services\Payments;

use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RazorpayX payout destinations (spec §7). Contact once per owner (stored on
 * the wallet row), a fund account per destination, then a fund account
 * validation (penny drop / VPA check) whose webhook verifies the account.
 * Only masked details are stored.
 */
class PayoutAccountService
{
    public function __construct(
        private readonly RazorpayClient $razorpay,
        private readonly WalletService $wallet,
    ) {}

    public function create(User $owner, array $data): PayoutAccount
    {
        $isBank = $data['type'] === PayoutAccount::TYPE_BANK;
        $wallet = $this->wallet->ensure($owner->id);

        try {
            if (! $wallet->razorpayx_contact_id) {
                $contact = $this->razorpay->createContact(array_filter([
                    'name' => Str::limit($owner->name ?: 'Organiser '.$owner->id, 50, ''),
                    'email' => $owner->email,
                    'type' => 'vendor',
                    'reference_id' => 'owner_'.$owner->id,
                ]));
                $wallet->update(['razorpayx_contact_id' => $contact['id']]);
            }

            $fundAccount = $this->razorpay->createFundAccount($isBank
                ? [
                    'contact_id' => $wallet->razorpayx_contact_id,
                    'account_type' => 'bank_account',
                    'bank_account' => [
                        'name' => $data['holderName'],
                        'ifsc' => strtoupper($data['ifsc']),
                        'account_number' => $data['accountNumber'],
                    ],
                ]
                : [
                    'contact_id' => $wallet->razorpayx_contact_id,
                    'account_type' => 'vpa',
                    'vpa' => ['address' => $data['vpa']],
                ]);
        } catch (RazorpayException $e) {
            Log::warning('RazorpayX fund account setup failed', ['ownerId' => $owner->id, 'error' => $e->getMessage()]);

            // Generic message only — $e's message can contain Razorpay's raw response body.
            if ($e->notSent) {
                throw new PaymentRuleException('Payout accounts are temporarily unavailable. Please try again later.', 503);
            }

            throw new PaymentRuleException('Could not add this account. Check the details and try again.', $e->definitelyRejected() ? 422 : 502);
        }

        $account = PayoutAccount::create([
            'id_owner' => $owner->id,
            'type' => $data['type'],
            'contact_id' => $wallet->razorpayx_contact_id,
            'fund_account_id' => $fundAccount['id'],
            'masked_account' => $isBank ? self::maskAccount($data['accountNumber']) : self::maskVpa($data['vpa']),
            'ifsc' => $isBank ? strtoupper($data['ifsc']) : null,
            'holder_name' => $isBank ? $data['holderName'] : null,
            'status' => PayoutAccount::STATUS_VALIDATING,
            'is_default' => false,
        ]);

        try {
            $validation = $this->razorpay->validateFundAccount($fundAccount['id']);
            $account->update(['validation_id' => $validation['id'] ?? null]);
            if (in_array($validation['status'] ?? null, ['completed', 'failed'], true)) {
                $this->apply($account, $validation); // some VPA validations may return a final result synchronously
            }
        } catch (RazorpayException $e) {
            Log::warning('RazorpayX fund account validation could not start', ['payoutAccountId' => $account->id, 'error' => $e->getMessage()]);
            $account->update(['status' => PayoutAccount::STATUS_FAILED, 'failure_reason' => 'Could not start account verification. Please add the account again.']);
        }

        return $account->refresh();
    }

    /** fund_account.validation.completed|failed */
    public function onValidationEvent(array $entity): string
    {
        $account = PayoutAccount::where('validation_id', $entity['id'] ?? '')->first()
            ?? PayoutAccount::where('fund_account_id', $entity['fund_account']['id'] ?? '')
                ->where('status', PayoutAccount::STATUS_VALIDATING)->latest('id')->first();

        if (! $account) {
            return 'ignored: unknown fund account';
        }

        $this->apply($account, $entity);

        return 'payout account '.$account->fresh()->status;
    }

    public function delete(PayoutAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $wasDefault = $account->is_default;
            $account->update(['is_default' => false]);
            $account->delete();

            if ($wasDefault) {
                PayoutAccount::where('id_owner', $account->id_owner)
                    ->where('status', PayoutAccount::STATUS_VERIFIED)
                    ->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });
    }

    public static function maskAccount(string $number): string
    {
        return str_repeat('X', max(0, strlen($number) - 4)).substr($number, -4);
    }

    public static function maskVpa(string $vpa): string
    {
        [$user, $handle] = array_pad(explode('@', $vpa, 2), 2, '');

        return substr($user, 0, 2).'***@'.$handle;
    }

    private function apply(PayoutAccount $account, array $validation): void
    {
        $results = (array) ($validation['results'] ?? []);
        $ok = ($validation['status'] ?? null) === 'completed'
            && in_array($results['account_status'] ?? null, ['active', 'valid'], true);

        if (! $ok) {
            Log::info('payout_account.validation_failed', [
                'payout_account_id' => $account->id,
                'status' => $validation['status'] ?? null,
                'account_status' => $results['account_status'] ?? null,
            ]);
            $account->update([
                'status' => PayoutAccount::STATUS_FAILED,
                // Generic for the organiser; RazorpayX's raw status is only logged.
                'failure_reason' => 'We could not verify this account. Check the details and add it again.',
            ]);

            return;
        }

        $hasDefault = PayoutAccount::where('id_owner', $account->id_owner)
            ->where('is_default', true)->whereKeyNot($account->id)->exists();

        $account->update([
            'status' => PayoutAccount::STATUS_VERIFIED,
            'verified_name' => isset($results['registered_name']) ? Str::limit((string) $results['registered_name'], 120, '') : null,
            'failure_reason' => null,
            'is_default' => $account->is_default || ! $hasDefault,
        ]);
    }
}
