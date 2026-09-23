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

class StateController extends Controller
{
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

    public function store(StoreStateRequest $request)
    {
        $state = State::create($request->validated());

        return response()->json([
            'message' => 'State created successfully.',
            'data' => new StateResource($state),
        ], 201);
    }

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
