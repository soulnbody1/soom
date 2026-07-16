<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminAuctionIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(AuctionStatus::cases(), 'value'))],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'seller_id' => ['nullable', 'integer', 'exists:users,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'starts_from' => ['nullable', 'date'],
            'starts_to' => ['nullable', 'date', 'after_or_equal:starts_from'],
            'ends_from' => ['nullable', 'date'],
            'ends_to' => ['nullable', 'date', 'after_or_equal:ends_from'],
            'sort' => ['nullable', Rule::in(['created_at', 'starts_at', 'ends_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 20)));
    }

    public function filters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'category_id' => $this->validated('category_id'),
            'seller_id' => $this->validated('seller_id'),
            'q' => $this->validated('q'),
            'starts_from' => $this->validated('starts_from'),
            'starts_to' => $this->validated('starts_to'),
            'ends_from' => $this->validated('ends_from'),
            'ends_to' => $this->validated('ends_to'),
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
