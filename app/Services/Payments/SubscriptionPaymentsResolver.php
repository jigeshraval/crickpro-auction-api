<?php

namespace App\Services\Payments;

use App\Models\User;

/**
 * Resolves how a buyer pays for a team pack, from their country (ISO2):
 *   country → { currency, gateway } (razorpay | whatsapp) + per-tier price.
 *
 * IN→INR, LK→LKR, BD→BDT (all razorpay); PK→whatsapp (disabled, Stripe later);
 * everything else → USD razorpay. Config: config/subscription.php.
 */
final class SubscriptionPaymentsResolver
{
    public const GATEWAY_RAZORPAY = 'razorpay';

    public const GATEWAY_WHATSAPP = 'whatsapp';

    /** @return array{country: ?string, currency: string, gateway: string} */
    public static function forCountry(?string $iso2): array
    {
        $iso2 = strtoupper(trim((string) $iso2));
        $map = (array) config('subscription.country_gateway', []);
        $entry = $map[$iso2] ?? (array) config('subscription.default_gateway', ['currency' => 'USD', 'gateway' => self::GATEWAY_RAZORPAY]);

        return [
            'country' => $iso2 !== '' ? $iso2 : null,
            'currency' => strtoupper((string) ($entry['currency'] ?? 'USD')),
            'gateway' => (string) ($entry['gateway'] ?? self::GATEWAY_RAZORPAY),
        ];
    }

    /** @return array{country: ?string, currency: string, gateway: string} */
    public static function forUser(?User $user): array
    {
        return self::forCountry($user?->country);
    }

    /** Major-unit price for a tier (`12_teams`) in a currency, or null when unpriced. */
    public static function price(string $currency, string $planCode): ?int
    {
        $prices = (array) config('subscription.prices', []);
        $row = (array) ($prices[strtoupper($currency)] ?? []);
        $value = $row[$planCode] ?? null;

        return ($value === null || $value === '') ? null : (int) $value;
    }

    public static function symbol(string $currency): string
    {
        $symbols = (array) config('subscription.currency_symbols', []);

        return (string) ($symbols[strtoupper($currency)] ?? (strtoupper($currency).' '));
    }
}
