<?php

namespace App\Services\Auth;

use App\Models\Country;
use App\Models\User;

/**
 * Applies the app-sent country to a user. Only acts when countryCode is
 * provided (never clears it). id_country is resolved by ISO2 (`country.iso2`), then by case-insensitive
 * exact name match on `country.name`; an unknown name resolves to null and
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
        // ISO2 first (seeded from api-v2's `country`), then the app-sent name.
        $countryId = $this->idForCode($countryCode)
            ?? ($countryName !== '' ? Country::whereRaw('LOWER(name) = ?', [mb_strtolower($countryName)])->value('id') : null)
            ?? ($user->country === $countryCode ? $user->id_country : null);

        $user->forceFill(['country' => $countryCode, 'id_country' => $countryId])->save();
    }

    /** Sets the user's country from Cloudflare's CF-IPCountry header, only while it is still unset. */
    public function fillFromCloudflare(User $user): void
    {
        $code = cfCountry();
        if ($user->country === null && $code !== null) {
            $user->forceFill(['country' => $code, 'id_country' => $this->idForCode($code)])->save();
        }
    }

    private function idForCode(string $code): ?int
    {
        $id = Country::where('iso2', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
