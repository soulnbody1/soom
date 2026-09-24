<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\DTO\Ad\AdFilterDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ad\AdIndexRequest;
use App\Http\Resources\AdResource;
use App\Http\Resources\HomeAdResource;
use App\Repositories\Ad\Queries\AdListingQuery;
use App\Repositories\Ad\Queries\CategoryFeedQuery;
use App\Repositories\Ad\Queries\HomeFeedQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'الإعلانات', description: 'استعراض الإعلانات العامة وتفاصيلها والبحث فيها وتجميعها حسب التصنيف.', weight: 7)]
class AdListingController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(title: 'عرض قائمة الإعلانات', description: 'يعرض الإعلانات المتاحة في السوق الحالي مع دعم التصفية والترتيب وتقسيم النتائج إلى صفحات.')]
    #[Response(200, description: 'قائمة الإعلانات وبيانات الصفحات.')]
    public function index(AdIndexRequest $request, AdListingQuery $listing): JsonResponse
    {
        $ads = $listing->paginate(AdFilterDTO::fromRequest($request), $this->viewer());

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.'
        );
    }

    #[Endpoint(title: 'عرض محتوى الصفحة الرئيسية', description: 'يعرض الإعلانات مجمّعة تحت تصنيفاتها الرئيسية مع مراعاة السوق والمستخدم الحالي.')]
    #[Response(200, description: 'مجموعات التصنيفات والإعلانات التابعة لكل مجموعة.')]
    public function home(Request $request, HomeFeedQuery $feed): JsonResponse
    {
        $groups = array_map(
            static fn (array $group): array => [
                'category' => $group['category'],
                'category_id' => $group['category_id'] ?? null,
                'ads' => HomeAdResource::collection($group['ads'])->resolve($request),
            ],
            $feed->build($this->viewer())
        );

        return $this->sendResponse($groups, 'تم جلب الإعلانات بنجاح.');
    }

    #[Endpoint(title: 'عرض إعلانات تصنيف', description: 'يعرض إعلانات التصنيف المحدد وفروعه، إلى جانب التصنيفات الفرعية والإعلانات القريبة عند توافرها.')]
    #[PathParameter('categoryId', description: 'المعرّف الرقمي للتصنيف.')]
    #[Response(200, description: 'إعلانات التصنيف والبيانات المرافقة مقسّمة إلى صفحات.')]
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
