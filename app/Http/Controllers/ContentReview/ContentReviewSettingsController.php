<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\PublishContentReviewSettingsRequest;
use App\Http\Resources\ContentReview\ContentReviewSettingsResource;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewSettingRepository;
use App\Services\ContentReview\Actions\PublishContentReviewSettingsAction;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class ContentReviewSettingsController extends Controller
{
    use ApiResponseTrait;

    private const DEFAULT_SCOPE = 'global';

    public function show(
        Request $request,
        ContentReviewSettingRepository $settings,
        ReviewModeResolver $modes
    ): JsonResponse {
        Gate::authorize('manageSettings', ContentReview::class);

        $scope = $this->scope($request);
        $active = $settings->active($scope);
        $active?->loadMissing('creator:id,name');

        $subjects = [];

        foreach (ReviewableSubjectType::cases() as $type) {
            $mode = $modes->resolve($type);

            $subjects[$type->value] = [
                'label' => __('content_review.subject_types.'.$type->value),
                'resolved_mode' => $mode->value,
                'resolved_mode_label' => __('content_review.modes.'.$mode->value),
                'effective' => $modes->effectiveSettings($type),
            ];
        }

        return $this->sendResponse([
            'scope' => $scope,
            'master_switch_enabled' => config('content_review.enabled') === true,
            'active' => $active === null ? null : new ContentReviewSettingsResource($active),
            'subjects' => $subjects,
        ], __('content_review.messages.review_fetched'));
    }

    public function index(Request $request, ContentReviewSettingRepository $settings): JsonResponse
    {
        Gate::authorize('manageSettings', ContentReview::class);

        return $this->sendResponse(
            ContentReviewSettingsResource::collection($settings->all($this->scope($request))),
            __('content_review.messages.reviews_fetched')
        );
    }

    public function store(
        PublishContentReviewSettingsRequest $request,
        PublishContentReviewSettingsAction $action
    ): JsonResponse {
        Gate::authorize('manageSettings', ContentReview::class);

        $published = $action->execute($request->scope(), $request->settingsPayload(), Auth::id());
        $published->loadMissing('creator:id,name');

        return $this->sendResponse(
            new ContentReviewSettingsResource($published),
            __('content_review.messages.settings_published'),
            201
        );
    }

    private function scope(Request $request): string
    {
        $scope = (string) $request->query('scope', self::DEFAULT_SCOPE);

        return $scope === '' ? self::DEFAULT_SCOPE : $scope;
    }
}
