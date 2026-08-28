<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\MyRefundResource;
use App\Http\Resources\Auction\UserAuctionResource;
use App\Repositories\Auction\Queries\MyParticipationQuery;
use App\Repositories\Auction\Queries\MyRefundQuery;
use App\Services\Auction\Support\ParticipationStateResolver;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group(name: 'مشاركاتي في المزادات', description: 'متابعة المستخدم لمزاداته التي شارك فيها ولعمليات الاسترداد الخاصة به.', weight: 3)]
final class MyParticipationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly ParticipationStateResolver $participation,
    ) {}

    #[Endpoint(
        title: 'عرض مشاركاتي في المزادات',
        description: 'يعرض المزادات التي شارك فيها المستخدم الحالي مع حالة مشاركته في كل منها، مع إمكانية التصفية بمرحلة المشاركة أو بحالة المزاد أو بالبحث النصي.'
    )]
    #[QueryParameter('filter', description: 'تصفية المشاركات بمرحلتها: all للكل، وactive للمزادات الجارية، وwon للمزادات التي فاز بها، وlost للمزادات التي خسرها، وpending_payment للمزادات المنتظرة للسداد، وhandover لمرحلة التسليم، وrefunds للمشاركات التي عليها استرداد.')]
    #[QueryParameter('search', description: 'نص البحث في عنوان المزاد.')]
    #[QueryParameter('status', description: 'تصفية المشاركات بحالة المزاد.')]
    #[QueryParameter('sort', description: 'ترتيب النتائج: latest للأحدث، وstarting_soon للأقرب بدءًا، وending_soon للأقرب انتهاءً.')]
    #[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
    #[QueryParameter('page', description: 'رقم الصفحة المطلوبة.')]
    #[Response(200, description: 'قائمة المزادات التي شارك فيها المستخدم مقسّمة إلى صفحات.')]
    public function index(Request $request, MyParticipationQuery $query): JsonResponse
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'active', 'won', 'lost', 'pending_payment', 'handover', 'refunds'])],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_column(AuctionStatus::cases(), 'value'))],
            'sort' => ['nullable', Rule::in(['latest', 'starting_soon', 'ending_soon'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $query->paginate(
            (int) $request->user()->id,
            $filters,
            min(100, max(1, (int) $request->input('per_page', 20)))
        );

        $this->participation->forCollection($paginator->items(), $request->user());

        return $this->sendResponse(
            UserAuctionResource::collection($paginator),
            __('auction.messages.auctions_fetched')
        );
    }

    #[Endpoint(
        title: 'عرض عمليات الاسترداد الخاصة بي',
        description: 'يعرض عمليات استرداد التأمينات والمبالغ المستحقة للمستخدم الحالي وحالة كل عملية.'
    )]
    #[QueryParameter('status', description: 'تصفية عمليات الاسترداد بحالتها.')]
    #[QueryParameter('search', description: 'نص البحث في عنوان المزاد المرتبط بالاسترداد.')]
    #[QueryParameter('sort', description: 'ترتيب النتائج: latest للأحدث أو oldest للأقدم.')]
    #[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
    #[QueryParameter('page', description: 'رقم الصفحة المطلوبة.')]
    #[Response(200, description: 'قائمة عمليات الاسترداد مقسّمة إلى صفحات.')]
    public function refunds(Request $request, MyRefundQuery $query): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(RefundTransactionStatus::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['latest', 'oldest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $query->paginate(
            (int) $request->user()->id,
            $filters,
            min(100, max(1, (int) $request->input('per_page', 20)))
        );

        return $this->sendResponse(
            MyRefundResource::collection($paginator),
            __('auction.messages.refunds_fetched')
        );
    }
}
