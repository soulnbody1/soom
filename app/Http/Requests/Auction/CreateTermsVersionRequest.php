<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('title', description: 'عنوان نسخة الشروط.')]
#[BodyParameter('body', description: 'النص الكامل لشروط المزاد.')]
#[BodyParameter('publish', description: 'نشر النسخة فور إنشائها واعتمادها النسخة السارية، والقيمة الافتراضية true.')]
final class CreateTermsVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string'],
            'publish' => ['nullable', 'boolean'],
        ];
    }
}
