<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdReelViewRequest;
use App\Http\Resources\AdReelResource;
use App\Http\Resources\AdReelViewResource;
use App\Models\AdReel;
use App\Models\Category;
use App\Repositories\Ad\Queries\ReelFeedQuery;
use App\Services\Ad\Actions\RecordAdReelViewAction;
use App\Services\Market\MarketCategoryCatalog;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

#[Group(name: 'ريلز الإعلانات', description: 'استعراض ريلز الإعلانات وتسجيل المشاهدة دون كشف المعرّف الرقمي للإعلان.', weight: 7)]
class AdReelViewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected RecordAdReelViewAction $recordView) {}

    #[Endpoint(title: 'تسجيل مشاهدة ريل', description: 'يسجل مشاهدة المستخدم للريل مرة واحدة.')]
    #[BodyParameter('ad_reel_id', description: 'المعرّف الرقمي للريل.', required: true, type: 'integer')]
    #[Response(200, description: 'تم تسجيل المشاهدة أو كانت مسجلة مسبقًا.')]
    public function store(StoreAdReelViewRequest $request)
    {
        $view = $this->recordView->execute((int) $request->ad_reel_id);

        if ($view->wasRecentlyCreated) {
            return new AdReelViewResource($view);
        }

        return response()->json(['message' => 'تمت مشاهدة الإعلان مسبقًا'], 200);
    }

    #[Endpoint(title: 'عرض ريلز الإعلانات', description: 'يعرض الريلز العامة؛ معرّف الريل رقمي بينما ad_id وad.id الخاصان بالإعلان ULID عامان.')]
    #[Response(200, description: 'الريلز مقسّمة إلى صفحات مع ريلز المستخدم.')]
    public function reels(ReelFeedQuery $feed): JsonResponse
    {
        return response()->json($this->publicFeed($feed->build($this->viewer())));
    }

    #[Endpoint(title: 'عرض ريلز تصنيف', description: 'يعرض ريلز التصنيف وفروعه وتكون معرّفات الإعلانات ULID عامة.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للتصنيف.')]
    #[Response(200, description: 'ريلز التصنيف مقسّمة إلى صفحات.')]
    public function ReelsForCategories(Category $id, ReelFeedQuery $feed, MarketCategoryCatalog $categories): JsonResponse
    {
        abort_unless($categories->isVisible((int) $id->id), 404);

        return response()->json(
            $this->publicFeed($feed->build($this->viewer(), $categories->subtreeIds((int) $id->id)))
        );
    }

    #[Endpoint(title: 'حذف ريل إعلان', description: 'يحذف ريلًا يخص إعلان المستخدم بعد التحقق من الملكية.')]
    #[PathParameter('id', description: 'المعرّف الرقمي للريل.')]
    #[Response(200, description: 'تم حذف الريل.')]
    #[Response(404, description: 'الريل غير موجود.')]
    public function delete(int $id): JsonResponse
    {
        $reel = AdReel::query()
            ->select('id', 'ad_id')
            ->with(['ad' => fn ($ad) => $ad->select('id', 'user_id')])
            ->find($id);

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

    private function publicFeed(array $feed): array
    {
        $page = $feed['all_reels'];

        return [
            'all_reels' => [
                'data' => AdReelResource::collection($page->items())->resolve(request()),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'next_page_url' => $page->nextPageUrl(),
                'prev_page_url' => $page->previousPageUrl(),
            ],
            'my_reels' => AdReelResource::collection($feed['my_reels'])->resolve(request()),
        ];
    }
}
