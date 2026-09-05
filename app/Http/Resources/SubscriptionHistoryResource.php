<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expired = $this->expires_at !== null && $this->expires_at->isPast();
        $status = $expired && in_array($this->status, ['active', 'cancelled'], true) ? 'expired' : $this->status;

        return [
            'id' => $this->id,
            'userId' => $this->id_user,
            'auctionId' => $this->id_auction,
            'planId' => $this->plan_id,
            'maxTeams' => (int) $this->max_teams,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'source' => $this->source,
            'store' => $this->store,
            'transactionId' => $this->transaction_id,
            'startsAt' => $this->starts_at,
            'expiresAt' => $this->expires_at,
            'isActive' => (bool) $this->is_active,
            'isExpired' => $expired,
            'status' => $status,
            'notes' => $this->notes,
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at,
        ];
    }
}
