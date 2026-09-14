<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MyAdResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ],
            'location' => [
                'LocationName' => implode(', ', array_filter([
                    $this->country?->name,
                    $this->state?->name,
                    $this->city?->name,
                ])),
                'country' => $this->country?->id,
                'state' => $this->state?->id,
                'city' => $this->city?->id,
            ],
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'attributes' => $this->attributeValues
                ->groupBy('attribute.name')
                ->map(function ($items, $attributeName) {
                    $values = $items->pluck('value');

                    return [
                        'id' => $items->first()->attribute_id,
                        'attribute' => $attributeName,
                        'value' => $values->count() === 1 ? $values->first() : $values->values(),
                    ];
                })
                ->values(),
            'user' => [
                'name' => $this->user?->name,
                'phone' => $this->user?->phone,
                'logo' => $this->user?->logo,
            ],
            'images' => $this->images->pluck('image_path'),
            'created_at' => $this->created_at?->toDateTimeString(),
            'views_count' => $this->views_count ?? 0,
            'status' => $this->deleted_at ? false : true,
        ];
    }
}
