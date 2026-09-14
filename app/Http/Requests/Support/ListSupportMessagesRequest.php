<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

final class ListSupportMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['before_id' => ['nullable', 'string', 'size:26', 'ulid'], 'per_page' => ['nullable', 'integer', 'between:1,100']];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
