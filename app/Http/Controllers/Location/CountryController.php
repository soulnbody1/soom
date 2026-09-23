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

class CountryController extends Controller
{
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

    public function store(StoreCountryRequest $request)
    {
        $country = Country::create($request->validated());

        return response()->json([
            'message' => 'Country created successfully.',
            'data' => new CountryResource($country),
        ], 201);
    }

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
