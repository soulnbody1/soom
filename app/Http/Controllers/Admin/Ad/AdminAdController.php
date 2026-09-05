<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Ad;

use App\DTO\Ad\AdSearchDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdResource;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdminAdQuery;
use App\Repositories\Ad\Queries\AdSearchQuery;
use App\Services\Ad\Actions\ForceDeleteAdAction;
use App\Services\Ad\Actions\ToggleAdBlockAction;
use App\Services\Ad\Actions\ToggleAdFeaturedAction;
use App\Services\AdService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAdController extends Controller
{
    use ApiResponseTrait;

    public function __construct(protected AdService $service) {}

    public function index(Request $request, AdminAdQuery $ads): AnonymousResourceCollection
    {
        return AdResource::collection($ads->paginateFeatured($request->input('status')))
            ->additional(['active_ads' => $ads->activeCount()]);
    }

    public function search(Request $request, AdSearchQuery $search, AdminAdQuery $admin): JsonResponse
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

    public function toggleBlock(int $id, ToggleAdBlockAction $toggleBlock): JsonResponse
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

    public function toggleFeatured(int $id, ToggleAdFeaturedAction $toggleFeatured): JsonResponse
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

    public function forceDelete(int $id, ForceDeleteAdAction $forceDeleteAd): JsonResponse
    {
        $forceDeleteAd->execute($this->service->getTrashedAd($id));

        return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
    }
}
