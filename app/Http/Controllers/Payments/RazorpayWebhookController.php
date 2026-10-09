<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentRuleException;
use App\Services\Payments\WebhookRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Razorpay (PG) and RazorpayX webhooks. No auth — HMAC-SHA256 of the RAW body
 * with the dashboard's webhook secret (X-Razorpay-Signature). Each event is
 * stored once by (source, X-Razorpay-Event-Id); processed duplicates are 200
 * no-ops. An unprocessed duplicate is reclaimed and re-run once its row is
 * older than payments.webhook_reclaim_seconds (crashed worker), else 500 so
 * Razorpay retries later.
 * Business-rule rejections are logged and acknowledged (200) so Razorpay
 * doesn't retry them; unexpected exceptions un-store the event and return 500
 * so Razorpay's retry gets another chance.
 */
class RazorpayWebhookController extends Controller
{
    public function __construct(private readonly WebhookRouter $router) {}

    public function gateway(Request $request): JsonResponse
    {
        return $this->receive($request, 'razorpay', config('payments.razorpay.webhook_secret'));
    }

    public function payouts(Request $request): JsonResponse
    {
        return $this->receive($request, 'razorpayx', config('payments.razorpayx.webhook_secret'));
    }

    private function receive(Request $request, string $source, ?string $secret): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $secret || $signature === '' || ! hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
            Log::warning('Razorpay webhook signature rejected', ['source' => $source, 'ip' => $request->ip()]);

            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 401);
        }

        $body = json_decode($raw, true);
        if (! is_array($body) || empty($body['event'])) {
            return response()->json(['status' => 'error', 'message' => 'Malformed payload.'], 400);
        }

        $event = (string) $body['event'];
        $eventId = (string) ($request->header('X-Razorpay-Event-Id') ?: 'sha256:'.hash('sha256', $raw));

        $inserted = DB::table('razorpay_webhook_events')->insertOrIgnore([
            'source' => $source,
            'event_id' => $eventId,
            'type' => Str::limit($event, 64, ''),
            'payload' => $raw,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = DB::table('razorpay_webhook_events')->where('source', $source)->where('event_id', $eventId);

        if ($inserted === 0) {
            $existing = (clone $stored)->first(['processed_at']);
            if (! $existing || $existing->processed_at !== null) {
                return response()->json(['status' => 'ok', 'duplicate' => true]);
            }

            // Stored but never finished: the worker that claimed it died (or is
            // still running). Reclaim it only once it is stale — conditionally,
            // so exactly one retry wins — otherwise ask Razorpay to retry later.
            $staleBefore = now()->subSeconds((int) config('payments.webhook_reclaim_seconds', 120));
            $reclaimed = (clone $stored)->whereNull('processed_at')->where('updated_at', '<', $staleBefore)
                ->update(['updated_at' => now()]);

            if ($reclaimed !== 1) {
                return response()->json(['status' => 'error', 'message' => 'Event is being processed.'], 500);
            }
        }

        try {
            // NO DB::transaction() here: every money handler opens its own
            // WalletService::withLock(), which must be the OUTERMOST transaction
            // (an outer REPEATABLE READ snapshot taken before the wallet lock
            // would make balances()/status reads inside the handler stale).
            // Handlers are idempotent, so a retry after a partial failure is safe.
            $result = $this->router->dispatch($source, $event, (array) ($body['payload'] ?? []));
        } catch (PaymentRuleException $e) {
            $result = 'rejected: '.$e->getMessage();
            Log::warning('Razorpay webhook rejected by business rule', ['event' => $event, 'eventId' => $eventId, 'reason' => $e->getMessage()]);
        } catch (\Throwable $e) {
            (clone $stored)->delete();
            report($e);

            return response()->json(['status' => 'error'], 500);
        }

        (clone $stored)->update(['processed_at' => now(), 'result' => Str::limit($result, 250, ''), 'updated_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
