<?php

namespace App\Http\Controllers;

use App\Models\Auction;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Compresses an uploaded image (GD: down-scale + re-encode to WebP, alpha
 * preserved) and stores it, returning only the RELATIVE PATH — same contract as
 * crickpro-api-v2/crickpro-app: the backend stores + serves a path, the frontend
 * turns it into a full URL via resourceUrl(). `php artisan storage:link` once.
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

    /**
     * Compress → store → return the relative path. Down-scales to a max edge and
     * re-encodes to WebP (alpha kept). Falls back to storing the original if GD
     * can't decode it.
     */
    /** Storage disk — DigitalOcean Spaces (served by cdn.crickpro.net). */
    private const DISK = 'do';

    /**
     * Compress → upload to Spaces → return the relative path (under `auction/`).
     * Down-scales to a max edge and re-encodes to WebP (alpha kept). Falls back to
     * storing the original bytes if GD can't decode it.
     */
    private function store(Request $request, string $kind, int $maxEdge = 512): string
    {
        $request->validate(['file' => 'required|image|max:5120']);

        $file = $request->file('file');
        $src = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        $path = 'auction/'.$kind.'/'.Str::random(40).'.webp';

        if ($src === false || ! function_exists('imagewebp')) {
            Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()), 'public');

            return $path;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagewebp($dst, null, 80);
        $data = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        Storage::disk(self::DISK)->put($path, $data, 'public');

        return $path; // relative path — frontend resolves via resourceUrl() (cdn.crickpro.net)
    }

    private function authorizeOwner(bool $isOwner): void
    {
        if (! $isOwner) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
