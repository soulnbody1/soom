<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Ad;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

final class AdAttributeValidationTest extends CatalogTestCase
{
    use RefreshDatabase;

    private Country $country;

    private State $state;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->country = Country::factory()->create();
        $this->state = State::factory()->create(['country_id' => $this->country->id]);
        $this->city = City::factory()->create(['state_id' => $this->state->id]);
    }

    public function test_an_attribute_from_another_category_is_rejected(): void
    {
        $category = $this->category();
        $foreign = $this->attribute();
        $this->attachAttribute($foreign, $this->category());

        $this->createAd($category, [['id' => $foreign->id, 'value' => 'x']])
            ->assertStatus(422)
            ->assertJsonFragment(['attributes.0.id' => ['هذه الخاصية لا تتبع الفئة المختارة.']]);
    }

    public function test_an_inherited_attribute_is_accepted(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();
        $attribute = $this->attribute();
        $this->attachAttribute($attribute, $parent);

        $this->createAd($child, [['id' => $attribute->id, 'value' => 'x']])->assertCreated();
    }

    public function test_an_excluded_attribute_is_rejected(): void
    {
        [$parent, $child] = $this->categoryChain(2)->all();
        $attribute = $this->attribute();
        $this->attachAttribute($attribute, $parent);
        $this->excludeAttribute($attribute, $child);

        $this->createAd($child, [['id' => $attribute->id, 'value' => 'x']])->assertStatus(422);
    }

    public function test_a_value_outside_the_option_list_is_rejected(): void
    {
        $category = $this->category();
        $attribute = $this->attribute(['type' => 'select']);
        $this->optionsFor($attribute, ['red', 'blue']);
        $this->attachAttribute($attribute, $category);

        $this->createAd($category, [['id' => $attribute->id, 'value' => 'green']])->assertStatus(422);
        $this->createAd($category, [['id' => $attribute->id, 'value' => 'red']])->assertCreated();
    }

    public function test_a_missing_required_attribute_is_rejected(): void
    {
        $category = $this->category();
        $required = $this->attribute(['is_required' => true, 'name' => 'الحالة']);
        $this->attachAttribute($required, $category);

        $this->createAd($category, [])
            ->assertStatus(422)
            ->assertJsonFragment(['attributes' => ['الخاصية «الحالة» مطلوبة لهذه الفئة.']]);

        $this->createAd($category, [['id' => $required->id, 'value' => 'new']])->assertCreated();
    }

    public function test_a_single_valued_attribute_rejects_an_array(): void
    {
        $category = $this->category();
        $single = $this->attribute(['is_multiple' => false]);
        $multiple = $this->attribute(['is_multiple' => true]);
        $this->attachAttribute($single, $category);
        $this->attachAttribute($multiple, $category);

        $this->createAd($category, [['id' => $single->id, 'value' => ['a', 'b']]])->assertStatus(422);
        $this->createAd($category, [['id' => $multiple->id, 'value' => ['a', 'b']]])->assertCreated();
    }

    public function test_a_between_attribute_accepts_any_number_inside_its_bounds(): void
    {
        $category = $this->category();
        $attribute = $this->attribute(['type' => 'between']);
        $this->optionsFor($attribute, ['1970', '2026']);
        $this->attachAttribute($attribute, $category);

        $this->createAd($category, [['id' => $attribute->id, 'value' => '1999']])->assertCreated();
        $this->createAd($category, [['id' => $attribute->id, 'value' => '2030']])->assertStatus(422);
        $this->createAd($category, [['id' => $attribute->id, 'value' => 'abc']])->assertStatus(422);
    }

    public function test_a_number_attribute_rejects_a_non_numeric_value(): void
    {
        $category = $this->category();
        $attribute = $this->attribute(['type' => 'number']);
        $this->attachAttribute($attribute, $category);

        $this->createAd($category, [['id' => $attribute->id, 'value' => 'abc']])->assertStatus(422);
        $this->createAd($category, [['id' => $attribute->id, 'value' => '42']])->assertCreated();
    }

    public function test_an_update_is_validated_against_the_target_category(): void
    {
        $owner = User::factory()->create();
        $category = $this->category();
        $ad = Ad::factory()->create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
        ]);

        $foreign = $this->attribute();
        $this->attachAttribute($foreign, $this->category());

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/soom/ads/my/'.$ad->public_id, $this->payload($category, [
                ['id' => $foreign->id, 'value' => 'x'],
            ]))
            ->assertStatus(422);
    }

    private function createAd(Category $category, array $attributes)
    {
        return $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/soom/ads', $this->payload($category, $attributes));
    }

    private function payload(Category $category, array $attributes): array
    {
        return [
            'title' => 'A listing',
            'description' => 'Described',
            'price' => 100,
            'category_id' => $category->id,
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'attributes' => $attributes,
            'images' => [UploadedFile::fake()->image('item.jpg')],
        ];
    }
}
