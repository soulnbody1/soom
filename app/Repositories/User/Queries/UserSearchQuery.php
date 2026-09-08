<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\User;
use App\Services\Support\FulltextQuerySanitizer;
use Illuminate\Database\Eloquent\Builder;

final class UserSearchQuery
{
    public function __construct(private readonly FulltextQuerySanitizer $sanitizer) {}

    public function apply(?string $keyword): Builder
    {
        $query = User::withTrashed()
            ->withCount('ads')
            ->with(['country:id,name', 'state:id,name', 'city:id,name'])
            ->where('role', '!=', 'admin');

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

    private function supportsFullText(): bool
    {
        return User::query()->getConnection()->getDriverName() === 'mysql';
    }
}
