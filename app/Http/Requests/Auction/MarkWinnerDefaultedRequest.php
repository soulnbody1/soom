<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Illuminate\Foundation\Http\FormRequest;

final class MarkWinnerDefaultedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'reassign_to_next' => ['sometimes', 'boolean'],
        ];
    }
}
