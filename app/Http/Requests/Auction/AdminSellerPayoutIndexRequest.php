<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminSellerPayoutIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($status) => $status->value, SellerPayoutStatus::cases()))],
            'auction_id' => ['nullable', 'string', 'max:40'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:160'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'auction_id' => $this->validated('auction_id'),
            'seller_id' => $this->validated('seller_id') !== null ? (int) $this->validated('seller_id') : null,
            'search' => $this->validated('search'),
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 20)));
    }
}
