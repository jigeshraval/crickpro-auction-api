<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ops user directory for crickpro-admin (X-Ops-Signature gated). Lets an admin
 * find an auction-app user, then manage that user's per-auction subscriptions.
 */
class OpsUserController extends Controller
{
    /** Whitelisted search fields (matches the admin's field picker). */
    private const FIELDS = ['id', 'name', 'email', 'mobile'];

    public function index(Request $request): JsonResponse
    {
        $q = $request->string('q')->value() ?: null;
        $searchBy = $request->string('searchBy')->value() ?: null;

        $users = User::query()
            ->when($q && $searchBy && in_array($searchBy, self::FIELDS, true), function ($query) use ($q, $searchBy) {
                // Exact for id, LIKE for text columns.
                $searchBy === 'id' ? $query->where('id', (int) $q) : $query->where($searchBy, 'like', "%{$q}%");
            })
            ->when($q && ! $searchBy, function ($query) use ($q) {
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%"));
            })
            ->latest()
            ->simplePaginate((int) $request->integer('perPage', 25));

        $countryNames = $this->countryNames(collect($users->items())->pluck('id_country')->filter()->unique()->all());
        $auctionLocations = $this->latestAuctionLocations(collect($users->items())->pluck('id')->all());

        $users->through(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'mobile' => $u->mobile,
            'profileImage' => $u->profile_image,
            'createdAt' => $u->created_at,
            // Users have no city/state — location is their latest auction's `auctions.location`
            // ("Calgary, Alberta, Canada"), falling back to the user's own country.
            'location' => $auctionLocations[(int) $u->id]
                ?? ($u->id_country ? ($countryNames[(int) $u->id_country] ?? null) : null),
            'country' => $u->country ?? null,
            'countryName' => $u->id_country ? ($countryNames[(int) $u->id_country] ?? null) : null,
        ]);

        return response()->json([
            'status' => 'success',
            ...paginated($users, 'users', true),
        ]);
    }

    /**
     * user id → `location` of the most recent auction they own (one query per page).
     * Returns [] when auctions.location doesn't exist yet (migration not run).
     *
     * @param  int[]  $userIds
     * @return array<int, string>
     */
    private function latestAuctionLocations(array $userIds): array
    {
        if (! $userIds || ! Schema::hasColumn('auctions', 'location')) {
            return [];
        }
        $rows = DB::table('auctions')
            ->whereIn('id_owner', $userIds)
            ->whereNull('deleted_at')
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->orderByDesc('id')
            ->get(['id_owner', 'location']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id_owner] ??= (string) $r->location; // first = latest
        }

        return $out;
    }

    /**
     * id_country → country name for one page of users. Works with either geo table
     * name (`country` after the singular rename, or legacy `countries`) and returns
     * [] if neither exists yet — the list must never fail on location.
     *
     * @param  int[]  $ids
     * @return array<int, string>
     */
    private function countryNames(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $table = Schema::hasTable('country') ? 'country' : (Schema::hasTable('countries') ? 'countries' : null);
        if (! $table) {
            return [];
        }

        return DB::table($table)->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }
}
