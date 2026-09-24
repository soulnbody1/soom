<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\PublishContentReviewPolicyRequest;
use App\Http\Resources\ContentReview\ContentReviewPolicyResource;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Repositories\ContentReview\ContentReviewPolicyRepository;
use App\Services\ContentReview\Actions\PublishContentReviewPolicyAction;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

#[Group(name: 'إدارة مراجعة المحتوى', description: 'إعداد منظومة مراجعة المحتوى ومراقبة مزوّد الذكاء الاصطناعي والسياسات والمقاييس التشغيلية.', weight: 21)]
final class ContentReviewPolicyController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    #[Endpoint(title: 'عرض إصدارات سياسات المراجعة', description: 'يعرض جميع إصدارات سياسات المراجعة لنوع المحتوى مع عدد مرات استخدامها.')]
    #[QueryParameter('subject_type', description: 'نوع المحتوى المطلوب عرض سياساته.')]
    #[Response(200, description: 'قائمة إصدارات سياسات المراجعة.')]
    public function index(Request $request, ContentReviewPolicyRepository $policies): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            ContentReviewPolicyResource::collection($policies->allWithUsage($type)),
            __('content_review.messages.reviews_fetched')
        );
    }

    #[Endpoint(title: 'عرض سياسة المراجعة الفعالة', description: 'يعرض السياسة المنشورة والفعالة حاليًا لنوع المحتوى المحدد.')]
    #[QueryParameter('subject_type', description: 'نوع المحتوى المطلوب عرض سياسته الفعالة.')]
    #[Response(200, description: 'السياسة الفعالة وتفاصيلها، أو قيمة فارغة إذا لم تُنشر سياسة بعد.')]
    public function active(Request $request, ContentReviewPolicyRepository $policies): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));
        $active = $policies->active($type);

        if ($active === null) {
            return $this->sendResponse(null, __('content_review.messages.no_review'));
        }

        $active->loadMissing('creator:id,name')->loadCount('reviews');

        return $this->sendResponse(
            $this->detailedPayload($request, $active),
            __('content_review.messages.review_fetched')
        );
    }

    #[Endpoint(title: 'عرض إصدار سياسة مراجعة', description: 'يعرض تفاصيل إصدار محدد من سياسة مراجعة المحتوى مع الحمولة الكاملة للسياسة.')]
    #[PathParameter('contentReviewPolicy', description: 'المعرّف العام لإصدار سياسة المراجعة.')]
    #[Response(200, description: 'تفاصيل إصدار سياسة المراجعة.')]
    public function show(Request $request, ContentReviewPolicy $contentReviewPolicy): JsonResponse
    {
        $contentReviewPolicy->loadMissing('creator:id,name')->loadCount('reviews');

        return $this->sendResponse(
            $this->detailedPayload($request, $contentReviewPolicy),
            __('content_review.messages.review_fetched')
        );
    }

    #[Endpoint(title: 'نشر سياسة مراجعة جديدة', description: 'ينشئ إصدارًا غير قابل للتعديل من سياسة المراجعة ويجعله السياسة الفعالة لنوع المحتوى.')]
    #[Response(201, description: 'تم نشر سياسة المراجعة وإرجاع تفاصيل إصدارها.')]
    public function store(
        PublishContentReviewPolicyRequest $request,
        PublishContentReviewPolicyAction $action
    ): JsonResponse {
        $published = $action->execute(
            $request->subjectType(),
            (string) $request->validated('name'),
            $request->policyPayload(),
            $request->promptVersion(),
            $request->resultSchemaVersion(),
            Auth::id(),
        );

        $published->loadMissing('creator:id,name')->loadCount('reviews');

        return $this->sendResponse(
            $this->detailedPayload($request, $published),
            __('content_review.messages.policy_published'),
            201
        );
    }

    private function detailedPayload(Request $request, ContentReviewPolicy $policy): array
    {
        return (new ContentReviewPolicyResource($policy))->toArray($request) + [
            'policy' => (array) $policy->policy,
        ];
    }
}
