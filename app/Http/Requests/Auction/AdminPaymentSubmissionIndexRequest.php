<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminPaymentSubmissionIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(PaymentSubmissionStatus::cases(), 'value'))],
            'purpose' => ['nullable', Rule::in(array_column(PaymentPurpose::cases(), 'value'))],
            'auction_id' => ['nullable', 'string', 'max:40'],
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
            'purpose' => $this->validated('purpose'),
            'auction_id' => $this->validated('auction_id'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
