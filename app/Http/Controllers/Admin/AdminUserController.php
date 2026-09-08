<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserSearchRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repositories\User\Queries\UserDirectoryQuery;
use App\Services\User\Actions\DeleteUserAccountAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserDirectoryQuery $users,
        private readonly DeleteUserAccountAction $deleteAccount,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->listing()->paginate(20));
    }

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

    public function analytics(): JsonResponse
    {
        return response()->json([
            'countUsersHasAds' => $this->users->countWithAds(),
            'countUsersNotHasAds' => $this->users->countWithoutAds(),
        ], 200);
    }

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
