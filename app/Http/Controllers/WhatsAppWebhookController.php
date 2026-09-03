<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetCode;
use App\Repositories\AuthRepository;
use App\Services\ApiGateway;
use App\Services\WhatsAppOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ported from crickpro-api's WhatsAppWebhookController — same incoming-
 * message flow: the user texts "verify"/"reset" to the business number
 * (via the wa.me deep link the auth screens open), and we reply with an
 * OTP within the 24h session that message just opened. Meta rejects a
 * cold outbound send with no open session (see WhatsAppOtpService), so
 * this webhook is the only way OTPs actually reach a phone.
 *
 * Trimmed of crickpro-api's ScoreMyGoal/crickpro-lite cross-app forwarding —
 * this app has its own dedicated number, nothing else shares it.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly AuthRepository $auth,
        private readonly WhatsAppOtpService $whatsapp,
    ) {}

    /** Meta's webhook verification handshake (GET), required when registering the callback URL. */
    public function verify(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token');

        if ($request->query('hub_mode') === 'subscribe' && $request->query('hub_verify_token') === $verifyToken) {
            return response($request->query('hub_challenge'), 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request)
    {
        if (! $this->verifySignature($request)) {
            Log::warning('WhatsApp webhook signature verification failed', ['ip' => $request->ip()]);

            return response('Unauthorized', 403);
        }

        $gateway = new ApiGateway('whatsapp-webhook');
        try {
            $gateway->check();
        } catch (\RuntimeException $e) {
            Log::warning('WhatsApp webhook rate limit reached', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'rate_limited'], 429);
        }

        $payload = $request->all();
        $value = $payload['entry'][0]['changes'][0]['value'] ?? null;

        // Skip status webhooks (sent/delivered/read) — nothing to act on.
        if (isset($value['statuses']) && ! isset($value['messages'])) {
            return response()->json(['status' => 'ok']);
        }

        $message = $value['messages'][0] ?? null;

        if (! $message || ($message['type'] ?? null) !== 'text') {
            return response()->json(['status' => 'ok']);
        }

        // `from` is the sender's phone — required to act on this at all. Not
        // every text-typed message object carries it (system/echo/partial
        // deliveries); bail with 200 so Meta doesn't retry-storm us over it.
        $from = $message['from'] ?? null; // e.g. "919601056348" (no +)
        if (! $from) {
            Log::warning('WhatsApp inbound message missing "from" — skipping', ['message' => $message]);

            return response()->json(['status' => 'ok']);
        }

        $text = trim($message['text']['body'] ?? '');
        $phone = '+'.$from; // normalize to the "+<phoneCode><mobile>" shape stored in our DB

        if ($this->isResetRequest($text)) {
            $this->handlePasswordReset($phone, $from);
        } elseif ($this->isVerifyRequest($text)) {
            $this->handlePhoneVerification($phone, $from);
        }

        return response()->json(['status' => 'ok']);
    }

    private function isResetRequest(string $text): bool
    {
        $text = strtolower($text);

        return str_contains($text, 'reset') || str_contains($text, 'forgot') || str_contains($text, 'password');
    }

    private function isVerifyRequest(string $text): bool
    {
        return str_contains(strtolower($text), 'verify');
    }

    /** New-registration phone verification — no account exists yet, just prove the number is reachable. */
    private function handlePhoneVerification(string $phone, string $whatsappId): void
    {
        $rateLimitKey = "whatsapp-verify-attempts:{$phone}";
        $attempts = (int) Cache::get($rateLimitKey, 0);

        if ($attempts >= 5) {
            $this->whatsapp->sendMessage($whatsappId, 'Too many verification requests. Please try again later.');

            return;
        }
        Cache::put($rateLimitKey, $attempts + 1, now()->addHour());

        $otp = $this->generateOtp();

        $this->auth->expirePriorCodes($phone, PasswordResetCode::CHANNEL_WHATSAPP);
        $this->auth->storeCode(
            $this->auth->findByMobile($phone)?->id,
            $phone,
            $otp,
            PasswordResetCode::CHANNEL_WHATSAPP,
            now()->addMinutes(5)
        );

        $this->whatsapp->sendMessage(
            $whatsappId,
            "Your CrickPro Auction verification code is: *{$otp}*\n\nThis code is valid for 5 minutes.\n\nIf you didn't request this, please ignore this message."
        );
    }

    private function handlePasswordReset(string $phone, string $whatsappId): void
    {
        $rateLimitKey = "whatsapp-reset-attempts:{$phone}";
        $attempts = (int) Cache::get($rateLimitKey, 0);

        if ($attempts >= 3) {
            $this->whatsapp->sendMessage($whatsappId, 'Too many reset requests. Please try again after some time.');

            return;
        }
        Cache::put($rateLimitKey, $attempts + 1, now()->addHour());

        $user = $this->auth->findByMobile($phone);

        if (! $user) {
            $this->whatsapp->sendMessage($whatsappId, 'No CrickPro Auction account found with this number.');

            return;
        }

        $otp = $this->generateOtp();

        $this->auth->expirePriorCodes($phone, PasswordResetCode::CHANNEL_WHATSAPP);
        $this->auth->storeCode($user->id, $phone, $otp, PasswordResetCode::CHANNEL_WHATSAPP, now()->addMinutes(5));

        $this->whatsapp->sendMessage(
            $whatsappId,
            "Your CrickPro Auction password reset code is: *{$otp}*\n\nThis code is valid for 5 minutes. Enter it in the app to reset your password.\n\nIf you didn't request this, please ignore this message."
        );
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Verify Meta's X-Hub-Signature-256 header — every inbound POST is signed with the app secret. */
    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        $appSecret = config('services.whatsapp.app_secret');

        if (! $signature || ! $appSecret) {
            return false;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expectedSignature, $signature);
    }
}
