<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Resources\RegistrationPaymentResource;
use App\Models\Auction;
use App\Models\RegistrationPayment;
use Illuminate\Http\JsonResponse;

/** Auction → Collections: money actually received (razorpay + approved manual). */
class CollectionController extends Controller
{
    public function index(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:'.implode(',', RegistrationPayment::COLLECTED_STATUSES),
            'perPage' => 'nullable|integer|min:1|max:100',
            'duplicate' => 'nullable|boolean',
        ]);

        $base = RegistrationPayment::where('id_auction', $auction->id)
            ->whereIn('status', RegistrationPayment::COLLECTED_STATUSES);

        $payments = (clone $base)->with('registration')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(isset($data['duplicate']), fn ($q) => $q->where('is_duplicate', (bool) $data['duplicate']))
            ->orderByDesc('id')
            ->simplePaginate((int) ($data['perPage'] ?? 25));

        $summary = (clone $base)
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS collected')
            ->selectRaw('COALESCE(SUM(refunded_amount), 0) AS refunded')
            ->selectRaw('COALESCE(SUM(organiser_net - organiser_refunded), 0) AS organiser_net')
            ->first();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'count' => (int) $summary->count,
                'collected' => (int) $summary->collected,
                'refunded' => (int) $summary->refunded,
                'organiserNet' => (int) $summary->organiser_net,
            ],
            ...paginated(RegistrationPaymentResource::collection($payments), 'items', true),
        ]);
    }
}
