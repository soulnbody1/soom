<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

final class SupportConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'after_message_id' => ['nullable', 'string', 'size:26', 'ulid'],
            'known_version' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', 50);
    }
}
