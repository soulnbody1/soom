<?php

namespace App\Services;

use App\Models\AdImage;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;


class UserService
{
    public function __construct(protected UserRepository $repository) {}

    public function updateProfile(User $user, array $data): User
    {
        unset($data['phone']);
        if (isset($data['logo'])) {
            if ($user->logo && Storage::disk('spaces')->exists($user->logo)) {
                $baseUrl = rtrim(env('DO_SPACES_URL'), '/') . '/';
                $path = str_replace($baseUrl, '', $user->logo);
                Storage::disk('spaces')->delete($path);
            }
            $data['logo'] = $data['logo']->store('users', 'spaces');
        }
        return $this->repository->update($user, $data);
    }

    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $adImagePaths = $user->ads()
                ->with('images:id,ad_id,image_path')
                ->get()
                ->pluck('images.*.image_path')
                ->flatten()
                ->filter()
                ->toArray();

            if (!empty($adImagePaths)) {
                $baseUrl = rtrim(env('DO_SPACES_URL'), '/') . '/';
                $paths = array_map(function ($path) use ($baseUrl) {return str_replace($baseUrl, '', $path);}, $adImagePaths);
                Storage::disk('spaces')->delete($paths);
            }


            AdImage::whereIn('ad_id', $user->ads()->pluck('id'))->delete();

            $user->ads()->delete();

            if ($user->logo) {
                $baseUrl = rtrim(env('DO_SPACES_URL'), '/') . '/';
                $path = str_replace($baseUrl, '', $user->logo);
                Storage::disk('spaces')->delete($path);
            }

            $user->tokens()->delete();

            $user->forceDelete();
        });
    }
}
