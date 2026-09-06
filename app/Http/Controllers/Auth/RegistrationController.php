<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Enums\OtpVerificationStatus;
use App\Http\Controllers\Auth\Concerns\HandlesOtpFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\Verify_otp;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\OtpManager;
use App\Services\Auth\UserSessionManager;
use App\Services\OtpRateLimiterService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegistrationController extends Controller
{
    use HandlesOtpFailures;

    public function __construct(
        private readonly OtpManager $otp,
        private readonly UserSessionManager $sessions,
    ) {}

    public function register(RegisterRequest $request, OtpService $otpService, OtpRateLimiterService $otpLimiter): JsonResponse
    {
        $limited = $otpLimiter->check($request->phone, $request->ip());

        if ($limited) {
            return response()->json(['message' => $limited['message']], $limited['code']);
        }

        $user = DB::transaction(fn (): User => User::updateOrCreate(
            ['phone' => $request->phone],
            [
                'name' => $request->name,
                'password' => Hash::make($request->password),
                'email_verified_at' => null,
            ]
        ));

        $result = $otpService->sendOtpToWhatsApp($request->phone);

        if ($result !== true) {
            $this->otp->invalidate($request->phone);
            $user->forceDelete();

            return response()->json([
                'message' => 'حدث خطأ أثناء إرسال رمز التحقق حاول لاحقًا.',
                'error' => $result,
            ], 500);
        }

        return response()->json([
            'message' => 'تم إرسال رمز التحقق إلى رقمك على واتساب',
        ], 201);
    }

    public function verify(Verify_otp $request): JsonResponse
    {
        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        $status = $this->otp->verifyAndConsume($request->phone, $request->otp);

        if ($status !== OtpVerificationStatus::Valid) {
            return $this->otpFailureResponse($status);
        }

        $user->update(['email_verified_at' => now()]);
        $tokens = $this->sessions->issue($user, $request->fcm_token);

        return response()->json([
            'message' => 'رمز التحقق صحيح.',
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }
}
