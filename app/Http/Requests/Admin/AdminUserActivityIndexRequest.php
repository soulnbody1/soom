<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;

#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 20.')]
#[QueryParameter('event_type', description: 'تصفية الأحداث بنوع الحدث.')]
#[QueryParameter('auction_id', description: 'قصر الأحداث على مزاد واحد بمعرّفه العام (ULID).')]
final class AdminUserActivityIndexRequest extends FormRequest
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
            'event_type' => ['nullable', 'string', 'max:120'],
            'auction_id' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'event_type' => $this->validated('event_type'),
            'auction_id' => $this->validated('auction_id'),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
