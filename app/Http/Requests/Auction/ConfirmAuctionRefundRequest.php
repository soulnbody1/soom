<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Models\Auction\PaymentMethod;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[BodyParameter('confirmation_reference', description: 'الرقم المرجعي للتحويل المنفَّذ خارج المنصة.')]
#[BodyParameter('reason', description: 'مبرر التأكيد اليدوي.')]
#[BodyParameter('recipient_name', description: 'اسم مستفيد بديل يتجاوز وجهة التحويل المحفوظة للعميل، ويجب إرساله مع نوع المعرّف وقيمته.')]
#[BodyParameter('identifier_type', description: 'نوع معرّف التحويل البديل، مثل رقم الحساب أو الآيبان أو رقم المحفظة.')]
#[BodyParameter('identifier_value', description: 'قيمة معرّف التحويل البديل المطابقة للنوع المختار.')]
#[BodyParameter('proof', description: 'ملف إثبات التحويل بصيغة صورة أو PDF بحد أقصى خمسة ميغابايت.')]
final class ConfirmAuctionRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'confirmation_reference' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:1000'],
            'recipient_name' => ['nullable', 'string', 'max:120', 'required_with:identifier_value'],
            'identifier_type' => ['nullable', 'string', Rule::in(PaymentMethod::IDENTIFIER_TYPES), 'required_with:identifier_value'],
            'identifier_value' => ['nullable', 'string', 'max:160'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
        ];
    }

    public function destinationOverride(): array
    {
        return [
            'recipient_name' => $this->validated('recipient_name'),
            'identifier_type' => $this->validated('identifier_type'),
            'identifier_value' => $this->validated('identifier_value'),
        ];
    }
}
