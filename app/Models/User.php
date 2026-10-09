<?php

namespace App\Models;

use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

#[Fillable(['name', 'email', 'mobile', 'masked_mobile', 'password', 'email_verified_at', 'verified', 'status', 'block_reason', 'uid', 'profile_image', 'country', 'id_country'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'verified' => 'boolean',
        ];
    }

    /**
     * The local `countries` row. Named countryRecord (not country) because
     * `country` is the ISO2 column and Eloquent would shadow one with the other.
     */
    public function countryRecord(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'id_country');
    }

    /**
     * Create a Sanctum token with an optional client_identifier (e.g.
     * "crickpro-auction" / "crickpro-auction-app"), used by AuthController's
     * single-session-per-client revocation. Sanctum's default createToken()
     * has no room for this extra column, hence the override.
     */
    public function createPersonalAccessToken(
        string $name,
        ?string $clientIdentifier = null,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null
    ): NewAccessToken {
        $plainTextToken = $this->generateTokenString();

        // Sanctum's default PersonalAccessToken model's $fillable doesn't
        // know about our custom client_identifier column, so mass-assigning
        // it via tokens()->create([...]) silently drops it. Build via
        // make() (fillable fields only) then set the extra column directly.
        $token = $this->tokens()->make([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);
        $token->client_identifier = $clientIdentifier;
        $token->save();

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }
}
