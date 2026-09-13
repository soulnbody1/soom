<?php

declare(strict_types=1);

namespace App\Http\Resources\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupportCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => app()->getLocale() === 'en' ? $this->name_en : $this->name_ar,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'default_priority' => $this->default_priority->value,
        ];
    }
}
