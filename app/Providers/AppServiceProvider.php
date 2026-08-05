<?php

namespace App\Providers;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Models\ContentReview\ContentReview;
use App\Policies\Auction\AuctionDashboardPolicy;
use App\Policies\Auction\AuctionDepositPolicy;
use App\Policies\Auction\AuctionDisputePolicy;
use App\Policies\Auction\AuctionPolicy;
use App\Policies\Auction\AuctionRefundPolicy;
use App\Policies\Auction\AuctionSettlementPolicy;
use App\Policies\Auction\PaymentSubmissionPolicy;
use App\Policies\Auction\SellerPayoutPolicy;
use App\Policies\ContentReview\ContentReviewPolicy;
use App\Services\Auction\ContentReview\AuctionReviewSubjectAdapter;
use App\Services\Auction\Notifications\OutboxNotifier;
use App\Services\Auction\Refunds\AuctionRefundProcessorInterface;
use App\Services\Auction\Refunds\ManualReviewRefundProcessor;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use App\Services\Outbox\OutboxContentReviewEventPublisher;
use App\Services\Outbox\OutboxTopicRouter;
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

        $this->app->singleton(FakeContentReviewProvider::class);

        $this->app->bind(
            ContentReviewProvider::class,
            fn ($app): ContentReviewProvider => $app->make(ContentReviewProviderFactory::class)->make()
        );

        $this->app->bind(OutboxNotifier::class, OutboxTopicRouter::class);

        $this->app->bind(ContentReviewEventPublisher::class, OutboxContentReviewEventPublisher::class);

        $this->app->singleton(ReviewSubjectRegistry::class, function ($app): ReviewSubjectRegistry {
            $registry = new ReviewSubjectRegistry;
            $registry->register($app->make(AuctionReviewSubjectAdapter::class));

            return $registry;
        });
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
        Gate::policy(ContentReview::class, ContentReviewPolicy::class);
        Gate::define('auction.dashboard.view', [AuctionDashboardPolicy::class, 'view']);

        $this->configureBidRateLimiting();
        $this->configureContentReviewRateLimiting();
    }

    private function configureContentReviewRateLimiting(): void
    {
        RateLimiter::for('content-review-provider-test', function (Request $request): array {
            $userId = (int) ($request->user()?->id ?? 0);

            return [
                Limit::perMinute((int) config('content_review.provider_test.rate_limit_per_minute', 3))
                    ->by("content-review-provider-test:user:{$userId}"),
                Limit::perHour((int) config('content_review.provider_test.rate_limit_per_hour', 20))
                    ->by('content-review-provider-test:global'),
            ];
        });
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
