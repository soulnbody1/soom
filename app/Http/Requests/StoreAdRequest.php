<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreAdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric',
            'category_id' => 'required|exists:categories,id',
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'nullable|exists:cities,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'attributes' => 'array',
            'attributes.*.id' => 'required|exists:attributes,id',
            'attributes.*.value' => 'required',
            'images' => 'nullable|array|max:30',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:4096',
            'reel_video' => 'nullable|file|mimes:mp4,mov,avi,webm|max:30000',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('attributes') && is_string($this->input('attributes'))) {
            $this->merge([
                'attributes' => json_decode($this->input('attributes'), true)
            ]);
        }
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors()
        ], 422));
    }

    public function wantsJson()
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'reel_video.max' => '⚠️ لا يمكن رفع فيديو يتجاوز حجمه 30 ميغابايت.',
            'reel_video.mimes' => '⚠️ صيغة الفيديو غير مدعومة. الصيغ المقبولة: mp4, mov, avi, webm.',
            'images.*.max' => '⚠️ حجم الصورة يجب ألا يتجاوز 4 ميغابايت.',
            'images.*.mimes' => '⚠️ الصيغ المسموح بها للصور: jpeg, png, jpg, gif, svg.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $totalSize = 0;
            $images = $this->file('images');
            if (!is_array($images) || count($images) < 1) {
                $validator->errors()->add('images', 'يجب رفع 1 صور على الأقل للإعلان.');
            }
            if ($images) {
                foreach ($images as $image) {
                    $totalSize += $image->getSize();
                }

                if ($totalSize > 100 * 1024 * 1024) {
                    $validator->errors()->add('images', 'إجمالي حجم الصور يجب ألا يتجاوز 100 ميجا بايت.');
                }
            }
        });
    }
}
