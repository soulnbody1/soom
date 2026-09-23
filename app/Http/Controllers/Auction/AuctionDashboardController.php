<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\AdminDashboardRequest;
use App\Http\Resources\Auction\DashboardResource;
use App\Models\Market;
use App\Repositories\Auction\Dashboard\DashboardActionQuery;
use App\Repositories\Auction\Dashboard\DashboardAuctionQuery;
use App\Repositories\Auction\Dashboard\DashboardFinancialQuery;
use App\Repositories\Auction\Dashboard\DashboardSeriesQuery;
use App\Support\Market\MarketContext;
use App\Support\Market\MarketMode;
use App\Support\Market\MarketState;
use App\Traits\ApiResponseTrait;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group(name: 'لوحة تحكم المزادات', description: 'مؤشرات المزادات المالية والتشغيلية خلال فترة زمنية محددة.', weight: 13)]
final class AuctionDashboardController extends Controller
{
    use ApiResponseTrait;

    #[Endpoint(
        title: 'عرض لوحة تحكم المزادات',
        description: 'يجمّع مؤشرات المزادات خلال الفترة المطلوبة في أربعة أقسام: المؤشرات المالية، ومؤشرات المزادات ودورة حياتها، والسلاسل الزمنية، والمهام التشغيلية التي تحتاج متابعة المشرف.'
    )]
    #[Response(200, description: 'مؤشرات لوحة التحكم للفترة المطلوبة.')]
    public function index(
        AdminDashboardRequest $request,
        DashboardFinancialQuery $financial,
        DashboardAuctionQuery $auctions,
        DashboardSeriesQuery $series,
        DashboardActionQuery $actions,
        MarketContext $context,
    ): JsonResponse {
        Gate::authorize('auction.dashboard.view');

        if ($context->state()->mode === MarketMode::AdminAll) {
            $rows = Market::query()->orderBy('code')->get()->map(function (Market $market) use (
                $context, $request, $financial, $auctions, $series, $actions
            ): array {
                return $context->run(
                    MarketState::adminMarket($market),
                    fn (): array => [
                        'market' => strtolower($market->code),
                        'is_active' => (bool) $market->is_active,
                        ...$this->payload($request, $financial, $auctions, $series, $actions, $market->currency_code),
                    ]
                );
            })->values();

            return $this->sendResponse(['markets' => $rows], __('auction.messages.dashboard_fetched'));
        }

        return $this->sendResponse(
            $this->payload($request, $financial, $auctions, $series, $actions, $context->market()->currency_code),
            __('auction.messages.dashboard_fetched')
        );
    }

    private function payload(
        AdminDashboardRequest $request,
        DashboardFinancialQuery $financial,
        DashboardAuctionQuery $auctions,
        DashboardSeriesQuery $series,
        DashboardActionQuery $actions,
        string $currency,
    ): array {
        $period = $request->period();

        $revenue = $financial->revenueAndGross($period);
        $payouts = $financial->payoutSummary($period);
        $refunds = $financial->refundSummary($period);
        $collections = $financial->winnerCollections($period);
        $statusCounts = $auctions->statusCounts();

        $payload = [
            'period' => $period->toMeta(),
            'generated_at' => now()->toIso8601String(),
            'currency' => $currency,
            'financials' => DashboardResource::financials(
                $revenue,
                $financial->forfeitedDeposits($period),
                $financial->heldFunds(),
                $payouts,
                $refunds,
                $collections,
                $currency,
            ),
            'auctions' => DashboardResource::auctions(
                $statusCounts,
                $auctions->lifecycle($period),
                $auctions->endedPerformance($period),
                $auctions->settlementPerformance($period),
                $auctions->participation($period),
                $auctions->topAuctionsByBids($period),
                $revenue,
                $currency,
            ),
            'series' => [
                'granularity' => $series->granularity($period),
                'financial' => $series->financialSeries($period),
                'lifecycle' => $series->lifecycleSeries($period),
            ],
            'operations' => DashboardResource::operations(
                $actions->queueAges(),
                $collections,
                $payouts,
                $refunds,
                $statusCounts,
                [
                    'completed_settlements' => $actions->recentCompletedSettlements(),
                    'largest_unpaid_payouts' => $actions->largestUnpaidPayouts(),
                    'overdue_winner_payments' => $actions->overdueWinnerPayments(),
                    'open_disputes' => $actions->recentOpenDisputes(),
                    'attention_refunds' => $actions->attentionRefunds(),
                ],
                $currency,
            ),
        ];

        return $payload;
    }
}
