<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[BodyParameter('payment_method_id', description: 'المعرّف العام لطريقة الدفع الإلكترونية المختارة (ULID).')]
#[BodyParameter('purpose', description: 'غرض الدفعة: bidder_deposit لتأمين المزايد، أو seller_deposit لتأمين البائع، أو winner_payment لسداد مستحقات الفائز.')]
final class CreatePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string', 'size:26', 'exists:payment_methods,public_id'],
            'purpose' => ['required', Rule::in(['bidder_deposit', 'seller_deposit', 'winner_payment'])],
        ];
    }
}
