<?php

namespace App\Exceptions;

use Exception;

/**
 * A bidding-engine rejection with a machine-readable code — mirrors the
 * source Node app's ErrorCode union (INVALID_STATE, SQUAD_FULL,
 * INSUFFICIENT_PURSE, ROSTER_RULE_VIOLATION, ...) so a client can react to
 * *why* a control action failed, not just that it did.
 */
class AuctionControlException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
