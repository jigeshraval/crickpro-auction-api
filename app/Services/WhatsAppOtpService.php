<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Same Meta WhatsApp Cloud API integration as crickpro-api's
 * WhatsAppWebhookController::sendMessage() — direct HTTP calls, no SDK, sent
 * through the same ApiGateway rate-limit/audit-log wrapper (channel
 * "whatsapp", limits seeded in the create_api_gateway_tables migration).
 *
 * Until WHATSAPP_API_TOKEN/WHATSAPP_PHONE_NUMBER_ID are configured, sendOtp()
 * no-ops (just logs the code) so registration/reset flows still work in dev —
 * mirrors crickpro-draft-api's DRAFT_BACKEND_MQTT toggle pattern.
 */
class WhatsAppOtpService
{
    public function isConfigured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * @param  string  $to  E.164-ish phone string, e.g. "+919876543210".
     */
    public function sendOtp(string $to, string $otp): bool
    {
        if (! $this->isConfigured()) {
            Log::info('WhatsApp OTP not sent — service unconfigured, logging instead', [
                'to' => $to,
                'otp' => $otp,
            ]);

            return true;
        }

        return $this->sendMessage($to, "Your CrickPro Auction verification code is: {$otp}. It expires in 5 minutes.");
    }

    /**
     * Send a freeform WhatsApp text message. Per Meta's policy, this only
     * actually delivers when $to has an open 24h session with the business
     * number — i.e. as a reply to a message $to sent us (see
     * WhatsAppWebhookController), not as a cold outbound send.
     *
     * @param  string  $to  Raw digits (webhook "from") or E.164-ish "+..." — Meta accepts both.
     */
    public function sendMessage(string $to, string $text): bool
    {
        $result = ApiGateway::execute(
            channel: 'whatsapp',
            message: 'Send WhatsApp message',
            identifier: $to,
            requestData: ['to' => $to, 'type' => 'text', 'body' => $text],
            callback: function () use ($to, $text) {
                $response = Http::withToken(config('services.whatsapp.token'))->post(
                    'https://graph.facebook.com/v21.0/'.config('services.whatsapp.phone_number_id').'/messages',
                    [
                        'messaging_product' => 'whatsapp',
                        'to' => $to,
                        'type' => 'text',
                        'text' => ['body' => $text],
                    ]
                );

                $json = $response->json();

                // Meta returns a non-2xx status with an "error" object on failure.
                // Http::post() does NOT throw by default, so detect it explicitly —
                // otherwise a failed send is silently logged as a success.
                if ($response->failed() || isset($json['error'])) {
                    $err = $json['error']['message'] ?? $response->body();
                    $code = $json['error']['code'] ?? $response->status();

                    throw new \RuntimeException("WhatsApp Cloud API error [{$code}]: {$err}");
                }

                return $json;
            },
        );

        if (! $result['success']) {
            Log::warning('WhatsApp message send failed', ['to' => $to, 'error' => $result['error']]);
        }

        return $result['success'];
    }
}
