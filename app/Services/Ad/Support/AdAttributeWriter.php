<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

use App\Models\Ad;
use App\Models\AttributeValue;

final class AdAttributeWriter
{
    public function replace(Ad $ad, array $attributes): void
    {
        $ad->attributeValues()->delete();
        $this->insert($ad, $attributes);
    }

    public function insert(Ad $ad, array $attributes): void
    {
        $now = now();
        $rows = [];

        foreach ($attributes as $attribute) {
            foreach ((array) $attribute['value'] as $value) {
                $rows[] = [
                    'ad_id' => $ad->id,
                    'attribute_id' => $attribute['id'],
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            AttributeValue::insert($rows);
        }
    }
}
