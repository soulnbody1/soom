<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $phone = '+962700000055';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    public function test_login_issues_an_access_and_refresh_token(): void
    {
        $this->verifiedUser();

        $response = $this->postJson('/api/auth/login', [
            'phone' => $this->phone,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertNotEmpty($response->json('refresh_token'));
        $this->assertDatabaseCount('refresh_tokens', 1);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $this->verifiedUser();

        $this->postJson('/api/auth/login', [
            'phone' => $this->phone,
            'password' => 'not-the-password',
        ])->assertStatus(431);

        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_login_rejects_an_unverified_phone(): void
    {
        $this->verifiedUser(verified: false);

        $this->postJson('/api/auth/login', [
            'phone' => $this->phone,
            'password' => 'password',
        ])->assertStatus(430);
    }

    public function test_login_never_returns_the_password_hash(): void
    {
        $this->verifiedUser();

        $response = $this->postJson('/api/auth/login', [
            'phone' => $this->phone,
            'password' => 'password',
        ])->assertOk();

        $this->assertArrayNotHasKey('password', (array) $response->json('user'));
        $this->assertArrayNotHasKey('fcm_token', (array) $response->json('user'));
    }

    public function test_login_is_rate_limited_per_phone(): void
    {
        config(['otp.rate_limits.login_per_minute' => 2]);
        $this->verifiedUser();

        foreach (range(1, 2) as $ignored) {
            $this->postJson('/api/auth/login', ['phone' => $this->phone, 'password' => 'wrong'])
                ->assertStatus(431);
        }

        $this->postJson('/api/auth/login', ['phone' => $this->phone, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_a_refresh_token_is_consumed_and_cannot_be_replayed(): void
    {
        $refreshToken = $this->login()['refresh_token'];

        $rotated = $this->postJson('/api/auth/refreshToken', ['refresh_token' => $refreshToken])
            ->assertOk()
            ->json('refresh_token');

        $this->assertNotSame($refreshToken, $rotated);

        $this->postJson('/api/auth/refreshToken', ['refresh_token' => $refreshToken])
            ->assertStatus(431);

        $this->postJson('/api/auth/refreshToken', ['refresh_token' => $rotated])
            ->assertOk();
    }

    public function test_a_refresh_token_is_never_stored_in_plaintext(): void
    {
        $refreshToken = $this->login()['refresh_token'];

        $stored = (string) DB::table('refresh_tokens')->value('token');

        $this->assertNotSame($refreshToken, $stored);
        $this->assertSame(hash('sha256', $refreshToken), $stored);
    }

    public function test_an_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/auth/refreshToken', ['refresh_token' => str_repeat('a', 64)])
            ->assertStatus(431);
    }

    public function test_an_expired_refresh_token_is_rejected(): void
    {
        $refreshToken = $this->login()['refresh_token'];

        DB::table('refresh_tokens')->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/refreshToken', ['refresh_token' => $refreshToken])
            ->assertStatus(431);
    }

    public function test_an_access_token_stops_working_once_it_expires(): void
    {
        $accessToken = $this->login()['access_token'];

        $this->asToken($accessToken)->getJson('/api/soom/profile')->assertOk();

        $this->travel((int) config('sanctum.expiration') + 1)->minutes();

        $this->asToken($accessToken)->getJson('/api/soom/profile')->assertStatus(401);
    }

    public function test_changing_the_password_invalidates_the_previous_access_token(): void
    {
        $tokens = $this->login();

        $this->asToken($tokens['access_token'])->postJson('/api/soom/change-password', [
            'old_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->asToken($tokens['access_token'])->getJson('/api/soom/profile')->assertStatus(401);
        $this->postJson('/api/auth/refreshToken', ['refresh_token' => $tokens['refresh_token']])->assertStatus(431);

        $this->postJson('/api/auth/login', ['phone' => $this->phone, 'password' => 'new-password-1'])->assertOk();
    }

    public function test_changing_the_password_rejects_a_wrong_current_password(): void
    {
        $tokens = $this->login();

        $this->asToken($tokens['access_token'])->postJson('/api/soom/change-password', [
            'old_password' => 'not-the-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(400);

        $this->asToken($tokens['access_token'])->getJson('/api/soom/profile')->assertOk();
    }

    public function test_logout_revokes_the_access_and_refresh_tokens(): void
    {
        $tokens = $this->login();

        $this->asToken($tokens['access_token'])->postJson('/api/soom/logout')->assertOk();

        $this->asToken($tokens['access_token'])->getJson('/api/soom/profile')->assertStatus(401);
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_protected_routes_reject_a_missing_token(): void
    {
        $this->getJson('/api/soom/profile')->assertStatus(401);
    }

    private function asToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function login(): array
    {
        $this->verifiedUser();

        return $this->postJson('/api/auth/login', [
            'phone' => $this->phone,
            'password' => 'password',
        ])->assertOk()->json();
    }

    private function verifiedUser(bool $verified = true): User
    {
        return User::factory()->create([
            'phone' => $this->phone,
            'password' => Hash::make('password'),
            'email_verified_at' => $verified ? now() : null,
        ]);
    }
}
