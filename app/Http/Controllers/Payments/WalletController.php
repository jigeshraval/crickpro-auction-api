<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletLedgerResource;
use App\Models\PayoutAccount;
use App\Models\WalletLedger;
use App\Services\Payments\FeeCalculator;
use App\Services\Payments\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Profile → Wallet (the signed-in organiser's own balance). */
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly FeeCalculator $fees,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $ownerId = $request->user()->id;
        $balances = $this->wallet->balances($ownerId);
        $byType = WalletLedger::where('id_owner', $ownerId)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');
        $sum = fn (array $types): int => (int) collect($types)->sum(fn ($t) => (int) ($byType[$t] ?? 0));
        $minWithdrawal = (int) config('payments.min_withdrawal');

        return response()->json([
            'status' => 'success',
            'wallet' => [
                ...$balances,
                'currency' => 'INR',
                'minWithdrawal' => $minWithdrawal,
                'payoutFee' => $this->fees->payoutFee(),
                'canWithdraw' => $balances['available'] >= $minWithdrawal,
                'hasVerifiedPayoutAccount' => PayoutAccount::where('id_owner', $ownerId)->where('status', PayoutAccount::STATUS_VERIFIED)->exists(),
                'totals' => [
                    'collected' => $sum([WalletLedger::TYPE_COLLECTION]),
                    'refunded' => -$sum([WalletLedger::TYPE_REFUND, WalletLedger::TYPE_REFUND_REVERSAL]),
                    'disputes' => -$sum([WalletLedger::TYPE_DISPUTE_HOLD, WalletLedger::TYPE_DISPUTE_RELEASE, WalletLedger::TYPE_DISPUTE_LOSS]),
                    'withdrawn' => -$sum([WalletLedger::TYPE_PAYOUT, WalletLedger::TYPE_PAYOUT_REVERSAL]),
                ],
            ],
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $entries = WalletLedger::where('id_owner', $request->user()->id)
            ->orderByDesc('id')
            ->simplePaginate(min(100, max(1, (int) $request->integer('perPage', 25))));

        return response()->json([
            'status' => 'success',
            ...paginated(WalletLedgerResource::collection($entries), 'items', true),
        ]);
    }
}
