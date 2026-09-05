<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Domain\Ad\Enums\AdInteractionAction;
use App\DTO\Ad\AdWriteInputDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdRequest;
use App\Http\Resources\AdResource;
use App\Jobs\Ad\RecordAdEngagement;
use App\Models\Ad;
use App\Repositories\Ad\Queries\AdDetailQuery;
use App\Services\Ad\Actions\CreateAdAction;
use App\Services\Ad\Actions\UpdateAdAction;
use App\Traits\ApiResponseTrait;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests;

    public function store(StoreAdRequest $request, CreateAdAction $createAd): AdResource
    {
        $this->authorize('create', Ad::class);

        $ad = $createAd->execute(
            AdWriteInputDTO::fromValidated($request->validated()),
            (int) Auth::id()
        );

        return new AdResource($ad->load(Ad::$defaultRelations));
    }

    public function update(StoreAdRequest $request, Ad $ad, UpdateAdAction $updateAd): AdResource
    {
        $this->authorize('update', $ad);

        $updateAd->execute($ad, AdWriteInputDTO::fromValidated($request->validated()));

        return new AdResource($ad->fresh(Ad::$defaultRelations));
    }

    public function show(int $id, AdDetailQuery $details): JsonResponse
    {
        $viewer = auth('sanctum')->user();
        $ad = $details->findOrFail($id, $viewer);

        if ($viewer !== null) {
            RecordAdEngagement::dispatch((int) $ad->id, (int) $viewer->id, AdInteractionAction::Click, true);
        }

        return $this->sendResponse(
            new AdResource($ad),
            'تم جلب الاعلان بنجاح.'
        );
    }
}
