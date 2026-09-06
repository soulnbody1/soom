<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Enums\OtpVerificationStatus;
use App\Http\Controllers\Auth\Concerns\HandlesOtpFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgetPasswordRequest;
use App\Http\Requests\Reset_password;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\OtpManager;
use App\Services\Auth\UserSessionManager;
use App\Services\OtpRateLimiterService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class PasswordResetController extends Controller
{
    use HandlesOtpFailures;

    public function __construct(
        private readonly OtpManager $otp,
        private readonly UserSessionManager $sessions,
    ) {}

    public function sendCode(ForgetPasswordRequest $request, OtpService $otpService, OtpRateLimiterService $otpLimiter): JsonResponse
    {
        $limited = $otpLimiter->check($request->phone, $request->ip());

        if ($limited) {
            return response()->json(['message' => $limited['message']], $limited['code']);
        }

        $result = $otpService->sendOtpToWhatsApp($request->phone);

        if ($result !== true) {
            return response()->json([
                'message' => 'حدث خطأ أثناء إرسال رمز التحقق حاول لاحقًا.',
                'error' => $result,
            ], 500);
        }

        return response()->json([
            'message' => 'تم إرسال رمز التحقق إلى رقمك على واتساب.',
        ]);
    }

    public function reset(Reset_password $request): JsonResponse
    {
        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        $status = $this->otp->verifyAndConsume($request->phone, $request->otp);

        if ($status !== OtpVerificationStatus::Valid) {
            return $this->otpFailureResponse($status);
        }

        $user->update(['password' => Hash::make($request->password)]);
        $tokens = $this->sessions->reissue($user, $request->fcm_token);

        return response()->json([
            'message' => 'تم إعادة تعيين كلمة المرور بنجاح.',
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }
}
