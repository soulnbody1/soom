<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:32', 'max:512', 'regex:/^[A-Za-z0-9_:\-\.]+$/'],
            'platform' => ['sometimes', 'nullable', 'string', 'in:android,ios,web'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.regex' => 'رمز الجهاز غير صالح.',
            'token.min' => 'رمز الجهاز غير صالح.',
        ];
    }
}
