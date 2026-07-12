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
            'seller_deposit_disposition' => ['nullable', 'string', Rule::in(['refund', 'forfeit', 'partial_forfeit', 'keep_held', 'manual_review', 'no_action'])],
            'seller_deposit_forfeit_amount_minor' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
