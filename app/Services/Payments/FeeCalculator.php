<?php

namespace App\Services\Payments;

/**
 * The ONE place fee maths lives (settings preview, player screen, stored
 * payment, refund/dispute splits, payout fee). Integer arithmetic only — the
 * gateway rate is carried in millionths so ceil/round never hit float error.
 */
final class FeeCalculator
{
    public const BEARER_ORGANISER = 'organiser';

    public const BEARER_PLAYER = 'player';

    private const MICRO = 1_000_000;

    /** P = max(round(B × percent%), min). Half-up. */
    public function platformFee(int $base): int
    {
        $bps = (int) round(((float) config('payments.platform_fee_percent')) * 100); // 2% → 200 bps
        $percentFee = intdiv($base * $bps * 2 + 10_000, 20_000);                     // round half-up of B×bps/10000

        return max($percentFee, (int) config('payments.platform_fee_min'));
    }

    /** G(T) = round(T × rate × (1 + gst)). Half-up. */
    public function estimatedGatewayFee(int $total): int
    {
        return intdiv($total * $this->gatewayMicro() * 2 + self::MICRO, 2 * self::MICRO);
    }

    public function breakdown(int $base, string $bearer): FeeBreakdown
    {
        $platform = $this->platformFee($base);

        if ($bearer === self::BEARER_PLAYER) {
            // T = ceil((B + P) / (1 − rate × (1+gst))), then rounded UP to the rupee.
            $denominator = self::MICRO - $this->gatewayMicro();
            $raw = intdiv(($base + $platform) * self::MICRO + $denominator - 1, $denominator);
            $total = intdiv($raw + 99, 100) * 100;
            $estimated = $this->estimatedGatewayFee($total);

            return new FeeBreakdown(
                baseAmount: $base,
                platformFee: $platform,
                gatewayFee: $total - $base - $platform,
                totalAmount: $total,
                organiserNet: $total - $platform - $estimated,
                estimatedGatewayFee: $estimated,
                feeBearer: self::BEARER_PLAYER,
            );
        }

        $estimated = $this->estimatedGatewayFee($base);

        return new FeeBreakdown(
            baseAmount: $base,
            platformFee: $platform,
            gatewayFee: 0,
            totalAmount: $base,
            organiserNet: $base - $platform - $estimated,
            estimatedGatewayFee: $estimated,
            feeBearer: self::BEARER_ORGANISER,
        );
    }

    /**
     * Proportional share of $portion for a CUMULATIVE amount out of $total,
     * half-up. Callers pass cumulative amounts and subtract what was already
     * booked, so a sequence of partial refunds always sums to exactly $portion
     * once the full $total has been refunded.
     */
    public function share(int $cumulativeAmount, int $total, int $portion): int
    {
        if ($cumulativeAmount < 0 || $total < 0 || $portion < 0) {
            throw new \InvalidArgumentException("share() needs non-negative amounts, got ({$cumulativeAmount}, {$total}, {$portion}).");
        }
        if ($total === 0 || $cumulativeAmount === 0) {
            return 0;
        }
        if ($cumulativeAmount >= $total) {
            return $portion;
        }

        return intdiv(2 * $cumulativeAmount * $portion + $total, 2 * $total);
    }

    /** RazorpayX payout fee incl. GST — deducted from every withdrawal. Half-up, integer maths. */
    public function payoutFee(): int
    {
        $fee = (int) config('payments.payout_fee');
        $gstMicro = self::micro('payments.payout_fee_gst');
        if ($fee < 0 || $gstMicro < 0) {
            throw new \LogicException('payments.payout_fee and payments.payout_fee_gst must be non-negative.');
        }

        return intdiv($fee * (self::MICRO + $gstMicro) * 2 + self::MICRO, 2 * self::MICRO);
    }

    /** rate × (1+gst) in millionths: 0.02 × 1.18 → 23 600. Must stay below 100%. */
    private function gatewayMicro(): int
    {
        $rateMicro = self::micro('payments.gateway_rate');
        $gstMicro = self::micro('payments.gateway_gst');
        $micro = intdiv($rateMicro * (self::MICRO + $gstMicro) * 2 + self::MICRO, 2 * self::MICRO);

        if ($rateMicro < 0 || $gstMicro < 0 || $micro >= self::MICRO) {
            throw new \LogicException('payments.gateway_rate × (1 + payments.gateway_gst) must be in [0, 1).');
        }

        return $micro;
    }

    /** A decimal config value (e.g. 0.18) as an integer count of millionths — the only float touch. */
    private static function micro(string $key): int
    {
        return (int) round(((float) config($key)) * self::MICRO);
    }
}
