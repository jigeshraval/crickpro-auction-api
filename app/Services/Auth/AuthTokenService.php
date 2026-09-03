<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

class AuthTokenService
{
    private const CLIENT_HEADER = 'X-CrickPro-Client';

    /**
     * Issue a Sanctum token capped at 30 days. If the calling client is in
     * config('auction.revokable_clients'), any prior token for this user
     * under that same client is revoked first (single-session-per-client) —
     * unless the user is in the no_revoke_user_ids allowlist (dev/QA).
     */
    public function issue(User $user): NewAccessToken
    {
        $clientId = $this->resolveClientId();

        if ($clientId
            && in_array($clientId, config('auction.revokable_clients'), true)
            && ! in_array($user->id, config('auction.no_revoke_user_ids'), true)
        ) {
            $this->revokeClientTokens($user, $clientId);
        }

        $tokenName = request()->header('platform', 'unknown').'-'.
            request()->header('version', '1.0.0').'-'.
            substr((string) request()->header('User-Agent', 'CrickPro'), 0, 50);

        return $user->createPersonalAccessToken(
            $tokenName,
            $clientId,
            ['*'],
            now()->addMonth()
        );
    }

    public function resolveClientId(): ?string
    {
        $explicit = strtolower((string) request()->header(self::CLIENT_HEADER));

        return $explicit !== '' ? $explicit : null;
    }

    private function revokeClientTokens(User $user, string $clientId): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('client_identifier', $clientId)
            ->delete();
    }
}
