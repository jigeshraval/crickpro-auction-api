<?php

namespace App\Services\Crickpro;

use App\Models\Auction;
use App\Models\AuctionPlayer;
use App\Models\CrickproLink;
use Illuminate\Support\Facades\DB;

/**
 * Pushes a completed auction's squads back into the CrickPro tournament, writing
 * DIRECTLY to the CrickPro database (the `crickpro` connection) so the logic
 * stays identical to crickpro-api-v2 without a cross-app HTTP call:
 *
 *  - Player imported from CrickPro (players.id_crickpro_player set) → use that
 *    user id.
 *  - Otherwise resolve by mobile, else CREATE a minimal placeholder users row
 *    (mirrors PlayerRepository::getSavedPlayer / User::create) — claimable later,
 *    with the full auction record kept in users.metadata.
 *  - Add each to the tournament team's squad (series_team_squads), mirroring
 *    TeamsV2::addPlayerToTournamentSquad + hasPlayerInTheSquad (idempotent).
 *
 * Re-runnable: created user ids are backfilled onto the auction player, and the
 * squad insert is guarded, so a second run is a no-op.
 */
class RosterExporter
{
    /** @return array<string,mixed> summary counts */
    public function export(Auction $auction): array
    {
        $seriesId = (int) ($auction->id_crickpro_series ?? 0);
        if ($seriesId <= 0) {
            throw new \RuntimeException('This auction is not linked to a CrickPro tournament.');
        }

        // The organiser's CrickPro user id — recorded as `added_by` on any newly
        // created player, same as an admin adding a squad player in CrickPro.
        $addedBy = (int) (optional(CrickproLink::where('id_owner', $auction->id_owner)->first())->crickpro_user_id ?? 0);

        $summary = [
            'seriesId' => $seriesId,
            'teams' => 0,
            'teamsWithoutRef' => 0,
            'linked' => 0,   // already had a CrickPro user
            'created' => 0,  // new placeholder user made
            'squadAdded' => 0,
            'squadSkipped' => 0, // already in squad
        ];

        foreach ($auction->teams as $team) {
            if (! $team->id_crickpro_team) {
                $summary['teamsWithoutRef']++;
                continue;
            }
            $summary['teams']++;
            $crickproTeamId = (int) $team->id_crickpro_team;

            $sold = AuctionPlayer::with('player')
                ->where('id_auction', $auction->id)
                ->where('status', 'sold')
                ->where('id_sold_to_team', $team->id)
                ->get();

            foreach ($sold as $ap) {
                $player = $ap->player;
                if (! $player) {
                    continue;
                }

                $meta = [
                    'source' => 'crickpro-auction',
                    'auctionId' => $auction->id,
                    'auctionName' => $auction->name,
                    'seriesId' => $seriesId,
                    'crickproTeamId' => $crickproTeamId,
                    'teamName' => $team->name,
                    'auctionPlayerId' => $ap->id,
                    'soldPrice' => $ap->sold_price,
                    'categoryCode' => $ap->category_code,
                    'role' => $player->role,
                    'battingStyle' => $player->batting_style,
                    'bowlingStyle' => $player->bowling_style,
                    'importedAt' => now()->toIso8601String(),
                ];

                [$userId, $created] = $this->resolveOrCreateUser($player, $addedBy, $meta);

                if ($created) {
                    $summary['created']++;
                    // Backfill so a re-run links instead of re-creating.
                    $player->forceFill(['id_crickpro_player' => $userId, 'id_user' => $userId])->save();
                } else {
                    $summary['linked']++;
                }

                $summary[$this->addToSquad($crickproTeamId, $userId, $seriesId) ? 'squadAdded' : 'squadSkipped']++;
            }
        }

        return $summary;
    }

    private function db(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection('crickpro');
    }

    /**
     * @return array{0:int,1:bool} [crickpro user id, wasCreated]
     */
    private function resolveOrCreateUser(\App\Models\Player $player, int $addedBy, array $meta): array
    {
        // 1) Imported from CrickPro → already a real user.
        if ($player->id_crickpro_player) {
            return [(int) $player->id_crickpro_player, false];
        }

        // 2) Dedup by mobile (mirrors PlayerRepository::isPlayerExist). Auction
        //    phone may not be in CrickPro's +<code><number> shape, so this only
        //    matches when formats line up — otherwise we create below.
        if (! empty($player->phone)) {
            $existing = $this->db()->table('users')->where('mobile', $player->phone)->value('id');
            if ($existing) {
                return [(int) $existing, false];
            }
        }

        // 3) Create a minimal placeholder user (no password, unverified → the
        //    real person can claim it via registration). Optional columns are
        //    omitted so the table defaults apply.
        $id = $this->db()->table('users')->insertGetId([
            'name' => $this->cleanName($player->name),
            'type' => 'App User',
            'mobile' => $player->phone ?: null,
            'team_role' => $this->mapRole($player->role),
            'batting_hand' => $this->mapBattingHand($player->batting_style),
            'bowling_style' => $player->bowling_style ?: null,
            'is_wicket_keeper' => in_array($player->role, ['wicket_keeper', 'wk_batter'], true) ? 1 : 0,
            'added_by' => $addedBy ?: null,
            'status' => 1,
            'verified' => 0,
            'metadata' => json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(int) $id, true];
    }

    /** Mirrors TeamsV2::hasPlayerInTheSquad + addPlayerToTournamentSquad. */
    private function addToSquad(int $crickproTeamId, int $userId, int $seriesId): bool
    {
        $exists = $this->db()->table('series_team_squads')
            ->where('id_series', $seriesId)
            ->where('id_player', $userId)
            ->where('id_team', $crickproTeamId)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return false;
        }

        $this->db()->table('series_team_squads')->insert([
            'id_series' => $seriesId,
            'id_team' => $crickproTeamId,
            'id_player' => $userId,
            'designation' => null,
            'captain' => 0,
            'wk' => 0, // matches TeamsV2::addPlayerToTournamentSquad (defaults)
            'approved' => 1, // auction result is authoritative
            'floater' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /** ucwords2 equivalent: title-case unless the name is mostly caps already. */
    private function cleanName(?string $name): string
    {
        $s = trim(strip_tags((string) $name));
        if ($s === '') {
            return 'Auction Player';
        }
        $upper = preg_match_all('/[A-Z]/', $s);

        return $upper < 3 ? ucwords($s) : ucfirst($s);
    }

    private function mapRole(?string $role): ?string
    {
        return match ($role) {
            'batter' => 'Batsman',
            'bowler' => 'Bowler',
            'all_rounder' => 'All Rounder',
            'wicket_keeper', 'wk_batter' => 'Wicket Keeper',
            default => null,
        };
    }

    private function mapBattingHand(?string $style): ?string
    {
        return match ($style) {
            'left_hand' => 'Left Hand Batsman',
            'right_hand' => 'Right Hand Batsman',
            default => null,
        };
    }
}
