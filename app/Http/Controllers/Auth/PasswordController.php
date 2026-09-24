<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Services\Auth\UserSessionManager;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

#[Group(name: 'المصادقة والحساب', description: 'إنشاء الحساب وتسجيل الدخول وإدارة الجلسات وكلمة المرور والتحقق من رقم الهاتف.', weight: 18)]
class PasswordController extends Controller
{
    public function __construct(private readonly UserSessionManager $sessions) {}

    #[Endpoint(title: 'تغيير كلمة المرور', description: 'يتحقق من كلمة المرور الحالية ثم يعيّن كلمة مرور جديدة ويصدر رموز جلسة بديلة.')]
    #[Response(200, description: 'تم تغيير كلمة المرور وإصدار رموز جلسة جديدة.')]
    #[Response(400, description: 'كلمة المرور الحالية غير صحيحة.')]
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
