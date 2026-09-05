<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\QuickStartAuctionRequest;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Requests\Auction\UpdateAuctionRequest;
use App\Http\Resources\AuctionResource;
use App\Http\Resources\PublicAuctionResource;
use App\Models\Auction;
use App\Repositories\AuctionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuctionController extends Controller
{
    public function __construct(
        private readonly AuctionRepository $auctions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $auctions = $this->auctions->paginateForOwner(
            $request->user()->id,
            $request->string('search')->value() ?: null,
            (int) $request->integer('perPage', 20),
        );
        $auctions->through(fn ($auction) => new AuctionResource($auction));

        return response()->json([
            'status' => 'success',
            ...paginated($auctions, 'auctions', true),
        ]);
    }

    /** The Dashboard tab's "All Auctions" feed — every listed auction from other organisers. */
    public function publicIndex(Request $request): JsonResponse
    {
        $auctions = $this->auctions->paginatePublic(
            $request->user()->id,
            $request->string('search')->value() ?: null,
            (int) $request->integer('perPage', 20),
        );
        $auctions->through(fn ($auction) => new PublicAuctionResource($auction));

        return response()->json([
            'status' => 'success',
            ...paginated($auctions, 'auctions', true),
        ]);
    }

    public function show(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'auction' => new AuctionResource($auction),
        ]);
    }

    public function quickStart(QuickStartAuctionRequest $request): JsonResponse
    {
        $result = $this->auctions->quickStart($request->user()->id, $request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Auction created — your overlay is ready',
            'auction' => new AuctionResource($result['auction']),
            'broadcastToken' => $result['broadcastToken'],
            'slug' => $result['slug'],
        ], 201);
    }

    public function update(UpdateAuctionRequest $request, Auction $auction): JsonResponse
    {
        $attributes = [];
        if ($request->has('isListed')) {
            $attributes['is_listed'] = $request->boolean('isListed');
        }
        if ($request->has('currencyId')) {
            $attributes['id_currency'] = (int) $request->input('currencyId');
        }
        $columnsByField = ['name' => 'name', 'venue' => 'venue', 'scheduledAt' => 'scheduled_at', 'seasonLabel' => 'season_label'];
        foreach ($columnsByField as $field => $column) {
            if ($request->has($field)) {
                $attributes[$column] = $request->input($field);
            }
        }

        $auction = $this->auctions->update($auction, $attributes);

        return response()->json([
            'status' => 'success',
            'auction' => new AuctionResource($auction),
        ]);
    }

    public function start(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->lifecycle($auction, fn () => $this->auctions->start($auction));
    }

    public function pause(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->lifecycle($auction, fn () => $this->auctions->pause($auction));
    }

    public function resume(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->lifecycle($auction, fn () => $this->auctions->resume($auction));
    }

    public function complete(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->lifecycle($auction, fn () => $this->auctions->complete($auction));
    }

    public function reset(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->lifecycle($auction, fn () => $this->auctions->reset($auction));
    }

    /**
     * The owner's overlay link credentials: the auction access code + a stable
     * secret (lazily generated once, then reused so the link never changes).
     */
    public function overlayLink(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        if (! $auction->overlay_secret) {
            $auction->update(['overlay_secret' => \Illuminate\Support\Str::random(40)]);
        }

        return response()->json([
            'status' => 'success',
            'overlay' => [
                'code' => $auction->access_code,
                'secret' => $auction->overlay_secret,
            ],
        ]);
    }

    public function destroy(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        try {
            $this->auctions->delete($auction);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success']);
    }

    private function lifecycle(Auction $auction, \Closure $transition): JsonResponse
    {
        try {
            $auction = $transition();
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'auction' => new AuctionResource($auction),
        ]);
    }
}
