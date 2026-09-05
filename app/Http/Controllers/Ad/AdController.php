<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Domain\Ad\Enums\AdInteractionAction;
use App\DTO\Ad\AdFilterDTO;
use App\DTO\Ad\AdSearchDTO;
use App\DTO\Ad\AdWriteInputDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdRequest;
use App\Http\Resources\AdResource;
use App\Http\Resources\MyAdResource;
use App\Jobs\Ad\RecordAdEngagement;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdDetailQuery;
use App\Repositories\Ad\Queries\AdListingQuery;
use App\Repositories\Ad\Queries\AdminAdQuery;
use App\Repositories\Ad\Queries\AdSearchQuery;
use App\Repositories\Ad\Queries\CategoryFeedQuery;
use App\Repositories\Ad\Queries\HomeFeedQuery;
use App\Repositories\Ad\Queries\MyAdsQuery;
use App\Services\Ad\Actions\CreateAdAction;
use App\Services\Ad\Actions\DeleteAdAction;
use App\Services\Ad\Actions\ForceDeleteAdAction;
use App\Services\Ad\Actions\RestoreAdAction;
use App\Services\Ad\Actions\ToggleAdBlockAction;
use App\Services\Ad\Actions\ToggleAdFeaturedAction;
use App\Services\Ad\Actions\UpdateAdAction;
use App\Services\AdService;
use App\Traits\ApiResponseTrait;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests;

    public function __construct(protected AdService $service) {}

    public function store(StoreAdRequest $request, CreateAdAction $createAd)
    {
        $ad = $createAd->execute(
            AdWriteInputDTO::fromValidated($request->validated()),
            (int) Auth::id()
        );

        return new AdResource($ad->load(Ad::$defaultRelations));
    }

    public function update(StoreAdRequest $request, Ad $ad, UpdateAdAction $updateAd)
    {
        $this->authorize('update', $ad);

        $updateAd->execute($ad, AdWriteInputDTO::fromValidated($request->validated()));

        return new AdResource($ad->fresh(Ad::$defaultRelations));
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

        if ($viewer !== null) {
            RecordAdEngagement::dispatch((int) $ad->id, (int) $viewer->id, AdInteractionAction::Click, true);
        }

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

    public function destroy(Ad $ad, DeleteAdAction $deleteAd): JsonResponse
    {
        $this->authorize('delete', $ad);
        $deleteAd->execute($ad);

        return $this->sendResponse([], 'تم حذف الاعلان بنجاح.');
    }

    public function restore($id, RestoreAdAction $restoreAd): JsonResponse
    {
        $ad = $this->service->getTrashedAdForUser((int) $id);
        $this->authorize('restore', $ad);

        if (! $ad->trashed()) {
            return $this->sendError('الإعلان غير محذوف.', 400);
        }

        $restoreAd->execute($ad);

        return $this->sendResponse([], 'تم استرجاع الإعلان بنجاح.');
    }

    public function forceDelete($id, ForceDeleteAdAction $forceDeleteAd): JsonResponse
    {
        $ad = $this->service->getTrashedAdForUser((int) $id);
        $this->authorize('forceDelete', $ad);

        $forceDeleteAd->execute($ad);

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

    public function toggleBlock($id, ToggleAdBlockAction $toggleBlock): JsonResponse
    {
        $ad = Ad::withTrashed()->find($id);

        if (! $ad) {
            return response()->json(['message' => 'الاعلان غير موجود.'], 404);
        }

        return response()->json([
            'message' => $toggleBlock->execute($ad)
                ? 'تم استرجاع الاعلان بنجاح.'
                : 'تم توقيف  الاعلان .',
        ], 200);
    }

    public function toggleFeatured($id, ToggleAdFeaturedAction $toggleFeatured): JsonResponse
    {
        $ad = Ad::withTrashed()->find($id);

        if (! $ad) {
            return response()->json(['message' => 'الاعلان غير موجود'], 404);
        }

        return response()->json([
            'message' => $toggleFeatured->execute($ad)
                ? 'تم جعل الاعلان مميز الان '
                : 'تم ارجاع الاعلان الى اعلان عادى  .',
        ], 200);
    }

    public function destroybyadmin($id, ForceDeleteAdAction $forceDeleteAd): JsonResponse
    {
        $forceDeleteAd->execute($this->service->getTrashedAd((int) $id));

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
