<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StorePayoutAccountRequest;
use App\Http\Resources\PayoutAccountResource;
use App\Models\PayoutAccount;
use App\Services\Payments\PayoutAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutAccountController extends Controller
{
    public function __construct(private readonly PayoutAccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = PayoutAccount::where('id_owner', $request->user()->id)
            ->orderByDesc('is_default')->orderByDesc('id')
            ->simplePaginate(50);

        return response()->json([
            'status' => 'success',
            ...paginated(PayoutAccountResource::collection($accounts), 'items', true),
        ]);
    }

    public function store(StorePayoutAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->create($request->user(), $request->validated());

        return response()->json(['status' => 'success', 'payoutAccount' => new PayoutAccountResource($account)], 201);
    }

    public function destroy(Request $request, PayoutAccount $payoutAccount): JsonResponse
    {
        abort_unless($payoutAccount->id_owner === $request->user()->id, 403);
        $this->accounts->delete($payoutAccount);

        return response()->json(['status' => 'success']);
    }
}
