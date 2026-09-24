<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Services\ContentReview\Actions\TestContentReviewProviderAction;
use App\Services\ContentReview\Support\ContentReviewHealthReporter;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'إدارة مراجعة المحتوى', description: 'إعداد منظومة مراجعة المحتوى ومراقبة مزوّد الذكاء الاصطناعي والسياسات والمقاييس التشغيلية.', weight: 21)]
final class ContentReviewHealthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    #[Endpoint(title: 'فحص جاهزية مراجعة المحتوى', description: 'يعرض حالة إعداد مزوّد المراجعة والسياسة والإعدادات الفعالة لنوع المحتوى المحدد.')]
    #[QueryParameter('subject_type', description: 'نوع المحتوى المراد فحصه، ويُستخدم النوع الافتراضي عند عدم إرساله.')]
    #[Response(200, description: 'تقرير جاهزية منظومة مراجعة المحتوى.')]
    public function show(Request $request, ContentReviewHealthReporter $health): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            $health->report($type),
            __('content_review.messages.review_fetched')
        );
    }

    #[Endpoint(title: 'اختبار مزوّد مراجعة المحتوى', description: 'ينفّذ اختبار اتصال وتشغيل آمن مع مزوّد المراجعة لنوع المحتوى المحدد.')]
    #[QueryParameter('subject_type', description: 'نوع المحتوى المراد اختبار المزوّد معه.')]
    #[Response(200, description: 'نتيجة اختبار مزوّد مراجعة المحتوى.')]
    public function test(Request $request, TestContentReviewProviderAction $action): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            $action->execute($type),
            __('content_review.messages.provider_tested')
        );
    }
}
