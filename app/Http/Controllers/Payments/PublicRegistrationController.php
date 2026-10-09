<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicRegistrationResource;
use App\Models\PlayerRegistration;
use App\Services\Payments\QrPollingService;
use App\Services\Payments\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public — the registration's public_token (40 random chars) is the credential. */
class PublicRegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrations,
        private readonly QrPollingService $polling,
    ) {}

    public function show(string $registrationToken): JsonResponse
    {
        $this->polling->refreshForStatus($this->find($registrationToken));

        return $this->respond($this->find($registrationToken));
    }

    /** "Generate new QR" / "Retry" — idempotent while a QR is still open. */
    public function newQr(string $registrationToken): JsonResponse
    {
        $registration = $this->find($registrationToken);

        abort_unless($registration->mode === PlayerRegistration::MODE_RAZORPAY, 409, 'This registration does not use online payment.');

        if ($registration->latestPayment?->hasOpenQr()) {
            return $this->respond($registration);
        }

        abort_unless($registration->canGenerateQr(), 409, 'This registration is already '.str_replace('_', ' ', $registration->status).'.');

        $this->registrations->issueQr($registration);

        return $this->respond($this->find($registrationToken));
    }

    public function proof(Request $request, string $registrationToken): JsonResponse
    {
        $registration = $this->find($registrationToken);

        abort_unless(
            $registration->mode === PlayerRegistration::MODE_MANUAL && $registration->status === PlayerRegistration::STATUS_AWAITING_REVIEW,
            409,
            'Payment proof can no longer be submitted for this registration.',
        );

        $data = $request->validate([
            'file' => 'required|image|max:5120',
            'utr' => 'nullable|string|max:40',
        ]);

        $this->registrations->submitProof($registration, $request->file('file'), $data['utr'] ?? null);

        return $this->respond($this->find($registrationToken));
    }

    private function find(string $token): PlayerRegistration
    {
        return PlayerRegistration::where('public_token', $token)
            ->with(['auction.settings', 'latestPayment'])
            ->firstOr(fn () => abort(404, 'Registration not found.'));
    }

    private function respond(PlayerRegistration $registration): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            ...(new PublicRegistrationResource($registration))->resolve(request()),
        ]);
    }
}
