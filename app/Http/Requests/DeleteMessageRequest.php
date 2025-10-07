<?php
// app/Http/Requests/DeleteMessageRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'message_ids' => ['nullable', 'array'],
            'message_ids.*' => ['integer', 'exists:messages,id'],
        ];
    }
}
