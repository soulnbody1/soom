<?php

namespace App\Providers;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Policies\Auction\AuctionDashboardPolicy;
use App\Policies\Auction\AuctionDepositPolicy;
use App\Policies\Auction\AuctionDisputePolicy;
use App\Policies\Auction\AuctionPolicy;
use App\Policies\Auction\AuctionRefundPolicy;
use App\Policies\Auction\AuctionSettlementPolicy;
use App\Policies\Auction\PaymentSubmissionPolicy;
use App\Policies\Auction\SellerPayoutPolicy;
use App\Services\Auction\Refunds\AuctionRefundProcessorInterface;
use App\Services\Auction\Refunds\ManualReviewRefundProcessor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuctionRefundProcessorInterface::class, ManualReviewRefundProcessor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Auction::class, AuctionPolicy::class);
        Gate::policy(PaymentSubmission::class, PaymentSubmissionPolicy::class);
        Gate::policy(AuctionDeposit::class, AuctionDepositPolicy::class);
        Gate::policy(AuctionSettlement::class, AuctionSettlementPolicy::class);
        Gate::policy(RefundTransaction::class, AuctionRefundPolicy::class);
        Gate::policy(AuctionDispute::class, AuctionDisputePolicy::class);
        Gate::policy(AuctionSellerPayout::class, SellerPayoutPolicy::class);
        Gate::define('auction.dashboard.view', [AuctionDashboardPolicy::class, 'view']);

        $this->configureBidRateLimiting();
    }

    private function configureBidRateLimiting(): void
    {
        RateLimiter::for('auction-bids', function (Request $request): array {
            $auctionKey = (string) ($request->route('auction')?->public_id ?? $request->route('auction') ?? 'unknown');
            $userId = (int) ($request->user()?->id ?? 0);

            $limits = [
                Limit::perMinute((int) config('auction.bidding.rate_limit_per_minute', 30))
                    ->by("auction-bids:user:{$userId}:auction:{$auctionKey}"),
            ];

            $perIp = (int) config('auction.bidding.rate_limit_per_minute_per_ip', 0);

            if ($perIp > 0) {
                $limits[] = Limit::perMinute($perIp)->by("auction-bids:ip:{$request->ip()}");
            }

            return $limits;
        });
    }
}
