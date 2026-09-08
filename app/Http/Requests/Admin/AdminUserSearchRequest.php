<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class AdminUserSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function keyword(): ?string
    {
        $keyword = trim((string) $this->input('search', ''));

        return $keyword === '' ? null : $keyword;
    }
}
