<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['subject', 'message', 'context_type', 'context_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'string', 'size:26'],
            'subject' => ['required', 'string', 'max:'.config('support_chat.subject_max_length')],
            'message' => ['required', 'string', 'max:'.config('support_chat.message_max_length')],
            'client_message_id' => ['nullable', 'uuid'],
            'context_type' => ['nullable', 'string', 'in:auction,ad,payment,account'],
            'context_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
