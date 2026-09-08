<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Models\Auction\PaymentMethod;
use App\Services\Auction\Payments\Providers\EFawateercomPaymentProvider;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class PaymentFeeSettingsTest extends TestCase
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

    public function test_the_option_endpoint_offers_only_the_two_approved_bases(): void
    {
        $response = $this->actingAs($this->paymentUser('admin'))
            ->getJson('/api/admin/auctions/payment-method-options');

        $response->assertOk();
        $this->assertSame(['principal', 'final_payable'], $response->json('data.fee_bases'));
    }

    public function test_an_admin_can_store_a_fee_schedule_and_service_codes(): void
    {
        $method = $this->enableEfawateercom();

        $response = $this->actingAs($this->paymentUser('admin'))
            ->putJson('/api/admin/auctions/payment-methods/'.$method->public_id, [
                'fee_basis' => 'principal',
                'fee_tiers' => self::TIERS,
                'provider_purpose_codes' => [
                    'bidder_deposit' => 'SOOMBID',
                    'seller_deposit' => 'SOOMSELL',
                    'winner_settlement' => 'SOOMWIN',
                ],
            ]);

        $response->assertOk();

        $stored = $method->refresh();
        $this->assertSame('principal', $stored->fee_basis);
        $this->assertSame(self::TIERS, $stored->fee_tiers);
        $this->assertSame('SOOMWIN', $stored->provider_purpose_codes['winner_settlement']);
        $this->assertSame('principal', $response->json('data.fee_basis'));
        $this->assertSame(250, $response->json('data.fee_tiers.0.fee_minor'));
    }

    public function test_overlapping_tiers_are_refused(): void
    {
        $method = $this->enableEfawateercom();

        $this->actingAs($this->paymentUser('admin'))
            ->putJson('/api/admin/auctions/payment-methods/'.$method->public_id, [
                'fee_basis' => 'principal',
                'fee_tiers' => [
                    ['from_minor' => 0, 'to_minor' => 10_000, 'fee_minor' => 250],
                    ['from_minor' => 10_000, 'to_minor' => 20_000, 'fee_minor' => 500],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_tiers');

        $this->assertNull($method->refresh()->fee_basis);
    }

    public function test_a_negative_fee_is_refused(): void
    {
        $method = $this->enableEfawateercom();

        $this->actingAs($this->paymentUser('admin'))
            ->putJson('/api/admin/auctions/payment-methods/'.$method->public_id, [
                'fee_basis' => 'principal',
                'fee_tiers' => [['from_minor' => 0, 'to_minor' => 10_000, 'fee_minor' => -1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_tiers.0.fee_minor');
    }

    public function test_an_inverted_range_is_refused(): void
    {
        $method = $this->enableEfawateercom();

        $this->actingAs($this->paymentUser('admin'))
            ->putJson('/api/admin/auctions/payment-methods/'.$method->public_id, [
                'fee_basis' => 'principal',
                'fee_tiers' => [['from_minor' => 20_000, 'to_minor' => 10_000, 'fee_minor' => 250]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_tiers');
    }

    public function test_an_unapproved_fee_basis_is_refused(): void
    {
        $method = $this->enableEfawateercom();

        $this->actingAs($this->paymentUser('admin'))
            ->putJson('/api/admin/auctions/payment-methods/'.$method->public_id, [
                'fee_basis' => 'percentage',
                'fee_tiers' => self::TIERS,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_basis');
    }

    public function test_a_basis_without_tiers_is_refused(): void
    {
        $method = $this->enableEfawateercom();

        $this->actingAs($this->paymentUser('admin'))
            ->putJson('/api/admin/auctions/payment-methods/'.$method->public_id, [
                'fee_basis' => 'final_payable',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee_tiers');
    }

    public function test_the_fee_can_be_switched_off_again(): void
    {
        $method = $this->enableEfawateercom();
        $method->forceFill(['fee_basis' => 'principal', 'fee_tiers' => self::TIERS])->save();

        $this->actingAs($this->paymentUser('admin'))
            ->putJson('/api/admin/auctions/payment-methods/'.$method->public_id, [
                'fee_basis' => null,
                'fee_tiers' => null,
            ])
            ->assertOk();

        $stored = $method->refresh();
        $this->assertNull($stored->fee_basis);
        $this->assertNull($stored->fee_tiers);
    }

    public function test_the_admin_listing_never_exposes_provider_credentials(): void
    {
        $this->enableEfawateercom();

        $response = $this->actingAs($this->paymentUser('admin'))
            ->getJson('/api/admin/auctions/payment-methods');

        $response->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('ctm-secret', $body);
        $this->assertStringNotContainsString('ctm-user', $body);
        $this->assertStringContainsString(EFawateercomPaymentProvider::CODE, $body);
    }

    public function test_the_provider_listing_reports_readiness_without_secrets(): void
    {
        $this->enableEfawateercom();

        $response = $this->actingAs($this->paymentUser('admin'))
            ->getJson('/api/admin/auctions/payment-providers');

        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('ctm-secret', $body);

        $described = collect($response->json('data'))
            ->firstWhere('code', EFawateercomPaymentProvider::CODE);

        $this->assertTrue($described['credentials_configured']);
        $this->assertFalse($described['capabilities']['refund']);
        $this->assertFalse($described['capabilities']['inquiry']);
        $this->assertTrue($described['capabilities']['webhook']);
    }

    public function test_the_provider_reports_missing_credentials_when_unconfigured(): void
    {
        config([
            'auction.payments.disabled_providers' => [],
            'services.efawateercom.biller_code' => '',
            'services.efawateercom.username' => '',
            'services.efawateercom.password' => '',
        ]);

        $described = collect(
            $this->actingAs($this->paymentUser('admin'))
                ->getJson('/api/admin/auctions/payment-providers')
                ->json('data')
        )->firstWhere('code', EFawateercomPaymentProvider::CODE);

        $this->assertFalse($described['credentials_configured']);
        $this->assertSame('missing_credentials', $described['status']);
    }

    public function test_the_connection_test_reports_unmapped_services_without_leaking_secrets(): void
    {
        PaymentMethod::query()->where('provider_code', EFawateercomPaymentProvider::CODE)->delete();
        config([
            'auction.payments.disabled_providers' => [],
            'services.efawateercom.biller_code' => '1000',
            'services.efawateercom.username' => 'ctm-user',
            'services.efawateercom.password' => 'ctm-secret',
        ]);

        $response = $this->actingAs($this->paymentUser('admin'))
            ->postJson('/api/admin/auctions/payment-providers/efawateercom/test');

        $response->assertOk();
        $this->assertFalse($response->json('data.ok'));
        $this->assertStringNotContainsString('ctm-secret', (string) $response->getContent());
    }
}
