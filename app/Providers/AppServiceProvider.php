<?php

namespace App\Providers;

use App\Events\Auction\AuctionOutboxEvent;
use App\Listeners\Auction\RecordAuctionOutboxConsumption;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Policies\Auction\AuctionDepositPolicy;
use App\Policies\Auction\AuctionPolicy;
use App\Policies\Auction\AuctionRefundPolicy;
use App\Policies\Auction\AuctionSettlementPolicy;
use App\Policies\Auction\PaymentSubmissionPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private static bool $auctionOutboxListenerRegistered = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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

        if (! self::$auctionOutboxListenerRegistered) {
            Event::listen(AuctionOutboxEvent::class, RecordAuctionOutboxConsumption::class);
            self::$auctionOutboxListenerRegistered = true;
        }

        require base_path('routes/channels.php');
    }
}
