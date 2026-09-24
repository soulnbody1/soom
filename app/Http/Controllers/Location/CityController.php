<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreCityRequest;
use App\Http\Requests\Location\UpdateCityRequest;
use App\Http\Resources\Location\CityResource;
use App\Models\City;
use App\Services\Location\GeographyDeletionGuard;
use App\Services\Location\LocationCache;
use App\Support\Market\MarketContext;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;

#[Group(name: 'المواقع الجغرافية', description: 'الدول والمحافظات والمدن المتاحة في السوق الحالي وإدارتها من لوحة التحكم.', weight: 15)]
class CityController extends Controller
{
    #[Endpoint(title: 'عرض المدن', description: 'يعرض المدن التابعة لدولة السوق الحالي.')]
    #[Response(200, description: 'قائمة المدن المتاحة في السوق الحالي.')]
    public function index(LocationCache $cache, MarketContext $market)
    {
        return response()->json([
            'data' => $cache->remember(
                'cities:all',
                fn (): array => CityResource::collection(
                    City::query()->whereHas('state', fn ($state) => $state->where('country_id', $market->market()->country_id))->get()
                )->resolve(request())
            ),
        ]);
    }

    #[Endpoint(title: 'إنشاء مدينة', description: 'ينشئ مدينة جديدة ويربطها بالمحافظة المحددة.')]
    #[Response(201, description: 'تم إنشاء المدينة وإرجاع بياناتها.')]
    public function store(StoreCityRequest $request)
    {
        $city = City::create($request->validated());

        return response()->json([
            'message' => 'City created successfully.',
            'data' => new CityResource($city),
        ], 201);
    }

    #[Endpoint(title: 'تحديث مدينة', description: 'يحدّث بيانات المدينة المحددة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمدينة.')]
    #[Response(200, description: 'تم تحديث المدينة وإرجاع بياناتها.')]
    #[Response(404, description: 'المدينة غير موجودة.')]
    public function update(UpdateCityRequest $request, $id)
    {
        $city = City::find($id);

        if (! $city) {
            return response()->json(['data' => [], 'message' => 'City not found.'], 404);
        }

        $city->update($request->validated());

        return response()->json([
            'message' => 'City updated successfully.',
            'data' => new CityResource($city),
        ]);
    }

    #[Endpoint(title: 'حذف مدينة', description: 'يحذف المدينة بعد التأكد من عدم ارتباط بيانات تشغيلية تمنع حذفها.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للمدينة.')]
    #[Response(200, description: 'تم حذف المدينة بنجاح.')]
    #[Response(404, description: 'المدينة غير موجودة.')]
    public function destroy($id)
    {
        $city = City::find($id);

        if (! $city) {
            return response()->json(['data' => [], 'message' => 'City not found.'], 404);
        }

        app(GeographyDeletionGuard::class)->assertCityDeletable((int) $city->id);

        $city->delete();

        return response()->json(['message' => 'City deleted successfully.']);
    }
}
