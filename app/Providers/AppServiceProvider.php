<?php

namespace App\Providers;

use App\Models\Ad;
use App\Models\AdReel;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\RefundTransaction;
use App\Policies\AdPolicy;
use App\Policies\AdReelPolicy;
use App\Policies\Auction\AuctionDashboardPolicy;
use App\Policies\Auction\AuctionDepositPolicy;
use App\Policies\Auction\AuctionDisputePolicy;
use App\Policies\Auction\AuctionPolicy;
use App\Policies\Auction\AuctionRefundPolicy;
use App\Policies\Auction\AuctionSettlementPolicy;
use App\Policies\Auction\PaymentSubmissionPolicy;
use App\Policies\Auction\SellerPayoutPolicy;
use App\Services\Auction\ContentReview\AuctionReviewSubjectAdapter;
use App\Services\Auction\Notifications\OutboxNotifier;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Payments\Providers\FakeBillPaymentProvider;
use App\Services\Auction\Payments\Providers\FakePaymentProvider;
use App\Services\Auction\Refunds\AuctionRefundProcessorInterface;
use App\Services\Auction\Refunds\ManualReviewRefundProcessor;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Providers\FakeContentReviewProvider;
use App\Services\ContentReview\Support\ProviderModelCatalog;
use App\Services\ContentReview\Support\ProviderSelectionResolver;
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

        $this->app->singleton(FakeContentReviewProvider::class);

        $this->app->bind(AuctionRefundProcessorInterface::class, ManualReviewRefundProcessor::class);

        $this->app->singleton(FakePaymentProvider::class);
        $this->app->singleton(FakeBillPaymentProvider::class);
        $this->app->singleton(PaymentProviderFactory::class);

        $this->app->singleton(ProviderModelCatalog::class);
        $this->app->singleton(ProviderSelectionResolver::class);

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
        Gate::define('auction.dashboard.view', [AuctionDashboardPolicy::class, 'view']);
        Gate::policy(Ad::class, AdPolicy::class);
        Gate::policy(AdReel::class, AdReelPolicy::class);

        $this->configurePaymentRateLimiting();
        $this->configureBidRateLimiting();
        $this->configureParticipationRateLimiting();
        $this->configureContentReviewRateLimiting();
        $this->configureAdRateLimiting();
    }

    private function configureAdRateLimiting(): void
    {
        RateLimiter::for('ads-public', fn (Request $request): Limit => Limit::perMinute(
            (int) config('ads.rate_limits.public_per_minute')
        )->by('ads-public:'.$request->ip()));

        RateLimiter::for('ads-search', fn (Request $request): Limit => Limit::perMinute(
            (int) config('ads.rate_limits.search_per_minute')
        )->by('ads-search:'.$request->ip()));

        RateLimiter::for('ads-write', fn (Request $request): Limit => Limit::perHour(
            (int) config('ads.rate_limits.write_per_hour')
        )->by('ads-write:user:'.(int) ($request->user()?->id ?? 0)));

        RateLimiter::for('ads-engagement', fn (Request $request): Limit => Limit::perMinute(
            (int) config('ads.rate_limits.engagement_per_minute')
        )->by('ads-engagement:user:'.(int) ($request->user()?->id ?? 0)));
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

    private function configurePaymentRateLimiting(): void
    {
        RateLimiter::for('payment-intents', function (Request $request): array {
            $userId = (int) ($request->user()?->id ?? 0);

            return [
                Limit::perMinute((int) config('auction.payments.intent_rate_limit_per_minute', 10))
                    ->by("payment-intents:user:{$userId}"),
            ];
        });

        RateLimiter::for('payment-webhooks', function (Request $request): array {
            $provider = (string) ($request->route('provider') ?? 'unknown');

            return [
                Limit::perMinute((int) config('auction.payments.webhook_rate_limit_per_minute', 600))
                    ->by("payment-webhooks:{$provider}"),
            ];
        });

        // Bill lookups are read-only and far chattier than payment events, so
        // they get their own, higher, budget.
        RateLimiter::for('payment-bill-queries', function (Request $request): array {
            $provider = (string) ($request->route('provider') ?? 'unknown');

            return [
                Limit::perMinute((int) config('auction.payments.bill_query_rate_limit_per_minute', 3000))
                    ->by("payment-bill-queries:{$provider}"),
            ];
        });
    }

    private function configureParticipationRateLimiting(): void
    {
        RateLimiter::for('auction-participation', function (Request $request): array {
            $auctionKey = (string) ($request->route('auction')?->public_id ?? $request->route('auction') ?? 'unknown');
            $userId = (int) ($request->user()?->id ?? 0);

            $limits = [
                Limit::perMinute((int) config('auction.participation.rate_limit_per_minute', 20))
                    ->by("auction-participation:user:{$userId}:auction:{$auctionKey}"),
            ];

            $perIp = (int) config('auction.participation.rate_limit_per_minute_per_ip', 0);

            if ($perIp > 0) {
                $limits[] = Limit::perMinute($perIp)->by("auction-participation:ip:{$request->ip()}");
            }

            return $limits;
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
