<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FailSellerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                SellerPayoutStatus::Failed->value,
                SellerPayoutStatus::ManualReview->value,
            ])],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
