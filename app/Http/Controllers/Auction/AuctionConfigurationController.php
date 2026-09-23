<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\CreateConfigurationVersionRequest;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Repositories\Auction\AuctionConfigurationRepository;
use App\Services\Auction\Actions\CreateConfigurationVersionAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'إعدادات المزادات', description: 'إصدارات إعدادات المزادات المالية والزمنية والإعدادات التشغيلية المشتقة من بيئة التشغيل.', weight: 14)]
final class AuctionConfigurationController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض إصدارات إعدادات المزادات',
        description: 'يعرض ملخّص إصدارات إعدادات المزادات مع بيان الإصدار الساري، دون محتوى الإعدادات التفصيلي.'
    )]
    #[Response(200, description: 'قائمة ملخّصة بإصدارات الإعدادات.')]
    public function index(AuctionConfigurationRepository $configurations): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse(
            $configurations->list()
                ->map(fn (AuctionConfigurationVersion $version): array => $this->summaryPayload($version))
                ->values(),
            __('auction.messages.configuration_versions_fetched')
        );
    }

    #[Endpoint(
        title: 'عرض إصدار إعدادات محدد',
        description: 'يعرض ملخّص إصدار الإعدادات مع محتوى الإعدادات كاملًا كما ثُبّت وقت نشره.'
    )]
    #[PathParameter('configurationVersion', description: 'المعرّف العام لإصدار الإعدادات (ULID).')]
    #[Response(200, description: 'تفاصيل إصدار الإعدادات ومحتواه.')]
    public function show(AuctionConfigurationVersion $configurationVersion): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $configurationVersion->loadMissing('creator:id,name');

        return $this->sendResponse(
            $this->summaryPayload($configurationVersion) + [
                'configuration' => (array) $configurationVersion->configuration,
            ],
            __('auction.messages.configuration_version_fetched')
        );
    }

    #[Endpoint(
        title: 'إنشاء إصدار إعدادات جديد',
        description: 'ينشئ إصدارًا جديدًا من إعدادات المزادات برقم إصدار متسلسل ويجعله الإصدار الساري عند نشره. لا يؤثر ذلك على المزادات القائمة لأن كل مزاد يحتفظ بنسخة الإعدادات المثبّتة عليه.'
    )]
    #[Response(201, description: 'تفاصيل الإصدار بعد إنشائه.')]
    public function store(
        CreateConfigurationVersionRequest $request,
        CreateConfigurationVersionAction $action
    ): JsonResponse {
        Gate::authorize('viewAny', Auction::class);

        $version = $action->execute(
            (array) $request->validated('configuration'),
            (bool) $request->boolean('publish', true),
            (int) Auth::id()
        );

        $version->loadMissing('creator:id,name');

        return $this->sendResponse(
            $this->summaryPayload($version) + [
                'configuration' => (array) $version->configuration,
            ],
            __('auction.messages.configuration_version_created'),
            201
        );
    }

    private function summaryPayload(AuctionConfigurationVersion $version): array
    {
        return [
            'id' => $version->public_id,
            'version_number' => $version->version_number,
            'is_active' => $version->is_active,
            'market' => strtolower((string) $version->market->code),
            'published_at' => $version->published_at?->toIso8601String(),
            'created_by' => $version->relationLoaded('creator') && $version->creator ? [
                'id' => $version->creator->id,
                'name' => $version->creator->name,
            ] : null,
        ];
    }
}
