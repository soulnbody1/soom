<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\DTO\Ad\AdWriteInputDTO;
use App\Models\Ad;
use App\Models\AdImage;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Services\Ad\Actions\CreateAdAction;
use App\Services\Ad\Actions\UpdateAdAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

final class AdWriteIntegrityTest extends AdTestCase
{
    use RefreshDatabase;

    private const MISSING_ATTRIBUTE_ID = 987654;

    public function test_a_failed_create_leaves_no_ad_and_no_orphaned_uploads(): void
    {
        Queue::fake();

        $input = AdWriteInputDTO::fromValidated([
            ...$this->baseFields('Doomed'),
            'attributes' => [['id' => self::MISSING_ATTRIBUTE_ID, 'value' => 'x']],
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ]);

        try {
            app(CreateAdAction::class)->execute($input, $this->adUser()->id);
            $this->fail('The action was expected to propagate the failure.');
        } catch (QueryException) {
        }

        $this->assertDatabaseCount('ads', 0);
        $this->assertDatabaseCount('ad_images', 0);
        $this->assertDatabaseCount('attribute_values', 0);
        $this->assertSame([], Storage::disk('spaces')->allFiles());
    }

    public function test_a_failed_update_keeps_the_existing_images_and_attributes(): void
    {
        Queue::fake();

        $owner = $this->adUser();
        $ad = $this->makeAd(['user_id' => $owner->id, 'title' => 'Original title']);
        $attribute = Attribute::factory()->create();

        AdImage::factory()->create(['ad_id' => $ad->id, 'image_path' => 'ads/original.jpg']);
        Storage::disk('spaces')->put('ads/original.jpg', 'original');
        AttributeValue::factory()->create([
            'ad_id' => $ad->id,
            'attribute_id' => $attribute->id,
            'value' => 'original',
        ]);

        $input = AdWriteInputDTO::fromValidated([
            ...$this->baseFields('Renamed', $ad->category_id),
            'attributes' => [['id' => self::MISSING_ATTRIBUTE_ID, 'value' => 'replacement']],
            'images' => [UploadedFile::fake()->image('new.jpg')],
        ]);

        try {
            app(UpdateAdAction::class)->execute($ad, $input);
            $this->fail('The action was expected to propagate the failure.');
        } catch (QueryException) {
        }

        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'title' => 'Original title']);
        $this->assertDatabaseHas('attribute_values', ['ad_id' => $ad->id, 'value' => 'original']);
        $this->assertDatabaseHas('ad_images', ['ad_id' => $ad->id]);
        $this->assertSame(['ads/original.jpg'], Storage::disk('spaces')->allFiles());
    }

    public function test_a_successful_update_removes_the_superseded_remote_images(): void
    {
        Queue::fake();

        $ad = $this->makeAd(['user_id' => $this->adUser()->id]);

        AdImage::factory()->create(['ad_id' => $ad->id, 'image_path' => 'ads/original.jpg']);
        Storage::disk('spaces')->put('ads/original.jpg', 'original');

        app(UpdateAdAction::class)->execute($ad, AdWriteInputDTO::fromValidated([
            ...$this->baseFields('Renamed', $ad->category_id),
            'images' => [UploadedFile::fake()->image('new.jpg')],
        ]));

        $this->assertFalse(Storage::disk('spaces')->exists('ads/original.jpg'));
        $this->assertCount(1, Storage::disk('spaces')->allFiles());
        $this->assertSame(1, $ad->images()->count());
    }

    public function test_attribute_values_are_written_in_one_statement(): void
    {
        Queue::fake();

        $attribute = Attribute::factory()->create();

        $input = AdWriteInputDTO::fromValidated([
            ...$this->baseFields('Batched'),
            'attributes' => [['id' => $attribute->id, 'value' => ['a', 'b', 'c', 'd']]],
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $ownerId = $this->adUser()->id;

        $this->countQueries(fn () => app(CreateAdAction::class)->execute($input, $ownerId));

        $inserts = array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => str_contains($sql, 'insert') && str_contains($sql, 'attribute_values')
        );

        $this->assertCount(1, $inserts);
        $this->assertSame(4, AttributeValue::count());
    }

    public function test_is_featured_cannot_be_mass_assigned(): void
    {
        $ad = Ad::create([
            ...$this->baseFields('Not featured'),
            'user_id' => $this->adUser()->id,
            'is_featured' => true,
        ]);

        $this->assertFalse((bool) $ad->fresh()->is_featured);
    }

    private function baseFields(string $title, ?int $categoryId = null): array
    {
        return [
            'title' => $title,
            'description' => 'Integrity fixture',
            'price' => 10,
            'category_id' => $categoryId ?? $this->category()->id,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
        ];
    }
}
