<?php

namespace App\Services\Payments;

use App\Models\Auction;
use App\Models\User;

/**
 * THE rule for who may use registration payments (India only, v1):
 *  - user level:    users.country === 'IN'
 *  - auction level: owner eligible AND auction currency code === 'INR'
 * Used by the user payload (features.registrationPayments), the settings
 * middleware, and the join path (ineligible → free registration).
 */
final class RegistrationPaymentsEligibility
{
    public const COUNTRY = 'IN';

    public const CURRENCY = 'INR';

    public const MESSAGE = 'Registration payments are only available in India.';

    public static function forOwner(?User $user): bool
    {
        return $user !== null && strtoupper((string) $user->country) === self::COUNTRY;
    }

    public static function forAuction(Auction $auction): bool
    {
        $auction->loadMissing(['owner', 'currency']);

        return self::forOwner($auction->owner) && $auction->currency?->code === self::CURRENCY;
    }
}
