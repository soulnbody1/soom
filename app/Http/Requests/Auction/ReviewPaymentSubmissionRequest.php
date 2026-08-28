<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('action', description: 'قرار المراجعة: approve لاعتماد إثبات الدفع أو reject لرفضه.')]
#[BodyParameter('note', description: 'ملاحظة المراجعة، وهي مطلوبة عند الرفض.')]
#[BodyParameter('provider_transaction_id', description: 'رقم العملية لدى جهة الدفع، وهو مطلوب عند الاعتماد.')]
#[BodyParameter('override_deadline', description: 'اعتماد الدفعة رغم انقضاء مهلتها، ويتطلب صلاحية إضافية.')]
#[BodyParameter('override_reason', description: 'مبرر تجاوز المهلة، وهو مطلوب عند تفعيل override_deadline.')]
final class ReviewPaymentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'required_if:action,reject', 'string', 'max:1000'],
            'provider_transaction_id' => ['nullable', 'required_if:action,approve', 'string', 'max:160'],
            'override_deadline' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'required_if:override_deadline,true', 'string', 'max:1000'],
        ];
    }
}
