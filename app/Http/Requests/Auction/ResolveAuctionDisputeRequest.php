<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolveAuctionDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(['complete', 'resume_handover', 'cancel'])],
            'note' => ['required', 'string', 'max:1000'],
        ];
    }
}
