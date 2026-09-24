<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserSearchRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repositories\User\Queries\UserDirectoryQuery;
use App\Services\User\Actions\DeleteUserAccountAction;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'إدارة المستخدمين', description: 'استعراض حسابات المستخدمين والبحث فيها ومتابعة إحصاءاتها وحالات الحظر والحذف النهائي.', weight: 19)]
final class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserDirectoryQuery $users,
        private readonly DeleteUserAccountAction $deleteAccount,
    ) {}

    #[Endpoint(title: 'عرض المستخدمين', description: 'يعرض حسابات المستخدمين مرتبة ومقسّمة إلى صفحات داخل لوحة الإدارة.')]
    #[Response(200, description: 'قائمة المستخدمين وبيانات الصفحات.')]
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->listing()->paginate(20));
    }

    #[Endpoint(title: 'البحث عن مستخدمين', description: 'يبحث في دليل المستخدمين بالكلمة المرسلة ويعيد النتائج من الأحدث إلى الأقدم.')]
    #[Response(200, description: 'نتائج البحث وبيانات الصفحات، أو نتيجة فارغة عند عدم وجود تطابق.')]
    public function search(AdminUserSearchRequest $request): JsonResponse
    {
        $users = $this->users->listing($request->keyword())->latest()->paginate(10);

        if ($users->total() === 0) {
            return response()->json([
                'status' => 'empty',
                'message' => 'لا يوجد مستخدمين مطابقين لهذا البحث',
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'data' => UserResource::collection($users),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    #[Endpoint(title: 'عرض إحصاءات المستخدمين', description: 'يعرض عدد المستخدمين الذين لديهم إعلانات وعدد المستخدمين الذين لا يملكون إعلانات.')]
    #[Response(200, description: 'ملخص إحصاءات نشاط المستخدمين.')]
    public function analytics(): JsonResponse
    {
        return response()->json([
            'countUsersHasAds' => $this->users->countWithAds(),
            'countUsersNotHasAds' => $this->users->countWithoutAds(),
        ], 200);
    }

    #[Endpoint(title: 'حظر مستخدم أو استعادته', description: 'يبدّل حالة المستخدم بين الحظر والنشاط وفق صلاحيات المشرف.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمستخدم.')]
    #[Response(200, description: 'تم تحديث حالة المستخدم بنجاح.')]
    #[Response(404, description: 'المستخدم غير موجود.')]
    public function toggleBlock(string $id): JsonResponse
    {
        $user = User::withTrashed()->find($id);

        if (! $user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        Gate::authorize('block', $user);

        if ($user->trashed()) {
            $user->restore();

            return response()->json(['message' => 'تم استرجاع المستخدم بنجاح.'], 200);
        }

        $user->delete();

        return response()->json(['message' => 'تم حظر المستخدم .'], 200);
    }

    #[Endpoint(title: 'حذف حساب مستخدم نهائيًا', description: 'يحذف حساب المستخدم نهائيًا مع البيانات والإعلانات المرتبطة به وفق سياسة حذف الحساب.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمستخدم.')]
    #[Response(200, description: 'تم حذف الحساب والبيانات المرتبطة به نهائيًا.')]
    #[Response(404, description: 'المستخدم غير موجود.')]
    public function forceDelete(string $id): JsonResponse
    {
        $user = User::withTrashed()->find($id);

        if (! $user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        Gate::authorize('forceDelete', $user);

        $this->deleteAccount->execute($user);

        return response()->json([
            'message' => 'تم حذف الحساب والإعلانات المرتبطة به نهائياً.',
        ], 200);
    }
}
