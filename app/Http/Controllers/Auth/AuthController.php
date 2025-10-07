<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Services\TokenService;
use App\Http\Resources\UserResource;
use App\Http\Requests\ForgetPasswordRequest;
use App\Http\Requests\Login;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\Reset_password;
use App\Http\Requests\Verify_otp;
use App\Repositories\PasswordResetTokenRepository;
use App\Services\OtpRateLimiterService;
use App\Services\OtpService;

class AuthController extends Controller
{
    protected $tokenService;
    protected $tokenRepo;

    public function __construct(TokenService $tokenService, PasswordResetTokenRepository $tokenRepo)
    {
        $this->tokenService = $tokenService;
        $this->tokenRepo = $tokenRepo;
    }

    public function login(Login $request)
    {
        $user = User::where('phone', $request->phone)->first();
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'بيانات  غير صالحة'], 431);
        }

        if (is_null($user->email_verified_at)) {
            return response()->json(['message' => 'رقم الهاتف غير مؤكد'], 430);
        }

        $tokens = $this->tokenService->createTokens($user);
        $this->FCMToken($user, $request->fcm_token);
        
        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],    
        ]);
    }

    public function register(RegisterRequest $request, OtpService $otpService)
    {
        User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
        ]);

        $result = $otpService->sendOtpToWhatsApp($request->phone);
        if ($result !== true) {
            return response()->json([
                'message' => 'حدث خطأ أثناء إرسال رمز التحقق حاول لاحقًا.',
                'error' => $result
            ], 500);
        }
        return response()->json([
            'message' => 'تم إرسال رمز التحقق إلى رقمك على واتساب',

        ], 201);
    }

    public function forget_password(ForgetPasswordRequest $request, OtpService $otpService,OtpRateLimiterService $otpLimiter)
    {
        $phone = $request->phone;
        $result = $otpLimiter->check($phone);

        if ($result) {
            return response()->json(['message' => $result['message']], $result['code']);
        }
        $result = $otpService->sendOtpToWhatsApp($phone);


        if ($result !== true) {
            return response()->json([
                'message' => 'حدث خطأ أثناء إرسال رمز التحقق حاول لاحقًا.',
                'error' => $result
            ], 500);
        }
        return response()->json([
            'message' => 'تم إرسال رمز التحقق إلى رقمك على واتساب.'
        ]);
    }

    public function reset_password(Reset_password $request)
    {
        $phone = $request->phone;
        $otp = $request->otp;

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        $otpValidation = $this->isValidOtp($phone, $otp);
        if (! $otpValidation['status']) {
            return response()->json([
                'message' => $otpValidation['message']
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        $this->tokenRepo->deleteByPhone($phone);
        $tokens = $this->tokenService->createTokens($user);
        $this->FCMToken($user, $request->fcm_token);

        return response()->json([
            'message' => 'تم إعادة تعيين كلمة المرور بنجاح.',
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'], 
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $user->tokens()->delete();
            $this->tokenService->revokeRefreshToken($user->id);
        }
        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function refreshToken(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required'
        ]);

        $tokenRecord = $this->tokenService->validateRefreshToken($request->refresh_token);

        if (! $tokenRecord) {
            return response()->json(['message' => 'رمز التحديث غير صالح أو منتهي الصلاحية'], 431);
        }

        $user = User::find($tokenRecord->user_id);

        if (! $user) {
            return response()->json(['message' => 'لم يتم العثور على المستخدم'], 404);
        }

        $this->tokenService->revokeRefreshToken($user->id);
        $tokens = $this->tokenService->createTokens($user);

        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }

    public function verify_otp(Verify_otp $request)
    {
        $phone = $request->phone;
        $otp = $request->otp;

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }
        $otpValidation = $this->isValidOtp($phone, $otp);
        if (! $otpValidation['status']) {
            return response()->json([
                'message' => $otpValidation['message']
            ], 422);
        }
        $user->update([
            'email_verified_at' => now()
        ]);
        $tokens = $this->tokenService->createTokens($user);
        $this->FCMToken($user, $request->fcm_token);
        return response()->json([
            'message' => 'رمز التحقق صحيح.',
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }

    private function isValidOtp($phone, $otp)
    {
        $token = $this->tokenRepo->getByPhone($phone);

        if (! $token || $token->token !== $otp) {
            return ['status' => false, 'message' => 'رمز التحقق غير صحيح.'];
        }

        if (Carbon::parse($token->created_at)->lt(Carbon::now()->subMinutes(15))) {
            return ['status' => false, 'message' => 'رمز التحقق منتهي الصلاحية.'];
        }

        return ['status' => true];
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $user = $request->user();
        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json([
                'message' => 'كلمة المرور القديمة غير صحيحة'
            ], 400);
        }
        $user->update([
            'password' => Hash::make($request->password)
        ]);
        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح'
        ]);
    }

    protected function FCMToken(User $user, $token)
    {
        if (!empty($token)) {
            $user->update([
                'fcm_token' => $token,
            ]);
        }
    }
}
