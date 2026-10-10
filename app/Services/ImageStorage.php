<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Compress → upload to Spaces → return the RELATIVE path (under `auction/`).
 * Down-scales to a max edge and re-encodes to WebP (alpha kept). Falls back to
 * storing the original bytes if GD can't decode it. Extracted from
 * UploadController so payments can store manual QR + proof images the same way.
 */
class ImageStorage
{
    /** Storage disk — DigitalOcean Spaces (served by cdn.crickpro.net). */
    public const DISK = 'do';

    /**
     * $lossless: re-encode as lossless WebP (PNG if this GD build lacks
     * lossless WebP) — for images that must stay machine-readable, e.g. a
     * UPI QR, where lossy artefacts break scanning.
     */
    public function store(UploadedFile $file, string $kind, int $maxEdge = 512, bool $lossless = false): string
    {
        $src = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        $png = $lossless && ! defined('IMG_WEBP_LOSSLESS');
        $path = 'auction/'.$kind.'/'.Str::random(40).($png ? '.png' : '.webp');

        if ($src === false || (! $png && ! function_exists('imagewebp'))) {
            // Original bytes, untouched (so alpha is never flattened), under the
            // original extension so the CDN serves the right content type.
            $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
            $path = 'auction/'.$kind.'/'.Str::random(40).'.'.$ext;
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
        match (true) {
            $png => imagepng($dst, null, 9),
            $lossless => imagewebp($dst, null, IMG_WEBP_LOSSLESS),
            default => imagewebp($dst, null, 80),
        };
        $data = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        Storage::disk(self::DISK)->put($path, $data, 'public');

        return $path; // relative path — frontend resolves via resourceUrl() (cdn.crickpro.net)
    }

    /** Best-effort removal of a previously stored object (a failure only leaves an orphan). */
    public function delete(?string $path): void
    {
        if (! $path || ! str_starts_with($path, 'auction/')) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
