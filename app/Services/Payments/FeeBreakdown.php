<?php

namespace App\Services\Payments;

/** One fee breakdown (paise). Produced only by FeeCalculator. */
final readonly class FeeBreakdown
{
    public function __construct(
        public int $baseAmount,          // B — the organiser's registration fee
        public int $platformFee,         // P
        public int $gatewayFee,          // shown to the player: T − B − P in player mode, 0 in organiser mode
        public int $totalAmount,         // T — what the player pays
        public int $organiserNet,        // estimated T − P − G(T)
        public int $estimatedGatewayFee, // G(T) = round(T × rate × (1+gst))
        public string $feeBearer,
    ) {}

    /** Organiser-facing (settings preview, fee-preview endpoint). */
    public function toArray(): array
    {
        return [
            'baseAmount' => $this->baseAmount,
            'platformFee' => $this->platformFee,
            'gatewayFee' => $this->gatewayFee,
            'totalAmount' => $this->totalAmount,
            'estimatedGatewayFee' => $this->estimatedGatewayFee,
            'organiserNet' => $this->organiserNet,
            'feeBearer' => $this->feeBearer,
            'currency' => 'INR',
        ];
    }

    /** Player-facing (no organiser net / internal estimate). */
    public function toPublicArray(): array
    {
        return [
            'baseAmount' => $this->baseAmount,
            'platformFee' => $this->platformFee,
            'gatewayFee' => $this->gatewayFee,
            'totalAmount' => $this->totalAmount,
            'feeBearer' => $this->feeBearer,
            'currency' => 'INR',
        ];
    }
}
