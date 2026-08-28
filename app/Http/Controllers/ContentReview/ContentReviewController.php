<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\ContentReviewHistoryRequest;
use App\Http\Requests\ContentReview\DecideContentReviewRequest;
use App\Http\Resources\ContentReview\ContentReviewResource;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Actions\ApplyContentReviewDecisionAction;
use App\Services\ContentReview\Actions\CancelContentReviewAction;
use App\Services\ContentReview\Actions\ForceManualReviewAction;
use App\Services\ContentReview\Actions\RetryContentReviewAction;
use App\Services\ContentReview\Actions\RunContentReviewAction;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

#[Group(name: 'مراجعة محتوى المزادات', description: 'مراجعة محتوى المزادات آليًا ويدويًا وتسجيل قرارات الاعتماد أو الرفض.', weight: 12)]
final class ContentReviewController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    #[Endpoint(
        title: 'عرض سجل مراجعات المحتوى',
        description: 'يعرض جميع مراجعات المحتوى السابقة والحالية للمزاد مرتبةً من الأحدث إلى الأقدم.'
    )]
    #[PathParameter('subjectType', description: 'نوع المحتوى الخاضع للمراجعة، والقيمة المدعومة حاليًا هي auction.')]
    #[PathParameter('subjectId', description: 'المعرّف العام للمزاد الخاضع للمراجعة (ULID).')]
    #[Response(200, description: 'سجل المراجعات مقسّمًا إلى صفحات.')]
    public function history(
        ContentReviewHistoryRequest $request,
        string $subjectType,
        string $subjectId,
        ContentReviewRepository $reviews
    ): JsonResponse {
        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);

        return $this->sendResponse(
            ContentReviewResource::collection($reviews->historyForSubject($type, $id, $request->perPage())),
            __('content_review.messages.reviews_fetched')
        );
    }

    #[Endpoint(
        title: 'عرض المراجعة الحالية',
        description: 'يعرض المراجعة السارية للمزاد مع توصية المراجعة الآلية والقرارات المسجّلة عليها. يُرجع قيمة فارغة إذا لم تكن هناك مراجعة سارية.'
    )]
    #[PathParameter('subjectType', description: 'نوع المحتوى الخاضع للمراجعة، والقيمة المدعومة حاليًا هي auction.')]
    #[PathParameter('subjectId', description: 'المعرّف العام للمزاد الخاضع للمراجعة (ULID).')]
    #[Response(200, description: 'المراجعة السارية أو قيمة فارغة عند عدم وجودها.')]
    public function current(string $subjectType, string $subjectId, ContentReviewRepository $reviews): JsonResponse
    {
        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);
        $review = $reviews->activeForSubject($type, $id);

        if ($review === null) {
            return $this->sendResponse(null, __('content_review.messages.no_review'));
        }

        $review->loadMissing('decisions.decidedBy:id,name');

        return $this->sendResponse(
            (new ContentReviewResource($review))->withAutomation(),
            __('content_review.messages.review_fetched')
        );
    }

    #[Endpoint(
        title: 'عرض تفاصيل مراجعة',
        description: 'يعرض تفاصيل سجل مراجعة بعينه مع توصية المراجعة الآلية والقرارات المسجّلة عليه.'
    )]
    #[PathParameter('contentReview', description: 'المعرّف العام لسجل المراجعة (ULID).')]
    #[Response(200, description: 'تفاصيل سجل المراجعة.')]
    public function show(ContentReview $contentReview): JsonResponse
    {
        $contentReview->loadMissing('decisions.decidedBy:id,name');

        return $this->sendResponse(
            (new ContentReviewResource($contentReview))->withAutomation(),
            __('content_review.messages.review_fetched')
        );
    }

    #[Endpoint(
        title: 'تشغيل مراجعة المحتوى',
        description: 'يبدأ مراجعة آلية جديدة لمحتوى المزاد عبر مزوّد المراجعة المهيّأ في النظام.'
    )]
    #[PathParameter('subjectType', description: 'نوع المحتوى الخاضع للمراجعة، والقيمة المدعومة حاليًا هي auction.')]
    #[PathParameter('subjectId', description: 'المعرّف العام للمزاد الخاضع للمراجعة (ULID).')]
    #[Response(201, description: 'سجل المراجعة بعد إنشائه.')]
    public function run(string $subjectType, string $subjectId, RunContentReviewAction $action): JsonResponse
    {
        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);

        return $this->sendResponse(
            new ContentReviewResource($action->execute($type, $id, Auth::id())),
            __('content_review.messages.review_requested'),
            201
        );
    }

    #[Endpoint(
        title: 'إعادة تشغيل مراجعة',
        description: 'يعيد تشغيل مراجعة سبق أن أخفقت أو انتهت دون نتيجة، وينشئ سجل مراجعة جديدًا للمزاد نفسه.'
    )]
    #[PathParameter('contentReview', description: 'المعرّف العام لسجل المراجعة (ULID).')]
    #[Response(201, description: 'سجل المراجعة الجديد.')]
    public function retry(ContentReview $contentReview, RetryContentReviewAction $action): JsonResponse
    {
        return $this->sendResponse(
            new ContentReviewResource($action->execute($contentReview, Auth::id())),
            __('content_review.messages.review_requested'),
            201
        );
    }

    #[Endpoint(
        title: 'إلغاء مراجعة',
        description: 'يلغي مراجعة قيد التنفيذ فلا يُعتد بنتيجتها عند وصولها.'
    )]
    #[PathParameter('contentReview', description: 'المعرّف العام لسجل المراجعة (ULID).')]
    #[Response(200, description: 'سجل المراجعة بعد إلغائه.')]
    public function cancel(ContentReview $contentReview, CancelContentReviewAction $action): JsonResponse
    {
        return $this->sendResponse(
            new ContentReviewResource($action->execute($contentReview, Auth::id())),
            __('content_review.messages.review_cancelled')
        );
    }

    #[Endpoint(
        title: 'تسجيل قرار المراجعة',
        description: 'يسجّل قرار المشرف على المراجعة باعتماد المزاد أو رفضه ويطبّقه على المزاد. مخالفة القرار لتوصية المراجعة الآلية تستلزم مبررًا مكتوبًا.'
    )]
    #[PathParameter('contentReview', description: 'المعرّف العام لسجل المراجعة (ULID).')]
    #[Response(200, description: 'سجل المراجعة بعد تسجيل القرار.')]
    public function decide(
        DecideContentReviewRequest $request,
        ContentReview $contentReview,
        ApplyContentReviewDecisionAction $action,
        ContentReviewRepository $reviews
    ): JsonResponse {
        $result = $action->applyHumanDecision(
            $contentReview->subject_type,
            (int) $contentReview->subject_id,
            $request->decision(),
            $request->user(),
            $request->reason(),
            (string) $contentReview->public_id,
        );

        $review = $reviews->findByPublicId((string) $contentReview->public_id);
        $review?->load('decisions.decidedBy:id,name');

        return $this->sendResponse(
            $review === null ? null : (new ContentReviewResource($review))->withAutomation(),
            __($result->overrodeRecommendation()
                ? 'content_review.messages.recommendation_overridden'
                : 'content_review.messages.recommendation_confirmed')
        );
    }

    #[Endpoint(
        title: 'تحويل المراجعة إلى يدوية',
        description: 'يوقف الاعتماد على توصية المراجعة الآلية للمزاد ويحيله إلى المراجعة اليدوية من المشرف.'
    )]
    #[PathParameter('subjectType', description: 'نوع المحتوى الخاضع للمراجعة، والقيمة المدعومة حاليًا هي auction.')]
    #[PathParameter('subjectId', description: 'المعرّف العام للمزاد الخاضع للمراجعة (ULID).')]
    #[Response(200, description: 'سجل مراجعات المزاد بعد التحويل إلى المراجعة اليدوية.')]
    public function forceManual(
        string $subjectType,
        string $subjectId,
        ForceManualReviewAction $action,
        ContentReviewRepository $reviews
    ): JsonResponse {
        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);

        $action->execute($type, $id, Auth::id());

        return $this->sendResponse(
            ContentReviewResource::collection($reviews->historyForSubject($type, $id, 15)),
            __('content_review.messages.review_forced_manual')
        );
    }
}
