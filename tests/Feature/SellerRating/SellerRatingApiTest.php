<?php

declare(strict_types=1);

namespace Tests\Feature\SellerRating;

use App\Models\SellerRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SellerRatingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    public function test_public_seller_profile_contains_rating_summary(): void
    {
        $seller = User::factory()->create();
        $firstReviewer = User::factory()->create();
        $secondReviewer = User::factory()->create();

        SellerRating::create([
            'seller_id' => $seller->id,
            'reviewer_id' => $firstReviewer->id,
            'rating' => 5,
            'message' => 'بائع ممتاز.',
        ]);
        SellerRating::create([
            'seller_id' => $seller->id,
            'reviewer_id' => $secondReviewer->id,
            'rating' => 4,
            'message' => 'تجربة جيدة.',
        ]);

        $this->getJson("/api/soom/sellers/{$seller->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $seller->id)
            ->assertJsonPath('data.rating_summary.average', 4.5)
            ->assertJsonPath('data.rating_summary.count', 2)
            ->assertJsonPath('data.rating_summary.distribution.0.rating', 5)
            ->assertJsonPath('data.rating_summary.distribution.0.count', 1)
            ->assertJsonPath('data.rating_summary.distribution.1.rating', 4)
            ->assertJsonPath('data.rating_summary.distribution.1.count', 1)
            ->assertJsonPath('data.viewer.can_rate', false)
            ->assertJsonPath('data.viewer.rating', null)
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.email');
    }

    public function test_authenticated_viewer_sees_own_rating_on_seller_profile(): void
    {
        $seller = User::factory()->create();
        $reviewer = User::factory()->create();
        SellerRating::create([
            'seller_id' => $seller->id,
            'reviewer_id' => $reviewer->id,
            'rating' => 5,
            'message' => 'موثوق جداً.',
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/soom/sellers/{$seller->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer.can_rate', true)
            ->assertJsonPath('data.viewer.rating.rating', 5)
            ->assertJsonPath('data.viewer.rating.message', 'موثوق جداً.');
    }

    public function test_user_can_create_then_update_one_rating_for_a_seller(): void
    {
        $seller = User::factory()->create();
        $reviewer = User::factory()->create();

        $this->actingAs($reviewer, 'sanctum')
            ->putJson("/api/soom/sellers/{$seller->id}/rating", [
                'rating' => 4,
                'message' => 'تعامل محترم.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.message', 'تعامل محترم.');

        $this->actingAs($reviewer, 'sanctum')
            ->putJson("/api/soom/sellers/{$seller->id}/rating", [
                'rating' => 5,
                'message' => 'تم تحديث التجربة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.message', 'تم تحديث التجربة.');

        $this->assertDatabaseCount('seller_ratings', 1);
        $this->assertDatabaseHas('seller_ratings', [
            'seller_id' => $seller->id,
            'reviewer_id' => $reviewer->id,
            'rating' => 5,
            'message' => 'تم تحديث التجربة.',
        ]);
    }

    public function test_user_cannot_rate_themselves(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/soom/sellers/{$user->id}/rating", [
                'rating' => 5,
                'message' => 'تقييم ذاتي.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('seller');

        $this->assertDatabaseCount('seller_ratings', 0);
    }

    public function test_rating_payload_is_validated(): void
    {
        $seller = User::factory()->create();
        $reviewer = User::factory()->create();

        $this->actingAs($reviewer, 'sanctum')
            ->putJson("/api/soom/sellers/{$seller->id}/rating", [
                'rating' => 6,
                'message' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating', 'message']);
    }

    public function test_public_rating_list_is_paginated_and_latest_first(): void
    {
        $seller = User::factory()->create();
        $olderReviewer = User::factory()->create();
        $newerReviewer = User::factory()->create();

        SellerRating::create([
            'seller_id' => $seller->id,
            'reviewer_id' => $olderReviewer->id,
            'rating' => 3,
            'message' => 'الأقدم.',
        ]);
        SellerRating::create([
            'seller_id' => $seller->id,
            'reviewer_id' => $newerReviewer->id,
            'rating' => 5,
            'message' => 'الأحدث.',
        ]);

        $this->getJson("/api/soom/sellers/{$seller->id}/ratings?per_page=1")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.message', 'الأحدث.')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonMissingPath('data.0.reviewer.phone')
            ->assertJsonMissingPath('data.0.reviewer.email');
    }

    public function test_user_can_delete_only_their_rating_for_the_seller(): void
    {
        $seller = User::factory()->create();
        $reviewer = User::factory()->create();
        SellerRating::create([
            'seller_id' => $seller->id,
            'reviewer_id' => $reviewer->id,
            'rating' => 4,
            'message' => 'سيتم حذفه.',
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->deleteJson("/api/soom/sellers/{$seller->id}/rating")
            ->assertOk();

        $this->assertDatabaseCount('seller_ratings', 0);
    }

    public function test_rating_writes_require_authentication(): void
    {
        $seller = User::factory()->create();

        $this->putJson("/api/soom/sellers/{$seller->id}/rating", [
            'rating' => 5,
            'message' => 'تقييم.',
        ])->assertUnauthorized();

        $this->deleteJson("/api/soom/sellers/{$seller->id}/rating")
            ->assertUnauthorized();
    }
}
