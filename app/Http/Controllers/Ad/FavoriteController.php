<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Domain\Ad\Enums\AdInteractionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\FavoriteRequest;
use App\Http\Resources\FavoriteResource;
use App\Jobs\Ad\RecordAdEngagement;
use App\Models\Favorite;
use App\Repositories\Ad\Queries\FavoriteListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FavoriteController extends Controller
{
    public function index(Request $request, FavoriteListQuery $favorites): AnonymousResourceCollection
    {
        return FavoriteResource::collection($favorites->paginate($request->user()->id));
    }

    public function store(FavoriteRequest $request): JsonResponse
    {
        $userId = $request->user()->id;

        $exists = Favorite::query()
            ->where('user_id', $userId)
            ->where('ad_id', $request->ad_id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'الإعلان مضاف بالفعل للمفضلة'], 409);
        }

        Favorite::create([
            'user_id' => $userId,
            'ad_id' => $request->ad_id,
        ]);

        RecordAdEngagement::dispatch((int) $request->ad_id, (int) $userId, AdInteractionAction::Save);

        return response()->json(['message' => 'تمت الإضافة إلى المفضلة']);
    }

    public function destroy($adId, Request $request): JsonResponse
    {
        $favorite = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('ad_id', $adId)
            ->first();

        if (! $favorite) {
            return response()->json(['message' => 'الإعلان غير موجود في المفضلة'], 404);
        }

        $favorite->delete();

        return response()->json(['message' => 'تمت الإزالة من المفضلة']);
    }
}
