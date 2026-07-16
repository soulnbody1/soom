<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\CreateTermsVersionRequest;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionTermsVersion;
use App\Services\Auction\Actions\CreateAuctionTermsVersionAction;
use App\Services\Auction\Actions\ListAuctionTermsAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class AuctionTermsController extends Controller
{
    use ApiResponseTrait;

    public function index(ListAuctionTermsAction $action): JsonResponse
    {
        return $this->sendResponse(
            $action->execute(),
            __('auction.messages.terms_fetched')
        );
    }

    public function show(AuctionTermsVersion $terms): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse([
            'id' => $terms->public_id,
            'version_number' => $terms->version_number,
            'title' => $terms->title,
            'body' => $terms->body,
            'is_active' => $terms->is_active,
            'published_at' => $terms->published_at?->toIso8601String(),
        ], __('auction.messages.terms_version_fetched'));
    }

    public function store(CreateTermsVersionRequest $request, CreateAuctionTermsVersionAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $publish = (bool) $request->boolean('publish', true);
        $terms = $action->execute(
            (string) $request->validated('title'),
            (string) $request->validated('body'),
            $publish,
            Auth::id()
        );

        return $this->sendResponse([
            'id' => $terms->public_id,
            'version_number' => $terms->version_number,
            'title' => $terms->title,
            'is_active' => $terms->is_active,
        ], __('auction.messages.terms_version_created'), 201);
    }
}
