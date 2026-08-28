<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AuctionIndexRequest;
use App\Http\Requests\Auction\PlaceBidRequest;
use App\Http\Resources\Auction\AuctionBidResource;
use App\Models\Auction\Auction;
use App\Services\Auction\Actions\ListAuctionBidsAction;
use App\Services\Auction\Actions\ListUserBidsAction;
use App\Services\Auction\Actions\PlaceBidAction;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'المزايدات', description: 'عرض مزايدات المزاد وتقديم المزايدات ومتابعة مزايدات المستخدم.', weight: 2)]
final class BidController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض مزايدات المزاد',
        description: 'يعرض مزايدات المزاد مرتبةً من الأحدث إلى الأقدم ومقسّمة إلى صفحات. الوصول إليه مرتبط بصلاحية عرض المزاد نفسه. ولا يؤثر في النتيجة سوى معاملي per_page وpage، أما بقية معاملات التصفية الظاهرة فمقبولة في التحقق ولا تُطبَّق على المزايدات.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(200, description: 'قائمة مزايدات المزاد مقسّمة إلى صفحات.')]
    public function index(AuctionIndexRequest $request, Auction $auction, ListAuctionBidsAction $action): JsonResponse
    {
        Gate::authorize('view', $auction);

        return $this->sendResponse(
            AuctionBidResource::collection($action->execute($auction, $request->perPage())),
            __('auction.messages.auction_bids_fetched')
        );
    }

    #[Endpoint(
        title: 'تقديم مزايدة',
        description: 'يسجّل مزايدة جديدة على المزاد باسم المستخدم الحالي بعد التحقق من أهليته وحالة المزاد وقيمة المزايدة. قد تؤدي المزايدة قرب نهاية المزاد إلى تمديد وقته وفق إعدادات المزاد، وهذا المسار محكوم بحد أقصى لعدد الطلبات في الدقيقة.'
    )]
    #[PathParameter('auction', description: 'المعرّف العام للمزاد (ULID).')]
    #[Response(201, description: 'المزايدة بعد قبولها.')]
    public function store(PlaceBidRequest $request, Auction $auction, PlaceBidAction $action): JsonResponse
    {
        Gate::authorize('bid', $auction);

        $data = $request->validated();
        $bid = $action->execute(
            $auction,
            Auth::id(),
            $data['amount'],
            $data['currency_code'],
            $data['idempotency_key'],
            $data['client_request_id'] ?? null
        );

        return $this->sendResponse(new AuctionBidResource($bid), __('auction.messages.bid_accepted'), 201);
    }

    #[Endpoint(
        title: 'عرض مزايداتي',
        description: 'يعرض المزايدات التي قدّمها المستخدم الحالي في جميع المزادات مرتبةً من الأحدث إلى الأقدم. ولا يؤثر في النتيجة سوى معاملي per_page وpage، أما بقية معاملات التصفية الظاهرة فمقبولة في التحقق ولا تُطبَّق على المزايدات.'
    )]
    #[Response(200, description: 'قائمة مزايدات المستخدم مقسّمة إلى صفحات.')]
    public function mine(AuctionIndexRequest $request, ListUserBidsAction $action): JsonResponse
    {
        return $this->sendResponse(
            AuctionBidResource::collection($action->execute(Auth::id(), $request->perPage())),
            __('auction.messages.my_bids_fetched')
        );
    }
}
