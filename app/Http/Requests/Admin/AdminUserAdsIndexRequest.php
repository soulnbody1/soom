<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[QueryParameter('per_page', description: 'عدد العناصر في الصفحة الواحدة، والقيمة الافتراضية 15.')]
#[QueryParameter('status', description: 'تصفية الإعلانات بحالتها: active للإعلانات الظاهرة، وblocked للإعلانات المحظورة.')]
#[QueryParameter('featured', description: 'تصفية الإعلانات المميّزة فقط أو غير المميّزة فقط.')]
#[QueryParameter('category_id', description: 'تصفية الإعلانات بمعرّف التصنيف.')]
#[QueryParameter('search', description: 'نص البحث في عنوان الإعلان.')]
final class AdminUserAdsIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(['active', 'blocked'])],
            'featured' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'featured' => $this->has('featured') ? $this->boolean('featured') : null,
            'category_id' => $this->validated('category_id'),
            'search' => $this->validated('search'),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
