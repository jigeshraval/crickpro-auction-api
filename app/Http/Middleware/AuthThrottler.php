<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from crickpro-api's AuthThrottler, trimmed to this API's endpoint
 * list. Fixes a gap in the original: crickpro-api's routes pass
 * 'verify-otp' as the action name for two different endpoints
 * (verify-reset-otp-mobile, verify-whatsapp-otp) but its $limits map never
 * defined a 'verify-otp' key, so neither got an action-specific limit. Here
 * each action name passed from routes/api.php has its own entry below.
 */
class AuthThrottler
{
    private array $limits = [
        'check-user' => ['attempts' => 30, 'decay' => 60],
        'check-user-mobile' => ['attempts' => 30, 'decay' => 60],
        'send-email-otp' => ['attempts' => 25, 'decay' => 300],
        'verify-email-otp' => ['attempts' => 10, 'decay' => 300],
        'send-whatsapp-otp' => ['attempts' => 10, 'decay' => 300],
        'login-password' => ['attempts' => 10, 'decay' => 300],
        'login-password-mobile' => ['attempts' => 10, 'decay' => 300],
        'register' => ['attempts' => 20, 'decay' => 3600],
        'verify-reset-otp-mobile' => ['attempts' => 10, 'decay' => 300],
        'reset-password-mobile' => ['attempts' => 5, 'decay' => 3600],
        'reset-password' => ['attempts' => 5, 'decay' => 3600],
        'verify-whatsapp-otp' => ['attempts' => 10, 'decay' => 300],
    ];

    private int $dailyOtpLimit = 500;

    private int $dailyLoginAttemptLimit = 10000;

    private int $dailyRegistrationLimit = 10000;

    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        $ip = ipAddress();
        $identifier = $this->getIdentifier($request);

        if ($this->isIpBlocked($ip)) {
            return $this->blockedResponse('Too many requests. Please try again later.');
        }

        $dailyCheckResult = $this->checkDailyLimits($ip, $action);
        if ($dailyCheckResult !== true) {
            return $dailyCheckResult;
        }

        if ($action && isset($this->limits[$action])) {
            $limit = $this->limits[$action];
            $key = $this->getRateLimitKey($action, $ip, $identifier);

            if ($this->tooManyAttempts($key, $limit['attempts'])) {
                $this->logSuspiciousActivity($request, $action, 'rate_limit_exceeded');
                $this->incrementBlockScore($ip);

                return $this->rateLimitResponse($this->availableIn($key), $action);
            }

            $this->hit($key, $limit['decay']);
        }

        if (in_array($action, ['send-email-otp'], true)) {
            $emailCheckResult = $this->checkEmailSpecificLimits($request, $ip);
            if ($emailCheckResult !== true) {
                return $emailCheckResult;
            }
        }

        if (in_array($action, ['login-password', 'login-password-mobile'], true)) {
            $loginCheckResult = $this->checkLoginSpecificLimits($request, $ip, $identifier);
            if ($loginCheckResult !== true) {
                return $loginCheckResult;
            }
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401 || $response->getStatusCode() === 422) {
            $this->trackFailedAttempt($request, $action, $ip, $identifier);
        }

        return $response;
    }

    private function getIdentifier(Request $request): string
    {
        return $request->input('email') ?? $request->input('mobile') ?? ipAddress();
    }

    private function isIpBlocked(string $ip): bool
    {
        return Cache::get("auth_block_score:{$ip}", 0) >= 1000;
    }

    private function incrementBlockScore(string $ip, int $points = 10): void
    {
        $key = "auth_block_score:{$ip}";
        $currentScore = Cache::get($key, 0);
        Cache::put($key, $currentScore + $points, now()->addHours(24));

        if ($currentScore + $points >= 100) {
            Log::warning('IP blocked due to suspicious auth activity', ['ip' => $ip]);
        }
    }

    private function checkDailyLimits(string $ip, ?string $action): bool|Response
    {
        $today = now()->format('Y-m-d');

        if (in_array($action, ['send-email-otp'], true)) {
            $otpKey = "daily_otp:{$ip}:{$today}";
            $otpCount = Cache::get($otpKey, 0);

            if ($otpCount >= $this->dailyOtpLimit && app()->environment('production')) {
                return $this->blockedResponse('Daily OTP limit reached. Please try again tomorrow.', 86400 - now()->secondsSinceMidnight());
            }

            Cache::put($otpKey, $otpCount + 1, now()->endOfDay());
        }

        if (in_array($action, ['login-password', 'login-password-mobile'], true)) {
            $loginKey = "daily_login:{$ip}:{$today}";
            $loginCount = Cache::get($loginKey, 0);

            if ($loginCount >= $this->dailyLoginAttemptLimit) {
                return $this->blockedResponse('Too many login attempts today. Please try again tomorrow.', 86400 - now()->secondsSinceMidnight());
            }

            Cache::put($loginKey, $loginCount + 1, now()->endOfDay());
        }

        if ($action === 'register') {
            $regKey = "daily_register:{$ip}:{$today}";
            $regCount = Cache::get($regKey, 0);

            if ($regCount >= $this->dailyRegistrationLimit) {
                return $this->blockedResponse('Too many registration attempts today. Please try again tomorrow.', 86400 - now()->secondsSinceMidnight());
            }

            Cache::put($regKey, $regCount + 1, now()->endOfDay());
        }

        return true;
    }

    private function checkEmailSpecificLimits(Request $request, string $ip): bool|Response
    {
        $email = $request->input('email');
        if (! $email) {
            return true;
        }

        $emailKey = 'otp_email:'.md5($email);
        $emailAttempts = Cache::get($emailKey, 0);

        if ($emailAttempts >= 200) {
            return $this->rateLimitResponse(Cache::get("{$emailKey}:ttl", 3600), 'send-email-otp', 'Too many OTP requests for this email address.');
        }

        Cache::put($emailKey, $emailAttempts + 1, now()->addHour());
        Cache::put("{$emailKey}:ttl", 3600, now()->addHour());

        return true;
    }

    private function checkLoginSpecificLimits(Request $request, string $ip, string $identifier): bool|Response
    {
        $accountKey = 'login_account:'.md5($identifier);
        $accountAttempts = Cache::get($accountKey, 0);

        if ($accountAttempts >= 5) {
            $this->incrementBlockScore($ip, 20);

            return $this->rateLimitResponse(Cache::get("{$accountKey}:ttl", 900), 'login-password', 'Too many failed login attempts. Account temporarily locked.');
        }

        return true;
    }

    private function trackFailedAttempt(Request $request, ?string $action, string $ip, string $identifier): void
    {
        if (in_array($action, ['login-password', 'login-password-mobile'], true)) {
            $accountKey = 'login_account:'.md5($identifier);
            $attempts = Cache::get($accountKey, 0);
            Cache::put($accountKey, $attempts + 1, now()->addMinutes(15));
            Cache::put("{$accountKey}:ttl", 900, now()->addMinutes(15));

            $this->incrementBlockScore($ip, 5);
        }

        if (in_array($action, ['verify-email-otp', 'verify-reset-otp-mobile', 'verify-whatsapp-otp'], true)) {
            $this->incrementBlockScore($ip, 15);
        }
    }

    private function logSuspiciousActivity(Request $request, string $action, string $reason): void
    {
        Log::warning('Suspicious auth activity detected', [
            'action' => $action,
            'reason' => $reason,
            'ip' => ipAddress(),
            'user_agent' => $request->userAgent(),
            'email' => $request->input('email'),
            'mobile' => $request->input('mobile'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function getRateLimitKey(string $action, string $ip, string $identifier): string
    {
        return "auth_throttle:{$action}:{$ip}:".md5($identifier);
    }

    private function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return RateLimiter::tooManyAttempts($key, $maxAttempts);
    }

    private function hit(string $key, int $decaySeconds): void
    {
        RateLimiter::hit($key, $decaySeconds);
    }

    private function availableIn(string $key): int
    {
        return RateLimiter::availableIn($key);
    }

    private function rateLimitResponse(int $retryAfter, string $action, ?string $message = null): Response
    {
        $defaultMessages = [
            'send-email-otp' => 'Too many OTP requests. Please wait before requesting another.',
            'verify-email-otp' => 'Too many verification attempts. Please wait before trying again.',
            'login-password' => 'Too many login attempts. Please wait before trying again.',
            'register' => 'Too many registration attempts. Please try again later.',
        ];

        return response()->json([
            'status' => 'error',
            'message' => $message ?? $defaultMessages[$action] ?? 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter,
        ], 429)->withHeaders([
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->timestamp,
        ]);
    }

    private function blockedResponse(string $message, int $retryAfter = 3600): Response
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'retry_after' => $retryAfter,
        ], 429)->withHeaders(['Retry-After' => $retryAfter]);
    }
}
