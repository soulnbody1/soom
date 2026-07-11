<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\CreateTermsVersionRequest;
use App\Models\Auction\AuctionTermsVersion;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

final class AuctionTermsController extends Controller
{
    use ApiResponseTrait;

    public function index(): JsonResponse
    {
        return $this->sendResponse(
            AuctionTermsVersion::latest('version_number')->get()->map(fn (AuctionTermsVersion $terms) => [
                'id' => $terms->public_id,
                'version_number' => $terms->version_number,
                'title' => $terms->title,
                'is_active' => $terms->is_active,
                'published_at' => $terms->published_at?->toIso8601String(),
            ]),
            'Auction terms fetched.'
        );
    }

    public function store(CreateTermsVersionRequest $request): JsonResponse
    {
        $next = ((int) AuctionTermsVersion::max('version_number')) + 1;
        $publish = (bool) $request->boolean('publish', true);

        if ($publish) {
            AuctionTermsVersion::query()->update(['is_active' => false]);
        }

        $terms = AuctionTermsVersion::create([
            'version_number' => $next,
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
            'is_active' => $publish,
            'created_by' => Auth::id(),
            'published_at' => $publish ? Carbon::now() : null,
        ]);

        return $this->sendResponse([
            'id' => $terms->public_id,
            'version_number' => $terms->version_number,
            'title' => $terms->title,
            'is_active' => $terms->is_active,
        ], 'Auction terms version created.', 201);
    }
}
