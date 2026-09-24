<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\ContentReviewMetricsRequest;
use App\Services\ContentReview\Support\ContentReviewMetricsReporter;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'إدارة مراجعة المحتوى', description: 'إعداد منظومة مراجعة المحتوى ومراقبة مزوّد الذكاء الاصطناعي والسياسات والمقاييس التشغيلية.', weight: 21)]
final class ContentReviewMetricsController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    #[Endpoint(title: 'عرض مقاييس مراجعة المحتوى', description: 'يعرض مؤشرات الأداء والنتائج والاستهلاك خلال النطاق الزمني المطلوب.')]
    #[QueryParameter('subject_type', description: 'نوع المحتوى المراد عرض مقاييسه.')]
    #[QueryParameter('range', description: 'النطاق الزمني المطلوب وفق القيم التي يدعمها النظام.')]
    #[Response(200, description: 'مقاييس مراجعة المحتوى للنطاق المحدد.')]
    public function show(ContentReviewMetricsRequest $request, ContentReviewMetricsReporter $metrics): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            $metrics->report($type, $request->range()),
            __('content_review.messages.metrics_fetched')
        );
    }
}
