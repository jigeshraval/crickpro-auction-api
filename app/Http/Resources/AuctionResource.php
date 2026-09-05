<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'seasonLabel' => $this->season_label,
            'description' => $this->description,
            'logoUrl' => $this->logo_url,
            'coverUrl' => $this->cover_url,
            'venue' => $this->venue,
            'accessCode' => $this->access_code,
            'currency' => new CurrencyResource($this->currency),
            'isListed' => (bool) $this->is_listed,
            'status' => $this->status,
            'maxTeams' => $this->max_teams !== null ? (int) $this->max_teams : null,
            'overlayAccess' => (bool) $this->overlay_access,
            'currentRound' => $this->current_round,
            'scheduledAt' => $this->scheduled_at,
            'startedAt' => $this->started_at,
            'pausedAt' => $this->paused_at,
            'completedAt' => $this->completed_at,
            'createdAt' => $this->created_at,
            // Only present when the query ran withCount()/with('settings') —
            // the "My Auction" list needs these for its card; quick-start's
            // and show()'s single-auction responses don't load them.
            'teamCount' => $this->teams_count,
            'playerCount' => $this->auction_players_count,
            'initialPurse' => $this->when($this->relationLoaded('settings'), fn () => $this->settings?->initial_purse),
        ];
    }
}
