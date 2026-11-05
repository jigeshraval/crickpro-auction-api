<?php

namespace Tests\Feature\Payments;

use App\Models\AuctionPlayer;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CSV player export, gated by the ops-granted `exportPlayers` feature flag
 * (users.metadata). Owner-only + flag enforced server-side, and the flag is
 * toggled through the ops endpoint.
 */
class PlayerExportTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private int $ownerId;

    private int $auctionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        config(['subscription.ops_signature' => 'ops_test']);

        $this->owner = $this->tokenFor('organiser@example.com', 'IN');
        $this->ownerId = $this->userId('organiser@example.com');
        $this->auctionId = (int) $this->createAuction($this->owner)['id'];

        $player = Player::create(['id_owner' => $this->ownerId, 'name' => 'Rahul Tester', 'role' => 'all_rounder', 'nationality' => 'India']);
        AuctionPlayer::create([
            'id_auction' => $this->auctionId, 'id_player' => $player->id,
            'status' => 'pending', 'auction_order' => 1, 'base_price' => 200000, 'is_overseas' => false,
        ]);
    }

    private function ops(): static
    {
        return $this->withHeader('X-Ops-Signature', 'ops_test');
    }

    public function test_feature_defaults_off_and_export_is_forbidden_without_it(): void
    {
        // Flag defaults off → export refused server-side even for the owner.
        $this->assertFalse(User::find($this->ownerId)->hasFeature('exportPlayers'));
        $this->as($this->owner)->get("/v1/auctions/{$this->auctionId}/players/export")->assertStatus(403);
    }

    public function test_ops_grants_the_flag_then_owner_can_export_csv(): void
    {
        $this->ops()->putJson("/v1/ops/users/{$this->ownerId}/features", ['exportPlayers' => true])
            ->assertOk()->assertJsonPath('features.exportPlayers', true);

        $res = $this->as($this->owner)->get("/v1/auctions/{$this->auctionId}/players/export")->assertOk();
        $this->assertStringContainsString('text/csv', strtolower($res->headers->get('content-type')));

        $csv = $res->streamedContent();
        // Header (fputcsv quotes fields containing spaces).
        $this->assertStringContainsString('Order,Name,Role,Overseas,Category,"Base Price"', $csv);
        $this->assertStringContainsString('Rahul Tester', $csv);
        $this->assertStringContainsString('all rounder', $csv);
        $this->assertStringContainsString('2000.00', $csv); // 200000 minor → 2000.00 major
    }

    public function test_ops_can_revoke_the_flag(): void
    {
        $this->ops()->putJson("/v1/ops/users/{$this->ownerId}/features", ['exportPlayers' => true])->assertOk();
        $this->ops()->putJson("/v1/ops/users/{$this->ownerId}/features", ['exportPlayers' => false])
            ->assertOk()->assertJsonPath('features.exportPlayers', false);

        $this->as($this->owner)->get("/v1/auctions/{$this->auctionId}/players/export")->assertStatus(403);
    }

    public function test_non_owner_cannot_export_even_with_the_flag(): void
    {
        // Grant the flag to a DIFFERENT user; they still can't export someone else's auction.
        $stranger = $this->tokenFor('stranger@example.com', 'IN');
        $strangerId = $this->userId('stranger@example.com');
        User::whereKey($strangerId)->update(['metadata' => ['exportPlayers' => true]]);

        $this->as($stranger)->get("/v1/auctions/{$this->auctionId}/players/export")->assertStatus(403);
    }

    public function test_ops_signature_required_to_toggle(): void
    {
        $this->putJson("/v1/ops/users/{$this->ownerId}/features", ['exportPlayers' => true])->assertStatus(401);
    }
}
