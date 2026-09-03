<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Single-session-per-client
    |--------------------------------------------------------------------------
    |
    | X-CrickPro-Client values that get single-session enforcement: logging
    | in again from the same client revokes that client's prior token(s) for
    | the user (see App\Services\Auth\AuthTokenService). Unlike crickpro-api
    | (which hardcodes this as a private class constant), this is
    | config/env-driven so it can be tuned without a deploy.
    */
    'revokable_clients' => array_filter(array_map(
        'trim',
        explode(',', (string) env('AUTH_REVOKABLE_CLIENTS', 'crickpro-auction,crickpro-auction-app'))
    )),

    /*
    | User IDs exempt from single-session revocation (dev/QA convenience).
    */
    'no_revoke_user_ids' => array_filter(array_map(
        'intval',
        explode(',', (string) env('AUTH_NO_REVOKE_USER_IDS', ''))
    )),

];
