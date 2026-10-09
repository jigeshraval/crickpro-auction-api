<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\ShowAuctionRequest;
use App\Http\Requests\Payments\UpdateRegistrationSettingsRequest;
use App\Http\Resources\RegistrationSettingsResource;
use App\Models\Auction;
use App\Models\AuctionSetting;
use App\Repositories\AuctionSettingRepository;
use App\Services\ImageStorage;
use App\Services\Payments\FeeCalculator;
use Illuminate\Http\JsonResponse;

/** Auction → Settings → "Registration Payments" (owner only). */
class RegistrationSettingsController extends Controller
{
    private const FIELDS = [
        'registrationMode' => 'registration_mode',
        'registrationFee' => 'registration_fee',
        'feeBearer' => 'fee_bearer',
        'manualInstructions' => 'manual_instructions',
    ];

    public function __construct(
        private readonly AuctionSettingRepository $settings,
        private readonly FeeCalculator $fees,
        private readonly ImageStorage $images,
    ) {}

    public function show(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        return $this->respond($this->settings->forAuction($auction));
    }

    public function update(UpdateRegistrationSettingsRequest $request, Auction $auction): JsonResponse
    {
        $validated = $request->validated();
        $attributes = [];
        foreach (self::FIELDS as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $attributes[$column] = $validated[$field];
            }
        }

        return $this->respond($this->settings->update($auction, $attributes));
    }

    /**
     * Manual mode: the organiser's own UPI QR image. Stored LOSSLESS (lossy
     * WebP artefacts break QR scanning) and large enough to stay scannable;
     * the replaced QR object is deleted best-effort.
     */
    public function uploadQr(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        $request->validate(['file' => 'required|image|max:5120']);
        $previous = $this->settings->forAuction($auction)->manual_qr_url;
        $path = $this->images->store($request->file('file'), 'registration-qr', 1024, lossless: true);
        $this->settings->update($auction, ['manual_qr_url' => $path]);

        if ($previous && $previous !== $path) {
            $this->images->delete($previous);
        }

        return response()->json(['status' => 'success', 'url' => $path]);
    }

    /** Live preview while the organiser types a fee (before saving). */
    public function feePreview(ShowAuctionRequest $request, Auction $auction): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:'.config('payments.min_registration_fee').'|max:'.config('payments.max_registration_fee'),
            'feeBearer' => 'required|string|in:organiser,player',
        ]);

        return response()->json([
            'status' => 'success',
            'breakdown' => $this->fees->breakdown((int) $data['amount'], $data['feeBearer'])->toArray(),
        ]);
    }

    private function respond(AuctionSetting $settings): JsonResponse
    {
        $preview = $settings->registration_mode === 'razorpay' && (int) $settings->registration_fee >= (int) config('payments.min_registration_fee')
            ? $this->fees->breakdown((int) $settings->registration_fee, $settings->fee_bearer)->toArray()
            : null;

        return response()->json([
            'status' => 'success',
            'eligible' => true, // the payments.eligible middleware already 403'd ineligible auctions
            'registrationSettings' => new RegistrationSettingsResource($settings),
            'preview' => $preview,
        ]);
    }
}
