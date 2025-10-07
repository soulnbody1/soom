<?php

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdReelViewRequest;
use App\Http\Resources\AdReelViewResource;
use App\Models\AdReel;
use App\Models\Category;
use App\Services\AdReelViewService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class AdReelViewController extends Controller
{
    use AuthorizesRequests;
    public function __construct(protected AdReelViewService $service) {}

    public function store(StoreAdReelViewRequest $request)
    {
        $adReelId = $request->ad_reel_id;

        $adReel = AdReel::find($adReelId);
        if (!$adReel) {
            return response()->json(['message' => 'الإعلان غير موجود'], 404);
        }

        $view = $this->service->store($adReelId);

        if ($view->wasRecentlyCreated) {
            return new AdReelViewResource($view);
        }

        return response()->json(['message' => 'تمت مشاهدة الإعلان مسبقًا'], 200);
    }


    public function reels()
    {
        $user = auth('sanctum')->user();
        $reels = $this->service->getReels($user);
        return response()->json($reels);
    }

    public function ReelsForCategories(Category $id)
    {
        $user = auth('sanctum')->user();
        $reels = $this->service->getReelsForCategory($user, $id);
        return response()->json($reels);
    }
    


    public function delete($adReelId)
    {
        $reel = AdReel::with('ad')->select('id', 'ad_id')
            ->with([
                'ad' => function ($query) {
                    $query->select('id', 'user_id');
                }
            ])
            ->find($adReelId);
        if (!$reel) {
            return response()->json([
                'message' => '❌ الريل المطلوب غير موجود أو قد تم حذفه مسبقًا.',
            ], 404);
        }
        $this->authorize('delete', $reel);
        $reel->delete();
        return response()->json(['message' => '✅ تم حذف الريل من بنجاح.']);
    }
}
