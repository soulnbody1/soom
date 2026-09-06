<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Services\Auth\UserSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    public function __construct(private readonly UserSessionManager $sessions) {}

    public function __invoke(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->old_password, $user->password)) {
            return response()->json(['message' => 'كلمة المرور القديمة غير صحيحة'], 400);
        }

        $user->update(['password' => Hash::make($request->password)]);
        $tokens = $this->sessions->reissue($user);

        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح',
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }
}
