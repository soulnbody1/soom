<?php

declare(strict_types=1);

namespace App\Http\Controllers\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContentReview\PublishContentReviewSettingsRequest;
use App\Http\Resources\ContentReview\ContentReviewSettingsResource;
use App\Repositories\ContentReview\ContentReviewSettingRepository;
use App\Services\ContentReview\Actions\PublishContentReviewSettingsAction;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

#[Group(name: 'إدارة مراجعة المحتوى', description: 'إعداد منظومة مراجعة المحتوى ومراقبة مزوّد الذكاء الاصطناعي والسياسات والمقاييس التشغيلية.', weight: 21)]
final class ContentReviewSettingsController extends Controller
{
    use ApiResponseTrait;

    private const DEFAULT_SCOPE = 'global';

    #[Endpoint(title: 'عرض إعدادات المراجعة الفعالة', description: 'يعرض الإعدادات الفعالة ونمط المراجعة المحسوب لكل نوع محتوى داخل النطاق المحدد.')]
    #[QueryParameter('scope', description: 'نطاق الإعدادات، والقيمة الافتراضية global.')]
    #[Response(200, description: 'الإعدادات الفعالة وحالة المفتاح العام وإعدادات أنواع المحتوى.')]
    public function show(
        Request $request,
        ContentReviewSettingRepository $settings,
        ReviewModeResolver $modes
    ): JsonResponse {
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

    #[Endpoint(title: 'عرض إصدارات إعدادات المراجعة', description: 'يعرض سجل إصدارات إعدادات المراجعة للنطاق المحدد.')]
    #[QueryParameter('scope', description: 'نطاق الإعدادات، والقيمة الافتراضية global.')]
    #[Response(200, description: 'قائمة إصدارات إعدادات المراجعة.')]
    public function index(Request $request, ContentReviewSettingRepository $settings): JsonResponse
    {
        return $this->sendResponse(
            ContentReviewSettingsResource::collection($settings->all($this->scope($request))),
            __('content_review.messages.reviews_fetched')
        );
    }

    #[Endpoint(title: 'نشر إعدادات مراجعة جديدة', description: 'ينشئ إصدارًا جديدًا من إعدادات المراجعة ويجعله فعالًا في النطاق المحدد.')]
    #[Response(201, description: 'تم نشر إعدادات المراجعة وإرجاع الإصدار الجديد.')]
    public function store(
        PublishContentReviewSettingsRequest $request,
        PublishContentReviewSettingsAction $action
    ): JsonResponse {
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
