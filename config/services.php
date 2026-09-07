<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'email' => [
        'send_enabled' => env('EMAIL_SEND_ENABLED', true),
    ],

    // The main CrickPro app's API (crickpro-api-v2). Used by the "Import from
    // CrickPro App" integration to verify a web access code and relay the
    // organiser's teams/players. `url` is the base, e.g. http://host.docker.internal:16016.
    'crickpro' => [
        'url' => env('CRICKPRO_API_URL'),
        // Shared secret for the server-to-server "Start Auctioning" provision call
        // (crickpro-api-v2 → this API). Must match CRICKPRO_PROVISION_SECRET there.
        'provision_secret' => env('CRICKPRO_PROVISION_SECRET'),
    ],

    // Public web frontend (crickpro-auction) — base for shareable invite links.
    'auction_web' => [
        'url' => env('AUCTION_WEB_URL', 'https://auctions.crick.pro'),
    ],

    // Meta WhatsApp Cloud API — same integration as crickpro-api's
    // WhatsAppWebhookController. WhatsAppOtpService no-ops (logs only) when
    // 'token' or 'phone_number_id' aren't set.
    'whatsapp' => [
        'token' => env('WHATSAPP_API_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_number' => env('WHATSAPP_BUSINESS_NUMBER', '15873290009'),
        // Used by WhatsAppWebhookController: verify_token for Meta's GET
        // handshake, app_secret to check the X-Hub-Signature-256 on inbound POSTs.
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

];
