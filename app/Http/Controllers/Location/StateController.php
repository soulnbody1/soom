<?php
namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Models\State;
use App\Http\Requests\Location\StoreStateRequest;
use App\Http\Requests\Location\UpdateStateRequest;
use App\Http\Resources\Location\CityResource;
use App\Http\Resources\Location\StateResource;

class StateController extends Controller
{
    public function index()
    {
        $states = State::all();
        return StateResource::collection($states);
    }

    public function store(StoreStateRequest $request)
    {
        $state = State::create($request->validated());

        return response()->json([
            'message' => 'State created successfully.',
            'data' => new StateResource($state)
        ], 201);
    }

    public function show($id)
    {
        $state = State::with('cities')->find($id);

        if (!$state) {
            return response()->json(['data' => [], 'message' => 'State not found.'], 404);
        }

        return response()->json([
            'data' => CityResource::collection($state->cities)
        ]);
    }

    public function update(UpdateStateRequest $request, $id)
    {
        $state = State::find($id);

        if (!$state) {
            return response()->json(['data' => [], 'message' => 'State not found.'], 404);
        }

        $state->update($request->validated());

        return response()->json([
            'message' => 'State updated successfully.',
            'data' => new StateResource($state)
        ]);
    }

    public function destroy($id)
    {
        $state = State::find($id);

        if (!$state) {
            return response()->json(['data' => [], 'message' => 'State not found.'], 404);
        }

        $state->delete();

        return response()->json(['message' => 'State deleted successfully.']);
    }
}
