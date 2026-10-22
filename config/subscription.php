<?php

return [
    /*
     | Ops signature — crickpro-admin sends this as X-Ops-Signature on /v1/ops/*
     | to grant/list/revoke subscriptions. Must equal the admin's configured value.
     */
    'ops_signature' => env('OPS_SIGNATURE', ''),

    /*
     | Global overlay signature — crickpro-auction-overlay sends this as
     | X-Overlay-Signature on the /v1/overlay/* routes (theme + state + validate),
     | matching crickpro-overlay's OVERLAY_API_SIGNATURE. The per-auction 8-char
     | secret is validated separately (PUT overlay/auction/validate/secret).
     */
    'overlay_signature' => env('OVERLAY_SIGNATURE', ''),

    /*
     | Currency the configured team-pack prices below are quoted in. Only used for
     | the display figures served to clients that cannot read a store price.
     */
    'currency' => env('TEAM_PACK_CURRENCY', 'INR'),
    'currency_symbol' => env('TEAM_PACK_CURRENCY_SYMBOL', '₹'),

    /*
     | Team-pack products (Google Play / App Store product id → tier). The store
     | product ids are `N_teams`; anything unmapped falls back to parsing the
     | leading number out of the id.
     |
     | `price` exists for clients that have no billing SDK to ask for one. The
     | native apps read the real price off Google Play / the App Store and ignore
     | this; the web build cannot, so GET /v1/subscription/plans serves these.
     | A null price is served as `priceString: null` and the web shows the tier
     | without a figure — the stores stay authoritative for what a native buyer
     | is actually charged, so set these to match the store, not to replace it.
     */
    'team_packs' => [
        '4_teams' => ['teams' => 4, 'price' => env('TEAM_PACK_PRICE_4')],
        '6_teams' => ['teams' => 6, 'price' => env('TEAM_PACK_PRICE_6')],
        '8_teams' => ['teams' => 8, 'price' => env('TEAM_PACK_PRICE_8')],
        '12_teams' => ['teams' => 12, 'price' => env('TEAM_PACK_PRICE_12')],
        '16_teams' => ['teams' => 16, 'price' => env('TEAM_PACK_PRICE_16')],
        '20_teams' => ['teams' => 20, 'price' => env('TEAM_PACK_PRICE_20')],
        '30_teams' => ['teams' => 30, 'price' => env('TEAM_PACK_PRICE_30')],
    ],

    /* Teams allowed on an auction with no active plan. */
    'free_teams' => (int) env('SUBSCRIPTION_FREE_TEAMS', 2),
];
