<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\DTO\Ad\AdSearchDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdResource;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdSearchQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'الإعلانات', description: 'استعراض الإعلانات العامة وتفاصيلها والبحث فيها وتجميعها حسب التصنيف.', weight: 7)]
class AdSearchController extends Controller
{
    use ApiResponseTrait;

    private const PER_PAGE = 10;

    #[Endpoint(title: 'البحث في الإعلانات', description: 'يبحث في إعلانات السوق الحالي باستخدام النص ومعايير التصفية المدعومة، ويعيد النتائج من الأحدث إلى الأقدم.')]
    #[Response(200, description: 'نتائج البحث مقسّمة إلى صفحات، أو استجابة فارغة عند عدم وجود نتائج مطابقة.')]
    public function __invoke(Request $request, AdSearchQuery $search): JsonResponse
    {
        $ads = $search->apply(AdSearchDTO::fromRequest($request))
            ->latest()
            ->with(Ad::$defaultRelations)
            ->paginate(self::PER_PAGE);

        if ($ads->total() === 0) {
            return $this->sendEmptyResponse('لم يتم العثور على إعلانات تطابق معايير البحث.');
        }

        return $this->sendResponse(
            AdResource::collection($ads),
            'تم جلب الإعلانات بنجاح.'
        );
    }
}
