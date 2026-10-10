<?php

namespace Tests\Feature\Payments;

use App\Models\PlayerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApprovalsAndCollectionsTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->fakeRazorpay();
        Storage::fake('do');
        $this->owner = $this->tokenFor('organiser@example.com');
    }

    /** @return array{0:int,1:string} [auctionId, registrationToken] */
    private function manualRegistrationWithProof(): array
    {
        $auction = $this->createAuction($this->owner);
        $this->setRegistration($this->owner, $auction['id'], ['registrationMode' => 'manual', 'registrationFee' => 30_000, 'manualInstructions' => 'Pay to cup@okicici']);
        $token = $this->joinAsPlayer($auction['id'], $this->createInvite($this->owner, $auction['id']), 'Manual Player')->json('registrationToken');
        $this->guest()->post("/v1/public/registrations/{$token}/proof", [
            'file' => UploadedFile::fake()->image('paid.jpg'), 'utr' => '412345678901',
        ], ['Accept' => 'application/json'])->assertOk();

        return [$auction['id'], $token];
    }

    public function test_approvals_list_shows_pending_proofs(): void
    {
        [$auctionId] = $this->manualRegistrationWithProof();

        $this->as($this->owner)->getJson("/v1/auctions/{$auctionId}/registrations?status=awaiting_review")
            ->assertOk()
            ->assertJsonPath('counts.awaiting_review', 1)
            ->assertJsonPath('nextPage', null)
            ->assertJsonPath('items.0.status', 'awaiting_review')
            ->assertJsonPath('items.0.player.name', 'Manual Player')
            ->assertJsonPath('items.0.amount', 30_000)
            ->assertJsonPath('items.0.utr', '412345678901')
            ->assertJsonPath('items.0.mode', 'manual');
    }

    public function test_approve_adds_the_player_and_marks_paid(): void
    {
        [$auctionId, $token] = $this->manualRegistrationWithProof();
        $registration = PlayerRegistration::first();

        $this->as($this->owner)->postJson("/v1/auctions/{$auctionId}/registrations/{$registration->id}/approve")
            ->assertOk()
            ->assertJsonPath('registration.status', 'completed');

        $this->assertDatabaseCount('auction_players', 1);
        $this->assertSame('paid', $this->latestPayment()->status);
        $this->assertDatabaseCount('wallet_ledger', 0); // manual money never touches the wallet
        $this->guest()->getJson("/v1/public/registrations/{$token}")->assertJsonPath('registrationStatus', 'completed');

        $this->as($this->owner)->postJson("/v1/auctions/{$auctionId}/registrations/{$registration->id}/approve")->assertStatus(409);
    }

    public function test_reject_requires_a_reason_and_shows_it_to_the_player(): void
    {
        [$auctionId, $token] = $this->manualRegistrationWithProof();
        $registration = PlayerRegistration::first();

        $this->as($this->owner)->postJson("/v1/auctions/{$auctionId}/registrations/{$registration->id}/reject")->assertStatus(422);
        $this->as($this->owner)->postJson("/v1/auctions/{$auctionId}/registrations/{$registration->id}/reject", ['reason' => 'No payment received'])
            ->assertOk()->assertJsonPath('registration.status', 'rejected');

        $this->guest()->getJson("/v1/public/registrations/{$token}")
            ->assertJsonPath('registrationStatus', 'rejected')
            ->assertJsonPath('rejectReason', 'No payment received')
            ->assertJsonPath('actions.canUploadProof', false);
        $this->assertDatabaseCount('auction_players', 0);
    }

    public function test_registration_must_belong_to_the_auction_and_the_caller(): void
    {
        [$auctionId] = $this->manualRegistrationWithProof();
        $registration = PlayerRegistration::first();
        $otherAuction = $this->createAuction($this->owner, 'Another Cup');

        $this->as($this->owner)->postJson("/v1/auctions/{$otherAuction['id']}/registrations/{$registration->id}/approve")->assertNotFound();

        $stranger = $this->tokenFor('other@example.com');
        $this->as($stranger)->postJson("/v1/auctions/{$auctionId}/registrations/{$registration->id}/approve")->assertForbidden();
        $this->as($stranger)->getJson("/v1/auctions/{$auctionId}/registrations")->assertForbidden();
    }

    public function test_collections_list_paid_payments_with_fees_and_summary(): void
    {
        $payment = $this->paidRegistration($this->owner, 'pay_A');
        // a second, unpaid registration in the same auction must not appear
        $this->joinAsPlayer($payment->id_auction, $this->createInvite($this->owner, $payment->id_auction), 'Unpaid Player')->assertCreated();

        $this->as($this->owner)->getJson("/v1/auctions/{$payment->id_auction}/collections")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.player.name', 'Rahul Player')
            ->assertJsonPath('items.0.provider', 'razorpay')
            ->assertJsonPath('items.0.totalAmount', 52_300)
            ->assertJsonPath('items.0.platformFee', 1000)
            ->assertJsonPath('items.0.gatewayFee', 1234)
            ->assertJsonPath('items.0.organiserNet', 50_000)
            ->assertJsonPath('items.0.refundableAmount', 52_300)
            ->assertJsonPath('items.0.status', 'paid')
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.collected', 52_300)
            ->assertJsonPath('summary.organiserNet', 50_000);
    }

    public function test_manual_approved_payment_appears_in_collections_without_refund(): void
    {
        [$auctionId] = $this->manualRegistrationWithProof();
        $this->as($this->owner)->postJson("/v1/auctions/{$auctionId}/registrations/".PlayerRegistration::first()->id.'/approve')->assertOk();

        $this->as($this->owner)->getJson("/v1/auctions/{$auctionId}/collections")
            ->assertJsonPath('items.0.provider', 'manual')
            ->assertJsonPath('items.0.utr', '412345678901')
            ->assertJsonPath('items.0.refundableAmount', 0);
    }
}
