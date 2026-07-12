<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\PlaceBidRequest;
use App\Http\Resources\Auction\AuctionBidResource;
use App\Models\Auction\Auction;
use App\Services\Auction\Actions\ListAuctionBidsAction;
use App\Services\Auction\Actions\ListUserBidsAction;
use App\Services\Auction\Actions\PlaceBidAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class BidController extends Controller
{
    use ApiResponseTrait;

    public function index(AuctionIndexRequest $request, Auction $auction, ListAuctionBidsAction $action): JsonResponse
    {
        Gate::authorize('view', $auction);

        return $this->sendResponse(
            AuctionBidResource::collection($action->execute($auction, $request->perPage())),
            __('auction.messages.auction_bids_fetched')
        );
    }

    public function store(PlaceBidRequest $request, Auction $auction, PlaceBidAction $action): JsonResponse
    {
        try {
            Gate::authorize('bid', $auction);

            $data = $request->validated();
            $bid = $action->execute(
                $auction,
                Auth::id(),
                $data['amount'],
                $data['currency_code'],
                $data['idempotency_key'],
                $data['client_request_id'] ?? null
            );

            return $this->sendResponse(new AuctionBidResource($bid), __('auction.messages.bid_accepted'), 201);
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function mine(AuctionIndexRequest $request, ListUserBidsAction $action): JsonResponse
    {
        return $this->sendResponse(
            AuctionBidResource::collection($action->execute(Auth::id(), $request->perPage())),
            __('auction.messages.my_bids_fetched')
        );
    }
}
