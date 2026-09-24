<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreCountryRequest;
use App\Http\Requests\Location\UpdateCountryRequest;
use App\Http\Resources\Location\CountryResource;
use App\Http\Resources\Location\StateResource;
use App\Models\Country;
use App\Services\Location\GeographyDeletionGuard;
use App\Services\Location\LocationCache;
use App\Support\Market\MarketContext;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;

#[Group(name: 'المواقع الجغرافية', description: 'الدول والمحافظات والمدن المتاحة في السوق الحالي وإدارتها من لوحة التحكم.', weight: 15)]
class CountryController extends Controller
{
    #[Endpoint(title: 'عرض دولة السوق', description: 'يعرض الدولة المرتبطة بالسوق الحالي.')]
    #[Response(200, description: 'بيانات دولة السوق الحالي.')]
    public function index(LocationCache $cache, MarketContext $market)
    {
        return response()->json([
            'data' => $cache->remember(
                'countries:all',
                fn (): array => CountryResource::collection(
                    Country::query()->whereKey($market->market()->country_id)->get()
                )->resolve(request())
            ),
        ]);
    }

    #[Endpoint(title: 'إنشاء دولة', description: 'ينشئ دولة جديدة بالبيانات ورمز الاتصال المحددين.')]
    #[Response(201, description: 'تم إنشاء الدولة وإرجاع بياناتها.')]
    public function store(StoreCountryRequest $request)
    {
        $country = Country::create($request->validated());

        return response()->json([
            'message' => 'Country created successfully.',
            'data' => new CountryResource($country),
        ], 201);
    }

    #[Endpoint(title: 'عرض محافظات دولة', description: 'يعرض محافظات الدولة المطلوبة إذا كانت مرتبطة بالسوق الحالي.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للدولة.')]
    #[Response(200, description: 'قائمة محافظات الدولة.')]
    #[Response(404, description: 'الدولة غير موجودة في السوق الحالي.')]
    public function show($id, LocationCache $cache, MarketContext $market)
    {
        $country = Country::query()->whereKey($market->market()->country_id)->find($id);
        if (! $country) {
            return response()->json(['data' => [], 'message' => 'Country not found.'], 404);
        }

        return response()->json([
            'data' => $cache->remember(
                'country:'.$country->id.':states',
                fn (): array => StateResource::collection($country->states()->get())->resolve(request())
            ),
        ]);
    }

    #[Endpoint(title: 'تحديث دولة', description: 'يحدّث بيانات الدولة المحددة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للدولة.')]
    #[Response(200, description: 'تم تحديث الدولة وإرجاع بياناتها.')]
    #[Response(404, description: 'الدولة غير موجودة.')]
    public function update(UpdateCountryRequest $request, $id)
    {
        $country = Country::find($id);
        if (! $country) {
            return response()->json(['data' => [], 'message' => 'Country not found.'], 404);
        }

        $country->update($request->validated());

        return response()->json([
            'message' => 'Country updated successfully.',
            'data' => new CountryResource($country),
        ]);
    }

    #[Endpoint(title: 'حذف دولة', description: 'يحذف الدولة بعد التأكد من عدم ارتباط بيانات تشغيلية تمنع حذفها.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للدولة.')]
    #[Response(200, description: 'تم حذف الدولة بنجاح.')]
    #[Response(404, description: 'الدولة غير موجودة.')]
    public function destroy($id)
    {
        $country = Country::find($id);
        if (! $country) {
            return response()->json(['data' => [], 'message' => 'Country not found.'], 404);
        }

        app(GeographyDeletionGuard::class)->assertCountryDeletable((int) $country->id);

        $country->delete();

        return response()->json(['message' => 'Country deleted successfully.']);
    }
}
