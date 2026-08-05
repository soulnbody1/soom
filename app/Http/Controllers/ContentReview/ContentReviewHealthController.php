<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Models\ContentReview\ContentReview;
use App\Services\ContentReview\Actions\TestContentReviewProviderAction;
use App\Services\ContentReview\Support\ContentReviewHealthReporter;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ContentReviewHealthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    public function show(Request $request, ContentReviewHealthReporter $health): JsonResponse
    {
        Gate::authorize('viewAny', ContentReview::class);

        $type = $this->subjects->typeOrDefault($request->query('subject_type'));
        $includeBudget = Gate::allows('viewCosts', ContentReview::class);

        return $this->sendResponse(
            $health->report($type, $includeBudget),
            __('content_review.messages.review_fetched')
        );
    }

    public function test(Request $request, TestContentReviewProviderAction $action): JsonResponse
    {
        Gate::authorize('manageSettings', ContentReview::class);

        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            $action->execute($type),
            __('content_review.messages.provider_tested')
        );
    }
}
