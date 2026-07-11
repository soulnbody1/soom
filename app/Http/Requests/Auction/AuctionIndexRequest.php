<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

final class AuctionIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ];
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 20)));
    }
}
