<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\Actions\DeleteUserAccountAction;
use App\Services\User\Actions\UpdateUserProfileAction;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'حساب المستخدم', description: 'بيانات الحساب والملف الشخصي الخاصة بالمستخدم الحالي وإجراءات إدارته.', weight: 8)]
final class ProfileController extends Controller
{
    public function __construct(
        private readonly UpdateUserProfileAction $updateProfile,
        private readonly DeleteUserAccountAction $deleteAccount,
    ) {}

    #[Endpoint(title: 'عرض الملف الشخصي', description: 'يعرض بيانات الحساب والملف الشخصي للمستخدم الحالي.')]
    #[Response(200, description: 'بيانات المستخدم الحالي.')]
    public function show(): UserResource
    {
        return new UserResource($this->currentUser());
    }

    #[Endpoint(title: 'تحديث الملف الشخصي', description: 'يحدّث البيانات المسموح بها في الملف الشخصي للمستخدم الحالي، بما في ذلك الصورة عند إرسالها.')]
    #[Response(200, description: 'بيانات المستخدم بعد التحديث.')]
    public function update(UpdateProfileRequest $request): UserResource
    {
        return new UserResource(
            $this->updateProfile->execute($this->currentUser(), $request->validated())
        );
    }

    #[Endpoint(title: 'حذف الحساب نهائيًا', description: 'يحذف حساب المستخدم الحالي نهائيًا مع البيانات والإعلانات المرتبطة به وفق سياسة حذف الحساب.')]
    #[Response(200, description: 'تم حذف الحساب والبيانات المرتبطة به نهائيًا.')]
    public function destroy(): JsonResponse
    {
        $this->deleteAccount->execute($this->currentUser());

        return response()->json([
            'message' => 'تم حذف الحساب والإعلانات المرتبطة به نهائياً.',
        ], 200);
    }

    private function currentUser(): User
    {
        return auth('sanctum')->user();
    }
}
