<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Regression: sendResponse() used to call paginator methods on every
 * AnonymousResourceCollection, so these two NON-paginated public catalog
 * endpoints (B4 payment methods, B6 auction terms) returned 500.
 */
final class PublicCatalogEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_payment_methods_index_returns_plain_resource_array(): void
    {
        PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'catalog-test-transfer',
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/soom/payment-methods')->assertOk();

        $response->assertJsonStructure(['success', 'message', 'data']);
        $this->assertArrayNotHasKey('current_page', $response->json());

        $row = collect($response->json('data'))->firstWhere(
            'code',
            'catalog-test-transfer'
        );
        $this->assertNotNull($row);
        $this->assertSame('Manual transfer', $row['name']);
        $this->assertTrue($row['is_active']);
    }

    public function test_auction_terms_index_returns_plain_array_without_body(): void
    {
        AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            ['title' => 'Terms', 'body' => 'Auction terms.', 'is_active' => true, 'published_at' => now()]
        );

        $response = $this->getJson('/api/soom/auction-terms')->assertOk();

        $response->assertJsonStructure(['success', 'message', 'data']);
        $this->assertArrayNotHasKey('current_page', $response->json());

        $row = $response->json('data.0');
        $this->assertSame(1, $row['version_number']);
        $this->assertArrayHasKey('title', $row);
        $this->assertArrayNotHasKey('body', $row);
    }
}
