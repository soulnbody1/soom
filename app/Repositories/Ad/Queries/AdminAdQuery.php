<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class AdminAdQuery
{
    private const PER_PAGE = 20;

    public function paginateFeatured(?string $status): LengthAwarePaginator
    {
        return $this->applyStatus(Ad::query()->withTrashed()->featured(), $status)
            ->with(Ad::$defaultRelations)
            ->paginate(self::PER_PAGE);
    }

    public function paginateSearch(Builder $query, ?string $status): LengthAwarePaginator
    {
        return $this->applyStatus($query->withTrashed(), $status)
            ->with(Ad::$defaultRelations)
            ->latest()
            ->paginate(self::PER_PAGE);
    }

    public function activeCount(): int
    {
        return Ad::query()->count();
    }

    private function applyStatus(Builder $query, ?string $status): Builder
    {
        return $query
            ->when($status === 'active', fn (Builder $q): Builder => $q->whereNull('deleted_at'))
            ->when($status === 'inactive', fn (Builder $q): Builder => $q->whereNotNull('deleted_at'));
    }
}
