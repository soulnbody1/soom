<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Models\Auction\PaymentMethod;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[BodyParameter('recipient_name', description: 'اسم المستفيد كما هو مسجّل لدى جهة التحويل.')]
#[BodyParameter('identifier_type', description: 'نوع معرّف التحويل، مثل رقم الحساب أو الآيبان أو رقم المحفظة.')]
#[BodyParameter('identifier_value', description: 'قيمة معرّف التحويل المطابقة للنوع المختار.')]
#[BodyParameter('is_default', description: 'اعتماد هذه الوجهة وجهة التحويل الافتراضية للبائع.')]
final class PayoutDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:120'],
            'identifier_type' => ['required', 'string', Rule::in(PaymentMethod::IDENTIFIER_TYPES)],
            'identifier_value' => ['required', 'string', 'max:160'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
