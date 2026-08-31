<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('terms_version_id', description: 'المعرّف العام لنسخة الشروط المعروضة على المستخدم (ULID)، ويجب أن تطابق النسخة المثبّتة على المزاد.')]
final class AcceptTermsActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'terms_version_id' => ['required', 'string', 'size:26'],
        ];
    }

    public function termsVersionId(): string
    {
        return (string) $this->input('terms_version_id');
    }
}
