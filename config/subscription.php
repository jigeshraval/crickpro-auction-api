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
     | Team-pack products (Google Play / App Store product id → max teams). The
     | store product ids are `N_teams`; anything unmapped falls back to parsing
     | the leading number out of the id.
     */
    'team_packs' => [
        '4_teams' => 4,
        '6_teams' => 6,
        '8_teams' => 8,
        '12_teams' => 12,
        '16_teams' => 16,
        '20_teams' => 20,
        '30_teams' => 30,
    ],

    /* Teams allowed on an auction with no active plan. */
    'free_teams' => 3,
];
