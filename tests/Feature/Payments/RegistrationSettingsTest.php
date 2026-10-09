<?php

namespace Tests\Feature\Payments;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationSettingsTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    private array $auction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->owner = $this->tokenFor('organiser@example.com');
        $this->auction = $this->createAuction($this->owner);
    }

    public function test_defaults_to_no_registration_fee(): void
    {
        $this->as($this->owner)->getJson("/v1/auctions/{$this->auction['id']}/registration-settings")
            ->assertOk()
            ->assertJsonPath('registrationSettings.registrationMode', 'none')
            ->assertJsonPath('registrationSettings.registrationFee', 0)
            ->assertJsonPath('registrationSettings.feeBearer', 'organiser')
            ->assertJsonPath('preview', null);
    }

    public function test_owner_sets_razorpay_mode_with_player_bearer_and_gets_a_preview(): void
    {
        $this->as($this->owner)->putJson("/v1/auctions/{$this->auction['id']}/registration-settings", [
            'registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player',
        ])->assertOk()
            ->assertJsonPath('registrationSettings.registrationMode', 'razorpay')
            ->assertJsonPath('registrationSettings.registrationFee', 50_000)
            ->assertJsonPath('preview.totalAmount', 52_300)
            ->assertJsonPath('preview.gatewayFee', 1300)
            ->assertJsonPath('preview.organiserNet', 50_066);

        $this->assertDatabaseHas('auction_settings', [
            'id_auction' => $this->auction['id'], 'registration_mode' => 'razorpay', 'registration_fee' => 50_000, 'fee_bearer' => 'player',
        ]);
    }

    public function test_fee_below_fifty_rupees_is_rejected_in_both_modes(): void
    {
        $this->as($this->owner)->putJson("/v1/auctions/{$this->auction['id']}/registration-settings", [
            'registrationMode' => 'razorpay', 'registrationFee' => 4999, 'feeBearer' => 'organiser',
        ])->assertStatus(422)->assertJsonValidationErrors('registrationFee');

        $this->as($this->owner)->putJson("/v1/auctions/{$this->auction['id']}/registration-settings", [
            'registrationMode' => 'manual', 'registrationFee' => 4999,
        ])->assertStatus(422)->assertJsonValidationErrors('registrationFee');
    }

    public function test_fifty_rupees_with_organiser_bearing_fees_is_accepted(): void
    {
        $this->as($this->owner)->putJson("/v1/auctions/{$this->auction['id']}/registration-settings", [
            'registrationMode' => 'razorpay', 'registrationFee' => 5000, 'feeBearer' => 'organiser',
        ])->assertOk()
            ->assertJsonPath('preview.platformFee', 1000)
            ->assertJsonPath('preview.estimatedGatewayFee', 118)
            ->assertJsonPath('preview.organiserNet', 3882); // ₹50 − ₹10 − ₹1.18
    }

    public function test_settings_report_eligibility(): void
    {
        $this->as($this->owner)->getJson("/v1/auctions/{$this->auction['id']}/registration-settings")
            ->assertOk()->assertJsonPath('eligible', true);
    }

    public function test_non_indian_owner_gets_403_on_every_settings_endpoint(): void
    {
        Storage::fake('do');
        $uae = $this->tokenFor('uae@example.com', 'AE');
        $id = $this->createAuction($uae, 'Dubai Cup')['id'];
        $message = 'Registration payments are only available in India.';

        $this->as($uae)->getJson("/v1/auctions/{$id}/registration-settings")->assertForbidden()->assertJsonPath('message', $message);
        $this->as($uae)->putJson("/v1/auctions/{$id}/registration-settings", ['registrationMode' => 'manual', 'registrationFee' => 5000])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->as($uae)->getJson("/v1/auctions/{$id}/fee-preview?amount=5000&feeBearer=organiser")->assertForbidden();
        $this->as($uae)->post("/v1/auctions/{$id}/registration-settings/qr", ['file' => UploadedFile::fake()->image('qr.png')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $unknown = $this->tokenFor('nocountry@example.com', null);
        $this->as($unknown)->getJson('/v1/auctions/'.$this->createAuction($unknown, 'Unknown Cup')['id'].'/registration-settings')->assertForbidden();
    }

    public function test_indian_owner_with_a_non_inr_auction_gets_403(): void
    {
        Currency::create(['id' => 2, 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);
        $id = $this->as($this->owner)->postJson('/v1/auctions/quick-start', ['name' => 'Dollar Cup', 'currencyId' => 2])->assertSuccessful()->json('auction.id');

        $this->as($this->owner)->getJson("/v1/auctions/{$id}/registration-settings")
            ->assertForbidden()->assertJsonPath('status', 'error')->assertJsonPath('message', 'Registration payments are only available in India.');
    }

    public function test_manual_mode_saves_instructions(): void
    {
        $this->as($this->owner)->putJson("/v1/auctions/{$this->auction['id']}/registration-settings", [
            'registrationMode' => 'manual', 'registrationFee' => 30_000, 'manualInstructions' => 'Pay to cup@okicici and upload the screenshot.',
        ])->assertOk()
            ->assertJsonPath('registrationSettings.manualInstructions', 'Pay to cup@okicici and upload the screenshot.')
            ->assertJsonPath('preview', null);
    }

    public function test_manual_mode_without_instructions_or_qr_is_rejected(): void
    {
        $url = "/v1/auctions/{$this->auction['id']}/registration-settings";

        $this->as($this->owner)->putJson($url, ['registrationMode' => 'manual', 'registrationFee' => 30_000])
            ->assertStatus(422)->assertJsonValidationErrors('manualInstructions');
        $this->as($this->owner)->putJson($url, ['registrationMode' => 'manual', 'registrationFee' => 30_000, 'manualInstructions' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('manualInstructions');
        $this->assertDatabaseMissing('auction_settings', ['id_auction' => $this->auction['id'], 'registration_mode' => 'manual']);
    }

    public function test_manual_mode_is_allowed_once_a_qr_is_uploaded(): void
    {
        Storage::fake('do');
        $this->as($this->owner)->post("/v1/auctions/{$this->auction['id']}/registration-settings/qr", [
            'file' => UploadedFile::fake()->image('qr.png', 400, 400),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->as($this->owner)->putJson("/v1/auctions/{$this->auction['id']}/registration-settings", [
            'registrationMode' => 'manual', 'registrationFee' => 30_000,
        ])->assertOk()->assertJsonPath('registrationSettings.registrationMode', 'manual');
    }

    public function test_manual_mode_keeps_previously_saved_instructions_when_omitted(): void
    {
        $url = "/v1/auctions/{$this->auction['id']}/registration-settings";
        $this->as($this->owner)->putJson($url, ['registrationMode' => 'manual', 'registrationFee' => 30_000, 'manualInstructions' => 'Pay to cup@okicici'])->assertOk();

        $this->as($this->owner)->putJson($url, ['registrationMode' => 'manual', 'registrationFee' => 40_000])->assertOk();
        $this->as($this->owner)->putJson($url, ['registrationMode' => 'manual', 'registrationFee' => 40_000, 'manualInstructions' => null])
            ->assertStatus(422)->assertJsonValidationErrors('manualInstructions');
    }

    public function test_non_owner_cannot_read_or_change_settings(): void
    {
        $other = $this->tokenFor('other@example.com');

        $this->as($other)->getJson("/v1/auctions/{$this->auction['id']}/registration-settings")->assertForbidden();
        $this->as($other)->putJson("/v1/auctions/{$this->auction['id']}/registration-settings", [
            'registrationMode' => 'none',
        ])->assertForbidden();
    }

    public function test_non_owner_of_an_ineligible_auction_gets_the_generic_403_first(): void
    {
        Storage::fake('do');
        $uae = $this->tokenFor('uae-owner@example.com', 'AE');
        $id = $this->createAuction($uae, 'Dubai Cup')['id'];
        $other = $this->tokenFor('nosy@example.com');

        $this->as($other)->getJson("/v1/auctions/{$id}/registration-settings")
            ->assertForbidden()->assertExactJson(['status' => 'error', 'message' => 'Forbidden.']);
        $this->as($other)->putJson("/v1/auctions/{$id}/registration-settings", ['registrationMode' => 'none'])
            ->assertForbidden()->assertJsonPath('message', 'Forbidden.');
        $this->as($other)->getJson("/v1/auctions/{$id}/fee-preview?amount=5000&feeBearer=organiser")
            ->assertForbidden()->assertJsonPath('message', 'Forbidden.');
        $this->as($other)->post("/v1/auctions/{$id}/registration-settings/qr", ['file' => UploadedFile::fake()->image('qr.png')], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('message', 'Forbidden.');
    }

    public function test_fee_preview_endpoint(): void
    {
        $this->as($this->owner)->getJson("/v1/auctions/{$this->auction['id']}/fee-preview?amount=50000&feeBearer=organiser")
            ->assertOk()
            ->assertJsonPath('breakdown.totalAmount', 50_000)
            ->assertJsonPath('breakdown.platformFee', 1000)
            ->assertJsonPath('breakdown.organiserNet', 47_820);

        $this->as($this->owner)->getJson("/v1/auctions/{$this->auction['id']}/fee-preview?amount=10&feeBearer=organiser")
            ->assertStatus(422);
    }

    public function test_manual_qr_upload_stores_the_image_path(): void
    {
        Storage::fake('do');

        $path = $this->as($this->owner)->post("/v1/auctions/{$this->auction['id']}/registration-settings/qr", [
            'file' => UploadedFile::fake()->image('qr.png', 800, 800),
        ], ['Accept' => 'application/json'])->assertOk()->json('url');

        $this->assertStringStartsWith('auction/registration-qr/', $path);
        Storage::disk('do')->assertExists($path);
        $bytes = Storage::disk('do')->get($path);
        $this->assertTrue(
            (str_starts_with($bytes, 'RIFF') && substr($bytes, 12, 4) === 'VP8L') || str_starts_with($bytes, "\x89PNG"),
            'Manual QR must be stored lossless (WebP VP8L or PNG).',
        );
        $this->as($this->owner)->getJson("/v1/auctions/{$this->auction['id']}/registration-settings")
            ->assertJsonPath('registrationSettings.manualQrUrl', $path);
    }

    public function test_replacing_the_manual_qr_deletes_the_previous_object(): void
    {
        Storage::fake('do');
        $upload = fn () => $this->as($this->owner)->post("/v1/auctions/{$this->auction['id']}/registration-settings/qr", [
            'file' => UploadedFile::fake()->image('qr.png', 400, 400),
        ], ['Accept' => 'application/json'])->assertOk()->json('url');

        $first = $upload();
        $second = $upload();

        $this->assertNotSame($first, $second);
        Storage::disk('do')->assertMissing($first);
        Storage::disk('do')->assertExists($second);
    }
}
