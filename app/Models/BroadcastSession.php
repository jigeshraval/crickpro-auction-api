<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The OBS/stream-viewer credential for one auction's overlay. Only the hash
 * is ever stored — same hash('sha256', ...) pattern as User::createPersonalAccessToken().
 */
#[Fillable(['id_auction', 'token_hash', 'label', 'is_revoked', 'last_seen_at', 'view_count', 'revoked_at'])]
class BroadcastSession extends Model
{
    protected function casts(): array
    {
        return [
            'is_revoked' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class, 'id_auction');
    }

    /**
     * @return array{model: self, plainToken: string}
     */
    public static function issue(int $auctionId, ?string $label = null): array
    {
        $plainToken = Str::random(40);

        $session = self::create([
            'id_auction' => $auctionId,
            'token_hash' => hash('sha256', $plainToken),
            'label' => $label,
        ]);

        return ['model' => $session, 'plainToken' => $plainToken];
    }
}
