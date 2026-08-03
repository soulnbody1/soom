<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\MyRefundResource;
use App\Http\Resources\Auction\UserAuctionResource;
use App\Repositories\Auction\Queries\MyParticipationQuery;
use App\Repositories\Auction\Queries\MyRefundQuery;
use App\Services\Auction\Support\ParticipationStateResolver;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MyParticipationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly ParticipationStateResolver $participation,
    ) {}

    public function index(Request $request, MyParticipationQuery $query): JsonResponse
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'active', 'won', 'lost', 'pending_payment', 'handover', 'refunds'])],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_column(AuctionStatus::cases(), 'value'))],
            'sort' => ['nullable', Rule::in(['latest', 'starting_soon', 'ending_soon'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $query->paginate(
            (int) $request->user()->id,
            $filters,
            min(100, max(1, (int) $request->input('per_page', 20)))
        );

        $this->participation->forCollection($paginator->items(), $request->user());

        return $this->sendResponse(
            UserAuctionResource::collection($paginator),
            __('auction.messages.auctions_fetched')
        );
    }

    public function refunds(Request $request, MyRefundQuery $query): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(RefundTransactionStatus::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['latest', 'oldest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $query->paginate(
            (int) $request->user()->id,
            $filters,
            min(100, max(1, (int) $request->input('per_page', 20)))
        );

        return $this->sendResponse(
            MyRefundResource::collection($paginator),
            __('auction.messages.refunds_fetched')
        );
    }
}
