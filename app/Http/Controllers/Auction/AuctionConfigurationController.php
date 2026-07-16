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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class AuctionConfigurationController extends Controller
{
    use ApiResponseTrait;

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
            'published_at' => $version->published_at?->toIso8601String(),
            'created_by' => $version->relationLoaded('creator') && $version->creator ? [
                'id' => $version->creator->id,
                'name' => $version->creator->name,
            ] : null,
        ];
    }
}
