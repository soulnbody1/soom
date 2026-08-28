<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;

#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 15.')]
#[QueryParameter('page', description: 'رقم الصفحة المطلوبة.')]
final class AdminUserConversationsIndexRequest extends FormRequest
{
    use ResolvesAdminPerPage;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }
}
