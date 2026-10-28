<?php

namespace App\Services\Payments;

use App\Services\ApiGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Every Razorpay (PG) and RazorpayX call. Goes through ApiGateway so each call
 * is rate-limit-checked and audited in api_calls (channels "razorpay" /
 * "razorpayx"). Only idempotent calls are retried on 5xx/connection errors
 * (GETs, and payouts — protected by X-Payout-Idempotency). QR create, refund,
 * contact and fund-account calls are never retried automatically.
 */
class RazorpayClient
{
    public const CHANNEL_GATEWAY = 'razorpay';

    public const CHANNEL_PAYOUTS = 'razorpayx';

    public function createQr(array $payload): array
    {
        return $this->send(self::CHANNEL_GATEWAY, 'POST', 'payments/qr_codes', $payload, 'Create UPI QR',
            isset($payload['notes']['payment_id']) ? (string) $payload['notes']['payment_id'] : null, retry: false);
    }

    /**
     * Create a hosted Razorpay Payment Link (used for web subscription checkout).
     * $amountMinor is in the currency's smallest unit (paise/cents). Returns the
     * decoded entity — callers need `id` (plink_…) + `short_url`. Not retried
     * (creating twice would make two links); idempotency is our reference_id.
     */
    public function createPaymentLink(
        int $amountMinor,
        string $currency,
        string $description,
        string $referenceId,
        array $notes = [],
        array $customer = [],
        ?string $callbackUrl = null,
    ): array {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException("Payment link amount must be positive, got {$amountMinor}.");
        }

        $payload = [
            'amount' => $amountMinor,
            'currency' => strtoupper($currency),
            'description' => $description,
            'reference_id' => $referenceId,
            // We confirm activation via the payment_link.paid webhook, not reminders.
            'reminder_enable' => false,
            'notify' => ['sms' => false, 'email' => false],
        ];

        // Razorpay rejects a customer block without an email ("customer email is
        // required"), so send it only when we have one — otherwise omit it and
        // let the buyer fill their details on the hosted page.
        $customer = array_filter($customer, fn ($v) => $v !== null && $v !== '');
        if ($customer !== [] && ! empty($customer['email'])) {
            $payload['customer'] = $customer;
        }
        if ($notes !== []) {
            $payload['notes'] = $notes;
        }
        if ($callbackUrl !== null && $callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
            $payload['callback_method'] = 'get';
        }

        return $this->send(self::CHANNEL_GATEWAY, 'POST', 'payment_links', $payload, 'Create payment link', $referenceId, retry: false);
    }

    /**
     * Payment links created with a reference_id (newest first per Razorpay).
     * Read-only backstop for a missed `payment_link.paid` webhook — mirrors
     * qrPayments() for registration. @return list<array> payment_link entities
     */
    public function paymentLinksByReference(string $referenceId): array
    {
        $response = $this->send(self::CHANNEL_GATEWAY, 'GET', 'payment_links', ['reference_id' => $referenceId], 'Fetch payment links', $referenceId, retry: true);

        return array_values((array) ($response['payment_links'] ?? []));
    }

    /** @return list<array> payment entities made against the QR */
    public function qrPayments(string $qrId): array
    {
        $response = $this->send(self::CHANNEL_GATEWAY, 'GET', "payments/qr_codes/{$qrId}/payments", [], 'Fetch QR payments', $qrId, retry: true);

        return array_values((array) ($response['items'] ?? []));
    }

    /** Close a still-active QR (best-effort cleanup; not retried). */
    public function closeQr(string $qrId): array
    {
        return $this->send(self::CHANNEL_GATEWAY, 'POST', "payments/qr_codes/{$qrId}/close", [], 'Close UPI QR', $qrId, retry: false);
    }

    /**
     * Partial/full refund of exactly $amount paise. Razorpay treats a missing
     * amount as "refund everything", so amount is always sent and must be > 0.
     * $idempotencyKey is sent as X-Refund-Idempotency (see the plan's
     * "Assumptions to verify") so a re-submitted attempt can't refund twice.
     */
    public function refund(string $paymentId, int $amount, array $notes = [], ?string $idempotencyKey = null): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Refund amount must be positive, got {$amount}.");
        }

        $payload = ['amount' => $amount];
        if ($notes !== []) {
            $payload['notes'] = $notes;
        }

        return $this->send(self::CHANNEL_GATEWAY, 'POST', "payments/{$paymentId}/refund", $payload, 'Refund payment', $paymentId, retry: false,
            headers: $idempotencyKey !== null ? ['X-Refund-Idempotency' => $idempotencyKey] : []);
    }

    public function createContact(array $payload): array
    {
        return $this->send(self::CHANNEL_PAYOUTS, 'POST', 'contacts', $payload, 'Create contact', $payload['reference_id'] ?? null, retry: false);
    }

    public function createFundAccount(array $payload): array
    {
        return $this->send(self::CHANNEL_PAYOUTS, 'POST', 'fund_accounts', $payload, 'Create fund account',
            $payload['contact_id'] ?? null, retry: false, sensitive: true);
    }

    public function validateFundAccount(string $fundAccountId): array
    {
        return $this->send(self::CHANNEL_PAYOUTS, 'POST', 'fund_accounts/validations', [
            'account_number' => config('payments.razorpayx.account_number'),
            'fund_account' => ['id' => $fundAccountId],
            'amount' => 100,
            'currency' => 'INR',
        ], 'Validate fund account', $fundAccountId, retry: false, sensitive: true);
    }

    public function createPayout(array $payload, string $idempotencyKey): array
    {
        return $this->send(self::CHANNEL_PAYOUTS, 'POST', 'payouts', $payload, 'Create payout', $idempotencyKey,
            retry: true, headers: ['X-Payout-Idempotency' => $idempotencyKey]);
    }

    /**
     * Look a payout up by our reference_id (GET /payouts?account_number=…&reference_id=…).
     * Returns the matching payout entity, null when RazorpayX has none, and
     * throws RazorpayException (outcome unknown) for an unexpected response
     * shape — callers must then NOT assume the payout doesn't exist.
     */
    public function findPayoutByReference(string $referenceId): ?array
    {
        $response = $this->send(self::CHANNEL_PAYOUTS, 'GET', 'payouts', [
            'account_number' => (string) config('payments.razorpayx.account_number'),
            'reference_id' => $referenceId,
        ], 'Find payout by reference', $referenceId, retry: true, sensitive: true);

        if (! array_key_exists('items', $response) || ! is_array($response['items']) || ! array_is_list($response['items'])) {
            throw new RazorpayException('Unexpected RazorpayX payout lookup response shape.');
        }

        foreach ($response['items'] as $item) {
            if (is_array($item) && ($item['reference_id'] ?? null) === $referenceId && ! empty($item['id'])) {
                return $item;
            }
        }

        return null;
    }

    /** Replace any bank account number with XXXX + last 4 (request AND response audit). */
    public static function mask(array $data): array
    {
        array_walk_recursive($data, function (&$value, $key) {
            if ($key === 'account_number' && is_scalar($value) && strlen((string) $value) > 4) {
                $value = 'XXXX'.substr((string) $value, -4);
            }
        });

        return $data;
    }

    private function send(
        string $channel,
        string $method,
        string $path,
        array $payload,
        string $label,
        ?string $identifier,
        bool $retry,
        array $headers = [],
        bool $sensitive = false,
    ): array {
        $config = (array) config($channel === self::CHANNEL_PAYOUTS ? 'payments.razorpayx' : 'payments.razorpay');
        if (empty($config['key_id']) || empty($config['key_secret'])) {
            throw RazorpayException::notSent("{$channel} credentials are not configured.");
        }

        $httpStatus = null;

        $result = ApiGateway::execute(
            channel: $channel,
            message: $label,
            identifier: $identifier,
            requestData: ['method' => $method, 'path' => $path, 'body' => $sensitive ? self::mask($payload) : $payload],
            callback: function () use ($config, $method, $path, $payload, $retry, $headers, $sensitive, &$httpStatus) {
                $request = Http::baseUrl((string) $config['base_url'])
                    ->withBasicAuth((string) $config['key_id'], (string) $config['key_secret'])
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout(5)
                    ->timeout(20)
                    ->withHeaders($headers);

                if ($retry) {
                    $request = $request->retry(3, 300, fn (\Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()));
                }

                try {
                    $response = $method === 'GET' ? $request->get($path, $payload ?: null) : $request->post($path, $payload);
                    $httpStatus = $response->status();
                    $json = (array) ($response->throw()->json() ?? []);
                } catch (RequestException $e) {
                    $httpStatus = $e->response->status();
                    throw $e;
                }

                return $sensitive ? self::mask($json) : $json;
            },
        );

        if (! $result['success']) {
            if ($result['call'] === null) {
                // ApiGateway::check() blocked it (channel disabled / over limit) — nothing was sent.
                throw RazorpayException::notSent((string) $result['error']);
            }

            throw new RazorpayException((string) $result['error'], $httpStatus);
        }

        return (array) $result['data'];
    }
}
