<?php

declare(strict_types=1);

namespace App\Http\Requests\SellerRating;

use Illuminate\Foundation\Http\FormRequest;

final class ListSellerRatingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('seller_ratings.pagination.maximum')],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('seller_ratings.pagination.default'));
    }
}
