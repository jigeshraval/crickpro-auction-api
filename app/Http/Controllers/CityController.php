<?php

namespace App\Http\Controllers;

use App\Http\Resources\CityResource;
use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Venue city search for the auction forms — same algorithm as crickpro-api's
 * own `GET /v3/cities` (full-text MATCH ... AGAINST in boolean mode for a
 * query of 4+ characters, LIKE for anything shorter — MySQL's fulltext
 * index needs a token at least innodb_ft_min_token_size long, usually 3-4,
 * so a 2-3 char query returns nothing from MATCH), run against this app's
 * own local copy of the city/state/country tables (see the
 * geo_location_tables migration) rather than a live call to crickpro-api.
 *
 * No auth:sanctum — same "Location (public)" call crickpro-api itself made
 * for this endpoint; nothing here is owner- or auction-scoped.
 */
class CityController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $countryId = (int) $request->input('countryId');
        $search = trim((string) $request->input('q', $request->input('query', '')));
        $page = max(1, (int) $request->input('page', 1));

        $cacheKey = "cities_{$countryId}_{$search}_p{$page}";

        $cities = Cache::remember($cacheKey, 60, function () use ($countryId, $search, $page) {
            $query = City::with(['state', 'country'])->where('id_country', $countryId);

            if ($search !== '') {
                $boolean = $this->booleanSearchTerm($search);
                if ($boolean !== '') {
                    $query
                        ->selectRaw('cities.*, MATCH(name) AGAINST(? IN BOOLEAN MODE) AS relevance', [$boolean])
                        ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$boolean])
                        ->orderBy('relevance', 'desc');
                } else {
                    $query->where('name', 'LIKE', '%'.addcslashes($search, '%_\\').'%');
                }
            }

            $paginator = $query->orderBy('name')->simplePaginate(20, ['*'], 'page', $page);

            // Cache a plain array, never the paginator/Resource objects directly — an
            // Eloquent model cached raw can fail to unserialize if the class map
            // isn't in the same state on the next request (confirmed the hard way
            // against crickpro-api's own identical cache-the-Resource pattern).
            return [
                'cities' => CityResource::collection($paginator->items())->resolve(),
                'nextPage' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            ];
        });

        return response()->json([
            'currentPage' => $page,
            'nextPage' => $cities['nextPage'],
            'cities' => $cities['cities'],
        ]);
    }

    /** Strips everything but letters/numbers/spaces and appends a wildcard — same as crickpro-api's booleanSearch() helper. Returns '' when nothing searchable survives (caller falls back to LIKE). */
    private function booleanSearchTerm(string $query): string
    {
        $escaped = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $query) ?? '');
        if ($escaped === '' || mb_strlen($query) < 4) {
            return '';
        }

        return $escaped.'*';
    }
}
