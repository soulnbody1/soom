<?php

declare(strict_types=1);

namespace App\Http\Requests\Ad;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[QueryParameter('per_page', description: 'عدد الإعلانات في الصفحة الواحدة من 1 إلى 50، والقيمة الافتراضية 20.')]
#[QueryParameter('page', description: 'رقم الصفحة المطلوبة.')]
#[QueryParameter('sort', description: 'ترتيب النتائج: latest للأحدث، وprice_asc وprice_desc حسب السعر، وmost_viewed للأكثر مشاهدة.')]
#[QueryParameter('price_min', description: 'الحد الأدنى للسعر.')]
#[QueryParameter('price_max', description: 'الحد الأعلى للسعر.')]
#[QueryParameter('country_id', description: 'تصفية الإعلانات بمعرّف الدولة.')]
#[QueryParameter('state_id', description: 'تصفية الإعلانات بمعرّف المحافظة.')]
#[QueryParameter('city_id', description: 'تصفية الإعلانات بمعرّف المدينة.')]
#[QueryParameter('category_id', description: 'تصفية الإعلانات بمعرّف التصنيف، ويشمل تصنيفاته الفرعية.')]
#[QueryParameter('attributes', description: 'تصفية الإعلانات بالخصائص، بالشكل attributes[معرّف الخاصية]=قيمة أو قيم مفصولة بفاصلة.')]
#[QueryParameter('title', description: 'كلمة البحث في عنوان الإعلان ووصفه وأسماء المواقع، ويمكن دمجها مع باقي الفلاتر.')]
final class AdIndexRequest extends FormRequest
{
    public const SORTS = ['latest', 'price_asc', 'price_desc', 'most_viewed'];

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'country_id' => ['nullable', 'integer', 'min:1'],
            'state_id' => ['nullable', 'integer', 'min:1'],
            'city_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'attributes' => ['nullable', 'array'],
            'title' => ['nullable', 'string', 'max:80'],
        ];
    }
}
