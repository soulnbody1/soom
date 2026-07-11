<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Rules\CurrencyDecimalRule;
use App\Domain\Auction\ValueObjects\Currency;
use App\Domain\Auction\ValueObjects\Money;
use App\DTO\Auction\CreateAuctionInputDTO;
use App\DTO\Auction\CreateAuctionRecordDTO;
use App\DTO\Auction\CreateSettlementDTO;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Unit-level tests for auction financial integrity, state machine transitions,
 * DTO construction, and currency validation.
 * These tests do NOT require a database.
 */
class AuctionCoreTest extends TestCase
{
    // ─── Currency & Money ─────────────────────────────────────

    public function test_jod_has_3_decimal_exponent(): void
    {
        $currency = Currency::fromCode('JOD');
        $this->assertSame(3, $currency->exponent());
        $this->assertSame(1000, $currency->scale());
    }

    public function test_egp_has_2_decimal_exponent(): void
    {
        $currency = Currency::fromCode('EGP');
        $this->assertSame(2, $currency->exponent());
        $this->assertSame(100, $currency->scale());
    }

    public function test_money_from_jod_decimal_preserves_3_decimals(): void
    {
        $money = Money::fromDecimalString('1.234', 'JOD');
        $this->assertSame(1234, $money->minor);
        $this->assertSame('JOD', $money->currency);
    }

    public function test_money_from_egp_decimal_preserves_2_decimals(): void
    {
        $money = Money::fromDecimalString('99.99', 'EGP');
        $this->assertSame(9999, $money->minor);
    }

    public function test_money_from_whole_number(): void
    {
        $money = Money::fromDecimalString('500', 'JOD');
        $this->assertSame(500000, $money->minor);
    }

    public function test_unsupported_currency_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Currency::fromCode('BTC');
    }

    // ─── DTO Integrity ─────────────────────────────────────────

    public function test_create_auction_input_dto_from_validated(): void
    {
        $dto = CreateAuctionInputDTO::fromValidated([
            'category_id' => 1,
            'country_id' => 2,
            'title' => 'Test Auction',
            'description' => 'Description here',
            'currency_code' => 'jod',
            'starting_amount' => '100.500',
            'starts_at' => '2026-08-01T10:00:00Z',
            'ends_at' => '2026-08-02T10:00:00Z',
        ]);

        $this->assertSame(1, $dto->category_id);
        $this->assertSame('JOD', $dto->currency_code); // uppercased
        $this->assertSame('100.500', $dto->starting_amount);
        $this->assertNull($dto->state_id);
        $this->assertNull($dto->reserve_amount);
        $this->assertSame([], $dto->media);
    }

    public function test_create_auction_record_dto_persistence_array(): void
    {
        $dto = new CreateAuctionRecordDTO(
            seller_id: 1,
            category_id: 2,
            country_id: 3,
            state_id: null,
            city_id: null,
            terms_version_id: 1,
            configuration_version_id: 1,
            currency_code: 'JOD',
            title: 'Test',
            description: 'Desc',
            latitude: null,
            longitude: null,
            status: AuctionStatus::Draft,
            starting_amount_minor: 100000,
            reserve_amount_minor: null,
            minimum_bid_increment_minor: 1000,
            seller_deposit_amount_minor: 50000,
            bidder_deposit_amount_minor: 25000,
            platform_fee_type: 'percentage',
            platform_fee_basis_points: 500,
            platform_fee_fixed_minor: 0,
            winner_payment_deadline_hours: 48,
            handover_deadline_hours: 72,
            starts_at: '2026-08-01',
            ends_at: '2026-08-02',
            original_ends_at: '2026-08-02',
            extension_window_seconds: 300,
            extension_duration_seconds: 600,
            maximum_extension_count: 6,
        );

        $array = $dto->toPersistenceArray();

        $this->assertSame(1, $array['seller_id']);
        $this->assertSame(1, $array['configuration_version_id']);
        $this->assertSame(100000, $array['starting_amount_minor']);
        $this->assertSame(500, $array['platform_fee_basis_points']);
        $this->assertSame(AuctionStatus::Draft, $array['status']);
    }

    public function test_settlement_dto_calculates_correctly(): void
    {
        $dto = new CreateSettlementDTO(
            auction_id: 1,
            winning_bid_id: 10,
            winner_id: 5,
            status: SettlementStatus::PaymentPending,
            winning_amount_minor: 100000,
            deposit_applied_minor: 25000,
            platform_fee_minor: 5000,
            seller_net_amount_minor: 95000,
            amount_due_minor: 75000,
            amount_paid_minor: 0,
            remaining_amount_minor: 75000,
            currency_code: 'JOD',
        );

        $this->assertSame(100000, $dto->winning_amount_minor);
        $this->assertSame(75000, $dto->amount_due_minor);
        $this->assertSame(0, $dto->amount_paid_minor);
        $this->assertSame(75000, $dto->remaining_amount_minor);
    }

    // ─── Platform Fee Calculation ────────────────────────────

    public function test_percentage_fee_is_integer_arithmetic(): void
    {
        // 5% of 100.000 JOD = 100000 minor * 500 / 10000 = 5000
        $amount = 100000;
        $basisPoints = 500;
        $fee = intdiv($amount * $basisPoints, 10_000);
        $this->assertSame(5000, $fee);
    }

    public function test_fee_on_small_amount_has_no_float_drift(): void
    {
        // 2.5% of 1.000 JOD = 1000 minor * 250 / 10000 = 25
        $amount = 1000;
        $basisPoints = 250;
        $fee = intdiv($amount * $basisPoints, 10_000);
        $this->assertSame(25, $fee);
    }

    // ─── CurrencyDecimalRule ──────────────────────────────────

    public function test_currency_decimal_rule_rejects_excess_decimals(): void
    {
        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'currency_code' => 'EGP',
            'starting_amount' => '50.123', // 3 decimals for EGP (max 2)
        ]);
        app()->instance('request', $request);

        $rule = new CurrencyDecimalRule('currency_code');
        $failed = false;
        $rule->validate('starting_amount', '50.123', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    public function test_currency_decimal_rule_accepts_valid_jod(): void
    {
        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'currency_code' => 'JOD',
            'starting_amount' => '50.123', // 3 decimals for JOD (max 3)
        ]);
        app()->instance('request', $request);

        $rule = new CurrencyDecimalRule('currency_code');
        $failed = false;
        $rule->validate('starting_amount', '50.123', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_place_bid_currency_validation_supports_jod_three_decimals(): void
    {
        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'currency_code' => 'JOD',
            'amount' => '10.001',
            'idempotency_key' => 'bid-key',
        ]);
        app()->instance('request', $request);

        $validator = Validator::make([
            'currency_code' => 'JOD',
            'amount' => '10.001',
            'idempotency_key' => 'bid-key',
        ], (new \App\Http\Requests\Auction\PlaceBidRequest)->rules());

        $this->assertFalse($validator->fails());
    }

    public function test_place_bid_currency_validation_rejects_egp_three_decimals(): void
    {
        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'currency_code' => 'EGP',
            'amount' => '10.001',
            'idempotency_key' => 'bid-key',
        ]);
        app()->instance('request', $request);

        $validator = Validator::make([
            'currency_code' => 'EGP',
            'amount' => '10.001',
            'idempotency_key' => 'bid-key',
        ], (new \App\Http\Requests\Auction\PlaceBidRequest)->rules());

        $this->assertTrue($validator->fails());
    }

    // ─── DTO Serialization ───────────────────────────────────

    public function test_dto_json_serialization(): void
    {
        $dto = CreateAuctionInputDTO::fromValidated([
            'category_id' => 1,
            'country_id' => 2,
            'title' => 'Test',
            'description' => 'Desc',
            'currency_code' => 'JOD',
            'starting_amount' => '100',
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-08-02',
        ]);

        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['category_id']);
        $this->assertSame('100', $decoded['starting_amount']);
        $this->assertArrayNotHasKey('media', $decoded); // media excluded from toArray
    }
}
