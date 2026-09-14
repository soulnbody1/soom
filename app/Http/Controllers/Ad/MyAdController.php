<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyAdResource;
use App\Repositories\Ad\Queries\MyAdsQuery;
use App\Repositories\Ad\Queries\TrashedAdQuery;
use App\Services\Ad\Actions\DeleteAdAction;
use App\Services\Ad\Actions\ForceDeleteAdAction;
use App\Services\Ad\Actions\RestoreAdAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

#[Group(name: 'إعلاناتي', description: 'إدارة إعلانات المستخدم الحالية والمحذوفة باستخدام ULID العام.', weight: 8)]
class MyAdController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests;

    public function __construct(protected TrashedAdQuery $trashedAds) {}

    #[Endpoint(title: 'عرض إعلاناتي', description: 'يعرض إعلانات المستخدم الحالية والمحذوفة مع عدادات الحالة والمشاهدات.')]
    #[QueryParameter('page', description: 'رقم الصفحة. عند عدم تمريره تعاد قائمة محدودة غير مقسمة.')]
    #[Response(200, description: 'إعلانات المستخدم وملخص حالتها.')]
    public function index(Request $request, MyAdsQuery $myAds): JsonResponse
    {
        $ownerId = (int) Auth::id();

        $data = $request->filled('page')
            ? MyAdResource::collection($myAds->paginate($ownerId))
            : MyAdResource::collection($myAds->get($ownerId))->resolve();

        $statusCounts = $myAds->statusCounts($ownerId);

        return $this->sendResponse(
            $data,
            'تم جلب الإعلانات بنجاح.',
            200,
            [
                'total_views' => $myAds->totalViews($ownerId),
                'active_count' => $statusCounts['active'],
                'deleted_count' => $statusCounts['deleted'],
            ]
        );
    }

    #[Endpoint(title: 'حذف إعلان مؤقتًا', description: 'ينقل إعلانًا يملكه المستخدم إلى المحذوفات باستخدام ULID العام.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تم حذف الإعلان مؤقتًا.')]
    #[Response(404, description: 'الإعلان غير موجود أو لا يملكه المستخدم.')]
    public function destroy(string $ad, DeleteAdAction $deleteAd): JsonResponse
    {
        $ad = $this->trashedAds->ownedActiveOrFail($ad);
        $this->authorize('delete', $ad);
        $deleteAd->execute($ad);

        return $this->sendResponse([], 'تم حذف الاعلان بنجاح.');
    }

    #[Endpoint(title: 'استعادة إعلان', description: 'يستعيد إعلانًا محذوفًا يملكه المستخدم باستخدام ULID العام.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تمت استعادة الإعلان.')]
    #[Response(404, description: 'الإعلان غير موجود أو لا يملكه المستخدم.')]
    public function restore(string $ad, RestoreAdAction $restoreAd): JsonResponse
    {
        $ad = $this->trashedAds->ownedOrFail($ad);
        $this->authorize('restore', $ad);

        if (! $ad->trashed()) {
            return $this->sendError('الإعلان غير محذوف.', 400, 'ad_not_deleted');
        }

        $restoreAd->execute($ad);

        return $this->sendResponse([], 'تم استرجاع الإعلان بنجاح.');
    }

    #[Endpoint(title: 'حذف إعلان نهائيًا', description: 'يحذف إعلانًا يملكه المستخدم نهائيًا باستخدام ULID العام.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تم حذف الإعلان نهائيًا.')]
    #[Response(404, description: 'الإعلان غير موجود أو لا يملكه المستخدم.')]
    public function forceDelete(string $ad, ForceDeleteAdAction $forceDeleteAd): JsonResponse
    {
        $ad = $this->trashedAds->ownedOrFail($ad);
        $this->authorize('forceDelete', $ad);

        $forceDeleteAd->execute($ad);

        return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
    }
}
