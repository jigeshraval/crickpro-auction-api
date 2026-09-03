<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-channel rate-limit/kill-switch settings for ApiGateway. Ported from
 * crickpro-api's App\Models\ApiLimit, trimmed of fail_open/google_bucket
 * (YouTube-quota specific, not relevant here).
 */
class ApiLimit extends Model
{
    protected $table = 'api_limits';

    protected $fillable = [
        'channel',
        'daily_limit',
        'hourly_limit',
        'is_enabled',
    ];

    protected $casts = [
        'daily_limit' => 'integer',
        'hourly_limit' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public static function getLimit(string $channel): ?self
    {
        return self::where('channel', $channel)->first();
    }

    public static function isEnabled(string $channel): bool
    {
        $limit = self::getLimit($channel);

        return $limit ? $limit->is_enabled : true;
    }

    public static function getDailyLimit(string $channel): ?int
    {
        return self::getLimit($channel)?->daily_limit;
    }

    public static function getHourlyLimit(string $channel): ?int
    {
        return self::getLimit($channel)?->hourly_limit;
    }
}
