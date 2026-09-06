<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\OtpManager;
use App\Services\TwilioWhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class OtpSecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $phone = '+962700000001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');

        $this->app->instance(
            TwilioWhatsappService::class,
            Mockery::mock(TwilioWhatsappService::class)->shouldReceive('sendOtp')->andReturn('sent')->getMock()
        );
    }

    public function test_the_stored_otp_is_hashed_not_plaintext(): void
    {
        $otp = $this->issueOtpFor($this->userWithPhone());

        $stored = (string) DB::table('password_reset_tokens')->where('phone', $this->phone)->value('token');

        $this->assertNotSame($otp, $stored);
        $this->assertTrue(Hash::check($otp, $stored));
    }

    public function test_a_correct_otp_is_consumed_and_cannot_be_replayed(): void
    {
        $user = $this->userWithPhone();
        $otp = $this->issueOtpFor($user);

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $otp])
            ->assertOk();

        $this->assertDatabaseCount('password_reset_tokens', 0);

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $otp])
            ->assertStatus(422);
    }

    public function test_brute_force_is_stopped_after_the_attempt_budget_and_burns_the_code(): void
    {
        $user = $this->userWithPhone();
        $otp = $this->issueOtpFor($user);
        $wrong = $this->wrongOtp($otp);

        $max = (int) config('otp.max_verification_attempts');

        for ($attempt = 1; $attempt < $max; $attempt++) {
            $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $wrong])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $wrong])
            ->assertStatus(429);

        $this->assertDatabaseCount('password_reset_tokens', 0);

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $otp])
            ->assertStatus(422);
    }

    public function test_an_expired_otp_is_rejected_and_discarded(): void
    {
        $user = $this->userWithPhone();
        $otp = $this->issueOtpFor($user);

        DB::table('password_reset_tokens')
            ->where('phone', $this->phone)
            ->update(['created_at' => now()->subMinutes((int) config('otp.ttl_minutes') + 1)]);

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $otp])
            ->assertStatus(422);

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_issuing_a_new_code_invalidates_the_previous_one_and_resets_attempts(): void
    {
        $user = $this->userWithPhone();
        $first = $this->issueOtpFor($user);

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $this->wrongOtp($first)])
            ->assertStatus(422);

        $second = $this->issueOtpFor($user);

        $this->assertSame(0, (int) DB::table('password_reset_tokens')->where('phone', $this->phone)->value('attempts'));

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $first])
            ->assertStatus(422);

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => $second])
            ->assertOk();
    }

    public function test_resetting_a_password_revokes_every_existing_session(): void
    {
        $user = $this->userWithPhone(verified: true);
        $user->createToken('api-token');
        $otp = $this->issueOtpFor($user);

        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/auth/reset-password', [
            'phone' => $this->phone,
            'otp' => $otp,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
    }

    public function test_changing_a_password_revokes_other_sessions_and_returns_fresh_tokens(): void
    {
        $user = $this->userWithPhone(verified: true);
        $user->createToken('old-device');
        $user->createToken('another-device');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/change-password', [
                'old_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'access_token', 'refresh_token']);

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_otp_verification_is_rate_limited_per_phone(): void
    {
        config(['otp.rate_limits.otp_verify_per_minute' => 3]);
        $this->userWithPhone();

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => '0000'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/verify-otp', ['phone' => $this->phone, 'otp' => '0000'])
            ->assertStatus(429);
    }

    public function test_repeated_otp_requests_are_throttled(): void
    {
        $this->userWithPhone();

        $this->postJson('/api/auth/forget-password', ['phone' => $this->phone])->assertOk();
        $this->postJson('/api/auth/forget-password', ['phone' => $this->phone])->assertStatus(429);
    }

    public function test_a_failed_otp_delivery_does_not_leave_a_half_registered_account(): void
    {
        $this->app->instance(
            TwilioWhatsappService::class,
            Mockery::mock(TwilioWhatsappService::class)
                ->shouldReceive('sendOtp')->andThrow(new \RuntimeException('gateway down'))->getMock()
        );

        $this->postJson('/api/auth/register', [
            'name' => 'Blocked Signup',
            'phone' => $this->phone,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(500);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_an_unverified_phone_can_be_registered_again(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'First Try',
            'phone' => $this->phone,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $this->travel((int) config('otp.send.cooldown_seconds') + 1)->seconds();

        $this->postJson('/api/auth/register', [
            'name' => 'Second Try',
            'phone' => $this->phone,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Second Try', User::where('phone', $this->phone)->value('name'));
    }

    public function test_a_verified_phone_cannot_be_registered_again(): void
    {
        $this->userWithPhone(verified: true);

        $this->postJson('/api/auth/register', [
            'name' => 'Impostor',
            'phone' => $this->phone,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);
    }

    private function userWithPhone(bool $verified = false): User
    {
        return User::factory()->create([
            'phone' => $this->phone,
            'password' => Hash::make('password'),
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    private function issueOtpFor(User $user): string
    {
        $otp = app(OtpManager::class)->generate();
        app(OtpManager::class)->issue($this->phone, $otp);

        return $otp;
    }

    private function wrongOtp(string $otp): string
    {
        return $otp === '0000' ? '1111' : '0000';
    }
}
