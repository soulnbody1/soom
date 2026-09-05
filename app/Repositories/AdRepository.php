<?php

namespace App\Repositories;

use App\Models\Ad;
use Illuminate\Support\Facades\Storage;

class AdRepository
{
    public function create(array $data): Ad
    {
        return Ad::create($data);
    }

    public function attachAttributes(Ad $ad, array $attributes): void
    {
        foreach ($attributes as $attr) {
            $values = is_array($attr['value']) ? $attr['value'] : [$attr['value']];

            foreach ($values as $value) {
                $ad->attributeValues()->create([
                    'attribute_id' => $attr['id'],
                    'value' => $value,
                ]);
            }
        }
    }

    public function attachImages(Ad $ad, array $images): void
    {
        foreach ($images as $image) {
            $ad->images()->create(['image_path' => $image->store('ads', 'spaces')]);
        }
    }

    public function update(Ad $ad, array $data): void
    {
        $ad->update($data);
    }

    public function syncAttributes(Ad $ad, array $attributes): void
    {
        $ad->attributeValues()->delete();
        $this->attachAttributes($ad, $attributes);
    }

    public function replaceImages(Ad $ad, array $images): void
    {
        if ($images === []) {
            return;
        }

        foreach ($ad->images as $image) {
            Storage::disk('spaces')->delete(ltrim($image->getRawOriginal('image_path'), '/'));
            $image->delete();
        }

        $this->attachImages($ad, $images);
    }
}
