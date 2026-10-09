<?php

namespace Tests\Feature\Payments;

use App\Models\ApiCall;
use App\Models\PayoutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayoutAccountTest extends TestCase
{
    use PaymentTestHelpers;
    use RefreshDatabase;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPayments();
        $this->fakeRazorpay();
        $this->owner = $this->tokenFor('organiser@example.com');
    }

    private function addBank(): array
    {
        return $this->as($this->owner)->postJson('/v1/wallet/payout-accounts', [
            'type' => 'bank', 'accountNumber' => '123456789012', 'ifsc' => 'hdfc0000123', 'holderName' => 'Raj Organiser',
        ])->assertCreated()->json('payoutAccount');
    }

    private function validationWebhook(string $event, string $validationId, string $fundAccountId, string $accountStatus, ?string $name = 'RAJ ORGANISER'): void
    {
        $this->webhook('razorpayx', $event, ['fund_account.validation' => ['entity' => [
            'id' => $validationId, 'entity' => 'fund_account.validation', 'status' => $event === 'fund_account.validation.failed' ? 'failed' : 'completed',
            'fund_account' => ['id' => $fundAccountId],
            'results' => ['account_status' => $accountStatus, 'registered_name' => $name],
        ]]])->assertOk();
    }

    public function test_bank_account_is_created_masked_and_sent_for_validation(): void
    {
        $account = $this->addBank();

        $this->assertSame('bank', $account['type']);
        $this->assertSame('validating', $account['status']);
        $this->assertSame('XXXXXXXX9012', $account['maskedAccount']);
        $this->assertSame('HDFC0000123', $account['ifsc']);
        $this->assertFalse($account['isDefault']);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/contacts') && $r['reference_id'] === 'owner_'.$this->userId('organiser@example.com'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/fund_accounts')
            && $r['account_type'] === 'bank_account' && $r['bank_account']['ifsc'] === 'HDFC0000123');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/fund_accounts/validations'));

        // the raw number never lands in our DB
        $this->assertDatabaseMissing('payout_accounts', ['masked_account' => '123456789012']);
        foreach (ApiCall::all() as $call) {
            $this->assertStringNotContainsString('123456789012', (string) $call->request.$call->response);
        }
    }

    public function test_contact_is_created_once_per_owner(): void
    {
        $this->addBank();
        $this->as($this->owner)->postJson('/v1/wallet/payout-accounts', ['type' => 'vpa', 'vpa' => 'raj@okicici'])->assertCreated();

        $this->assertSame(1, Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/contacts'))->count());
        $this->assertDatabaseHas('wallets', ['razorpayx_contact_id' => 'cont_TEST']);
    }

    public function test_vpa_is_masked(): void
    {
        $this->as($this->owner)->postJson('/v1/wallet/payout-accounts', ['type' => 'vpa', 'vpa' => 'rajorganiser@okicici'])
            ->assertCreated()
            ->assertJsonPath('payoutAccount.maskedAccount', 'ra***@okicici')
            ->assertJsonPath('payoutAccount.type', 'vpa');
    }

    public function test_validation_completed_verifies_and_becomes_default(): void
    {
        $this->addBank();
        $acc = PayoutAccount::first();

        $this->validationWebhook('fund_account.validation.completed', $acc->validation_id, $acc->fund_account_id, 'active');

        $this->as($this->owner)->getJson('/v1/wallet/payout-accounts')
            ->assertOk()
            ->assertJsonPath('items.0.status', 'verified')
            ->assertJsonPath('items.0.verifiedName', 'RAJ ORGANISER')
            ->assertJsonPath('items.0.isDefault', true);
    }

    public function test_invalid_account_fails_validation(): void
    {
        $this->addBank();
        $acc = PayoutAccount::first();

        $this->validationWebhook('fund_account.validation.completed', $acc->validation_id, $acc->fund_account_id, 'invalid', null);

        $this->assertSame('failed', $acc->fresh()->status);
        $this->assertNotNull($acc->fresh()->failure_reason);
    }

    public function test_validation_failed_event_fails_the_account(): void
    {
        $this->addBank();
        $acc = PayoutAccount::first();

        $this->validationWebhook('fund_account.validation.failed', $acc->validation_id, $acc->fund_account_id, 'invalid', null);

        $this->assertSame('failed', $acc->fresh()->status);
    }

    public function test_validation_api_failure_marks_the_account_failed(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeRazorpay(['*/fund_accounts/validations' => Http::response(['error' => ['description' => 'x']], 400)]);

        $this->addBank();

        $this->assertSame('failed', PayoutAccount::first()->status);
    }

    public function test_bad_details_are_rejected(): void
    {
        $this->as($this->owner)->postJson('/v1/wallet/payout-accounts', ['type' => 'bank', 'accountNumber' => '12', 'ifsc' => 'BAD', 'holderName' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors(['accountNumber', 'ifsc']);
        $this->as($this->owner)->postJson('/v1/wallet/payout-accounts', ['type' => 'vpa', 'vpa' => 'not-a-vpa'])
            ->assertStatus(422)->assertJsonValidationErrors(['vpa']);
    }

    public function test_delete_soft_deletes_and_promotes_another_default(): void
    {
        $this->addBank();
        $this->as($this->owner)->postJson('/v1/wallet/payout-accounts', ['type' => 'vpa', 'vpa' => 'raj@okicici'])->assertCreated();
        [$bank, $vpa] = PayoutAccount::orderBy('id')->get()->all();
        $this->validationWebhook('fund_account.validation.completed', $bank->validation_id, $bank->fund_account_id, 'active');
        $this->validationWebhook('fund_account.validation.completed', $vpa->validation_id, $vpa->fund_account_id, 'valid');

        $this->as($this->owner)->deleteJson("/v1/wallet/payout-accounts/{$bank->id}")->assertOk();

        $this->assertSoftDeleted('payout_accounts', ['id' => $bank->id]);
        $this->assertTrue($vpa->fresh()->is_default);
    }

    public function test_cannot_delete_someone_elses_account(): void
    {
        $this->addBank();
        $other = $this->tokenFor('other@example.com');

        $this->as($other)->deleteJson('/v1/wallet/payout-accounts/'.PayoutAccount::first()->id)->assertForbidden();
    }
}
