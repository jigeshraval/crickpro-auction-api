<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log of outbound calls made through ApiGateway. Ported from
 * crickpro-api's App\Models\ApiCall, trimmed of its unit_cost/quota-bucket
 * columns (YouTube-quota specific, not relevant here).
 */
class ApiCall extends Model
{
    protected $table = 'api_calls';

    protected $fillable = [
        'channel',
        'message',
        'identifier',
        'request',
        'response',
        'response_status',
        'response_time',
        'ip_address',
        'id_user',
    ];

    protected $casts = [
        'id_user' => 'integer',
        'response_status' => 'integer',
        'response_time' => 'decimal:2',
    ];

    public static function getDailyCount(string $channel): int
    {
        return self::where('channel', $channel)
            ->whereDate('created_at', today())
            ->count();
    }

    public static function getHourlyCount(string $channel): int
    {
        return self::where('channel', $channel)
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    public static function log(
        string $channel,
        ?string $message = null,
        ?string $identifier = null,
        ?string $request = null,
        ?string $ipAddress = null,
        ?int $userId = null
    ): self {
        return self::create([
            'channel' => $channel,
            'message' => $message,
            'identifier' => $identifier,
            'request' => $request,
            'ip_address' => $ipAddress,
            'id_user' => $userId,
        ]);
    }

    public function logResponse(
        array|string|null $response,
        ?int $status = null,
        ?float $responseTime = null
    ): self {
        $this->update([
            'response' => is_array($response) ? json_encode($response) : $response,
            'response_status' => $status,
            'response_time' => $responseTime,
        ]);

        return $this;
    }
}
