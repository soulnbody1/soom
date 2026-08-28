<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('reason', description: 'مبرر تسجيل تخلّف الفائز.')]
#[BodyParameter('reassign_to_next', description: 'ترحيل الفوز إلى المزايد التالي بدلًا من إنهاء المزاد دون بيع.')]
#[BodyParameter('override_deadline', description: 'تنفيذ الإجراء قبل انقضاء مهلة السداد المحددة.')]
#[BodyParameter('override_reason', description: 'مبرر تجاوز المهلة، وهو مطلوب عند تفعيل override_deadline.')]
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
            'override_deadline' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'required_if:override_deadline,true', 'string', 'max:1000'],
        ];
    }
}
