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
            'planLabel' => $this->planLabel(),
            'maxTeams' => (int) $this->max_teams,
            'amount' => $this->amount,
            'currency' => $this->currency,
            // Display-ready figures (client has no store SDK) — null when the row
            // has no price (RevenueCat rows carry none; the store holds it).
            'amountDisplay' => $this->amountDisplay(),
            'source' => $this->source,
            'store' => $this->store,
            'storeLabel' => $this->storeLabel(),
            'transactionId' => $this->transaction_id,
            'startsAt' => $this->starts_at,
            'expiresAt' => $this->expires_at,
            'isActive' => (bool) $this->is_active,
            'isExpired' => $expired,
            'status' => $status,
            'statusLabel' => ucfirst($status),
            'notes' => $this->notes,
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at,
        ];
    }

    /** "30_teams" → "Up to 30 Teams"; otherwise a humanised plan id. */
    private function planLabel(): string
    {
        $id = (string) $this->plan_id;
        if (preg_match('/^(\d+)_teams$/', $id, $m)) {
            return "Up to {$m[1]} Teams";
        }

        return $id === '' ? 'Team Pack' : ucwords(str_replace(['_', '-'], ' ', $id));
    }

    /** "INR 679.00" — currency code + amount; null when unpriced. */
    private function amountDisplay(): ?string
    {
        if ($this->amount === null || $this->currency === null) {
            return null;
        }

        return strtoupper((string) $this->currency).' '.number_format((float) $this->amount, 2);
    }

    private function storeLabel(): ?string
    {
        return match ($this->store) {
            'play_store' => 'Google Play',
            'app_store' => 'App Store',
            'web' => 'Web',
            'admin' => 'Admin',
            null, '' => null,
            default => ucfirst((string) $this->store),
        };
    }
}
