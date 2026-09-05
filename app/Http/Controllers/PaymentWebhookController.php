<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionHistory;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RevenueCat webhook — the entitlement source of truth. Verifies the bearer
 * token, then reconciles purchases/expiry/refunds. The auction id is carried in
 * the purchase's subscriber attributes (metadata.auctionId) set at buy time.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly SubscriptionService $subs) {}

    public function revenueCat(Request $request): JsonResponse
    {
        $expected = config('subscription.ops_signature');
        $auth = $request->bearerToken();
        if ($expected && (! $auth || ! hash_equals($expected, $auth))) {
            return response()->json(['status' => 'error'], 401);
        }

        $event = (array) $request->input('event', []);
        $type = $event['type'] ?? null;
        $txn = $event['transaction_id'] ?? ($event['id'] ?? null);
        $userId = (int) ($event['app_user_id'] ?? 0);
        $productId = $event['product_id'] ?? '';
        $attrs = (array) ($event['subscriber_attributes'] ?? []);
        $auctionId = isset($attrs['auctionId']['value']) ? (int) $attrs['auctionId']['value'] : null;

        if (! $userId || ! $type) {
            return response()->json(['status' => 'ignored'], 200);
        }

        switch ($type) {
            case 'INITIAL_PURCHASE':
            case 'RENEWAL':
            case 'NON_RENEWING_PURCHASE':
                $this->subs->confirm($userId, $auctionId, $productId, $txn, 'play_store', ['event_type' => $type]);
                break;

            case 'EXPIRATION':
            case 'REFUND':
            case 'CANCELLATION':
                if ($txn) {
                    $sub = SubscriptionHistory::where('transaction_id', $txn)->first();
                    if ($sub) {
                        $sub->update([
                            'is_active' => false,
                            'status' => $type === 'REFUND' ? SubscriptionHistory::STATUS_REFUNDED : ($type === 'CANCELLATION' ? SubscriptionHistory::STATUS_CANCELLED : SubscriptionHistory::STATUS_EXPIRED),
                        ]);
                        $this->subs->syncAuction($sub->id_auction);
                    }
                }
                break;
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
