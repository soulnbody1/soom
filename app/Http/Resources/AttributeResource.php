<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttributeResource extends JsonResource
{
    public function toArray($request)
    {
        $options = $this->options
            ->map(fn ($option): array => (new AttributeOptionResource($option))->toArray($request))
            ->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_required' => (bool) $this->is_required,
            'is_multiple' => (bool) $this->is_multiple,
            'parent_attribute_id' => $this->parent_attribute_id,
            'options' => $this->type === 'between'
                ? $this->getBetweenValues($options)
                : $options,
            'categories' => $this->whenLoaded('categories', function () {
                return $this->categories->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'is_inheritable' => (bool) $category->pivot->is_inheritable,
                    ];
                });
            }),
        ];
    }

    protected function getBetweenValues($options)
    {
        $grouped = [];

        foreach ($options as $option) {
            $parentId = isset($option['parent_option_id']) ? $option['parent_option_id'] : null;

            $grouped[] = [
                'parent_option_id' => $parentId,
                'value' => (int) $option['value'],
            ];
        }
        $groupedByParent = [];
        foreach ($grouped as $item) {
            $parentId = $item['parent_option_id'];
            $groupedByParent[$parentId][] = $item['value'];
        }
        $result = [];
        foreach ($groupedByParent as $parentId => $values) {
            sort($values);
            $start = $values[0] ?? null;
            $end = $values[1] ?? null;

            $limit = (int) config('catalog.between_range_limit');

            if (! is_null($start) && ! is_null($end) && $start <= $end && ($end - $start) < $limit) {
                $range = range($start, $end);

                $result[] = [
                    'parent_option_id' => $parentId === '' ? null : $parentId,
                    'values' => $range,
                ];
            }
        }

        return $result;
    }
}
