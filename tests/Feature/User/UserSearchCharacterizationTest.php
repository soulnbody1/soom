<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class UserSearchCharacterizationTest extends UserTestCase
{
    use RefreshDatabase;

    public function test_search_matches_by_name(): void
    {
        $admin = $this->admin();
        $target = $this->member(['name' => 'خالد المطيري']);
        $other = $this->member(['name' => 'سعيد الحربي']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/search?search=خالد')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($target->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_search_matches_by_phone_fragment(): void
    {
        $admin = $this->admin();
        $target = $this->member(['phone' => '+962795550001']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/search?search=795550001')
            ->assertOk();

        $this->assertContains($target->id, array_column($response->json('data'), 'id'));
    }

    public function test_search_reports_an_empty_status_when_nothing_matches(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users/search?search=لاأحديطابقهذا')
            ->assertOk()
            ->assertJsonPath('status', 'empty');
    }

    public function test_search_excludes_admins(): void
    {
        $admin = $this->admin(['name' => 'مشرف النظام']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/search?search=مشرف')
            ->assertOk()
            ->assertJsonPath('status', 'empty');
    }

    public function test_search_returns_a_paginated_envelope(): void
    {
        $admin = $this->admin();
        $this->member(['name' => 'سعيد الحربي']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/search?search=سعيد')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => ['*' => $this->userResourceKeys()],
                'pagination' => ['current_page', 'last_page', 'total'],
            ]);
    }

    /**
     * @dataProvider hostileSearchTerms
     */
    public function test_search_survives_boolean_mode_operators(string $term): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users/search?search='.urlencode($term))
            ->assertOk();
    }

    public static function hostileSearchTerms(): array
    {
        return [
            'double quote' => ['"'],
            'at sign' => ['@'],
            'dangling operators' => ['+ali -'],
            'bare wildcard' => ['*'],
            'unbalanced parens' => ['((('],
            'tilde' => ['~'],
            'angle brackets' => ['<>'],
        ];
    }

    public function test_search_includes_blocked_users(): void
    {
        $admin = $this->admin();
        $blocked = $this->member(['name' => 'زياد المحظور']);
        $blocked->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/search?search=زياد')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertContains($blocked->id, array_column($response->json('data'), 'id'));
    }

    public function test_search_without_a_keyword_lists_every_non_admin(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/search')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($member->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    public function test_search_rejects_an_oversized_keyword(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users/search?search='.str_repeat('a', 256))
            ->assertStatus(422);
    }

    public function test_search_is_forbidden_for_members(): void
    {
        $this->actingAs($this->member(), 'sanctum')
            ->getJson('/api/admin/users/search?search=x')
            ->assertForbidden();
    }
}
