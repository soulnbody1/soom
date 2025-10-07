<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Models\County;
use App\Http\Requests\Location\StoreCountyRequest;
use App\Http\Requests\Location\UpdateCountyRequest;
use App\Http\Resources\Location\CountyResource;

class CountyController extends Controller
{
    public function index()
    {
        $counties = County::all();
        return CountyResource::collection($counties);
    }

    public function store(StoreCountyRequest $request)
    {
        $county = County::create($request->validated());

        return response()->json([
            'message' => 'County created successfully.',
            'data' => new CountyResource($county)
        ], 201);
    }

    public function update(UpdateCountyRequest $request, $id)
    {
        $county = County::find($id);

        if (!$county) {
            return response()->json(['data' => [], 'message' => 'County not found.'], 404);
        }

        $county->update($request->validated());

        return response()->json([
            'message' => 'County updated successfully.',
            'data' => new CountyResource($county)
        ]);
    }

    public function destroy($id)
    {
        $county = County::find($id);

        if (!$county) {
            return response()->json(['data' => [], 'message' => 'County not found.'], 404);
        }

        $county->delete();

        return response()->json(['message' => 'County deleted successfully.']);
    }
}
