<?php

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;

use App\Http\Requests\FavoriteRequest;
use App\Http\Resources\FavoriteResource;
use App\Models\Favorite;
use App\Services\UserAdInteractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;


class FavoriteController extends Controller
{
    public function __construct(
        protected UserAdInteractionService $Interaction,
    ) {}
    public function index(Request $request)
    {
        $user = $request->user();

        $favorites = Favorite::with('ad')
            ->where('user_id', $user->id)
            ->whereHas('ad')
            ->latest()
            ->paginate(10);

        return FavoriteResource::collection($favorites);
    }

    public function store(FavoriteRequest $request)
    {
        $user = $request->user();
        $exists = Favorite::where('user_id', $user->id)
            ->where('ad_id', $request->ad_id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'الإعلان مضاف بالفعل للمفضلة'], 409);
        }

        Favorite::create([
            'user_id' => $user->id,
            'ad_id' => $request->ad_id,
        ]);
        Cache::forget('home_ads_data');
        $this->Interaction->store($request->ad_id, 'save');
        return response()->json(['message' => 'تمت الإضافة إلى المفضلة']);
    }


    public function destroy($adId, Request $request)
    {
        $user = $request->user();

        $favorite = Favorite::where('user_id', $user->id)
            ->where('ad_id', $adId)
            ->first();

        if (! $favorite) {
            return response()->json(['message' => 'الإعلان غير موجود في المفضلة'], 404);
        }

        $favorite->delete();
        Cache::forget('home_ads_data');
        return response()->json(['message' => 'تمت الإزالة من المفضلة']);
    }
}
