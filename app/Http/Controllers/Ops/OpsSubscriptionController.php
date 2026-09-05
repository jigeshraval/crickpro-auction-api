<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionHistoryResource;
use App\Models\SubscriptionHistory;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * crickpro-admin subscription management for auctions. Gated by X-Ops-Signature.
 * Admin can list a user's (or an auction's) subscriptions, grant one manually,
 * and revoke.
 */
class OpsSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subs) {}

    /** List by user or auction: /v1/ops/subscriptions?userId= | ?auctionId= */
    public function index(Request $request): JsonResponse
    {
        $query = SubscriptionHistory::query()->latest();
        if ($uid = $request->integer('userId')) {
            $query->where('id_user', $uid);
        }
        if ($aid = $request->integer('auctionId')) {
            $query->where('id_auction', $aid);
        }

        return response()->json([
            'status' => 'success',
            'subscriptions' => SubscriptionHistoryResource::collection($query->limit(100)->get()),
        ]);
    }

    /** Grant a subscription manually. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId' => 'required|integer|exists:users,id',
            'auctionId' => 'nullable|integer|exists:auctions,id',
            'maxTeams' => 'required|integer|min:1|max:200',
            'planId' => 'nullable|string|max:100',
            'expiresAt' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:500',
            'adminId' => 'nullable|integer',
        ]);

        $sub = $this->subs->grantManual(
            (int) ($data['adminId'] ?? 0),
            $data['userId'],
            $data['auctionId'] ?? null,
            $data['maxTeams'],
            $data['planId'] ?? null,
            isset($data['expiresAt']) ? Carbon::parse($data['expiresAt']) : null,
            $data['amount'] ?? null,
            $data['currency'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json([
            'status' => 'success',
            'subscription' => new SubscriptionHistoryResource($sub),
        ], 201);
    }

    /** Revoke (soft-delete) a subscription. */
    public function destroy(int $id): JsonResponse
    {
        $sub = SubscriptionHistory::findOrFail($id);
        $this->subs->revoke($sub);

        return response()->json(['status' => 'success']);
    }
}
