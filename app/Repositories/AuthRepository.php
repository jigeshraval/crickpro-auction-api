<?php

namespace App\Repositories;

use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findByMobile(string $mobile): ?User
    {
        return User::where('mobile', $mobile)->first();
    }

    /**
     * A "placeholder" account is one created without credentials (no
     * password, unverified) — e.g. an organiser pre-adding a team owner.
     * The real person claims it via /register instead of dead-ending on
     * "already registered".
     *
     * @return array{claimable: ?User, existingByEmail: ?User, existingByMobile: ?User}
     */
    public function findRegistrationConflicts(?string $email, ?string $mobile): array
    {
        $existingByEmail = $email ? $this->findByEmail($email) : null;
        $existingByMobile = $mobile ? $this->findByMobile($mobile) : null;

        $isClaimable = static fn (?User $u) => $u && empty($u->password) && ! $u->verified;

        // Prefer the mobile match (mobile-led registration), else email.
        $claimable = $isClaimable($existingByMobile)
            ? $existingByMobile
            : ($isClaimable($existingByEmail) ? $existingByEmail : null);

        return compact('claimable', 'existingByEmail', 'existingByMobile');
    }

    public function createOrClaimUser(?User $claimable, array $attributes): User
    {
        $user = $claimable ?? new User;
        $user->fill($attributes);

        if (empty($user->uid)) {
            $user->uid = (string) Str::uuid();
        }

        $user->save();

        return $user;
    }

    public function updatePassword(User $user, string $plainPassword): void
    {
        $user->password = Hash::make($plainPassword);
        $user->save();
    }

    public function markEmailVerified(User $user): void
    {
        $user->email_verified_at = now();
        $user->save();
    }

    /**
     * Expire (mark used) any prior unused codes for this identifier+channel
     * before issuing a new one, so only the latest code is ever valid.
     */
    public function expirePriorCodes(string $identifier, string $channel): void
    {
        PasswordResetCode::where('identifier', $identifier)
            ->where('channel', $channel)
            ->where('used', false)
            ->update(['used' => true, 'used_at' => now()]);
    }

    public function storeCode(?int $userId, string $identifier, string $code, string $channel, \DateTimeInterface $expiresAt): PasswordResetCode
    {
        return PasswordResetCode::create([
            'id_user' => $userId,
            'identifier' => $identifier,
            'code' => $code,
            'channel' => $channel,
            'used' => false,
            'expires_at' => $expiresAt,
            'ip' => ipAddress(),
        ]);
    }

    /**
     * Find a valid (unused, unexpired) code WITHOUT consuming it — used for
     * pre-check verification steps where a later step does the real consume.
     */
    public function findValidCode(string $identifier, string $code, ?string $channel = null): ?PasswordResetCode
    {
        return PasswordResetCode::where('identifier', $identifier)
            ->where('code', $code)
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function consumeCode(PasswordResetCode $resetCode): void
    {
        $resetCode->update(['used' => true, 'used_at' => now(), 'ip' => ipAddress()]);
    }
}
