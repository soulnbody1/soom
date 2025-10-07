<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreCountryRequest;
use App\Http\Requests\Location\UpdateCountryRequest;
use App\Http\Resources\Location\CountryResource;
use App\Http\Resources\Location\StateResource;
use App\Models\Country;

class CountryController extends Controller
{
    public function index()
    {
        $countries = Country::all();
        return CountryResource::collection($countries);
    }

    public function store(StoreCountryRequest $request)
    {
        $country = Country::create($request->validated());

        return response()->json([
            'message' => 'Country created successfully.',
            'data' => new CountryResource($country)
        ], 201);
    }

    public function show($id)
    {
        $country = Country::with('states')->find($id);
        if (!$country) {
            return response()->json(['data' => [], 'message' => 'Country not found.'], 404);
        }
        return response()->json([
            'data' => StateResource::collection($country->states)
        ]);
    }

    public function update(UpdateCountryRequest $request, $id)
    {
        $country = Country::find($id);
        if (!$country) {
            return response()->json(['data' => [], 'message' => 'Country not found.'], 404);
        }

        $country->update($request->validated());

        return response()->json([
            'message' => 'Country updated successfully.',
            'data' => new CountryResource($country)
        ]);
    }

    public function destroy($id)
    {
        $country = Country::find($id);
        if (!$country) {
            return response()->json(['data' => [], 'message' => 'Country not found.'], 404);
        }

        $country->delete();

        return response()->json(['message' => 'Country deleted successfully.']);
    }
}
