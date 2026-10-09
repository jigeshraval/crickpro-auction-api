<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationPaymentResource;
use App\Models\RegistrationPayment;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Collections → payment → Refund (owner only). Omit amount for a full refund. 202 = outcome pending. */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(Request $request, RegistrationPayment $payment): JsonResponse
    {
        abort_unless($payment->id_owner === $request->user()->id, 403);

        $data = $request->validate(['amount' => 'nullable|integer|min:1']);
        $refund = $this->refunds->refundByOrganiser($payment, isset($data['amount']) ? (int) $data['amount'] : null);

        if ($refund === null) {
            // Gateway outcome unknown (timeout / 5xx) — never report it as failed;
            // the refund.created webhook books it if Razorpay created it.
            return response()->json([
                'status' => 'pending',
                'message' => 'Refund is being processed.',
                'payment' => new RegistrationPaymentResource($payment->fresh('registration')),
            ], 202);
        }

        return response()->json([
            'status' => 'success',
            'refund' => [
                'id' => $refund->id,
                'razorpayRefundId' => $refund->razorpay_refund_id,
                'amount' => $refund->amount,
                'organiserDebit' => $refund->organiser_debit,
                'status' => $refund->status,
            ],
            'payment' => new RegistrationPaymentResource($payment->fresh('registration')),
        ]);
    }
}
