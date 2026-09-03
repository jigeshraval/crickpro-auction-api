<?php

namespace App\Http\Controllers;

use App\Models\Auction;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stores an uploaded image as-is (no resize/re-encode pipeline — the Node
 * reference re-encodes to WebP via sharp; this is the simplest thing that
 * works, worth revisiting only if payload size becomes a real problem) and
 * returns its public URL. `php artisan storage:link` must have been run once.
 */
class UploadController extends Controller
{
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

    private function store(Request $request, string $kind): string
    {
        $request->validate(['file' => 'required|image|max:5120']);

        $path = $request->file('file')->store($kind, 'public');

        return Storage::disk('public')->url($path);
    }

    private function authorizeOwner(bool $isOwner): void
    {
        if (! $isOwner) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
