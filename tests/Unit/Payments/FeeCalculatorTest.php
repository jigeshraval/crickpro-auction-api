<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\FeeCalculator;
use Tests\TestCase;

class FeeCalculatorTest extends TestCase
{
    private FeeCalculator $fees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fees = new FeeCalculator;
    }

    public function test_platform_fee_is_two_percent_with_a_ten_rupee_floor(): void
    {
        $this->assertSame(1000, $this->fees->platformFee(20_000));   // 2% = ₹4 → floor ₹10
        $this->assertSame(1000, $this->fees->platformFee(50_000));   // 2% = ₹10 exactly
        $this->assertSame(2000, $this->fees->platformFee(100_000));  // 2% = ₹20
        $this->assertSame(1501, $this->fees->platformFee(75_050));   // 1501.0 (half-up)
        $this->assertSame(1503, $this->fees->platformFee(75_125));   // 1502.5 → 1503
    }

    public function test_organiser_bears_fees_for_a_500_rupee_registration(): void
    {
        $b = $this->fees->breakdown(50_000, FeeCalculator::BEARER_ORGANISER);

        $this->assertSame(50_000, $b->baseAmount);
        $this->assertSame(1000, $b->platformFee);
        $this->assertSame(0, $b->gatewayFee);            // not shown to the player
        $this->assertSame(50_000, $b->totalAmount);
        $this->assertSame(1180, $b->estimatedGatewayFee); // 500 × 2% × 1.18
        $this->assertSame(47_820, $b->organiserNet);
    }

    public function test_player_bears_fees_for_a_500_rupee_registration(): void
    {
        $b = $this->fees->breakdown(50_000, FeeCalculator::BEARER_PLAYER);

        $this->assertSame(52_300, $b->totalAmount);       // ceil(510 / 0.9764) = ₹523
        $this->assertSame(1300, $b->gatewayFee);          // T − B − P
        $this->assertSame(1234, $b->estimatedGatewayFee); // 523 × 0.0236 = 12.34
        $this->assertSame(50_066, $b->organiserNet);
        $this->assertSame([
            'baseAmount' => 50_000, 'platformFee' => 1000, 'gatewayFee' => 1300,
            'totalAmount' => 52_300, 'feeBearer' => 'player', 'currency' => 'INR',
        ], $b->toPublicArray());
    }

    public function test_player_mode_total_is_whole_rupees_and_net_never_below_base(): void
    {
        foreach ([1000, 9999, 12_345, 50_000, 99_999, 250_000, 1_000_000] as $base) {
            $b = $this->fees->breakdown($base, FeeCalculator::BEARER_PLAYER);
            $this->assertSame(0, $b->totalAmount % 100, "total for {$base} not whole rupees");
            $this->assertGreaterThanOrEqual($base, $b->organiserNet, "net below base for {$base}");
        }
    }

    public function test_organiser_mode_can_go_negative_for_tiny_fees(): void
    {
        // The ₹50 minimum fee (Task 5) keeps real settings far from this — the calculator just reports it.
        $this->assertSame(-24, $this->fees->breakdown(1000, FeeCalculator::BEARER_ORGANISER)->organiserNet);
    }

    public function test_share_is_proportional_half_up_and_settles_exactly_on_full_amount(): void
    {
        $this->assertSame(25_033, $this->fees->share(26_150, 52_300, 50_066));
        $this->assertSame(50_066, $this->fees->share(52_300, 52_300, 50_066));
        $this->assertSame(50_066, $this->fees->share(60_000, 52_300, 50_066)); // clamps at full
        $this->assertSame(33, $this->fees->share(1, 3, 100));
        $this->assertSame(9573, $this->fees->share(10_000, 52_300, 50_066));
        $this->assertSame(0, $this->fees->share(0, 52_300, 50_066));
    }

    public function test_payout_fee_includes_gst(): void
    {
        $this->assertSame(590, $this->fees->payoutFee()); // ₹5 + 18% GST
    }

    public function test_payout_fee_rounds_half_up_with_integer_maths(): void
    {
        config(['payments.payout_fee' => 250, 'payments.payout_fee_gst' => 0.18]);
        $this->assertSame(295, $this->fees->payoutFee()); // 295.0

        config(['payments.payout_fee' => 25, 'payments.payout_fee_gst' => 0.18]);
        $this->assertSame(30, $this->fees->payoutFee()); // 29.5 → 30
    }

    public function test_gateway_rate_of_100_percent_or_more_is_rejected(): void
    {
        config(['payments.gateway_rate' => 0.9, 'payments.gateway_gst' => 0.18]); // 106.2%

        $this->expectException(\LogicException::class);
        $this->fees->breakdown(50_000, FeeCalculator::BEARER_PLAYER);
    }

    public function test_share_rejects_negative_inputs(): void
    {
        foreach ([[-1, 100, 50], [10, -100, 50], [10, 100, -50]] as [$amount, $total, $portion]) {
            try {
                $this->fees->share($amount, $total, $portion);
                $this->fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
