<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Models\Auction\AuctionDeposit;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\RefreshOnlinePaymentAction;
use App\Services\Auction\Payments\Fees\CustomerFeePolicy;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class CustomerPaymentFeeTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    private const TIERS = [
        ['from_minor' => 0, 'to_minor' => 10_000, 'fee_minor' => 250],
        ['from_minor' => 10_001, 'to_minor' => null, 'fee_minor' => 500],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_a_method_without_a_schedule_charges_nothing(): void
    {
        $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod();

        $this->assertSame(0, app(CustomerFeePolicy::class)->feeFor($method, 50_000));
        $this->assertNull(app(CustomerFeePolicy::class)->scheduleFor($method));
    }

    public function test_a_basis_without_tiers_stays_inactive(): void
    {
        $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod(['fee_basis' => 'principal']);

        $this->assertSame(0, app(CustomerFeePolicy::class)->feeFor($method, 50_000));
    }

    public function test_tiers_without_a_basis_stay_inactive(): void
    {
        $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod(['fee_tiers' => self::TIERS]);

        $this->assertSame(0, app(CustomerFeePolicy::class)->feeFor($method, 50_000));
    }

    public function test_the_principal_basis_reads_the_tier_against_the_obligation(): void
    {
        $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod([
            'fee_basis' => 'principal',
            'fee_tiers' => self::TIERS,
        ]);
        $policy = app(CustomerFeePolicy::class);

        $this->assertSame(250, $policy->feeFor($method, 10_000));
        $this->assertSame(500, $policy->feeFor($method, 10_001));
    }

    public function test_the_final_payable_basis_reads_the_tier_against_the_total(): void
    {
        $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod([
            'fee_basis' => 'final_payable',
            'fee_tiers' => self::TIERS,
        ]);
        $policy = app(CustomerFeePolicy::class);

        // 9_900 + 250 = 10_150 leaves the first tier, so the second one applies.
        $this->assertSame(500, $policy->feeFor($method, 9_900));
        $this->assertSame(250, $policy->feeFor($method, 5_000));
    }

    public function test_a_fee_is_frozen_on_the_intent_and_never_enters_the_principal(): void
    {
        $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod([
            'fee_basis' => 'principal',
            'fee_tiers' => self::TIERS,
        ]);
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id)
            ->refresh();

        $this->assertSame(1_000, (int) $transaction->amount_minor);
        $this->assertSame(250, (int) $transaction->customer_fee_minor);
        $this->assertSame(1_250, $transaction->payableAmountMinor());

        // Changing the schedule afterwards must not move a bill already quoted.
        $method->forceFill(['fee_tiers' => [['from_minor' => 0, 'to_minor' => null, 'fee_minor' => 9_999]]])->save();

        $this->assertSame(250, (int) $transaction->refresh()->customer_fee_minor);
    }

    public function test_a_deposit_holds_the_principal_and_not_the_fee(): void
    {
        $provider = $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod([
            'fee_basis' => 'principal',
            'fee_tiers' => self::TIERS,
        ]);
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id)
            ->refresh();

        $provider->markSucceeded((string) $transaction->provider_transaction_id, 1_250, 'JOD');
        app(RefreshOnlinePaymentAction::class)->execute($transaction, false);

        $this->assertSame(
            1_000,
            (int) AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $payer->id)->value('held_amount_minor')
        );
        $this->assertNull($transaction->refresh()->failure_code);
    }

    public function test_an_existing_card_method_is_untouched_by_the_fee_feature(): void
    {
        $provider = $this->enableFakeProvider();
        $method = $this->onlinePaymentMethod();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id)
            ->refresh();

        $this->assertSame(0, (int) $transaction->customer_fee_minor);
        $this->assertSame((int) $transaction->amount_minor, $transaction->payableAmountMinor());

        $provider->markSucceeded((string) $transaction->provider_transaction_id, 1_000, 'JOD');
        app(RefreshOnlinePaymentAction::class)->execute($transaction, false);

        $this->assertNull($transaction->refresh()->failure_code);
        $this->assertSame(
            1_000,
            (int) AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $payer->id)->value('held_amount_minor')
        );
    }

    public function test_no_money_calculation_uses_floating_point(): void
    {
        $sources = [
            app_path('Services/Auction/Payments/Fees/CustomerFeeSchedule.php'),
            app_path('Services/Auction/Payments/Fees/CustomerFeeTier.php'),
            app_path('Services/Auction/Payments/Fees/CustomerFeePolicy.php'),
            app_path('Services/Auction/Payments/Providers/EFawateercomPaymentProvider.php'),
        ];

        foreach ($sources as $source) {
            $contents = (string) file_get_contents($source);

            $this->assertDoesNotMatchRegularExpression('/\bfloatval\b|\(float\)|\bround\(/', $contents, $source);
        }
    }
}
