<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('reason', description: 'سبب فتح النزاع كما يكتبه صاحب الطلب.')]
final class OpenAuctionDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
