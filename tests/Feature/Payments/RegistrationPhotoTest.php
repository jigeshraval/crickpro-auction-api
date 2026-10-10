<?php

namespace Tests\Feature\Payments;

use App\Models\Player;
use App\Models\PlayerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Invitee self-uploaded photo on the invite join (free / razorpay / manual). */
class RegistrationPhotoTest extends TestCase
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
        Storage::fake('do');
        $this->owner = $this->tokenFor('organiser@example.com');
        $this->auction = $this->createAuction($this->owner);
        $this->invite = $this->createInvite($this->owner, $this->auction['id']);
    }

    private function joinWithPhoto(?UploadedFile $photo): TestResponse
    {
        return $this->guest()->post("/v1/public/invitations/{$this->auction['id']}/{$this->invite}/join", array_filter([
            'name' => 'Rahul Player', 'role' => 'batter', 'phone' => '+919999999999', 'photo' => $photo,
        ]), ['Accept' => 'application/json']);
    }

    /** 64×64 PNG: fully transparent background, opaque red square in the middle. */
    private function transparentPng(bool $palette = false): UploadedFile
    {
        if ($palette) {
            $img = imagecreate(64, 64);
            $clear = imagecolorallocate($img, 0, 255, 0);
            imagecolortransparent($img, $clear);
            $red = imagecolorallocate($img, 255, 0, 0);
        } else {
            $img = imagecreatetruecolor(64, 64);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
            $red = imagecolorallocatealpha($img, 255, 0, 0, 0);
        }
        imagefilledrectangle($img, 16, 16, 47, 47, $red);

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'me.png', 'image/png', null, true);
    }

    public function test_free_mode_join_sets_the_photo_on_the_player(): void
    {
        $this->joinWithPhoto(UploadedFile::fake()->image('me.jpg', 800, 600))
            ->assertOk()->assertJsonPath('mode', 'none');

        $photo = Player::firstOrFail()->photo_url;
        $this->assertMatchesRegularExpression('#^auction/player-photos/[A-Za-z0-9]{40}\.webp$#', (string) $photo);
        Storage::disk('do')->assertExists($photo);
        [$w, $h] = getimagesizefromstring(Storage::disk('do')->get($photo));
        $this->assertSame([512, 384], [$w, $h]); // same 512 max edge as the organiser upload
    }

    public function test_join_without_photo_still_works(): void
    {
        $this->joinWithPhoto(null)->assertOk();

        $this->assertNull(Player::firstOrFail()->photo_url);
    }

    public function test_razorpay_join_keeps_the_photo_until_payment_completes(): void
    {
        $this->fakeRazorpay();
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'razorpay', 'registrationFee' => 50_000, 'feeBearer' => 'player']);

        $this->joinWithPhoto(UploadedFile::fake()->image('me.png', 300, 300))->assertCreated();

        $this->assertDatabaseCount('players', 0);
        $stored = PlayerRegistration::firstOrFail()->payload['photoUrl'];
        $this->assertStringStartsWith('auction/player-photos/', $stored);
        Storage::disk('do')->assertExists($stored);

        $payment = $this->latestPayment();
        $this->webhook('razorpay', 'qr_code.credited', $this->qrCredited($payment->razorpay_qr_id, $this->capturedPayment('pay_PHOTO', 52_300)))->assertOk();

        $this->assertSame('completed', PlayerRegistration::firstOrFail()->status);
        $this->assertSame($stored, Player::firstOrFail()->photo_url);
    }

    public function test_manual_join_applies_the_photo_on_approval(): void
    {
        $this->setRegistration($this->owner, $this->auction['id'], ['registrationMode' => 'manual', 'registrationFee' => 30_000, 'manualInstructions' => 'Pay to cup@okicici']);

        $this->joinWithPhoto(UploadedFile::fake()->image('me.webp', 200, 200))->assertCreated();
        $registration = PlayerRegistration::firstOrFail();

        $this->as($this->owner)->getJson("/v1/auctions/{$this->auction['id']}/registrations")
            ->assertOk()->assertJsonFragment(['photoUrl' => $registration->payload['photoUrl']]);

        $this->as($this->owner)->postJson("/v1/auctions/{$this->auction['id']}/registrations/{$registration->id}/approve")->assertOk();

        $this->assertSame($registration->payload['photoUrl'], Player::firstOrFail()->photo_url);
    }

    public function test_invalid_photo_is_rejected(): void
    {
        $this->joinWithPhoto(UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->joinWithPhoto(UploadedFile::fake()->image('huge.png')->size(6000))
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->joinWithPhoto(UploadedFile::fake()->image('anim.gif'))
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('players', 0);
        $this->assertSame([], Storage::disk('do')->allFiles());
    }

    public function test_transparent_png_stays_transparent(): void
    {
        foreach ([false, true] as $palette) {
            $this->joinWithPhoto($this->transparentPng($palette))->assertOk();

            $photo = Player::latest('id')->firstOrFail()->photo_url;
            $this->assertStringEndsWith('.webp', $photo);
            $img = imagecreatefromstring(Storage::disk('do')->get($photo));
            $this->assertNotFalse($img);

            $corner = imagecolorsforindex($img, imagecolorat($img, 0, 0));
            $center = imagecolorsforindex($img, imagecolorat($img, 32, 32));
            $label = $palette ? 'palette PNG' : 'truecolor PNG';
            $this->assertSame(127, $corner['alpha'], "$label: corner must stay fully transparent");
            $this->assertSame(0, $center['alpha'], "$label: centre must stay opaque");
            $this->assertGreaterThan(200, $center['red']);
        }
    }
}
