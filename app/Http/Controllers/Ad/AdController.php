<?php

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Http\Requests\StoreAdRequest;
use App\Services\AdService;
use App\Services\UserAdInteractionService;
use App\Http\Resources\AdResource;
use App\Models\Ad;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Ad\AdFilter;
use App\Http\Controllers\Ad\AdSearch;
use App\Http\Resources\MyAdResource;
use Illuminate\Support\Facades\Cache;


class AdController extends Controller
{
    use AuthorizesRequests, ApiResponseTrait;
    public function __construct(
        protected AdService $service,
        protected UserAdInteractionService $Interaction,
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

    public function filter(Request $request, AdFilter $filter)
    {
        $user = auth('sanctum')->user();
        $query = $filter->apply($request, Ad::query());
        $ads = $query->latest()
            ->with(['category:id,name'])
            ->withIsFavorite($user)
            ->paginate(20);

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.'
        );
    }

    public function search(Request $request, AdSearch $search)
    {
        $query = $search->apply($request);
        $ads = $query->latest()
            ->with((Ad::$defaultRelations))
            ->paginate(10);

        if ($ads->total() === 0) {
            return $this->sendEmptyResponse('لم يتم العثور على إعلانات تطابق معايير البحث.');
        }

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.'
        );
    }

    public function show($id)
    {
        $user = auth('sanctum')->user();
        $ad = $this->service->getAdWithRelations($id, $user);
        $this->service->recordView($ad);
        $this->Interaction->store($ad->id, 'click');
        return $this->sendResponse(
            new AdResource($ad),
            'تم جلب الاعلان بنجاح.'
        );
    }

    public function adsByCategoryWithChildren($categoryId)
    {
        $user = auth('sanctum')->user();
        $data = $this->service->getAdsWithCategoryAndNearby($categoryId, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'ads' => AdResource::collection($data['ads']),
                'nearby_ads' => AdResource::collection($data['nearby_ads']),
                'subcategories' => $data['subcategories'],
                'meta' => [
                    'current_page' => $data['ads']->currentPage(),
                    'last_page' => $data['ads']->lastPage(),
                    'per_page' => $data['ads']->perPage(),
                    'total' => $data['ads']->total(),
                    'next_page_url' => $data['ads']->nextPageUrl(),
                    'prev_page_url' => $data['ads']->previousPageUrl(),
                ],
            ],
            'message' => 'تم جلب الإعلانات والفئات الفرعية والإعلانات القريبة بنجاح.',
        ]);
    }

    public function myads()
    {
        $ads = $this->service->getMyAdsWithStats();
        return $this->sendResponse(
            MyAdResource::collection($ads)->resolve(),
            'تم جلب الإعلانات بنجاح.',
            200,
            ['total_views' => $ads->sum('views_count')]
        );
    }

    public function destroy(Ad $ad)
    {
        $this->authorize('delete', $ad);
        $ad->delete();
        Cache::forget('home_ads_data');
        return $this->sendResponse([], 'تم حذف الاعلان بنجاح.');
    }

    public function restore($id)
    {
        $ad = $this->service->getTrashedAdForUser($id);
        $this->authorize('restore', $ad);
        if ($ad->trashed()) {
            $ad->restore();
            return $this->sendResponse([], 'تم استرجاع الإعلان بنجاح.');
        }
        Cache::forget('home_ads_data');
        return $this->sendError('الإعلان غير محذوف.', 400);
    }

    public function forceDelete($id)
    {
        $ad = $this->service->getTrashedAdForUser($id);
        $this->authorize('forceDelete', $ad);
        if (!$ad) {
            return $this->sendError('الإعلان غير موجود أو لا ينتمي للمستخدم.', 404);
        }

        if ($ad) {
            $ad->forceDelete();
            Cache::forget('home_ads_data');
            return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
        }
    }

    public function home()
    {
        $user = auth('sanctum')->user();
        $homeData = Cache::remember('home_ads_data', now()->addMinutes(10), function () use ($user) {
            return $this->service->getHomeAds($user);
        });
        return $this->sendResponse($homeData, 'تم جلب الإعلانات بنجاح.');
    }

    public function ads()
    {
        $ads = Ad::withTrashed()->Featured()->paginate(20);
        return AdResource::collection($ads);
    }

    public function toggleBlock($id)
    {
        $Ad = Ad::withTrashed()->find($id);

        if (!$Ad) {
            return response()->json([
                'message' => 'الاعلان غير موجود.'
            ], 404);
        }

        if ($Ad->trashed()) {
            $Ad->restore();
            Cache::forget('home_ads_data');

            return response()->json([
                'message' => 'تم استرجاع الاعلان بنجاح.'
            ], 200);
        } else {
            $Ad->delete();
            Cache::forget('home_ads_data');

            return response()->json([
                'message' => 'تم توقيف  الاعلان .'
            ], 200);
        }
    }

    public function toggleFeatured($id)
    {
        $Ad = Ad::withTrashed()->find($id);
        if (!$Ad) {
            return response()->json(['message' => 'الاعلان غير موجود'], 404);
        }
        if (!$Ad->is_featured) {
            $Ad->is_featured = true;
            $Ad->save();
            Cache::forget('home_ads_data');
            return response()->json(['message' => 'تم جعل الاعلان مميز الان '], 200);
        } else {
            $Ad->is_featured = false;
            $Ad->save();
            Cache::forget('home_ads_data');
            return response()->json(['message' => 'تم ارجاع الاعلان الى اعلان عادى  .'], 200);
        }
    }

    public function destroybyadmin($id)
    {
        $ad = $this->service->getTrashedAdForUser($id);
        if (!$ad) {
            return $this->sendError('الإعلان غير موجود أو لا ينتمي للمستخدم.', 404);
        }

        if ($ad) {
            $ad->forceDelete();
            Cache::forget('home_ads_data');
            return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
        }
    }

    public function sharePage($id)
    {
        $ad = Ad::with("images")->findOrFail($id);
        return view('share.show', compact('ad'));
    }
}
