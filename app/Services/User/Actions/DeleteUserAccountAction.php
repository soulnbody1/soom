<?php

declare(strict_types=1);

namespace App\Services\User\Actions;

use App\Exceptions\User\AccountDeletionBlockedException;
use App\Models\AdImage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeleteUserAccountAction
{
    private const INTEGRITY_VIOLATION = '23000';

    public function execute(User $user): void
    {
        $paths = $this->storagePaths($user);

        try {
            DB::transaction(function () use ($user): void {
                $adIds = $user->ads()->withTrashed()->pluck('id');

                AdImage::whereIn('ad_id', $adIds)->delete();
                $user->ads()->withTrashed()->forceDelete();
                $user->tokens()->delete();
                $user->forceDelete();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::INTEGRITY_VIOLATION) {
                throw new AccountDeletionBlockedException;
            }

            throw $exception;
        }

        if ($paths !== []) {
            Storage::disk('spaces')->delete($paths);
        }
    }

    /**
     * @return list<string>
     */
    private function storagePaths(User $user): array
    {
        $adIds = $user->ads()->withTrashed()->pluck('id');

        $paths = DB::table('ad_images')
            ->whereIn('ad_id', $adIds)
            ->pluck('image_path')
            ->all();

        $paths[] = $user->getRawOriginal('logo');

        return array_values(array_filter(array_map(
            static fn (?string $path): string => ltrim((string) $path, '/'),
            $paths
        )));
    }
}
