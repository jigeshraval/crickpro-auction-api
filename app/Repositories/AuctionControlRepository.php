<?php

namespace App\Repositories;

use App\Exceptions\AuctionControlException;
use App\Models\Auction;
use App\Models\AuctionPlayer;
use App\Models\Bid;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * The live-bidding engine — one operator (the organiser) drives every action
 * here over plain REST, and every action returns the fresh state snapshot a
 * viewer can also poll via GET .../state. Ported from the Node app's
 * auctionControlService.ts — including its own reasoning for skipping a
 * socket layer: with a single writer there's no fan-out consistency problem
 * a push channel would be solving, so the new backend doesn't build one either.
 *
 * State machine: PENDING -> SELECTED -> BIDDING -> {SOLD | UNSOLD};
 * SOLD/UNSOLD -> PENDING via returnToPool(); undo() steps back one action.
 */
class AuctionControlRepository
{
    public function __construct(
        private readonly TeamRepository $teams,
    ) {}

    /** The snapshot every control action returns and every viewer polls. */
    public function buildState(Auction $auction): array
    {
        $auction->refresh()->loadMissing('currentAuctionPlayer.player', 'currentAuctionPlayer.leadingTeam');
        $current = $auction->currentAuctionPlayer;

        $teams = $this->teams->listForAuction($auction);

        return [
            'auction' => [
                'id' => $auction->id,
                'name' => $auction->name,
                'status' => $auction->status,
            ],
            'currentPlayer' => $current ? $this->presentAuctionPlayer($current) : null,
            'teams' => $teams->map(fn (Team $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'shortName' => $t->short_name,
                'logoUrl' => $t->logo_url,
                'primaryColor' => $t->primary_color,
                'remainingPurse' => $t->remaining_purse,
                'boughtCount' => $t->bought_count,
                'slotsLeft' => $t->slots_left,
                'maxBid' => $t->max_bid,
            ])->values()->all(),
        ];
    }

    private function presentAuctionPlayer(AuctionPlayer $ap): array
    {
        return [
            'auctionPlayerId' => $ap->id,
            'status' => $ap->status,
            'basePrice' => $ap->base_price,
            'currentBid' => $ap->current_bid,
            'bidCount' => $ap->bid_count,
            'categoryCode' => $ap->category_code,
            'player' => [
                'id' => $ap->player->id,
                'name' => $ap->player->name,
                'photoUrl' => $ap->player->photo_url,
                'role' => $ap->player->role,
                'jerseyNumber' => $ap->player->jersey_number,
                'isOverseas' => (bool) $ap->is_overseas,
            ],
            'leadingTeam' => $ap->leadingTeam ? [
                'id' => $ap->leadingTeam->id,
                'name' => $ap->leadingTeam->name,
                'shortName' => $ap->leadingTeam->short_name,
                'logoUrl' => $ap->leadingTeam->logo_url,
                'primaryColor' => $ap->leadingTeam->primary_color,
            ] : null,
        ];
    }

    public function selectPlayer(Auction $auction, ?int $auctionPlayerId): array
    {
        DB::transaction(function () use ($auction, $auctionPlayerId) {
            if ($auction->status !== Auction::STATUS_LIVE) {
                throw new AuctionControlException('INVALID_STATE', 'The auction must be live to select a player.');
            }
            if ($auction->id_current_auction_player !== null) {
                throw new AuctionControlException('INVALID_STATE', 'A player is already on the block.');
            }

            if ($auctionPlayerId) {
                $player = AuctionPlayer::where('id_auction', $auction->id)->findOrFail($auctionPlayerId);
                if (! in_array($player->status, [AuctionPlayer::STATUS_PENDING, AuctionPlayer::STATUS_UNSOLD], true)) {
                    throw new AuctionControlException('INVALID_STATE', 'That player is not available to select.');
                }
            } else {
                $player = AuctionPlayer::where('id_auction', $auction->id)
                    ->where('status', AuctionPlayer::STATUS_PENDING)
                    ->orderBy('auction_order')
                    ->first();
                if (! $player) {
                    throw new AuctionControlException('INVALID_STATE', 'No pending players left in the pool.');
                }
            }

            $player->update([
                'status' => AuctionPlayer::STATUS_SELECTED,
                'current_bid' => null,
                'id_leading_team' => null,
                'bid_count' => 0,
                'nominated_at' => now(),
            ]);
            $auction->update(['id_current_auction_player' => $player->id]);
        });

        return $this->buildState($auction);
    }

    public function nextPlayer(Auction $auction): array
    {
        return $this->selectPlayer($auction, null);
    }

    public function openBidding(Auction $auction): array
    {
        DB::transaction(function () use ($auction) {
            $player = $this->requireCurrentPlayer($auction);
            if ($player->status !== AuctionPlayer::STATUS_SELECTED) {
                throw new AuctionControlException('INVALID_STATE', 'This player is not awaiting bidding.');
            }
            $player->update(['status' => AuctionPlayer::STATUS_BIDDING]);
        });

        return $this->buildState($auction);
    }

    public function placeBid(Auction $auction, int $teamId, ?int $amount): array
    {
        DB::transaction(function () use ($auction, $teamId, $amount) {
            $player = $this->requireCurrentPlayer($auction);
            if ($player->status !== AuctionPlayer::STATUS_BIDDING) {
                throw new AuctionControlException('INVALID_STATE', 'Bidding is not open for this player.');
            }
            if ($player->id_leading_team === $teamId) {
                throw new AuctionControlException('INVALID_TEAM', 'This team is already leading — another team must bid next.');
            }

            $team = Team::where('id_auction', $auction->id)->findOrFail($teamId);
            $settings = $auction->settings;

            $minimum = $player->current_bid === null ? $player->base_price : $player->current_bid + $this->nextIncrement($settings, $player->current_bid);
            $finalAmount = ($amount !== null && $settings->allow_custom_bid && $amount >= $minimum) ? $amount : $minimum;

            $boughtCount = AuctionPlayer::where('id_auction', $auction->id)
                ->where('status', AuctionPlayer::STATUS_SOLD)
                ->where('id_sold_to_team', $teamId)
                ->count();

            if ($settings->enforce_squad_max && $boughtCount >= $settings->squad_max) {
                throw new AuctionControlException('SQUAD_FULL', 'This team\'s squad is already full.');
            }

            if ($settings->enforce_purse) {
                $remainingSlots = max(0, $settings->squad_max - $boughtCount);
                $maxBid = $settings->enforce_squad_max
                    ? $team->remaining_purse - max(0, $remainingSlots - 1) * $settings->min_base_price
                    : $team->remaining_purse;
                if ($finalAmount > $maxBid) {
                    throw new AuctionControlException('INSUFFICIENT_PURSE', 'This bid would leave the team unable to fill its remaining squad slots.');
                }
            }

            if ($settings->enforce_overseas && $player->is_overseas && $settings->max_overseas_per_team !== null) {
                $overseasCount = AuctionPlayer::where('id_auction', $auction->id)
                    ->where('status', AuctionPlayer::STATUS_SOLD)
                    ->where('id_sold_to_team', $teamId)
                    ->where('is_overseas', true)
                    ->count();
                if ($overseasCount >= $settings->max_overseas_per_team) {
                    throw new AuctionControlException('ROSTER_RULE_VIOLATION', 'This team has already reached its overseas-player limit.');
                }
            }

            $nextSequence = (Bid::where('id_auction_player', $player->id)->max('sequence_number') ?? 0) + 1;
            Bid::create([
                'id_auction' => $auction->id,
                'id_auction_player' => $player->id,
                'id_team' => $teamId,
                'bid_amount' => $finalAmount,
                'sequence_number' => $nextSequence,
            ]);

            $player->update([
                'current_bid' => $finalAmount,
                'id_leading_team' => $teamId,
                'bid_count' => $player->bid_count + 1,
            ]);
        });

        return $this->buildState($auction);
    }

    public function markSold(Auction $auction, ?int $teamId, ?int $amount, bool $overrideRules): array
    {
        DB::transaction(function () use ($auction, $teamId, $amount, $overrideRules) {
            $player = $this->requireCurrentPlayer($auction);
            if ($player->status !== AuctionPlayer::STATUS_BIDDING || $player->bid_count === 0) {
                throw new AuctionControlException('NO_BIDS', 'This player has no bids yet.');
            }

            $finalTeamId = $teamId ?? $player->id_leading_team;
            $finalAmount = $amount ?? $player->current_bid;
            $team = Team::where('id_auction', $auction->id)->findOrFail($finalTeamId);

            if (! $overrideRules && $auction->settings->enforce_purse && $finalAmount > $team->remaining_purse) {
                throw new AuctionControlException('INSUFFICIENT_PURSE', 'This team cannot afford that price.');
            }

            $player->update([
                'status' => AuctionPlayer::STATUS_SOLD,
                'id_sold_to_team' => $finalTeamId,
                'sold_price' => $finalAmount,
                'sold_at' => now(),
            ]);
            $team->decrement('remaining_purse', $finalAmount);
            $auction->update(['id_current_auction_player' => null]);
        });

        return $this->buildState($auction);
    }

    public function markUnsold(Auction $auction): array
    {
        DB::transaction(function () use ($auction) {
            $player = $this->requireCurrentPlayer($auction);
            if ($player->status !== AuctionPlayer::STATUS_BIDDING) {
                throw new AuctionControlException('INVALID_STATE', 'Bidding must be open to mark a player unsold.');
            }
            $player->update(['status' => AuctionPlayer::STATUS_UNSOLD, 'unsold_at' => now()]);
            $auction->update(['id_current_auction_player' => null]);
        });

        return $this->buildState($auction);
    }

    public function returnToPool(Auction $auction, int $auctionPlayerId, ?int $newBasePrice): array
    {
        DB::transaction(function () use ($auction, $auctionPlayerId, $newBasePrice) {
            if (! $auction->settings->allow_reentry) {
                throw new AuctionControlException('INVALID_STATE', 'Re-entry is disabled for this auction.');
            }

            $player = AuctionPlayer::where('id_auction', $auction->id)->findOrFail($auctionPlayerId);
            if (! in_array($player->status, [AuctionPlayer::STATUS_SOLD, AuctionPlayer::STATUS_UNSOLD], true)) {
                throw new AuctionControlException('INVALID_STATE', 'Only a sold or unsold player can return to the pool.');
            }

            if ($player->status === AuctionPlayer::STATUS_SOLD && $player->id_sold_to_team) {
                Team::where('id', $player->id_sold_to_team)->increment('remaining_purse', $player->sold_price);
            }

            $player->update([
                'status' => AuctionPlayer::STATUS_PENDING,
                'current_bid' => null,
                'id_leading_team' => null,
                'id_sold_to_team' => null,
                'sold_price' => null,
                'bid_count' => 0,
                'sold_at' => null,
                'unsold_at' => null,
                'base_price' => $newBasePrice ?? $player->base_price,
            ]);
        });

        return $this->buildState($auction);
    }

    /** Single-step undo, not a stack — mirrors the source's own scope. */
    public function undo(Auction $auction): array
    {
        DB::transaction(function () use ($auction) {
            $current = $auction->id_current_auction_player
                ? AuctionPlayer::find($auction->id_current_auction_player)
                : null;

            if ($current) {
                if ($current->bid_count > 0) {
                    $lastBid = Bid::where('id_auction_player', $current->id)->orderByDesc('sequence_number')->first();
                    $lastBid?->delete();
                    $prior = Bid::where('id_auction_player', $current->id)->orderByDesc('sequence_number')->first();
                    $current->update([
                        'current_bid' => $prior?->bid_amount,
                        'id_leading_team' => $prior?->id_team,
                        'bid_count' => max(0, $current->bid_count - 1),
                    ]);
                } else {
                    $current->update(['status' => AuctionPlayer::STATUS_PENDING, 'nominated_at' => null]);
                    $auction->update(['id_current_auction_player' => null]);
                }

                return;
            }

            $last = AuctionPlayer::where('id_auction', $auction->id)
                ->whereIn('status', [AuctionPlayer::STATUS_SOLD, AuctionPlayer::STATUS_UNSOLD])
                ->orderByDesc('updated_at')
                ->first();

            if (! $last) {
                throw new AuctionControlException('INVALID_STATE', 'Nothing to undo.');
            }

            if ($last->status === AuctionPlayer::STATUS_SOLD && $last->id_sold_to_team) {
                Team::where('id', $last->id_sold_to_team)->increment('remaining_purse', $last->sold_price);
            }

            $last->update([
                'status' => $last->bid_count > 0 ? AuctionPlayer::STATUS_BIDDING : AuctionPlayer::STATUS_SELECTED,
                'id_sold_to_team' => null,
                'sold_price' => null,
                'sold_at' => null,
                'unsold_at' => null,
            ]);
            $auction->update(['id_current_auction_player' => $last->id]);
        });

        return $this->buildState($auction);
    }

    private function requireCurrentPlayer(Auction $auction): AuctionPlayer
    {
        $player = $auction->id_current_auction_player ? AuctionPlayer::find($auction->id_current_auction_player) : null;
        if (! $player) {
            throw new AuctionControlException('INVALID_STATE', 'No player is currently on the block.');
        }

        return $player;
    }

    private function nextIncrement($settings, ?int $currentBid): int
    {
        if ($settings->bid_increment_mode !== 'slab' || empty($settings->bid_increment_slabs)) {
            return $settings->bid_increment_flat;
        }

        $slabs = collect($settings->bid_increment_slabs)->sortBy('upTo')->values();
        $base = $currentBid ?? 0;
        $match = $slabs->first(fn ($slab) => $base < $slab['upTo']);

        return $match['increment'] ?? ($slabs->last()['increment'] ?? $settings->bid_increment_flat);
    }
}
