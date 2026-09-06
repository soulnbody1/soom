<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Login;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\UserSessionManager;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SessionController extends Controller
{
    private const DUMMY_HASH = '$2y$12$0000000000000000000000000000000000000000000000000000u';

    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly TokenService $tokenService,
    ) {}

    public function login(Login $request): JsonResponse
    {
        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            Hash::check($request->password, self::DUMMY_HASH);

            return response()->json(['message' => 'بيانات  غير صالحة'], 431);
        }

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'بيانات  غير صالحة'], 431);
        }

        if (is_null($user->email_verified_at)) {
            return response()->json(['message' => 'رقم الهاتف غير مؤكد'], 430);
        }

        $tokens = $this->sessions->issue($user, $request->fcm_token);

        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $this->sessions->revokeAll($user);
        }

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);

        $tokenRecord = $this->tokenService->validateRefreshToken($request->refresh_token);

        if (! $tokenRecord) {
            return response()->json(['message' => 'رمز التحديث غير صالح أو منتهي الصلاحية'], 431);
        }

        $user = User::find($tokenRecord->user_id);

        if (! $user) {
            return response()->json(['message' => 'لم يتم العثور على المستخدم'], 404);
        }

        $this->tokenService->revokeRefreshToken($user->id);
        $tokens = $this->sessions->issue($user);

        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }
}
