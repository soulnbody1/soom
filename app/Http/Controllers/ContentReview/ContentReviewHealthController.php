<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Services\ContentReview\Actions\TestContentReviewProviderAction;
use App\Services\ContentReview\Support\ContentReviewHealthReporter;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContentReviewHealthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    public function show(Request $request, ContentReviewHealthReporter $health): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            $health->report($type),
            __('content_review.messages.review_fetched')
        );
    }

    public function test(Request $request, TestContentReviewProviderAction $action): JsonResponse
    {
        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            $action->execute($type),
            __('content_review.messages.provider_tested')
        );
    }
}
