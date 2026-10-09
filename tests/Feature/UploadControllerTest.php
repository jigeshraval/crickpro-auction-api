<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\OverlayTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cover_and_logo_upload_compress_to_webp(): void
    {
        OverlayTemplate::create(['code' => 'nakshatra', 'name' => 'Nakshatra']);
        Currency::create(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹']);
        Storage::fake('do');
        User::factory()->create(['email' => 'o@example.com', 'password' => bcrypt('Passw0rd1')]);
        $token = $this->postJson('/v1/auth/login-password', ['email' => 'o@example.com', 'password' => 'Passw0rd1'])->json('token');
        $id = $this->withHeader('Authorization', "Bearer $token")->postJson('/v1/auctions/quick-start', ['name' => 'Upload Cup'])->json('auction.id');

        $url = $this->withHeader('Authorization', "Bearer $token")
            ->post("/v1/auctions/$id/cover", ['file' => UploadedFile::fake()->image('c.png', 2000, 1000)], ['Accept' => 'application/json'])
            ->assertOk()->json("url");
        $this->assertMatchesRegularExpression('#^auction/auction-covers/[A-Za-z0-9]{40}\.webp$#', $url);
        Storage::disk('do')->assertExists($url);
        [$w, $h] = getimagesizefromstring(Storage::disk('do')->get($url));
        $this->assertSame([512, 256], [$w, $h]);

        $this->withHeader('Authorization', "Bearer $token")
            ->post("/v1/auctions/$id/logo", ['file' => UploadedFile::fake()->image('l.png', 100, 100)], ['Accept' => 'application/json'])
            ->assertOk();
        $this->withHeader('Authorization', "Bearer $token")
            ->post("/v1/auctions/$id/logo", [], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
