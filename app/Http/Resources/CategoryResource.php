<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request)
    {
        $data = [
            'id'        => $this->id,
            'name'      => $this->name,
            'image'     => $this->image,
            'parent_id' => $this->parent_id,
        ];

        if ($this->relationLoaded('children')) {
            $data['children'] = CategoryResource::collection($this->children);
        }
        return $data;
    }
}
