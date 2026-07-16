<?php

namespace App\Providers;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Policies\Auction\AuctionDepositPolicy;
use App\Policies\Auction\AuctionDisputePolicy;
use App\Policies\Auction\AuctionPolicy;
use App\Policies\Auction\AuctionRefundPolicy;
use App\Policies\Auction\AuctionSettlementPolicy;
use App\Policies\Auction\PaymentSubmissionPolicy;
use App\Services\Auction\Refunds\AuctionRefundProcessorInterface;
use App\Services\Auction\Refunds\ManualReviewRefundProcessor;
use Illuminate\Support\Facades\Gate;
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
    }
}
