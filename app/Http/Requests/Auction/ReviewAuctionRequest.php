<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
