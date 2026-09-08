<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\User;
use App\Services\Support\FulltextQuerySanitizer;
use Illuminate\Database\Eloquent\Builder;

final class UserDirectoryQuery
{
    public function __construct(private readonly FulltextQuerySanitizer $sanitizer) {}

    public function scope(): Builder
    {
        return User::withTrashed()->where('role', '!=', 'admin');
    }

    public function listing(?string $keyword = null): Builder
    {
        $query = $this->scope()
            ->withCount('ads')
            ->with(['country:id,name', 'state:id,name', 'city:id,name']);

        $keyword = trim((string) $keyword);

        if ($keyword === '') {
            return $query;
        }

        $expression = $this->sanitizer->toBooleanMode($keyword);
        $contains = $this->sanitizer->toLikeContains($keyword);

        return $query->where(function (Builder $group) use ($expression, $contains): void {
            if ($expression !== null && $this->supportsFullText()) {
                $group->whereRaw('MATCH(name, phone) AGAINST(? IN BOOLEAN MODE)', [$expression]);
            }

            $group->orWhere('name', 'LIKE', $contains)
                ->orWhere('phone', 'LIKE', $contains);
        });
    }

    public function countWithAds(): int
    {
        return $this->scope()->whereHas('ads')->count();
    }

    public function countWithoutAds(): int
    {
        return $this->scope()->whereDoesntHave('ads')->count();
    }

    private function supportsFullText(): bool
    {
        return User::query()->getConnection()->getDriverName() === 'mysql';
    }
}
