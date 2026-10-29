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

    /*
     |--------------------------------------------------------------------------
     | Web (Razorpay Payment Link) purchase — country → currency → gateway
     |--------------------------------------------------------------------------
     | The native apps buy packs through RevenueCat (store billing); the web has
     | no billing SDK, so it buys via a Razorpay Payment Link in the buyer's
     | country currency. These are resolved by SubscriptionPaymentsResolver from
     | users.country (ISO2).
     |
     |  IN → INR razorpay · LK → LKR razorpay · BD → BDT razorpay
     |  PK → whatsapp (payments disabled for now; Stripe later)
     |  everything else → USD razorpay
     |
     | NOTE: the non-INR prices below are converted DRAFTS — adjust to taste.
     | Razorpay charges in the currency's smallest unit; the per-tier numbers
     | here are MAJOR units (SubscriptionService multiplies by 100). A link in a
     | non-INR currency only succeeds if that currency is enabled on the Razorpay
     | account (India merchants are often INR-only + international cards).
     */
    'country_gateway' => [
        'IN' => ['currency' => 'INR', 'gateway' => 'razorpay'],
        'LK' => ['currency' => 'LKR', 'gateway' => 'razorpay'],
        'BD' => ['currency' => 'BDT', 'gateway' => 'razorpay'],
        'PK' => ['currency' => 'PKR', 'gateway' => 'whatsapp'],
    ],

    /* Any country not listed above. */
    'default_gateway' => ['currency' => 'USD', 'gateway' => 'razorpay'],

    'currency_symbols' => [
        'INR' => '₹',
        'LKR' => 'Rs',
        'BDT' => '৳',
        'USD' => '$',
        'PKR' => 'Rs',
    ],

    /* Per-currency tier prices (MAJOR units). Keyed to the team_packs product ids. */
    'prices' => [
        // TEMP TEST: all INR tiers ₹1 for live-payment QR testing. REVERT to
        // 679/1149/1699/1999/2799/3499/4499 before real sales.
        'INR' => ['4_teams' => 1, '6_teams' => 1, '8_teams' => 1, '12_teams' => 1, '16_teams' => 1, '20_teams' => 1, '30_teams' => 1],
        'LKR' => ['4_teams' => 2300, '6_teams' => 3900, '8_teams' => 5800, '12_teams' => 6800, '16_teams' => 9500, '20_teams' => 11900, '30_teams' => 15300],
        'BDT' => ['4_teams' => 900, '6_teams' => 1550, '8_teams' => 2300, '12_teams' => 2700, '16_teams' => 3800, '20_teams' => 4700, '30_teams' => 6100],
        'USD' => ['4_teams' => 8, '6_teams' => 13, '8_teams' => 20, '12_teams' => 23, '16_teams' => 32, '20_teams' => 40, '30_teams' => 52],
    ],
];
