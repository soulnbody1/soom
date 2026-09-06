<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyAdResource;
use App\Models\Ad;
use App\Repositories\Ad\Queries\MyAdsQuery;
use App\Repositories\Ad\Queries\TrashedAdQuery;
use App\Services\Ad\Actions\DeleteAdAction;
use App\Services\Ad\Actions\ForceDeleteAdAction;
use App\Services\Ad\Actions\RestoreAdAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MyAdController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests;

    public function __construct(protected TrashedAdQuery $trashedAds) {}

    public function index(Request $request, MyAdsQuery $myAds): JsonResponse
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

    public function restore(int $id, RestoreAdAction $restoreAd): JsonResponse
    {
        $ad = $this->trashedAds->ownedOrFail($id);
        $this->authorize('restore', $ad);

        if (! $ad->trashed()) {
            return $this->sendError('الإعلان غير محذوف.', 400, 'ad_not_deleted');
        }

        $restoreAd->execute($ad);

        return $this->sendResponse([], 'تم استرجاع الإعلان بنجاح.');
    }

    public function forceDelete(int $id, ForceDeleteAdAction $forceDeleteAd): JsonResponse
    {
        $ad = $this->trashedAds->ownedOrFail($id);
        $this->authorize('forceDelete', $ad);

        $forceDeleteAd->execute($ad);

        return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
    }
}
