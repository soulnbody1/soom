<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('payment_method_id', description: 'المعرّف العام لطريقة الدفع المستخدمة في التحويل (ULID).')]
#[BodyParameter('receipt', description: 'ملف إيصال التحويل بصيغة صورة أو PDF بحد أقصى خمسة ميغابايت.')]
#[BodyParameter('idempotency_key', description: 'مفتاح منع التكرار الذي يولّده العميل. إعادة الإرسال بالمفتاح نفسه تُرجع الإثبات الأصلي دون إنشاء إثبات جديد.')]
#[BodyParameter('provider_reference', description: 'الرقم المرجعي للتحويل لدى جهة الدفع.')]
final class PaymentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string', 'size:26', 'exists:payment_methods,public_id'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:5120'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'provider_reference' => ['nullable', 'string', 'max:160'],
        ];
    }
}
