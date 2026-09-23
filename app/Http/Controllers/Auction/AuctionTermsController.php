<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\CreateTermsVersionRequest;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Services\Auction\Actions\CreateAuctionTermsVersionAction;
use App\Services\Auction\Actions\ListAuctionTermsAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'شروط المزاد', description: 'نسخ شروط المزادات المنشورة وحالة قبول المستخدم لها.', weight: 4)]
final class AuctionTermsController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض نسخ شروط المزادات',
        description: 'يعرض ملخّص جميع نسخ شروط المزادات مرتبةً من الأحدث إلى الأقدم مع بيان النسخة السارية، دون نص الشروط الكامل.'
    )]
    #[Response(200, description: 'قائمة ملخّصة بنسخ الشروط.')]
    public function index(ListAuctionTermsAction $action): JsonResponse
    {
        return $this->sendResponse(
            $action->execute(),
            __('auction.messages.terms_fetched')
        );
    }

    public function adminIndex(\App\Repositories\Auction\Queries\AuctionTermsQuery $query): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse($query->adminList(), __('auction.messages.terms_fetched'));
    }

    #[Endpoint(
        title: 'عرض شروط المزاد',
        description: 'يعرض نص نسخة الشروط المثبّتة على المزاد وقت نشره مع بيان ما إذا كان المستخدم الحالي قد قبلها وتاريخ القبول. يُرجع 404 إذا لم يكن المزاد متاحًا للعرض أو لم تُثبّت له نسخة شروط.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'نص نسخة الشروط المرتبطة بالمزاد وحالة قبولها.')]
    public function showForAuction(Request $request, Auction $auction): JsonResponse
    {
        if (! Gate::allows('view', $auction)) {
            return $this->sendError(__('auction.errors.auction_not_found'), 404, 'auction_not_found');
        }

        $termsVersionId = $this->snapshotTermsVersionId($auction);

        if ($termsVersionId === null) {
            return $this->sendError(__('auction.errors.terms_missing'), 404, 'terms_missing');
        }

        $terms = AuctionTermsVersion::find($termsVersionId);

        if (! $terms) {
            return $this->sendError(__('auction.errors.terms_missing'), 404, 'terms_missing');
        }

        $userId = $request->user()?->id;
        $acceptance = $userId === null
            ? null
            : AuctionTermsAcceptance::where('auction_id', $auction->id)
                ->where('user_id', $userId)
                ->where('terms_version_id', $terms->id)
                ->first();

        return $this->sendResponse([
            'id' => $terms->public_id,
            'version_number' => $terms->version_number,
            'title' => $terms->title,
            'body' => $terms->body,
            'published_at' => $terms->published_at?->toIso8601String(),
            'accepted' => $userId !== null && $acceptance !== null,
            'accepted_at' => $acceptance?->accepted_at?->toIso8601String(),
        ], __('auction.messages.terms_version_fetched'));
    }

    #[Endpoint(
        title: 'عرض نسخة شروط محددة',
        description: 'يعرض النص الكامل لنسخة شروط بعينها مع بيان ما إذا كانت هي النسخة السارية.'
    )]
    #[PathParameter('terms', description: 'المعرّف العام لنسخة الشروط (ULID).')]
    #[Response(200, description: 'تفاصيل نسخة الشروط.')]
    public function show(AuctionTermsVersion $terms): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        return $this->sendResponse([
            'id' => $terms->public_id,
            'version_number' => $terms->version_number,
            'title' => $terms->title,
            'body' => $terms->body,
            'is_active' => $terms->is_active,
            'published_at' => $terms->published_at?->toIso8601String(),
        ], __('auction.messages.terms_version_fetched'));
    }

    #[Endpoint(
        title: 'إنشاء نسخة شروط جديدة',
        description: 'ينشئ نسخة جديدة من شروط المزادات برقم إصدار متسلسل، ويجعلها النسخة السارية عند نشرها. لا يؤثر ذلك على المزادات القائمة لأن كل مزاد يحتفظ بالنسخة المثبّتة عليه.'
    )]
    #[Response(201, description: 'ملخّص نسخة الشروط بعد إنشائها.')]
    public function store(CreateTermsVersionRequest $request, CreateAuctionTermsVersionAction $action): JsonResponse
    {
        Gate::authorize('viewAny', Auction::class);

        $publish = (bool) $request->boolean('publish', true);
        $terms = $action->execute(
            (string) $request->validated('title'),
            (string) $request->validated('body'),
            $publish,
            Auth::id()
        );

        return $this->sendResponse([
            'id' => $terms->public_id,
            'version_number' => $terms->version_number,
            'title' => $terms->title,
            'is_active' => $terms->is_active,
            'market' => strtolower((string) $terms->market->code),
        ], __('auction.messages.terms_version_created'), 201);
    }

    private function snapshotTermsVersionId(Auction $auction): ?int
    {
        $snapshotTermsId = AuctionConfigurationSnapshot::where('auction_id', $auction->id)
            ->value('terms_version_id');

        return $snapshotTermsId === null
            ? ($auction->terms_version_id === null ? null : (int) $auction->terms_version_id)
            : (int) $snapshotTermsId;
    }
}
