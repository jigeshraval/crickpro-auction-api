<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CheckUserMobileRequest;
use App\Http\Requests\Auth\CheckUserRequest;
use App\Http\Requests\Auth\LoginPasswordMobileRequest;
use App\Http\Requests\Auth\LoginPasswordRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordMobileRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendEmailOtpRequest;
use App\Http\Requests\Auth\SendWhatsappOtpRequest;
use App\Http\Requests\Auth\VerifyEmailOtpRequest;
use App\Http\Requests\Auth\VerifyResetOtpMobileRequest;
use App\Http\Requests\Auth\VerifyWhatsappOtpRequest;
use App\Http\Resources\UserResource;
use App\Mail\OtpMail;
use App\Models\Auction;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Repositories\AuthRepository;
use App\Services\Auth\AuthTokenService;
use App\Services\WhatsAppOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    private array $blockedEmailDomains = [
        'tempmail.com', 'throwaway.email', 'guerrillamail.com', 'mailinator.com',
        'temp-mail.org', '10minutemail.com', 'fakeinbox.com', 'trashmail.com',
        'getnada.com', 'yopmail.com', 'maildrop.cc', 'dispostable.com',
    ];

    public function __construct(
        private readonly AuthRepository $auth,
        private readonly AuthTokenService $tokens,
        private readonly WhatsAppOtpService $whatsapp,
    ) {}

    public function checkUser(CheckUserRequest $request): JsonResponse
    {
        $email = $this->sanitizeEmail($request->email);

        if ($this->isDisposableEmail($email)) {
            return $this->error('Disposable email addresses are not allowed', 422);
        }

        $user = $this->auth->findByEmail($email);

        if ($blocked = $this->blockedResponse($user)) {
            return $blocked;
        }

        return response()->json([
            'status' => 'success',
            'userExists' => (bool) $user,
            // Accounts an organiser pre-adds have no password — route them to
            // OTP/set-password instead of a dead-end login form.
            'hasPassword' => $user ? ! empty($user->password) : false,
        ]);
    }

    public function checkUserMobile(CheckUserMobileRequest $request): JsonResponse
    {
        $mobile = $this->formatMobile($request->mobile, $request->phoneCode);
        $user = $this->auth->findByMobile($mobile);

        if ($blocked = $this->blockedResponse($user)) {
            return $blocked;
        }

        return response()->json([
            'status' => 'success',
            'userExists' => (bool) $user,
            'hasPassword' => $user ? ! empty($user->password) : false,
        ]);
    }

    public function sendEmailOtp(SendEmailOtpRequest $request): JsonResponse
    {
        $email = $this->sanitizeEmail($request->email);

        if ($this->isDisposableEmail($email)) {
            return $this->error('Disposable email addresses are not allowed', 422);
        }

        $user = $this->auth->findByEmail($email);
        $otp = $this->generateOtp();

        $this->auth->expirePriorCodes($email, PasswordResetCode::CHANNEL_EMAIL);
        $this->auth->storeCode($user?->id, $email, $otp, PasswordResetCode::CHANNEL_EMAIL, now()->addMinutes(10));

        if (config('services.email.send_enabled', true)) {
            try {
                Mail::to($email)->send(new OtpMail($otp));
            } catch (\Throwable $e) {
                Log::error('Failed to send OTP email', ['email' => $email, 'error' => $e->getMessage()]);

                return $this->error('Failed to send OTP email. Please try again.', 500);
            }
        }

        $response = ['status' => 'success', 'message' => 'OTP sent successfully'];

        if (app()->environment('local', 'testing')) {
            $response['debugOtp'] = $otp;
        }

        return response()->json($response);
    }

    public function verifyEmailOtp(VerifyEmailOtpRequest $request): JsonResponse
    {
        $email = $this->sanitizeEmail($request->email);

        $resetCode = $this->auth->findValidCode($email, $request->otp, PasswordResetCode::CHANNEL_EMAIL);

        if (! $resetCode) {
            return $this->error('Invalid or expired OTP', 422);
        }

        $this->auth->consumeCode($resetCode);

        $user = $this->auth->findByEmail($email);

        if (! $user) {
            return response()->json([
                'status' => 'success',
                'message' => 'OTP verified - proceed to registration',
                'userExists' => false,
            ]);
        }

        $this->auth->markEmailVerified($user);
        $token = $this->tokens->issue($user);

        return response()->json([
            'status' => 'success',
            'message' => 'OTP verified successfully',
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ]);
    }

    public function sendWhatsappOtp(SendWhatsappOtpRequest $request): JsonResponse
    {
        $mobile = $this->formatMobile($request->mobile, $request->phoneCode);
        $otp = $this->generateOtp();

        $this->auth->expirePriorCodes($mobile, PasswordResetCode::CHANNEL_WHATSAPP);
        $this->auth->storeCode(
            $this->auth->findByMobile($mobile)?->id,
            $mobile,
            $otp,
            PasswordResetCode::CHANNEL_WHATSAPP,
            now()->addMinutes(5)
        );

        $this->whatsapp->sendOtp($mobile, $otp);

        $response = ['status' => 'success', 'message' => 'OTP sent via WhatsApp'];

        if (app()->environment('local', 'testing')) {
            $response['debugOtp'] = $otp;
        }

        return response()->json($response);
    }

    /**
     * Verify WhatsApp OTP for registration phone verification. Marks the
     * code used immediately — this is the terminal step of that flow,
     * unlike verifyResetOtpMobile below (see its docblock).
     */
    public function verifyWhatsappOtp(VerifyWhatsappOtpRequest $request): JsonResponse
    {
        $mobile = $this->formatMobile($request->mobile, $request->phoneCode);

        $resetCode = $this->auth->findValidCode($mobile, $request->otp, PasswordResetCode::CHANNEL_WHATSAPP);

        if (! $resetCode) {
            return $this->error('Invalid or expired verification code', 422);
        }

        $this->auth->consumeCode($resetCode);

        return response()->json([
            'status' => 'success',
            'message' => 'Phone number verified successfully',
        ]);
    }

    public function loginPassword(LoginPasswordRequest $request): JsonResponse
    {
        $user = $this->auth->findByEmail($this->sanitizeEmail($request->email));

        return $this->attemptLogin($user, $request->password);
    }

    public function loginPasswordMobile(LoginPasswordMobileRequest $request): JsonResponse
    {
        $mobile = $this->formatMobile($request->mobile, $request->phoneCode);
        $user = $this->auth->findByMobile($mobile);

        return $this->attemptLogin($user, $request->password);
    }

    private function attemptLogin(?User $user, string $password): JsonResponse
    {
        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            return $this->error('Invalid credentials', 400);
        }

        if ($blocked = $this->blockedResponse($user)) {
            return $blocked;
        }

        $token = $this->tokens->issue($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * Verify a password-reset OTP WITHOUT consuming it — a pre-check step.
     * The actual consumption happens in resetPasswordMobile (the final step),
     * which re-verifies the same code. Do not mark used here.
     */
    public function verifyResetOtpMobile(VerifyResetOtpMobileRequest $request): JsonResponse
    {
        $mobile = $this->formatMobile($request->mobile, $request->phoneCode);

        $resetCode = $this->auth->findValidCode($mobile, $request->otp, PasswordResetCode::CHANNEL_WHATSAPP);

        if (! $resetCode) {
            return $this->error('Invalid or expired verification code', 422);
        }

        return response()->json(['status' => 'success', 'message' => 'Verification successful']);
    }

    public function resetPasswordMobile(ResetPasswordMobileRequest $request): JsonResponse
    {
        if ($errors = $this->isStrongPassword($request->password)) {
            return $this->error(implode('. ', $errors), 422);
        }

        $mobile = $this->formatMobile($request->mobile, $request->phoneCode);

        $resetCode = $this->auth->findValidCode($mobile, $request->otp, PasswordResetCode::CHANNEL_WHATSAPP);

        if (! $resetCode) {
            return $this->error('Invalid or expired verification code', 422);
        }

        $user = $this->auth->findByMobile($mobile);

        if (! $user) {
            return $this->error('User not found', 404);
        }

        $this->auth->updatePassword($user, $request->password);
        $this->auth->consumeCode($resetCode);

        $token = $this->tokens->issue($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully',
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        if ($errors = $this->isStrongPassword($request->password)) {
            return $this->error(implode('. ', $errors), 422);
        }

        $email = $this->sanitizeEmail($request->email);

        $resetCode = $this->auth->findValidCode($email, $request->otp, PasswordResetCode::CHANNEL_EMAIL);

        if (! $resetCode) {
            return $this->error('Invalid or expired verification code', 422);
        }

        $user = $this->auth->findByEmail($email);

        if (! $user) {
            return $this->error('User not found', 404);
        }

        $this->auth->updatePassword($user, $request->password);
        $this->auth->consumeCode($resetCode);

        $token = $this->tokens->issue($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully',
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        if (! $request->termsAccepted || $request->termsAccepted === '0') {
            return $this->error('You must accept the terms and conditions', 422);
        }

        if ($errors = $this->isStrongPassword($request->password)) {
            return $this->error(implode('. ', $errors), 422);
        }

        $email = $request->email ? $this->sanitizeEmail($request->email) : null;

        if ($email && $this->isDisposableEmail($email)) {
            return $this->error('Disposable email addresses are not allowed', 422);
        }

        $mobile = $request->mobile ? $this->formatMobile($request->mobile, $request->phoneCode) : null;

        $conflicts = $this->auth->findRegistrationConflicts($email, $mobile);
        ['claimable' => $claimable, 'existingByEmail' => $existingByEmail, 'existingByMobile' => $existingByMobile] = $conflicts;

        $isClaimable = static fn (?User $u) => $u && empty($u->password) && ! $u->verified;

        if ($existingByEmail && ! $isClaimable($existingByEmail) && (! $claimable || $existingByEmail->id !== $claimable->id)) {
            return $this->error('Email already registered', 422);
        }

        if ($existingByMobile && ! $isClaimable($existingByMobile) && (! $claimable || $existingByMobile->id !== $claimable->id)) {
            return $this->error('Mobile number already registered', 422);
        }

        $user = $this->auth->createOrClaimUser($claimable, [
            'name' => $request->name,
            'email' => $email,
            'mobile' => $mobile,
            'password' => Hash::make($request->password),
            'verified' => true,
            'email_verified_at' => $email ? now() : null,
        ]);

        if ($request->hasFile('profileImage')) {
            $path = $request->file('profileImage')->store('profile-images/'.$user->id, 'public');
            $user->profile_image = $path;
            $user->save();
        }

        $token = $this->tokens->issue($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Registration successful',
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ], 201);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'user' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'success', 'message' => 'Logged out']);
    }

    /**
     * Permanent account + data deletion (Play/App Store requirement). Revokes
     * every session, soft-deletes the user's auctions (which hides all nested
     * teams/players/overlays from the app + API), scrubs all personal data so
     * nothing identifiable remains, then soft-deletes the account. Irreversible.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            // Revoke all sessions/tokens across every device + client.
            $user->tokens()->delete();

            // Remove the user's owned auctions (soft delete cascades visibility
            // of their teams/players/overlays through the ownership scope).
            Auction::where('id_owner', $user->id)->get()->each->delete();

            // Scrub PII + block re-use of the identity, then soft-delete.
            $user->forceFill([
                'name' => 'Deleted User',
                'email' => 'deleted+'.$user->id.'@crickpro.deleted',
                'mobile' => null,
                'masked_mobile' => null,
                'profile_image' => null,
                'password' => null,
                'uid' => null,
                'status' => 4, // deleted
            ])->save();

            $user->delete();
        });

        return response()->json(['status' => 'success', 'message' => 'Your account and data have been deleted']);
    }

    private function blockedResponse(?User $user): ?JsonResponse
    {
        if ($user && (int) $user->status === 3) {
            return $this->error($user->block_reason ?: 'Your account is blocked. Please contact support.', 403);
        }

        return null;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function formatMobile(string $mobile, string $phoneCode): string
    {
        return '+'.$phoneCode.$mobile;
    }

    private function sanitizeEmail(string $email): string
    {
        return strtolower(trim((string) filter_var($email, FILTER_SANITIZE_EMAIL)));
    }

    private function isDisposableEmail(string $email): bool
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);

        return in_array(strtolower($domain), $this->blockedEmailDomains, true);
    }

    /**
     * @return array<int, string> Empty when the password is strong enough.
     */
    private function isStrongPassword(string $password): array
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }

        if (! preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        $commonPasswords = ['password', '12345678', 'qwerty123', 'password123'];
        if (in_array(strtolower($password), $commonPasswords, true)) {
            $errors[] = 'Please choose a stronger password';
        }

        return $errors;
    }
}
