<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Services\Payments\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function index(Request $request): JsonResponse
    {
        $payouts = Payout::with('account')
            ->where('id_owner', $request->user()->id)
            ->orderByDesc('id')
            ->simplePaginate(min(100, max(1, (int) $request->integer('perPage', 25))));

        return response()->json([
            'status' => 'success',
            ...paginated(PayoutResource::collection($payouts), 'items', true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'payoutAccountId' => 'required|integer',
        ]);

        $payout = $this->withdrawals->withdraw($request->user()->id, (int) $data['amount'], (int) $data['payoutAccountId']);

        return response()->json(['status' => 'success', 'payout' => new PayoutResource($payout->load('account'))], 201);
    }
}
