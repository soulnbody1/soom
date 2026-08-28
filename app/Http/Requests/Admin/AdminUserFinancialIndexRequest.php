<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;

#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 15.')]
#[QueryParameter('status', description: 'تصفية السجلات بحالتها.')]
#[QueryParameter('type', description: 'تصفية التأمينات بنوعها: seller أو bidder.')]
#[QueryParameter('purpose', description: 'تصفية إيصالات الدفع بالغرض منها.')]
final class AdminUserFinancialIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'string', 'max:20'],
            'purpose' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'type' => $this->validated('type'),
            'purpose' => $this->validated('purpose'),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
