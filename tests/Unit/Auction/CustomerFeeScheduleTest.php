<?php

declare(strict_types=1);

namespace Tests\Unit\Auction;

use App\Domain\Auction\Enums\CustomerFeeBasis;
use App\Services\Auction\Payments\Fees\CustomerFeeSchedule;
use App\Services\Auction\Payments\Fees\CustomerFeeTierMissing;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CustomerFeeScheduleTest extends TestCase
{
    private const TIERS = [
        ['from_minor' => 1, 'to_minor' => 10_000, 'fee_minor' => 250],
        ['from_minor' => 10_001, 'to_minor' => 100_000, 'fee_minor' => 500],
        ['from_minor' => 100_001, 'to_minor' => null, 'fee_minor' => 5_000],
    ];

    public function test_a_principal_basis_charges_the_tier_covering_the_principal(): void
    {
        $schedule = CustomerFeeSchedule::fromConfiguration('principal', self::TIERS);

        $this->assertSame(CustomerFeeBasis::Principal, $schedule->basis);
        $this->assertSame(250, $schedule->feeFor(1));
        $this->assertSame(250, $schedule->feeFor(10_000));
        $this->assertSame(500, $schedule->feeFor(10_001));
        $this->assertSame(500, $schedule->feeFor(100_000));
        $this->assertSame(5_000, $schedule->feeFor(100_001));
        $this->assertSame(5_000, $schedule->feeFor(9_999_999));
    }

    public function test_a_final_payable_basis_charges_the_tier_covering_principal_plus_fee(): void
    {
        $schedule = CustomerFeeSchedule::fromConfiguration('final_payable', self::TIERS);

        // 9_800 + 250 = 10_050, which leaves the first tier, so the second applies:
        // 9_800 + 500 = 10_300 falls inside 10_001..100_000.
        $this->assertSame(500, $schedule->feeFor(9_800));

        // 5_000 + 250 = 5_250 stays inside the first tier.
        $this->assertSame(250, $schedule->feeFor(5_000));

        // 99_900 + 500 = 100_400 leaves the second tier; the open-ended tier covers it.
        $this->assertSame(5_000, $schedule->feeFor(99_900));
    }

    public function test_the_two_bases_agree_away_from_a_boundary(): void
    {
        $principal = CustomerFeeSchedule::fromConfiguration('principal', self::TIERS);
        $payable = CustomerFeeSchedule::fromConfiguration('final_payable', self::TIERS);

        foreach ([2_000, 50_000, 500_000] as $amount) {
            $this->assertSame($principal->feeFor($amount), $payable->feeFor($amount));
        }
    }

    public function test_tier_boundaries_are_inclusive_at_both_ends(): void
    {
        $schedule = CustomerFeeSchedule::fromConfiguration('principal', [
            ['from_minor' => 0, 'to_minor' => 100, 'fee_minor' => 10],
            ['from_minor' => 101, 'to_minor' => 200, 'fee_minor' => 20],
        ]);

        $this->assertSame(10, $schedule->feeFor(0));
        $this->assertSame(10, $schedule->feeFor(100));
        $this->assertSame(20, $schedule->feeFor(101));
        $this->assertSame(20, $schedule->feeFor(200));
    }

    public function test_an_amount_no_tier_covers_fails_instead_of_charging_an_invented_fee(): void
    {
        $schedule = CustomerFeeSchedule::fromConfiguration('principal', [
            ['from_minor' => 0, 'to_minor' => 100, 'fee_minor' => 10],
        ]);

        $this->expectException(CustomerFeeTierMissing::class);

        $schedule->feeFor(101);
    }

    public function test_overlapping_tiers_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerFeeSchedule::fromConfiguration('principal', [
            ['from_minor' => 0, 'to_minor' => 100, 'fee_minor' => 10],
            ['from_minor' => 100, 'to_minor' => 200, 'fee_minor' => 20],
        ]);
    }

    public function test_an_open_ended_tier_may_not_be_followed_by_another(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerFeeSchedule::fromConfiguration('principal', [
            ['from_minor' => 0, 'to_minor' => null, 'fee_minor' => 10],
            ['from_minor' => 200, 'to_minor' => 300, 'fee_minor' => 20],
        ]);
    }

    public function test_a_negative_fee_or_inverted_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerFeeSchedule::fromConfiguration('principal', [
            ['from_minor' => 0, 'to_minor' => 100, 'fee_minor' => -1],
        ]);
    }

    public function test_an_inverted_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerFeeSchedule::fromConfiguration('principal', [
            ['from_minor' => 500, 'to_minor' => 100, 'fee_minor' => 10],
        ]);
    }

    public function test_a_schedule_without_a_supported_basis_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerFeeSchedule::fromConfiguration('percentage', self::TIERS);
    }

    public function test_a_schedule_without_tiers_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerFeeSchedule::fromConfiguration('principal', []);
    }

    public function test_a_fee_is_inactive_until_both_basis_and_tiers_are_configured(): void
    {
        $this->assertFalse(CustomerFeeSchedule::isConfigured(null, self::TIERS));
        $this->assertFalse(CustomerFeeSchedule::isConfigured('principal', null));
        $this->assertFalse(CustomerFeeSchedule::isConfigured('principal', []));
        $this->assertFalse(CustomerFeeSchedule::isConfigured('nonsense', self::TIERS));
        $this->assertTrue(CustomerFeeSchedule::isConfigured('principal', self::TIERS));
        $this->assertTrue(CustomerFeeSchedule::isConfigured('final_payable', self::TIERS));
    }

    public function test_tiers_are_ordered_regardless_of_how_they_were_entered(): void
    {
        $schedule = CustomerFeeSchedule::fromConfiguration('principal', [
            ['from_minor' => 100_001, 'to_minor' => null, 'fee_minor' => 5_000],
            ['from_minor' => 1, 'to_minor' => 10_000, 'fee_minor' => 250],
            ['from_minor' => 10_001, 'to_minor' => 100_000, 'fee_minor' => 500],
        ]);

        $this->assertSame([1, 10_001, 100_001], array_column($schedule->toArray(), 'from_minor'));
        $this->assertSame(250, $schedule->feeFor(5_000));
    }
}
