<?php

namespace App\Services\Auth;

use App\Models\Country;
use App\Models\User;

/**
 * Applies the app-sent country to a user. Only acts when countryCode is
 * provided (never clears it). id_country is resolved by case-insensitive
 * exact name match on `countries.name`; an unknown name resolves to null and
 * never fails the request. If no name is sent and the code didn't change,
 * the existing id_country is kept.
 *
 * When no code is sent and the user has no country yet, it is filled from
 * Cloudflare's CF-IPCountry header (cfCountry()) — no user prompt.
 */
class UserCountry
{
    public function apply(User $user, ?string $countryCode, ?string $countryName = null): void
    {
        $countryCode = $countryCode !== null ? strtoupper(trim($countryCode)) : '';
        if ($countryCode === '') {
            $this->fillFromCloudflare($user);

            return;
        }

        $countryName = $countryName !== null ? trim($countryName) : '';
        $countryId = $countryName !== ''
            ? Country::whereRaw('LOWER(name) = ?', [mb_strtolower($countryName)])->value('id')
            : ($user->country === $countryCode ? $user->id_country : null);

        $user->forceFill(['country' => $countryCode, 'id_country' => $countryId])->save();
    }

    /** Sets the user's country from Cloudflare's CF-IPCountry header, only while it is still unset. */
    public function fillFromCloudflare(User $user): void
    {
        $code = cfCountry();
        if ($user->country === null && $code !== null) {
            $user->forceFill(['country' => $code])->save();
        }
    }
}
