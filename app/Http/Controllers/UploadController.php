<?php

namespace App\Http\Controllers;

use App\Models\Auction;
use App\Models\Player;
use App\Models\Team;
use App\Services\ImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Compresses an uploaded image (GD: down-scale + re-encode to WebP, alpha
 * preserved) and stores it, returning only the RELATIVE PATH — same contract as
 * crickpro-api-v2/crickpro-app: the backend stores + serves a path, the frontend
 * turns it into a full URL via resourceUrl(). Compression lives in ImageStorage.
 */
class UploadController extends Controller
{
    public function __construct(private readonly ImageStorage $images) {}

    public function auctionCover(Request $request, Auction $auction): JsonResponse
    {
        $this->authorizeOwner($auction->id_owner === $request->user()->id);
        $auction->update(['cover_url' => $this->store($request, 'auction-covers')]);

        return response()->json(['status' => 'success', 'url' => $auction->cover_url]);
    }

    public function auctionLogo(Request $request, Auction $auction): JsonResponse
    {
        $this->authorizeOwner($auction->id_owner === $request->user()->id);
        $auction->update(['logo_url' => $this->store($request, 'auction-logos')]);

        return response()->json(['status' => 'success', 'url' => $auction->logo_url]);
    }

    public function teamLogo(Request $request, Auction $auction, Team $team): JsonResponse
    {
        $this->authorizeOwner($auction->id_owner === $request->user()->id && $team->id_auction === $auction->id);
        $team->update(['logo_url' => $this->store($request, 'team-logos')]);

        return response()->json(['status' => 'success', 'url' => $team->logo_url]);
    }

    public function playerPhoto(Request $request, Player $player): JsonResponse
    {
        $this->authorizeOwner($player->id_owner === $request->user()->id);
        $player->update(['photo_url' => $this->store($request, 'player-photos')]);

        return response()->json(['status' => 'success', 'url' => $player->photo_url]);
    }

    private function store(Request $request, string $kind, int $maxEdge = 512): string
    {
        $request->validate(['file' => 'required|image|max:5120']);

        return $this->images->store($request->file('file'), $kind, $maxEdge);
    }

    private function authorizeOwner(bool $isOwner): void
    {
        if (! $isOwner) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
