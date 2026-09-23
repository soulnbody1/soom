<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Auction\Rules\CurrencyDecimalRule;
use App\Services\Catalog\AdAttributeValidator;
use App\Support\Market\MarketContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreAdRequest extends FormRequest
{
    private const MAX_IMAGES = 30;

    private const MAX_TOTAL_IMAGE_BYTES = 104857600;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'price' => [
                'required',
                'numeric',
                'min:0',
                'max:9999999999.999',
                CurrencyDecimalRule::forCurrency((string) app(MarketContext::class)->market()->currency_code),
            ],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('market_category', 'category_id')
                    ->where('market_id', app(MarketContext::class)->marketId())
                    ->where('is_visible', true),
            ],
            'country_id' => ['required', 'integer', Rule::in([app(MarketContext::class)->market()->country_id])],
            'state_id' => [
                'required',
                'integer',
                Rule::exists('states', 'id')->where('country_id', $this->input('country_id')),
            ],
            'city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id')->where('state_id', $this->input('state_id')),
            ],
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'attributes' => 'nullable|array',
            'attributes.*.id' => 'required|integer|exists:attributes,id',
            'attributes.*.value' => 'required',
            'attributes.*.value.*' => 'string|max:255',
            'images' => 'nullable|array|max:'.self::MAX_IMAGES,
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'reel_video' => 'nullable|file|mimes:mp4,mov,avi,webm|max:30000',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('attributes') || ! is_string($this->input('attributes'))) {
            return;
        }

        $decoded = json_decode((string) $this->input('attributes'), true);

        $this->merge(['attributes' => is_array($decoded) ? $decoded : []]);
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function wantsJson()
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'state_id.exists' => '⚠️ المحافظة المختارة لا تتبع الدولة المحددة.',
            'city_id.exists' => '⚠️ المدينة المختارة لا تتبع المحافظة المحددة.',
            'reel_video.max' => '⚠️ لا يمكن رفع فيديو يتجاوز حجمه 30 ميغابايت.',
            'reel_video.mimes' => '⚠️ صيغة الفيديو غير مدعومة. الصيغ المقبولة: mp4, mov, avi, webm.',
            'images.*.max' => '⚠️ حجم الصورة يجب ألا يتجاوز 4 ميغابايت.',
            'images.*.mimes' => '⚠️ الصيغ المسموح بها للصور: jpeg, png, jpg, gif, webp.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator): void {
            $this->validateCategoryAttributes($validator);

            $images = $this->file('images');

            if (! is_array($images) || $images === []) {
                $validator->errors()->add('images', 'يجب رفع 1 صور على الأقل للإعلان.');

                return;
            }

            $totalSize = array_sum(array_map(static fn ($image): int => (int) $image->getSize(), $images));

            if ($totalSize > self::MAX_TOTAL_IMAGE_BYTES) {
                $validator->errors()->add('images', 'إجمالي حجم الصور يجب ألا يتجاوز 100 ميجا بايت.');
            }
        });
    }

    private function validateCategoryAttributes($validator): void
    {
        if ($validator->errors()->has('category_id') || $validator->errors()->hasAny(['attributes'])) {
            return;
        }

        $categoryId = $this->input('category_id');
        $submitted = $this->input('attributes');

        if (! is_numeric($categoryId) || ! is_array($submitted)) {
            return;
        }

        $errors = app(AdAttributeValidator::class)->validate((int) $categoryId, $submitted);

        foreach ($errors->getMessages() as $key => $messages) {
            foreach ($messages as $message) {
                $validator->errors()->add($key, $message);
            }
        }
    }
}
