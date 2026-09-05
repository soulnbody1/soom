<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\DTO\Ad\AdFilterDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdResource;
use App\Repositories\Ad\Queries\AdListingQuery;
use App\Repositories\Ad\Queries\CategoryFeedQuery;
use App\Repositories\Ad\Queries\HomeFeedQuery;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdListingController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, AdListingQuery $listing): JsonResponse
    {
        $ads = $listing->paginate(AdFilterDTO::fromRequest($request), $this->viewer());

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.'
        );
    }

    public function home(HomeFeedQuery $feed): JsonResponse
    {
        return $this->sendResponse($feed->build($this->viewer()), 'تم جلب الإعلانات بنجاح.');
    }

    public function byCategory(int $categoryId, CategoryFeedQuery $feed): JsonResponse
    {
        $data = $feed->build($categoryId, $this->viewer());
        $ads = $data['ads'];

        return response()->json([
            'success' => true,
            'data' => [
                'ads' => AdResource::collection($ads),
                'nearby_ads' => AdResource::collection($data['nearby_ads']),
                'subcategories' => $data['subcategories'],
                'meta' => [
                    'current_page' => $ads->currentPage(),
                    'last_page' => $ads->lastPage(),
                    'per_page' => $ads->perPage(),
                    'total' => $ads->total(),
                    'next_page_url' => $ads->nextPageUrl(),
                    'prev_page_url' => $ads->previousPageUrl(),
                ],
            ],
            'message' => 'تم جلب الإعلانات والفئات الفرعية والإعلانات القريبة بنجاح.',
        ]);
    }

    private function viewer(): ?object
    {
        return auth('sanctum')->user();
    }
}
