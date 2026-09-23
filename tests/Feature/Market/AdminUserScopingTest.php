<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Auction\Auction;
use App\Models\Market;
use App\Models\User;
use App\Support\Market\MarketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUserScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Storage::fake('spaces');
        Market::query()->where('code', 'EG')->update(['is_active' => true]);
        $this->member = User::factory()->create();

        $this->refundFor('JO', 'JOD', 1500);
        $this->refundFor('EG', 'EGP', 70000);
    }

    private function refundFor(string $code, string $currency, int $amount): void
    {
        $market = Market::query()->where('code', $code)->firstOrFail();

        $auction = app(MarketContext::class)->runInMarket($market, fn (): Auction => Auction::factory()->create([
            'country_id' => $market->country_id,
            'currency_code' => $currency,
        ]));

        DB::table('refund_transactions')->insert([
            'market_id' => $market->getKey(),
            'public_id' => (string) Str::ulid(),
            'auction_id' => $auction->id,
            'user_id' => $this->member->id,
            'status' => 'pending',
            'amount_minor' => $amount,
            'held_refund_amount_minor' => $amount,
            'applied_refund_amount_minor' => 0,
            'currency_code' => $currency,
            'reason' => 'bidder_deposit_release',
            'provider' => 'manual',
            'idempotency_key' => (string) Str::ulid(),
            'attempt_count' => 0,
            'obligation_type' => 'bidder_deposit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function summaryFor(?string $market): array
    {
        $admin = User::factory()->admin()->create();
        $query = $market === null ? '' : "?market={$market}";

        return $this->actingAs($admin, 'sanctum')
            ->getJson("http://api-admin.soom.test/api/admin/users/{$this->member->id}/financial/summary{$query}")
            ->assertOk()
            ->json('data.refunds');
    }

    public function test_an_unfiltered_summary_reports_every_market_separately(): void
    {
        $rows = $this->summaryFor(null);

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['jo', 'eg'], array_column($rows, 'market'));
        $this->assertEqualsCanonicalizing(['JOD', 'EGP'], array_column($rows, 'currency'));
    }

    public function test_an_unfiltered_summary_never_sums_across_currencies(): void
    {
        foreach ($this->summaryFor(null) as $row) {
            $this->assertNotNull($row['market']);
            $this->assertSame(1, $row['count']);
        }
    }

    public function test_a_filtered_summary_reports_only_that_market(): void
    {
        $jordan = $this->summaryFor('jo');
        $this->assertCount(1, $jordan);
        $this->assertSame('jo', $jordan[0]['market']);
        $this->assertSame('JOD', $jordan[0]['currency']);
        $this->assertSame(1500, $jordan[0]['total_minor']);

        $egypt = $this->summaryFor('eg');
        $this->assertCount(1, $egypt);
        $this->assertSame('eg', $egypt[0]['market']);
        $this->assertSame(70000, $egypt[0]['total_minor']);
    }

    public function test_profile_counts_follow_the_market_filter_and_label_it(): void
    {
        $admin = User::factory()->admin()->create();
        $base = "http://api-admin.soom.test/api/admin/users/{$this->member->id}";

        $all = $this->actingAs($admin, 'sanctum')->getJson($base)->assertOk()->json('data.counts');
        $this->assertSame('all', $all['market']);
        $this->assertSame(2, $all['refunds']);

        $jordan = $this->actingAs($admin, 'sanctum')->getJson("{$base}?market=jo")->assertOk()->json('data.counts');
        $this->assertSame('jo', $jordan['market']);
        $this->assertSame(1, $jordan['refunds']);

        $egypt = $this->actingAs($admin, 'sanctum')->getJson("{$base}?market=eg")->assertOk()->json('data.counts');
        $this->assertSame('eg', $egypt['market']);
        $this->assertSame(1, $egypt['refunds']);
    }

    public function test_account_level_records_stay_global_under_a_market_filter(): void
    {
        $admin = User::factory()->admin()->create();

        DB::table('payout_destinations')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $this->member->id,
            'recipient_name' => 'صاحب الحساب',
            'identifier_type' => 'iban',
            'identifier_value' => 'JO00SOOM0000000000000000',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $base = "http://api-admin.soom.test/api/admin/users/{$this->member->id}";

        foreach ([null, 'jo', 'eg'] as $market) {
            $query = $market === null ? '' : "?market={$market}";
            $counts = $this->actingAs($admin, 'sanctum')->getJson($base.$query)->assertOk()->json('data.counts');
            $this->assertSame(1, $counts['payout_destinations'], (string) $market);
        }
    }

    public function test_the_context_resolves_both_markets_by_host(): void
    {
        $context = app(MarketContext::class);

        foreach (['JO' => 'JOD', 'EG' => 'EGP'] as $code => $currency) {
            $market = Market::query()->where('code', $code)->firstOrFail();
            $resolved = $context->runInMarket($market, fn (): string => $context->market()->currency_code);
            $this->assertSame($currency, $resolved);
        }
    }
}
