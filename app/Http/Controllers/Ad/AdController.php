<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\DTO\Ad\AdFilterDTO;
use App\DTO\Ad\AdSearchDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdRequest;
use App\Http\Resources\AdResource;
use App\Http\Resources\MyAdResource;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdDetailQuery;
use App\Repositories\Ad\Queries\AdListingQuery;
use App\Repositories\Ad\Queries\AdminAdQuery;
use App\Repositories\Ad\Queries\AdSearchQuery;
use App\Repositories\Ad\Queries\CategoryFeedQuery;
use App\Repositories\Ad\Queries\HomeFeedQuery;
use App\Repositories\Ad\Queries\MyAdsQuery;
use App\Services\Ad\Support\AdCacheVersion;
use App\Services\AdService;
use App\Services\UserAdInteractionService;
use App\Traits\ApiResponseTrait;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests;

    public function __construct(
        protected AdService $service,
        protected UserAdInteractionService $interactions,
        protected AdCacheVersion $cacheVersion,
    ) {}

    public function store(StoreAdRequest $request)
    {
        $ad = $this->service->store([...$request->validated(), 'user_id' => Auth::id()]);

        return new AdResource($ad);
    }

    public function update(StoreAdRequest $request, Ad $ad)
    {
        $this->authorize('update', $ad);
        $this->service->update($ad, [...$request->validated(), 'user_id' => Auth::id()]);

        return new AdResource($ad->fresh());
    }

    public function filter(Request $request, AdListingQuery $listing): JsonResponse
    {
        $ads = $listing->paginate(AdFilterDTO::fromRequest($request), $this->viewer());

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.'
        );
    }

    public function search(Request $request, AdSearchQuery $search): JsonResponse
    {
        $ads = $search->apply(AdSearchDTO::fromRequest($request))
            ->latest()
            ->with(Ad::$defaultRelations)
            ->paginate(10);

        if ($ads->total() === 0) {
            return $this->sendEmptyResponse('لم يتم العثور على إعلانات تطابق معايير البحث.');
        }

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.'
        );
    }

    public function search_for_admin(Request $request, AdSearchQuery $search, AdminAdQuery $admin): JsonResponse
    {
        $filters = AdSearchDTO::fromRequest($request);
        $query = $search->apply($filters);
        $activeAds = $query->count();

        $ads = $admin->paginateSearch($query, $filters->status);

        if ($ads->total() === 0) {
            return $this->sendEmptyResponse('لم يتم العثور على إعلانات تطابق معايير البحث.');
        }

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.',
            200,
            ['active_ads' => $activeAds]
        );
    }

    public function show(int $id, AdDetailQuery $details): JsonResponse
    {
        $viewer = $this->viewer();
        $ad = $details->findOrFail($id, $viewer);

        $this->service->recordView($ad);
        $this->interactions->store($ad->id, 'click');

        return $this->sendResponse(
            new AdResource($ad),
            'تم جلب الاعلان بنجاح.'
        );
    }

    public function adsByCategoryWithChildren(int $categoryId, CategoryFeedQuery $feed): JsonResponse
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

    public function myads(Request $request, MyAdsQuery $myAds): JsonResponse
    {
        $ownerId = (int) Auth::id();

        $data = $request->filled('page')
            ? MyAdResource::collection($myAds->paginate($ownerId))
            : MyAdResource::collection($myAds->get($ownerId))->resolve();

        return $this->sendResponse(
            $data,
            'تم جلب الإعلانات بنجاح.',
            200,
            ['total_views' => $myAds->totalViews($ownerId)]
        );
    }

    public function destroy(Ad $ad): JsonResponse
    {
        $this->authorize('delete', $ad);
        $ad->delete();
        $this->cacheVersion->bump();

        return $this->sendResponse([], 'تم حذف الاعلان بنجاح.');
    }

    public function restore($id): JsonResponse
    {
        $ad = $this->service->getTrashedAdForUser((int) $id);
        $this->authorize('restore', $ad);

        if (! $ad->trashed()) {
            return $this->sendError('الإعلان غير محذوف.', 400);
        }

        $ad->restore();
        $this->cacheVersion->bump();

        return $this->sendResponse([], 'تم استرجاع الإعلان بنجاح.');
    }

    public function forceDelete($id): JsonResponse
    {
        $ad = $this->service->getTrashedAdForUser((int) $id);
        $this->authorize('forceDelete', $ad);

        $ad->forceDelete();
        $this->cacheVersion->bump();

        return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
    }

    public function home(HomeFeedQuery $feed): JsonResponse
    {
        return $this->sendResponse($feed->build($this->viewer()), 'تم جلب الإعلانات بنجاح.');
    }

    public function ads(Request $request, AdminAdQuery $admin)
    {
        $ads = $admin->paginateFeatured($request->input('status'));

        return AdResource::collection($ads)
            ->additional(['active_ads' => $admin->activeCount()]);
    }

    public function toggleBlock($id): JsonResponse
    {
        $ad = Ad::withTrashed()->find($id);

        if (! $ad) {
            return response()->json(['message' => 'الاعلان غير موجود.'], 404);
        }

        if ($ad->trashed()) {
            $ad->restore();
            $this->cacheVersion->bump();

            return response()->json(['message' => 'تم استرجاع الاعلان بنجاح.'], 200);
        }

        $ad->delete();
        $this->cacheVersion->bump();

        return response()->json(['message' => 'تم توقيف  الاعلان .'], 200);
    }

    public function toggleFeatured($id): JsonResponse
    {
        $ad = Ad::withTrashed()->find($id);

        if (! $ad) {
            return response()->json(['message' => 'الاعلان غير موجود'], 404);
        }

        $ad->is_featured = ! $ad->is_featured;
        $ad->save();
        $this->cacheVersion->bump();

        return response()->json([
            'message' => $ad->is_featured
                ? 'تم جعل الاعلان مميز الان '
                : 'تم ارجاع الاعلان الى اعلان عادى  .',
        ], 200);
    }

    public function destroybyadmin($id): JsonResponse
    {
        $ad = $this->service->getTrashedAd((int) $id);
        $ad->forceDelete();
        $this->cacheVersion->bump();

        return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
    }

    public function sharePage($id)
    {
        $ad = Ad::with('images')->findOrFail($id);

        return view('share.show', compact('ad'));
    }

    private function viewer(): ?object
    {
        return auth('sanctum')->user();
    }
}
