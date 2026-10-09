<?php

namespace Tests\Feature\Payments;

use App\Models\PlayerRegistration;
use App\Models\RegistrationPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
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
        $this->fakeRazorpay();
        $this->owner = $this->tokenFor('organiser@example.com');
        $this->auction = $this->createAuction($this->owner);
        $this->invite = $this->createInvite($this->owner, $this->auction['id']);
    }

    private function joinRazorpay(): string
    {
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);

        return $this->joinAsPlayer($this->auction['id'], $this->invite)->assertCreated()->json('registrationToken');
    }

    private function joinManual(): string
    {
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'manual', 'registrationFee' => 30_000, 'manualInstructions' => 'Pay to cup@okicici']);

        return $this->joinAsPlayer($this->auction['id'], $this->invite)->assertCreated()->json('registrationToken');
    }

    private function qrCreateCalls(): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments/qr_codes'))->count();
    }

    public function test_status_returns_the_same_shape_as_join(): void
    {
        $token = $this->joinRazorpay();

        $this->guest()->getJson("/v1/public/registrations/{$token}")
            ->assertOk()
            ->assertJsonPath('registrationToken', $token)
            ->assertJsonPath('registrationStatus', 'awaiting_payment')
            ->assertJsonPath('breakdown.totalAmount', 52_300)
            ->assertJsonPath('auction.name', 'Payments Cup');
    }

    public function test_unknown_token_is_404(): void
    {
        $this->guest()->getJson('/v1/public/registrations/nope')->assertNotFound();
    }

    public function test_new_qr_while_one_is_open_returns_the_current_qr(): void
    {
        $token = $this->joinRazorpay();
        $before = $this->latestPayment();

        $this->guest()->postJson("/v1/public/registrations/{$token}/new-qr")
            ->assertOk()
            ->assertJsonPath('qr.imageUrl', "https://rzp.io/i/qr_{$before->id}");

        $this->assertSame(1, $this->qrCreateCalls());
    }

    public function test_after_expiry_a_new_qr_is_issued_on_the_same_registration(): void
    {
        $token = $this->joinRazorpay();
        $first = $this->latestPayment();
        $this->travel(16)->minutes();

        $this->guest()->getJson("/v1/public/registrations/{$token}")
            ->assertJsonPath('qr', null)
            ->assertJsonPath('actions.canGenerateQr', true);

        $response = $this->guest()->postJson("/v1/public/registrations/{$token}/new-qr")->assertOk();
        $second = $this->latestPayment();

        $this->assertNotSame($first->id, $second->id);
        $response->assertJsonPath('qr.imageUrl', "https://rzp.io/i/qr_{$second->id}")
            ->assertJsonPath('registrationStatus', 'awaiting_payment')
            ->assertJsonPath('breakdown.totalAmount', 52_300);
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame(2, RegistrationPayment::count());
        $this->assertSame(2, $this->qrCreateCalls());
    }

    public function test_new_qr_is_refused_for_manual_registrations(): void
    {
        $token = $this->joinManual();

        $this->guest()->postJson("/v1/public/registrations/{$token}/new-qr")->assertStatus(409);
    }

    public function test_new_qr_is_refused_once_completed(): void
    {
        $token = $this->joinRazorpay();
        PlayerRegistration::where('public_token', $token)->update(['status' => 'completed']);
        RegistrationPayment::query()->update(['status' => 'paid']);

        $this->guest()->postJson("/v1/public/registrations/{$token}/new-qr")->assertStatus(409);
    }

    public function test_manual_proof_upload_with_utr(): void
    {
        Storage::fake('do');
        $token = $this->joinManual();

        $this->guest()->post("/v1/public/registrations/{$token}/proof", [
            'file' => UploadedFile::fake()->image('paid.jpg', 1080, 2340),
            'utr' => ' 412345678901 ',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('proof.submitted', true)
            ->assertJsonPath('proof.utr', '412345678901')
            ->assertJsonPath('registrationStatus', 'awaiting_review');

        $payment = $this->latestPayment();
        $this->assertStringStartsWith('auction/registration-proofs/', $payment->proof_url);
        Storage::disk('do')->assertExists($payment->proof_url);
    }

    public function test_proof_requires_an_image_and_a_manual_registration(): void
    {
        Storage::fake('do');
        $manual = $this->joinManual();
        $this->guest()->postJson("/v1/public/registrations/{$manual}/proof", ['utr' => '1'])->assertStatus(422);

        $this->invite = $this->createInvite($this->owner, $this->auction['id']);
        $razorpay = $this->joinRazorpay();
        $this->guest()->post("/v1/public/registrations/{$razorpay}/proof", [
            'file' => UploadedFile::fake()->image('paid.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }
}
