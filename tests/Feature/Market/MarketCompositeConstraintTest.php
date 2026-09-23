<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Ad;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentMethod;
use App\Models\Market;
use App\Models\User;
use App\Support\Market\MarketContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarketCompositeConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Composite FK proof runs on MySQL.');
        }
    }

    public function test_resource_country_and_currency_cannot_cross_market(): void
    {
        [$jo, $eg] = $this->markets();
        $ad = app(MarketContext::class)->runInMarket($jo, fn () => Ad::factory()->create([
            'country_id' => $jo->country_id,
            'currency_code' => 'JOD',
        ]));

        $this->expectException(QueryException::class);
        DB::table('ads')->where('id', $ad->id)->update(['market_id' => $eg->id]);
    }

    public function test_auction_child_cannot_reference_another_market(): void
    {
        [$jo, $eg] = $this->markets();
        $auction = app(MarketContext::class)->runInMarket($jo, fn () => Auction::factory()->create([
            'country_id' => $jo->country_id,
            'currency_code' => 'JOD',
        ]));

        $this->expectException(QueryException::class);
        DB::table('auction_views')->insert([
            'market_id' => $eg->id,
            'auction_id' => $auction->id,
            'viewer_hash' => hash('sha256', 'cross-market'),
            'viewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payment_method_cannot_be_used_by_another_market(): void
    {
        [$jo, $eg] = $this->markets();
        $user = User::factory()->create();
        $auction = app(MarketContext::class)->runInMarket($jo, fn () => Auction::factory()->create([
            'seller_id' => $user->id,
            'country_id' => $jo->country_id,
            'currency_code' => 'JOD',
        ]));
        $method = app(MarketContext::class)->runInMarket($eg, fn () => PaymentMethod::factory()->create());

        $this->expectException(QueryException::class);
        DB::table('payment_submissions')->insert([
            'market_id' => $jo->id,
            'public_id' => (string) Str::ulid(),
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'purpose' => 'seller_deposit',
            'status' => 'pending_review',
            'amount_minor' => 1000,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'receipts/test.jpg',
            'receipt_mime_type' => 'image/jpeg',
            'receipt_size_bytes' => 100,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{Market, Market} */
    private function markets(): array
    {
        return [
            Market::query()->where('code', 'JO')->firstOrFail(),
            Market::query()->where('code', 'EG')->firstOrFail(),
        ];
    }
}
