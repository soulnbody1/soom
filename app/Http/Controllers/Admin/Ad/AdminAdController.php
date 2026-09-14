<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Ad;

use App\DTO\Ad\AdSearchDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdResource;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdminAdQuery;
use App\Repositories\Ad\Queries\AdSearchQuery;
use App\Repositories\Ad\Queries\TrashedAdQuery;
use App\Services\Ad\Actions\ForceDeleteAdAction;
use App\Services\Ad\Actions\ToggleAdBlockAction;
use App\Services\Ad\Actions\ToggleAdFeaturedAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group(name: 'إدارة الإعلانات', description: 'عرض الإعلانات وإدارتها من لوحة التحكم باستخدام ULID العام.', weight: 3)]
class AdminAdController extends Controller
{
    use ApiResponseTrait;

    public function __construct(protected TrashedAdQuery $trashedAds) {}

    #[Endpoint(title: 'عرض الإعلانات للإدارة', description: 'يعرض الإعلانات الحالية والمحظورة مع ULID العام لكل إعلان.')]
    #[QueryParameter('status', description: 'تصفية اختيارية بالقيمة active أو inactive.')]
    #[Response(200, description: 'الإعلانات مقسّمة إلى صفحات مع عدد النشطة.')]
    public function index(Request $request, AdminAdQuery $ads): AnonymousResourceCollection
    {
        return AdResource::collection($ads->paginateFeatured($request->input('status')))
            ->additional(['active_ads' => $ads->activeCount()]);
    }

    #[Endpoint(title: 'البحث في الإعلانات للإدارة', description: 'يبحث ويصفي الإعلانات ويعيد ULID العام في النتائج.')]
    #[QueryParameter('search', description: 'عبارة البحث في الإعلان.')]
    #[QueryParameter('status', description: 'تصفية اختيارية بالحالة.')]
    #[Response(200, description: 'نتائج البحث مقسّمة إلى صفحات.')]
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

    #[Endpoint(title: 'حظر إعلان أو استعادته', description: 'يبدّل حالة حظر الإعلان المحدد بمعرّفه العام ULID.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تم تبديل حالة الإعلان.')]
    #[Response(404, description: 'الإعلان غير موجود.')]
    public function toggleBlock(string $ad, ToggleAdBlockAction $toggleBlock): JsonResponse
    {
        $ad = Ad::withTrashed()->where('public_id', $ad)->first();

        if (! $ad) {
            return response()->json(['message' => 'الاعلان غير موجود.'], 404);
        }

        return response()->json([
            'message' => $toggleBlock->execute($ad)
                ? 'تم استرجاع الاعلان بنجاح.'
                : 'تم توقيف  الاعلان .',
        ], 200);
    }

    #[Endpoint(title: 'تمييز إعلان أو إلغاء تمييزه', description: 'يبدّل حالة تمييز الإعلان المحدد بمعرّفه العام ULID.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تم تبديل حالة التمييز.')]
    #[Response(404, description: 'الإعلان غير موجود.')]
    public function toggleFeatured(string $ad, ToggleAdFeaturedAction $toggleFeatured): JsonResponse
    {
        $ad = Ad::withTrashed()->where('public_id', $ad)->first();

        if (! $ad) {
            return response()->json(['message' => 'الاعلان غير موجود'], 404);
        }

        return response()->json([
            'message' => $toggleFeatured->execute($ad)
                ? 'تم جعل الاعلان مميز الان '
                : 'تم ارجاع الاعلان الى اعلان عادى  .',
        ], 200);
    }

    #[Endpoint(title: 'حذف إعلان نهائيًا للإدارة', description: 'يحذف الإعلان نهائيًا باستخدام ULID العام.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تم حذف الإعلان نهائيًا.')]
    #[Response(404, description: 'الإعلان غير موجود.')]
    public function forceDelete(string $ad, ForceDeleteAdAction $forceDeleteAd): JsonResponse
    {
        $forceDeleteAd->execute($this->trashedAds->findOrFail($ad));

        return $this->sendResponse([], 'تم حذف الإعلان نهائيًا.');
    }
}
