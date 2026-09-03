<?php

namespace App\Services;

use App\Models\ApiCall;
use App\Models\ApiLimit;
use Illuminate\Support\Facades\Log;

/**
 * Centralized throttler + audit log for outbound external API calls.
 * Ported from crickpro-api's App\Services\ApiGateway, trimmed of its
 * Google-quota-bucket logic (passesQuota/logBlocked/fail_open) — not
 * relevant to a single always-simple-rate-limited channel like WhatsApp.
 *
 * Usage:
 *   $result = ApiGateway::execute(
 *       channel: 'whatsapp',
 *       message: 'Send OTP',
 *       identifier: $to,
 *       requestData: $payload,
 *       callback: fn () => Http::post(...),
 *   );
 *   // $result: ['success' => bool, 'data' => mixed, 'error' => ?string, 'call' => ?ApiCall]
 */
class ApiGateway
{
    protected string $channel;

    public function __construct(string $channel)
    {
        $this->channel = $channel;
    }

    /** Throws if the channel is disabled or over its hourly/daily limit. */
    public function check(): void
    {
        if (! ApiLimit::isEnabled($this->channel)) {
            Log::warning("ApiGateway: {$this->channel} is disabled");

            throw new \RuntimeException("{$this->channel} API is currently disabled");
        }

        $this->checkHourlyLimit();
        $this->checkDailyLimit();
    }

    public function before(string $message, ?string $identifier = null, mixed $requestData = null): ApiCall
    {
        return ApiCall::log(
            channel: $this->channel,
            message: $message,
            identifier: $identifier,
            request: is_array($requestData) ? json_encode($requestData) : $requestData,
            ipAddress: request()->ip(),
        );
    }

    public function after(ApiCall $call, mixed $response, ?int $status = null, ?float $responseTime = null): void
    {
        $call->logResponse($response, $status, $responseTime);
    }

    /**
     * check() -> log before -> run $callback -> log response, never throws.
     *
     * @param  callable  $callback  Receives the ApiCall record, returns the result.
     * @return array{success: bool, data: mixed, error: ?string, call: ?ApiCall}
     */
    public static function execute(
        string $channel,
        string $message,
        ?string $identifier,
        callable $callback,
        mixed $requestData = null,
    ): array {
        $gateway = new self($channel);

        try {
            $gateway->check();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'call' => null];
        }

        $call = $gateway->before($message, $identifier, $requestData);
        $startTime = microtime(true);

        try {
            $result = $callback($call);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $gateway->after($call, $result, 200, $responseTime);

            return ['success' => true, 'data' => $result, 'error' => null, 'call' => $call];
        } catch (\Throwable $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $gateway->after($call, ['error' => $e->getMessage()], 500, $responseTime);

            Log::error("ApiGateway: {$channel} call failed", [
                'message' => $message,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'call' => $call];
        }
    }

    protected function checkHourlyLimit(): void
    {
        $limit = ApiLimit::getHourlyLimit($this->channel);
        if (! $limit) {
            return;
        }

        $count = ApiCall::getHourlyCount($this->channel);

        if ($count >= $limit) {
            Log::warning("ApiGateway: {$this->channel} hourly limit reached", ['limit' => $limit, 'count' => $count]);

            throw new \RuntimeException("{$this->channel} hourly API limit reached ({$count}/{$limit})");
        }
    }

    protected function checkDailyLimit(): void
    {
        $limit = ApiLimit::getDailyLimit($this->channel);
        if (! $limit) {
            return;
        }

        $count = ApiCall::getDailyCount($this->channel);

        if ($count >= $limit) {
            Log::warning("ApiGateway: {$this->channel} daily limit reached", ['limit' => $limit, 'count' => $count]);

            throw new \RuntimeException("{$this->channel} daily API limit reached ({$count}/{$limit})");
        }
    }
}
