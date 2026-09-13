<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerRating;

use App\Http\Controllers\Controller;
use App\Http\Requests\SellerRating\ListSellerRatingsRequest;
use App\Http\Requests\SellerRating\StoreSellerRatingRequest;
use App\Http\Resources\SellerRating\SellerRatingResource;
use App\Models\User;
use App\Repositories\SellerRating\SellerRatingQuery;
use App\Services\SellerRating\Actions\DeleteSellerRatingAction;
use App\Services\SellerRating\Actions\SaveSellerRatingAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group(name: 'تقييمات البائعين', description: 'صفحة البائع العامة وتقييماته النصية والرقمية المستقلة عن الإعلانات والمنتجات.', weight: 12)]
final class SellerRatingController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض تقييمات البائع',
        description: 'يعرض رسائل تقييم البائع من الأحدث إلى الأقدم مع درجة كل تقييم والبيانات العامة لكاتبه. النتائج مقسّمة إلى صفحات ولا تحتوي على أي بيانات اتصال خاصة بالمقيّمين.'
    )]
    #[PathParameter('seller', description: 'معرّف البائع الرقمي.')]
    #[QueryParameter('page', description: 'رقم الصفحة المطلوبة، والقيمة الافتراضية 1.')]
    #[QueryParameter('per_page', description: 'عدد التقييمات في الصفحة، والقيمة الافتراضية 15 والحد الأقصى 50.')]
    #[Response(200, description: 'تقييمات البائع مقسّمة إلى صفحات.')]
    #[Response(404, description: 'البائع غير موجود أو حسابه محذوف.')]
    #[Response(422, description: 'معاملات التقسيم إلى صفحات غير صحيحة.')]
    public function index(
        ListSellerRatingsRequest $request,
        User $seller,
        SellerRatingQuery $ratings
    ): AnonymousResourceCollection {
        return SellerRatingResource::collection($ratings->paginate($seller, $request->perPage()))
            ->additional([
                'success' => true,
                'message' => 'تم جلب تقييمات البائع بنجاح.',
            ]);
    }

    #[Endpoint(
        title: 'إضافة أو تحديث تقييم البائع',
        description: 'يحفظ تقييم المستخدم الحالي للبائع نفسه وليس لمنتج أو إعلان. ينشئ التقييم في المرة الأولى ويحدّث السجل نفسه عند تكرار الطلب، لذلك لا يمكن أن يمتلك المستخدم أكثر من تقييم واحد للبائع. لا يمكن للمستخدم تقييم نفسه.'
    )]
    #[PathParameter('seller', description: 'معرّف البائع الرقمي.')]
    #[BodyParameter('rating', description: 'درجة صحيحة من 1 إلى 5.', required: true, type: 'integer', example: 5)]
    #[BodyParameter('message', description: 'رسالة المستخدم عن تجربته مع البائع بحد أقصى 1000 حرف.', required: true, type: 'string', example: 'بائع محترم وسريع في التعامل.')]
    #[Response(201, description: 'تم إنشاء تقييم جديد للبائع.')]
    #[Response(200, description: 'تم تحديث تقييم المستخدم الموجود للبائع.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    #[Response(403, description: 'الحساب الحالي غير مسموح له بإضافة التقييمات.')]
    #[Response(404, description: 'البائع غير موجود أو حسابه محذوف.')]
    #[Response(422, description: 'بيانات التقييم غير صحيحة أو يحاول المستخدم تقييم نفسه.')]
    public function store(
        StoreSellerRatingRequest $request,
        User $seller,
        SaveSellerRatingAction $saveRating
    ): JsonResponse {
        $rating = $saveRating->execute($request->user(), $seller, $request->validated());
        $created = $rating->wasRecentlyCreated;

        return $this->sendResponse(
            new SellerRatingResource($rating),
            $created ? 'تم إضافة تقييم البائع بنجاح.' : 'تم تحديث تقييم البائع بنجاح.',
            $created ? 201 : 200
        );
    }

    #[Endpoint(
        title: 'حذف تقييم البائع',
        description: 'يحذف تقييم المستخدم الحالي لهذا البائع فقط، ولا يمكن استخدام المسار لحذف تقييم كتبه مستخدم آخر.'
    )]
    #[PathParameter('seller', description: 'معرّف البائع الرقمي.')]
    #[Response(200, description: 'تم حذف تقييم المستخدم الحالي.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    #[Response(403, description: 'الحساب الحالي غير مسموح له بحذف التقييمات.')]
    #[Response(404, description: 'البائع أو تقييم المستخدم الحالي غير موجود.')]
    public function destroy(
        Request $request,
        User $seller,
        DeleteSellerRatingAction $deleteRating
    ): JsonResponse {
        $deleteRating->execute($request->user(), $seller);

        return $this->sendEmptyResponse('تم حذف تقييم البائع بنجاح.');
    }
}
