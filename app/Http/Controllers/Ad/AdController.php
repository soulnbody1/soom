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
use App\Repositories\Ad\Queries\TrashedAdQuery;
use App\Services\Ad\Actions\CreateAdAction;
use App\Services\Ad\Actions\UpdateAdAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

#[Group(name: 'الإعلانات', description: 'إنشاء الإعلانات وتعديلها وعرض تفاصيلها باستخدام المعرّف العام ULID.', weight: 7)]
class AdController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests;

    #[Endpoint(title: 'إنشاء إعلان', description: 'ينشئ إعلانًا جديدًا للمستخدم الحالي ويعيد معرّفه العام ULID لاستخدامه في الروابط والعمليات اللاحقة.')]
    #[BodyParameter('title', description: 'عنوان الإعلان.', required: true, type: 'string')]
    #[BodyParameter('description', description: 'وصف الإعلان.', required: true, type: 'string')]
    #[BodyParameter('price', description: 'سعر الإعلان.', required: true, type: 'number')]
    #[BodyParameter('category_id', description: 'المعرّف الرقمي للتصنيف.', required: true, type: 'integer')]
    #[Response(200, description: 'تم إنشاء الإعلان وإرجاع بياناته متضمنة ULID.')]
    #[Response(422, description: 'بيانات الإعلان غير صحيحة.')]
    public function store(StoreAdRequest $request, CreateAdAction $createAd): AdResource
    {
        $this->authorize('create', Ad::class);

        $ad = $createAd->execute(
            AdWriteInputDTO::fromValidated($request->validated()),
            (int) Auth::id()
        );

        return new AdResource($ad->load(Ad::$defaultRelations));
    }

    #[Endpoint(title: 'تعديل إعلان', description: 'يعدّل إعلانًا يملكه المستخدم الحالي باستخدام المعرّف العام ULID.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[BodyParameter('title', description: 'عنوان الإعلان.', required: true, type: 'string')]
    #[BodyParameter('description', description: 'وصف الإعلان.', required: true, type: 'string')]
    #[BodyParameter('price', description: 'سعر الإعلان.', required: true, type: 'number')]
    #[Response(200, description: 'تم تعديل الإعلان.')]
    #[Response(404, description: 'الإعلان غير موجود أو لا يملكه المستخدم.')]
    #[Response(422, description: 'بيانات الإعلان غير صحيحة.')]
    public function update(StoreAdRequest $request, string $ad, UpdateAdAction $updateAd, TrashedAdQuery $ownedAds): AdResource
    {
        $ad = $ownedAds->ownedActiveOrFail($ad);
        $this->authorize('update', $ad);

        $updateAd->execute($ad, AdWriteInputDTO::fromValidated($request->validated()));

        return new AdResource($ad->fresh(Ad::$defaultRelations));
    }

    #[Endpoint(title: 'عرض تفاصيل إعلان', description: 'يعرض تفاصيل الإعلان العام باستخدام معرّفه العام ULID دون كشف المعرّف الرقمي الداخلي.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تفاصيل الإعلان.')]
    #[Response(404, description: 'الإعلان غير موجود.')]
    public function show(string $ad, AdDetailQuery $details): JsonResponse
    {
        $viewer = auth('sanctum')->user();
        $ad = $details->findOrFail($ad, $viewer);

        if ($viewer !== null) {
            RecordAdEngagement::dispatch((int) $ad->id, (int) $viewer->id, AdInteractionAction::Click, true);
        }

        return $this->sendResponse(
            new AdResource($ad),
            'تم جلب الاعلان بنجاح.'
        );
    }
}
