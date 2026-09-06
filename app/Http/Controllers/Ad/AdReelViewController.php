<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdReelViewRequest;
use App\Http\Resources\AdReelViewResource;
use App\Models\AdReel;
use App\Models\Category;
use App\Repositories\Ad\Queries\ReelFeedQuery;
use App\Services\Ad\Actions\RecordAdReelViewAction;
use App\Services\Ad\Support\CategoryTreeResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class AdReelViewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected RecordAdReelViewAction $recordView) {}

    public function store(StoreAdReelViewRequest $request)
    {
        $view = $this->recordView->execute((int) $request->ad_reel_id);

        if ($view->wasRecentlyCreated) {
            return new AdReelViewResource($view);
        }

        return response()->json(['message' => 'تمت مشاهدة الإعلان مسبقًا'], 200);
    }

    public function reels(ReelFeedQuery $feed): JsonResponse
    {
        return response()->json($feed->build($this->viewer()));
    }

    public function ReelsForCategories(Category $id, ReelFeedQuery $feed, CategoryTreeResolver $categories): JsonResponse
    {
        return response()->json(
            $feed->build($this->viewer(), $categories->subtreeIds((int) $id->id))
        );
    }

    public function delete(int $adReelId): JsonResponse
    {
        $reel = AdReel::query()
            ->select('id', 'ad_id')
            ->with(['ad' => fn ($ad) => $ad->select('id', 'user_id')])
            ->find($adReelId);

        if (! $reel) {
            return response()->json([
                'message' => '❌ الريل المطلوب غير موجود أو قد تم حذفه مسبقًا.',
            ], 404);
        }

        $this->authorize('delete', $reel);
        $reel->delete();

        return response()->json(['message' => '✅ تم حذف الريل من بنجاح.']);
    }

    private function viewer(): ?object
    {
        return auth('sanctum')->user();
    }
}
