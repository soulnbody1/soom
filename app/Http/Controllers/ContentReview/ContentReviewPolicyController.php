<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\PublishContentReviewPolicyRequest;
use App\Http\Resources\ContentReview\ContentReviewPolicyResource;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Repositories\ContentReview\ContentReviewPolicyRepository;
use App\Services\ContentReview\Actions\PublishContentReviewPolicyAction;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class ContentReviewPolicyController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly ReviewSubjectResolver $subjects) {}

    public function index(Request $request, ContentReviewPolicyRepository $policies): JsonResponse
    {
        Gate::authorize('managePolicy', ContentReview::class);

        $type = $this->subjects->typeOrDefault($request->query('subject_type'));

        return $this->sendResponse(
            ContentReviewPolicyResource::collection($policies->allWithUsage($type)),
            __('content_review.messages.reviews_fetched')
        );
    }

    public function active(Request $request, ContentReviewPolicyRepository $policies): JsonResponse
    {
        Gate::authorize('managePolicy', ContentReview::class);

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

    public function show(Request $request, ContentReviewPolicy $contentReviewPolicy): JsonResponse
    {
        Gate::authorize('managePolicy', ContentReview::class);

        $contentReviewPolicy->loadMissing('creator:id,name')->loadCount('reviews');

        return $this->sendResponse(
            $this->detailedPayload($request, $contentReviewPolicy),
            __('content_review.messages.review_fetched')
        );
    }

    public function store(
        PublishContentReviewPolicyRequest $request,
        PublishContentReviewPolicyAction $action
    ): JsonResponse {
        Gate::authorize('managePolicy', ContentReview::class);

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
