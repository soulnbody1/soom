<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\DTO\Ad\AdSearchDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdResource;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdSearchQuery;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdSearchController extends Controller
{
    use ApiResponseTrait;

    private const PER_PAGE = 10;

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
