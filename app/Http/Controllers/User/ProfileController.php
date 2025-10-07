<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\User\UserSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;



class ProfileController extends Controller
{
    public function __construct(protected UserService $service) {}
    public function show()
    {
        $user = auth('sanctum')->user();
        return new UserResource($user);
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = auth('sanctum')->user();
        $updatedUser = $this->service->updateProfile($user, $request->validated());
        return new UserResource($updatedUser);
    }

    public function destroy()
    {
        $user = auth('sanctum')->user();
        $this->service->deleteAccount($user);
        return response()->json([
            'message' => 'تم حذف الحساب والإعلانات المرتبطة به نهائياً.'
        ], 200);
    }

    public function users()
    {
        $users = User::withTrashed()->where('role', '!=', 'admin')->paginate(20);
        $users->each(function ($user) {
            $user->loadCount('ads');
            $user->hasAds = $user->ads_count > 0;
        });

        return UserResource::collection($users);
    }

    public function search(Request $request, UserSearch $search)
    {
        $query = $search->apply($request);
        $users = $query->where('role', '!=', 'admin')->latest()->paginate(10);

        if ($users->total() === 0) {
            return response()->json([
                'status' => 'empty',
                'message' => 'لا يوجد مستخدمين مطابقين لهذا البحث'
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'data' => UserResource::collection($users),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total()
            ]
        ]);
    }

    public function toggleBlock($id)
    {
        $user = User::withTrashed()->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'المستخدم غير موجود.'
            ], 404);
        }

        if ($user->trashed()) {
            // Restore the soft-deleted user
            $user->restore();
            return response()->json([
                'message' => 'تم استرجاع المستخدم بنجاح.'
            ], 200);
        } else {
            // Soft-delete the user
            $user->delete();
            return response()->json([
                'message' => 'تم حظر المستخدم .'
            ], 200);
        }
    }

    public function destroybyadmin($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'message' => 'المستخدم غير موجود.'
            ], 404);
        }
        $this->service->deleteAccount($user);


        return response()->json([
            'message' => 'تم حذف الحساب والإعلانات المرتبطة به نهائياً.'
        ], 200);
    }

    public function analytics()
    {
        $countUsersHasAds = User::whereHas('ads')->count();
        $countUsersNotHasAds = User::whereDoesntHave('ads')->count();
        return response()->json([
            'countUsersHasAds' => $countUsersHasAds,
            'countUsersNotHasAds' => $countUsersNotHasAds
        ], 200);
    }
}
