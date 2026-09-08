<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\Actions\DeleteUserAccountAction;
use App\Services\User\Actions\UpdateUserProfileAction;
use Illuminate\Http\JsonResponse;

final class ProfileController extends Controller
{
    public function __construct(
        private readonly UpdateUserProfileAction $updateProfile,
        private readonly DeleteUserAccountAction $deleteAccount,
    ) {}

    public function show(): UserResource
    {
        return new UserResource($this->currentUser());
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        return new UserResource(
            $this->updateProfile->execute($this->currentUser(), $request->validated())
        );
    }

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
