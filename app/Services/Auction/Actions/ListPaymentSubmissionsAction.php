<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Repositories\Auction\Queries\PaymentSubmissionQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPaymentSubmissionsAction
{
    public function __construct(
        private readonly PaymentSubmissionQuery $query,
    ) {}

    public function execute(int $perPage): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }
}
