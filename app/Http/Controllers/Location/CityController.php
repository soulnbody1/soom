<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreCityRequest;
use App\Http\Requests\Location\UpdateCityRequest;
use App\Http\Resources\Location\CityResource;
use App\Models\City;
use App\Services\Location\GeographyDeletionGuard;
use App\Services\Location\LocationCache;

class CityController extends Controller
{
    public function index(LocationCache $cache)
    {
        return response()->json([
            'data' => $cache->remember(
                'cities:all',
                fn (): array => CityResource::collection(City::all())->resolve(request())
            ),
        ]);
    }

    public function store(StoreCityRequest $request)
    {
        $city = City::create($request->validated());

        return response()->json([
            'message' => 'City created successfully.',
            'data' => new CityResource($city),
        ], 201);
    }

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
