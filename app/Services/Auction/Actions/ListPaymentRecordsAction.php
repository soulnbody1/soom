<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\AdminPaymentRecordQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPaymentRecordsAction
{
    public function __construct(
        private readonly AdminPaymentRecordQuery $query,
    ) {}

    public function execute(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->query->paginate($filters, $perPage, $page);
    }

    public function statusCounts(array $filters): array
    {
        return $this->query->statusCounts($filters);
    }
}
