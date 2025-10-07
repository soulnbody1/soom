<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'category' => $this->category?->name,
            'location' => implode(', ', array_filter([
                $this->country?->name,
                $this->state?->name,
                $this->city?->name,
            ])),
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'attributes'=> $this->attributeValues
                ->groupBy('attribute.name')
                ->map(function ($items, $attributeName) {
                    $values = $items->pluck('value');
                    return [
                        'attribute' => $attributeName,
                        'value'     => $values->count() === 1 ? $values->first() : $values->values()
                    ];
                })
                ->values(),
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'phone' => $this->user?->phone,
                'logo' => $this->user?->logo,
            ],
            'images' => $this->images->pluck('image_path'),
            'created_at' => $this->created_at?->toDateTimeString(),
            'views_count' => $this->views_count ?? 0,
            'is_favorite' => $this->is_favorite ?? false,
            'status' => $this->deleted_at ? false : true,
        ];
    }
}
