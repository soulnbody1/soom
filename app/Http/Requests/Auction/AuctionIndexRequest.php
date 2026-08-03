<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AuctionIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_column(AuctionStatus::cases(), 'value'))],
            'phase' => ['nullable', Rule::in(['live', 'upcoming', 'finished'])],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sort' => ['nullable', Rule::in(['latest', 'starting_soon', 'ending_soon', 'price_asc', 'price_desc'])],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'search' => $this->input('search'),
            'status' => $this->input('status'),
            'phase' => $this->input('phase'),
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'currency' => $this->input('currency'),
            'sort' => $this->input('sort'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 20)));
    }
}
