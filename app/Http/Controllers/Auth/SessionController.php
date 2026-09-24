<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Login;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\UserSessionManager;
use App\Services\TokenService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

#[Group(name: 'المصادقة والحساب', description: 'إنشاء الحساب وتسجيل الدخول وإدارة الجلسات وكلمة المرور والتحقق من رقم الهاتف.', weight: 18)]
class SessionController extends Controller
{
    private const DUMMY_HASH = '$2y$12$0000000000000000000000000000000000000000000000000000u';

    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly TokenService $tokenService,
    ) {}

    #[Endpoint(title: 'تسجيل الدخول', description: 'يتحقق من بيانات الحساب وتأكيد رقم الهاتف ثم يصدر رمزي الوصول والتحديث.')]
    #[Response(200, description: 'بيانات المستخدم ورموز الجلسة.')]
    #[Response(430, description: 'رقم الهاتف غير مؤكد.')]
    #[Response(431, description: 'بيانات تسجيل الدخول غير صحيحة.')]
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

    #[Endpoint(title: 'تسجيل الخروج', description: 'يلغي جميع رموز الجلسة النشطة للمستخدم الحالي.')]
    #[Response(200, description: 'تم تسجيل الخروج بنجاح.')]
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $this->sessions->revokeAll($user);
        }

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    #[Endpoint(title: 'تجديد جلسة الدخول', description: 'يستهلك رمز التحديث الصالح ويصدر زوجًا جديدًا من رموز الوصول والتحديث.')]
    #[BodyParameter('refresh_token', description: 'رمز التحديث الصادر عند تسجيل الدخول.', required: true, type: 'string')]
    #[Response(200, description: 'بيانات المستخدم ورموز الجلسة الجديدة.')]
    #[Response(431, description: 'رمز التحديث غير صالح أو منتهي الصلاحية.')]
    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);

        $tokenRecord = $this->tokenService->consumeRefreshToken($request->refresh_token);

        if (! $tokenRecord) {
            return response()->json(['message' => 'رمز التحديث غير صالح أو منتهي الصلاحية'], 431);
        }

        $user = User::find($tokenRecord->user_id);

        if (! $user) {
            return response()->json(['message' => 'لم يتم العثور على المستخدم'], 404);
        }

        $tokens = $this->sessions->issue($user);

        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }
}
