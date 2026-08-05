<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\ContentReviewHistoryRequest;
use App\Http\Resources\ContentReview\ContentReviewResource;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Actions\CancelContentReviewAction;
use App\Services\ContentReview\Actions\ForceManualReviewAction;
use App\Services\ContentReview\Actions\RetryContentReviewAction;
use App\Services\ContentReview\Actions\RunContentReviewAction;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class ContentReviewController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    public function history(
        ContentReviewHistoryRequest $request,
        string $subjectType,
        string $subjectId,
        ContentReviewRepository $reviews
    ): JsonResponse {
        Gate::authorize('viewAny', ContentReview::class);

        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);

        return $this->sendResponse(
            ContentReviewResource::collection($reviews->historyForSubject($type, $id, $request->perPage())),
            __('content_review.messages.reviews_fetched')
        );
    }

    public function current(string $subjectType, string $subjectId, ContentReviewRepository $reviews): JsonResponse
    {
        Gate::authorize('viewAny', ContentReview::class);

        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);
        $review = $reviews->activeForSubject($type, $id);

        if ($review === null) {
            return $this->sendResponse(null, __('content_review.messages.no_review'));
        }

        $review->loadMissing('decisions.decidedBy:id,name');

        return $this->sendResponse(
            new ContentReviewResource($review),
            __('content_review.messages.review_fetched')
        );
    }

    public function show(ContentReview $contentReview): JsonResponse
    {
        Gate::authorize('view', $contentReview);

        $contentReview->loadMissing('decisions.decidedBy:id,name');

        return $this->sendResponse(
            new ContentReviewResource($contentReview),
            __('content_review.messages.review_fetched')
        );
    }

    public function run(string $subjectType, string $subjectId, RunContentReviewAction $action): JsonResponse
    {
        Gate::authorize('run', ContentReview::class);

        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);

        return $this->sendResponse(
            new ContentReviewResource($action->execute($type, $id, Auth::id())),
            __('content_review.messages.review_requested'),
            201
        );
    }

    public function retry(ContentReview $contentReview, RetryContentReviewAction $action): JsonResponse
    {
        Gate::authorize('run', ContentReview::class);

        return $this->sendResponse(
            new ContentReviewResource($action->execute($contentReview, Auth::id())),
            __('content_review.messages.review_requested'),
            201
        );
    }

    public function cancel(ContentReview $contentReview, CancelContentReviewAction $action): JsonResponse
    {
        Gate::authorize('cancel', $contentReview);

        return $this->sendResponse(
            new ContentReviewResource($action->execute($contentReview, Auth::id())),
            __('content_review.messages.review_cancelled')
        );
    }

    public function forceManual(
        string $subjectType,
        string $subjectId,
        ForceManualReviewAction $action,
        ContentReviewRepository $reviews
    ): JsonResponse {
        Gate::authorize('forceManual', ContentReview::class);

        $type = $this->subjects->type($subjectType);
        $id = $this->subjects->id($type, $subjectId);

        $action->execute($type, $id, Auth::id());

        return $this->sendResponse(
            ContentReviewResource::collection($reviews->historyForSubject($type, $id, 15)),
            __('content_review.messages.review_forced_manual')
        );
    }
}
