<?php

namespace Tests\Feature\Payments;

use App\Models\PlayerRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationJoinTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private array $auction;

    private string $invite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->owner = $this->tokenFor('organiser@example.com');
        $this->auction = $this->createAuction($this->owner);
        $this->invite = $this->createInvite($this->owner, $this->auction['id']);
    }

    public function test_none_mode_keeps_todays_direct_add(): void
    {
        $this->joinAsPlayer($this->auction['id'], $this->invite)
            ->assertOk()
            ->assertJsonPath('mode', 'none');

        $this->assertDatabaseCount('auction_players', 1);
        $this->assertDatabaseCount('player_registrations', 0);
        $this->assertDatabaseHas('auction_invitations', ['token' => $this->invite, 'joined_count' => 1]);
    }

    public function test_razorpay_join_creates_a_registration_and_a_fixed_amount_qr(): void
    {
        $this->fakeRazorpay();
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);

        $response = $this->joinAsPlayer($this->auction['id'], $this->invite)
            ->assertCreated()
            ->assertJsonPath('mode', 'razorpay')
            ->assertJsonPath('registrationStatus', 'awaiting_payment')
            ->assertJsonPath('breakdown.baseAmount', 50_000)
            ->assertJsonPath('breakdown.platformFee', 1000)
            ->assertJsonPath('breakdown.gatewayFee', 1300)
            ->assertJsonPath('breakdown.totalAmount', 52_300)
            ->assertJsonPath('actions.canGenerateQr', false);

        $this->assertSame(40, strlen($response->json('registrationToken')));
        $payment = $this->latestPayment();
        $this->assertSame("https://rzp.io/i/qr_{$payment->id}", $response->json('qr.imageUrl'));
        $this->assertNotNull($response->json('qr.expiresAt'));
        $this->assertDatabaseHas('registration_payments', [
            'id' => $payment->id, 'provider' => 'razorpay', 'status' => 'created', 'total_amount' => 52_300,
            'razorpay_qr_id' => "qr_{$payment->id}", 'organiser_net' => 50_066,
        ]);
        $this->assertDatabaseCount('auction_players', 0);
        $this->assertDatabaseHas('auction_invitations', ['token' => $this->invite, 'joined_count' => 0]);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments/qr_codes')
            && $r['type'] === 'upi_qr' && $r['usage'] === 'single_use' && $r['fixed_amount'] === true
            && $r['payment_amount'] === 52_300 && $r['close_by'] >= now()->addSeconds(120)->timestamp
            && $r['notes']['registration_id'] === (string) PlayerRegistration::first()->id);
    }

    public function test_qr_failure_keeps_the_registration_and_offers_retry(): void
    {
        $this->fakeRazorpay(['*/payments/qr_codes' => Http::response(['error' => ['description' => 'down']], 500)]);
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'organiser']);

        $this->joinAsPlayer($this->auction['id'], $this->invite)
            ->assertCreated()
            ->assertJsonPath('registrationStatus', 'awaiting_payment')
            ->assertJsonPath('qr', null)
            ->assertJsonPath('actions.canGenerateQr', true);

        $this->assertSame('expired', $this->latestPayment()->status);
    }

    public function test_manual_join_returns_instructions_and_amount_without_platform_fee(): void
    {
        $this->setRegistration($this->owner, $this->auction['id'], [
            'registrationMode' => 'manual', 'registrationFee' => 30_000, 'manualInstructions' => 'Pay to cup@okicici',
        ]);

        $this->joinAsPlayer($this->auction['id'], $this->invite)
            ->assertCreated()
            ->assertJsonPath('mode', 'manual')
            ->assertJsonPath('registrationStatus', 'awaiting_review')
            ->assertJsonPath('breakdown', null)
            ->assertJsonPath('manual.amount', 30_000)
            ->assertJsonPath('manual.instructions', 'Pay to cup@okicici')
            ->assertJsonPath('actions.canUploadProof', true);

        $this->assertDatabaseHas('registration_payments', [
            'provider' => 'manual', 'platform_fee' => 0, 'total_amount' => 30_000, 'organiser_net' => 30_000, 'status' => 'created',
        ]);
        $this->assertDatabaseCount('auction_players', 0);
    }

    public function test_ineligible_auction_falls_back_to_free_registration(): void
    {
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);
        // The owner's country changes after setup (e.g. PATCH v1/auth/user from abroad).
        User::where('email', 'organiser@example.com')->update(['country' => 'AE']);

        $this->guest()->getJson("/v1/public/invitations/{$this->auction['id']}/{$this->invite}")
            ->assertJsonPath('registration.mode', 'none')
            ->assertJsonPath('registration.breakdown', null);

        $this->joinAsPlayer($this->auction['id'], $this->invite)
            ->assertOk()
            ->assertJsonPath('mode', 'none');

        $this->assertDatabaseCount('auction_players', 1);
        $this->assertDatabaseCount('player_registrations', 0);
        Http::assertNothingSent();
    }

    public function test_invitation_page_shows_the_fee_before_the_form(): void
    {
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);

        $this->guest()->getJson("/v1/public/invitations/{$this->auction['id']}/{$this->invite}")
            ->assertOk()
            ->assertJsonPath('registration.mode', 'razorpay')
            ->assertJsonPath('registration.fee', 50_000)
            ->assertJsonPath('registration.breakdown.totalAmount', 52_300);
    }
}
