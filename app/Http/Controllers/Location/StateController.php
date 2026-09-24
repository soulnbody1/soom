<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreStateRequest;
use App\Http\Requests\Location\UpdateStateRequest;
use App\Http\Resources\Location\CityResource;
use App\Http\Resources\Location\StateResource;
use App\Models\State;
use App\Services\Location\GeographyDeletionGuard;
use App\Services\Location\LocationCache;
use App\Support\Market\MarketContext;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;

#[Group(name: 'المواقع الجغرافية', description: 'الدول والمحافظات والمدن المتاحة في السوق الحالي وإدارتها من لوحة التحكم.', weight: 15)]
class StateController extends Controller
{
    #[Endpoint(title: 'عرض المحافظات', description: 'يعرض المحافظات التابعة لدولة السوق الحالي.')]
    #[Response(200, description: 'قائمة المحافظات المتاحة في السوق الحالي.')]
    public function index(LocationCache $cache, MarketContext $market)
    {
        return response()->json([
            'data' => $cache->remember(
                'states:all',
                fn (): array => StateResource::collection(
                    State::query()->where('country_id', $market->market()->country_id)->get()
                )->resolve(request())
            ),
        ]);
    }

    #[Endpoint(title: 'إنشاء محافظة', description: 'ينشئ محافظة جديدة ويربطها بالدولة المحددة.')]
    #[Response(201, description: 'تم إنشاء المحافظة وإرجاع بياناتها.')]
    public function store(StoreStateRequest $request)
    {
        $state = State::create($request->validated());

        return response()->json([
            'message' => 'State created successfully.',
            'data' => new StateResource($state),
        ], 201);
    }

    #[Endpoint(title: 'عرض مدن محافظة', description: 'يعرض مدن المحافظة المطلوبة إذا كانت تابعة لدولة السوق الحالي.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمحافظة.')]
    #[Response(200, description: 'قائمة مدن المحافظة.')]
    #[Response(404, description: 'المحافظة غير موجودة في السوق الحالي.')]
    public function show($id, LocationCache $cache, MarketContext $market)
    {
        $state = State::query()->where('country_id', $market->market()->country_id)->find($id);

        if (! $state) {
            return response()->json(['data' => [], 'message' => 'State not found.'], 404);
        }

        return response()->json([
            'data' => $cache->remember(
                'state:'.$state->id.':cities',
                fn (): array => CityResource::collection($state->cities()->get())->resolve(request())
            ),
        ]);
    }

    #[Endpoint(title: 'تحديث محافظة', description: 'يحدّث بيانات المحافظة المحددة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمحافظة.')]
    #[Response(200, description: 'تم تحديث المحافظة وإرجاع بياناتها.')]
    #[Response(404, description: 'المحافظة غير موجودة.')]
    public function update(UpdateStateRequest $request, $id)
    {
        $state = State::find($id);

        if (! $state) {
            return response()->json(['data' => [], 'message' => 'State not found.'], 404);
        }

        $state->update($request->validated());

        return response()->json([
            'message' => 'State updated successfully.',
            'data' => new StateResource($state),
        ]);
    }

    #[Endpoint(title: 'حذف محافظة', description: 'يحذف المحافظة بعد التأكد من عدم ارتباط بيانات تشغيلية تمنع حذفها.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمحافظة.')]
    #[Response(200, description: 'تم حذف المحافظة بنجاح.')]
    #[Response(404, description: 'المحافظة غير موجودة.')]
    public function destroy($id)
    {
        $state = State::find($id);

        if (! $state) {
            return response()->json(['data' => [], 'message' => 'State not found.'], 404);
        }

        app(GeographyDeletionGuard::class)->assertStateDeletable((int) $state->id);

        $state->delete();

        return response()->json(['message' => 'State deleted successfully.']);
    }
}
