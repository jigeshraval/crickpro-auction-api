<?php

/*
 | Player registration payments — Razorpay single-use UPI QR collected into
 | CrickPro's account, organiser wallet ledger, RazorpayX payouts.
 | ALL MONEY IS INTEGER PAISE. Spec:
 | crickpro-auction-app/docs/superpowers/specs/2026-10-09-registration-payments-design.md
 */
return [
    'currency' => 'INR',

    // Platform fee P = max(round(B × percent / 100), min), on the registration fee B.
    'platform_fee_percent' => (float) env('PAYMENTS_PLATFORM_FEE_PERCENT', 2),
    'platform_fee_min' => (int) env('PAYMENTS_PLATFORM_FEE_MIN', 1000),

    // Estimated gateway fee on a charge T = round(T × rate × (1 + gst)). The real
    // fee from the captured payment entity replaces the estimate on capture.
    'gateway_rate' => (float) env('PAYMENTS_GATEWAY_RATE', 0.02),
    'gateway_gst' => (float) env('PAYMENTS_GATEWAY_GST', 0.18),

    // Minimum registration fee per player: ₹50 (razorpay AND manual modes).
    'min_registration_fee' => (int) env('PAYMENTS_MIN_REGISTRATION_FEE', 5000),
    'max_registration_fee' => (int) env('PAYMENTS_MAX_REGISTRATION_FEE', 10_000_000),

    // QR lifetime and the polling backstop.
    'qr_ttl_minutes' => (int) env('PAYMENTS_QR_TTL_MINUTES', 15),
    'poll_min_age_seconds' => 30,
    'poll_throttle_seconds' => 10,
    'poll_grace_minutes' => 10, // keep polling closed QRs this long (late payments are honoured)

    // Collections become withdrawable after this many days.
    'hold_days' => (int) env('PAYMENTS_HOLD_DAYS', 3),

    // RazorpayX payout fee (borne by the organiser) + GST on it.
    'payout_fee' => (int) env('PAYMENTS_PAYOUT_FEE', 500),
    'payout_fee_gst' => (float) env('PAYMENTS_PAYOUT_FEE_GST', 0.18),
    'min_withdrawal' => (int) env('PAYMENTS_MIN_WITHDRAWAL', 10_000),

    // Webhook events: an unprocessed stored event is reclaimed by a retry after
    // this many seconds; processed events are pruned daily after N days.
    'webhook_reclaim_seconds' => (int) env('PAYMENTS_WEBHOOK_RECLAIM_SECONDS', 120),
    'webhook_event_retention_days' => (int) env('PAYMENTS_WEBHOOK_EVENT_RETENTION_DAYS', 90),

    // Ops alerts (negative balances, amount mismatches, duplicate payments).
    'ops_email' => env('PAYMENTS_OPS_EMAIL'),

    'razorpay' => [
        'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'razorpayx' => [
        'base_url' => env('RAZORPAYX_BASE_URL', env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1')),
        'key_id' => env('RAZORPAYX_KEY_ID', env('RAZORPAY_KEY_ID')),
        'key_secret' => env('RAZORPAYX_KEY_SECRET', env('RAZORPAY_KEY_SECRET')),
        'account_number' => env('RAZORPAYX_ACCOUNT_NUMBER'),
        'webhook_secret' => env('RAZORPAYX_WEBHOOK_SECRET'),
        // Stuck payouts are auto-resent (same idempotency key) only this long;
        // after that they are flagged "Needs manual reconciliation" for ops.
        'recovery_max_hours' => (int) env('PAYMENTS_PAYOUT_RECOVERY_MAX_HOURS', 24),
    ],
];
