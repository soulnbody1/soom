<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Models\Auction\AuctionTermsVersion;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminTermsShowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_admin_can_read_terms_version_body(): void
    {
        $terms = AuctionTermsVersion::create([
            'version_number' => 7,
            'title' => 'شروط المزاد',
            'body' => 'النص الكامل لشروط المزاد.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/terms/'.$terms->public_id)
            ->assertOk();

        $response->assertJsonPath('data.id', $terms->public_id);
        $response->assertJsonPath('data.version_number', 7);
        $response->assertJsonPath('data.title', 'شروط المزاد');
        $response->assertJsonPath('data.body', 'النص الكامل لشروط المزاد.');
        $response->assertJsonPath('data.is_active', true);
        $this->assertNotNull($response->json('data.published_at'));
    }

    public function test_unknown_terms_ulid_returns_404(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/terms/'.strtoupper((string) Str::ulid()))
            ->assertNotFound();
    }

    public function test_non_admin_cannot_read_terms_version(): void
    {
        $terms = AuctionTermsVersion::create([
            'version_number' => 8,
            'title' => 'Terms',
            'body' => 'Body.',
            'is_active' => false,
            'published_at' => null,
        ]);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/terms/'.$terms->public_id)
            ->assertForbidden();
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "terms-show-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
