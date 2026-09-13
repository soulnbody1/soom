<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerRating;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerRating\SellerProfileResource;
use App\Models\User;
use App\Repositories\SellerRating\SellerRatingQuery;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'تقييمات البائعين', description: 'صفحة البائع العامة وتقييماته النصية والرقمية المستقلة عن الإعلانات والمنتجات.', weight: 12)]
final class SellerProfileController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض صفحة البائع',
        description: 'يعرض البيانات العامة للبائع وعدد إعلاناته ومتوسط تقييمه وعدد التقييمات وتوزيعها من خمس نجوم إلى نجمة واحدة. إذا أرسل المستخدم رمز دخول صالحًا يُرجع أيضًا تقييمه الحالي لهذا البائع وإمكانية التقييم، بينما تظل الصفحة متاحة للزائر دون تسجيل دخول.'
    )]
    #[PathParameter('seller', description: 'معرّف البائع الرقمي.')]
    #[Response(200, description: 'بيانات صفحة البائع مع ملخص التقييم وحالة الزائر تجاهه.')]
    #[Response(404, description: 'البائع غير موجود أو حسابه محذوف.')]
    public function __invoke(User $seller, SellerRatingQuery $ratings): JsonResponse
    {
        $viewerId = auth('sanctum')->id();
        $profile = $ratings->sellerProfile($seller, $viewerId === null ? null : (int) $viewerId);

        return $this->sendResponse(
            new SellerProfileResource($profile),
            'تم جلب صفحة البائع بنجاح.'
        );
    }
}
