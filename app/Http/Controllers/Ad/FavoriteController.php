<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ad;

use App\Domain\Ad\Enums\AdInteractionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\FavoriteRequest;
use App\Http\Resources\FavoriteResource;
use App\Jobs\Ad\RecordAdEngagement;
use App\Models\Ad;
use App\Models\Favorite;
use App\Repositories\Ad\Queries\FavoriteListQuery;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group(name: 'المفضلة', description: 'إدارة إعلانات المستخدم المفضلة باستخدام ULID العام للإعلان.', weight: 8)]
class FavoriteController extends Controller
{
    #[Endpoint(title: 'عرض المفضلة', description: 'يعرض إعلانات المستخدم المضافة إلى المفضلة دون كشف المعرّفات الرقمية الداخلية للإعلانات.')]
    #[Response(200, description: 'الإعلانات المفضلة مقسّمة إلى صفحات.')]
    public function index(Request $request, FavoriteListQuery $favorites): AnonymousResourceCollection
    {
        return FavoriteResource::collection($favorites->paginate($request->user()->id));
    }

    #[Endpoint(title: 'إضافة إعلان إلى المفضلة', description: 'يضيف إعلانًا نشطًا إلى مفضلة المستخدم باستخدام ULID العام.')]
    #[BodyParameter('ad_id', description: 'المعرّف العام ULID للإعلان.', required: true, type: 'string')]
    #[Response(200, description: 'تمت الإضافة إلى المفضلة.')]
    #[Response(422, description: 'ULID غير صالح أو الإعلان غير موجود.')]
    #[Response(409, description: 'الإعلان موجود بالفعل في المفضلة.')]
    public function store(FavoriteRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $ad = Ad::query()->where('public_id', $request->validated('ad_id'))->firstOrFail();

        $exists = Favorite::query()
            ->where('user_id', $userId)
            ->where('ad_id', $ad->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'الإعلان مضاف بالفعل للمفضلة'], 409);
        }

        Favorite::create([
            'user_id' => $userId,
            'ad_id' => $ad->id,
        ]);

        RecordAdEngagement::dispatch((int) $ad->id, (int) $userId, AdInteractionAction::Save);

        return response()->json(['message' => 'تمت الإضافة إلى المفضلة']);
    }

    #[Endpoint(title: 'إزالة إعلان من المفضلة', description: 'يزيل الإعلان المحدد بمعرّفه العام ULID من مفضلة المستخدم.')]
    #[PathParameter('ad', description: 'المعرّف العام ULID للإعلان.')]
    #[Response(200, description: 'تمت الإزالة من المفضلة.')]
    #[Response(404, description: 'الإعلان غير موجود في المفضلة.')]
    public function destroy(Ad $ad, Request $request): JsonResponse
    {
        $favorite = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('ad_id', $ad->id)
            ->first();

        if (! $favorite) {
            return response()->json(['message' => 'الإعلان غير موجود في المفضلة'], 404);
        }

        $favorite->delete();

        return response()->json(['message' => 'تمت الإزالة من المفضلة']);
    }
}
