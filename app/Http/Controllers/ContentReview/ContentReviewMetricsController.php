<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\ContentReviewMetricsRequest;
use App\Services\ContentReview\Support\ContentReviewMetricsReporter;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

final class ContentReviewMetricsController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    public function show(ContentReviewMetricsRequest $request, ContentReviewMetricsReporter $metrics): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            $metrics->report($type, $request->range()),
            __('content_review.messages.metrics_fetched')
        );
    }
}
