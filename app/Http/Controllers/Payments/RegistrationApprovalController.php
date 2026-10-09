<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Resources\PlayerRegistrationResource;
use App\Models\Auction;
use App\Models\PlayerRegistration;
use App\Services\Payments\RegistrationService;
use Illuminate\Http\JsonResponse;

/** Auction → Approvals (owner only). */
class RegistrationApprovalController extends Controller
{
    private const STATUSES = 'awaiting_payment,awaiting_review,completed,expired,rejected,refunded,needs_review';

    public function __construct(private readonly RegistrationService $registrations) {}

    public function index(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:'.self::STATUSES,
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $registrations = PlayerRegistration::with('latestPayment')
            ->where('id_auction', $auction->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->simplePaginate((int) ($data['perPage'] ?? 25));

        $counts = PlayerRegistration::where('id_auction', $auction->id)
            ->selectRaw('status, COUNT(*) AS count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        return response()->json([
            'status' => 'success',
            'counts' => (object) $counts,
            ...paginated(PlayerRegistrationResource::collection($registrations), 'items', true),
        ]);
    }

    public function approve(ShowAuctionRequest $request, Auction $auction, PlayerRegistration $registration): JsonResponse
    {
        abort_unless($registration->id_auction === $auction->id, 404);

        return response()->json([
            'status' => 'success',
            'registration' => new PlayerRegistrationResource($this->registrations->approve($registration)),
        ]);
    }

    public function reject(ShowAuctionRequest $request, Auction $auction, PlayerRegistration $registration): JsonResponse
    {
        abort_unless($registration->id_auction === $auction->id, 404);
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);

        return response()->json([
            'status' => 'success',
            'registration' => new PlayerRegistrationResource($this->registrations->reject($registration, $data['reason'])),
        ]);
    }
}
