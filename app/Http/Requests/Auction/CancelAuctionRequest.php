<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

#[QueryParameter('reason', description: 'سبب الإلغاء، ويُستخدم بديلًا عن reason_text للتوافق مع الإصدارات السابقة.')]
#[QueryParameter('reason_text', description: 'نص سبب الإلغاء، ويجب إرسال أحد الحقلين reason_text أو reason.')]
#[QueryParameter('reason_code', description: 'تصنيف سبب الإلغاء، وهو مطلوب عند تنفيذ الإلغاء من مشرف لأنه يحدّد مصير التأمينات.')]
#[QueryParameter('liability', description: 'الجهة التي تتحمل مسؤولية الإلغاء، وهي مطلوبة عند تنفيذ الإلغاء من مشرف.')]
final class CancelAuctionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
            'reason_text' => ['nullable', 'string', 'max:2000'],
            'reason_code' => ['nullable', 'string', Rule::in([
                'platform_fault',
                'seller_breach',
                'fraud',
                'compliance',
                'buyer_fault',
                'neutral',
            ])],
            'liability' => ['nullable', 'string', Rule::in([
                'platform',
                'seller',
                'buyer',
                'manual_review',
                'neutral',
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->reasonText() === '') {
                $validator->errors()->add('reason_text', __('auction.errors.cancellation_reason_required'));
            }

            if ($this->user()?->role !== 'admin') {
                return;
            }

            if (! $this->filled('reason_code')) {
                $validator->errors()->add('reason_code', __('validation.required', ['attribute' => 'reason_code']));
            }

            if (! $this->filled('liability')) {
                $validator->errors()->add('liability', __('validation.required', ['attribute' => 'liability']));
            }
        });
    }

    public function reasonText(): string
    {
        return trim((string) ($this->input('reason_text') ?? $this->input('reason', '')));
    }

    public function reasonCode(): ?string
    {
        return $this->filled('reason_code') ? (string) $this->input('reason_code') : null;
    }

    public function liability(): ?string
    {
        return $this->filled('liability') ? (string) $this->input('liability') : null;
    }
}
