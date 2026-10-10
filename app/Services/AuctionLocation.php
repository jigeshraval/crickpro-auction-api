<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\City;
use App\Models\Country;
use App\Models\State;

/**
 * The ONE place an auction's city/state/country ids and its `location` label
 * ("Calgary, Alberta, Canada") are kept consistent — applied from the Auction
 * model's `saving` hook, so every write path (quick-start, PATCH, CrickPro
 * provision/import) goes through it.
 *
 *  - id_city set   → id_state / id_country copied from the city row.
 *  - id_city cleared (and state/country not set in the same save) → all four cleared.
 *  - state/country without a city (a CrickPro tournament with no city) → kept,
 *    label built from what exists.
 *
 * crickpro-api-v2's "Start Auctioning" writes the auction DB directly and
 * builds the same label with the same rule (skip empty parts, ", " joined).
 */
class AuctionLocation
{
    public const GEO = ['id_city', 'id_state', 'id_country'];

    public static function apply(Auction $auction): void
    {
        if (! $auction->isDirty(self::GEO)) {
            return;
        }

        // Clearing the city alone clears the whole location.
        if (! $auction->id_city && $auction->isDirty('id_city') && ! $auction->isDirty(['id_state', 'id_country'])) {
            $auction->id_state = null;
            $auction->id_country = null;
        }

        $resolved = self::describe($auction->id_city, $auction->id_state, $auction->id_country);
        $auction->id_city = $resolved['cityId'];
        $auction->id_state = $resolved['stateId'];
        $auction->id_country = $resolved['countryId'];
        $auction->location = $resolved['location'];
    }

    /**
     * Resolve ids → {cityId, stateId, countryId, location}. A city wins over
     * the given state/country (they derive from it); unknown ids drop out.
     *
     * @return array{cityId: ?int, stateId: ?int, countryId: ?int, location: ?string}
     */
    public static function describe(?int $cityId, ?int $stateId = null, ?int $countryId = null): array
    {
        $city = $cityId ? City::find($cityId) : null;
        if ($city) {
            $stateId = $city->id_state;
            $countryId = $city->id_country;
        }
        $state = $stateId ? State::find($stateId) : null;
        $countryId ??= $state?->id_country;
        $country = $countryId ? Country::find($countryId) : null;

        return [
            'cityId' => $city?->id,
            'stateId' => $state?->id,
            'countryId' => $country?->id,
            'location' => self::format($city?->name, $state?->name, $country?->name),
        ];
    }

    /**
     * A CrickPro tournament's (api-v2 `series`) geo ids → auction attributes.
     * Only the city is needed (state/country derive from it); a tournament with
     * a state/country but no city keeps what it has. [] when it has none.
     *
     * @return array<string, ?int>
     */
    public static function attributesFrom(mixed $cityId, mixed $stateId = null, mixed $countryId = null): array
    {
        $resolved = self::describe((int) $cityId ?: null, (int) $stateId ?: null, (int) $countryId ?: null);
        if (! $resolved['cityId'] && ! $resolved['stateId'] && ! $resolved['countryId']) {
            return [];
        }

        return ['id_city' => $resolved['cityId'], 'id_state' => $resolved['stateId'], 'id_country' => $resolved['countryId']];
    }

    /** "City, State, Country" — empty parts skipped; null when nothing is left. */
    public static function format(?string $city, ?string $state, ?string $country): ?string
    {
        $parts = array_values(array_filter(
            array_map(fn ($p) => trim((string) $p), [$city, $state, $country]),
            fn ($p) => $p !== '',
        ));

        return $parts ? mb_substr(implode(', ', $parts), 0, 255) : null;
    }
}
