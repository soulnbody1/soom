<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Application\Auction\Actions\PlaceBidAction;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\PlaceBidRequest;
use App\Http\Resources\Auction\AuctionBidResource;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class BidController extends Controller
{
    use ApiResponseTrait;

    public function index(AuctionIndexRequest $request, Auction $auction): JsonResponse
    {
        return $this->sendResponse(
            AuctionBidResource::collection(
                AuctionBid::with(['bidder', 'auction'])
                    ->where('auction_id', $auction->id)
                    ->orderByDesc('amount_minor')
                    ->orderBy('sequence_number')
                    ->paginate($request->perPage())
            ),
            'Auction bids fetched.'
        );
    }

    public function store(PlaceBidRequest $request, Auction $auction, PlaceBidAction $action): JsonResponse
    {
        try {
            $data = $request->validated();
            $bid = $action->execute(
                $auction,
                Auth::id(),
                $data['amount'],
                $data['currency_code'],
                $data['idempotency_key'],
                $data['client_request_id'] ?? null
            );

            return $this->sendResponse(new AuctionBidResource($bid), 'Bid accepted.', 201);
        } catch (AuctionException $exception) {
            return $this->sendError($exception->getMessage(), 422);
        }
    }

    public function mine(AuctionIndexRequest $request): JsonResponse
    {
        return $this->sendResponse(
            AuctionBidResource::collection(
                AuctionBid::with(['auction.media', 'bidder'])
                    ->where('bidder_id', Auth::id())
                    ->latest('id')
                    ->paginate($request->perPage())
            ),
            'My bids fetched.'
        );
    }
}
