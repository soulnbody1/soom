<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreAuctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // التأكد من وجود Token (Bearer) مش Session بس
        return request()->bearerToken() !== null && auth('sanctum')->check();
    }
    
    protected function failedAuthorization()
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
            'error' => 'يجب تسجيل الدخول أولاً.'
        ], 401));
    }

    public function rules(): array
    {
        return [
            // بيانات الإعلان
            'title' => 'required|string|max:255',
            'description' => 'required|string|min:50',
            'category_id' => 'required|exists:categories,id',
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'nullable|exists:cities,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            
            // صور الإعلان
            'images' => 'required|array|min:1|max:30',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:4096',
            
            // فيديو (اختياري)
            'reel_video' => 'nullable|file|mimes:mp4,mov,avi,webm|max:30000',
            
            // بيانات المزاد
            'starting_price' => 'required|numeric|min:1|max:999999999.99',
            'min_accept_price' => 'nullable|numeric|min:0|max:999999999.99',
            
            // مدة وتوقيت المزاد
            'starts_at' => 'nullable|date',
            'ends_at' => 'required|date',
            'duration_days' => 'required|integer|min:1|max:30',
            'terms_accepted' => 'required|accepted',
            
            // السمات (اختياري)
            'attributes' => 'nullable|array',
            'attributes.*.id' => 'required_with:attributes|exists:attributes,id',
            'attributes.*.value' => 'required_with:attributes',
        ];
    }

    protected function prepareForValidation(): void
    {
        // تحويل attributes من JSON لو جاي كـ string
        if ($this->has('attributes') && is_string($this->input('attributes'))) {
            $this->merge([
                'attributes' => json_decode($this->input('attributes'), true)
            ]);
        }

        // تحويل terms_accepted من string "true"/"false" لـ boolean
        if ($this->has('terms_accepted')) {
            $termsValue = $this->input('terms_accepted');
            $termsBool = filter_var($termsValue, FILTER_VALIDATE_BOOLEAN);
            $this->merge([
                'terms_accepted' => $termsBool ? 1 : 0
            ]);
        }

        // حساب تاريخ البداية والنهاية تلقائياً
        $startsAt = $this->has('starts_at') ? $this->input('starts_at') : now();
        $durationDays = (int) $this->input('duration_days', 1);
        
        $endsAt = \Carbon\Carbon::parse($startsAt)->addDays($durationDays);
        
        $this->merge([
            'starts_at' => is_string($startsAt) ? $startsAt : now()->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s')
        ]);
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
            'title.required' => '⚠️ عنوان الإعلان مطلوب.',
            'title.max' => '⚠️ عنوان الإعلان لا يمكن أن يتجاوز 255 حرف.',
            'description.required' => '⚠️ وصف الإعلان مطلوب.',
            'description.min' => '⚠️ وصف الإعلان يجب أن يكون 50 حرف على الأقل.',
            'category_id.required' => '⚠️ يجب اختيار القسم.',
            'category_id.exists' => '⚠️ القسم المختار غير موجود.',
            'starting_price.required' => '⚠️ سعر البداية مطلوب.',
            'starting_price.min' => '⚠️ سعر البداية يجب أن يكون أكبر من صفر.',
            'deposit_type.required' => '⚠️ نوع التأمين مطلوب.',
            'deposit_type.in' => '⚠️ نوع التأمين يجب أن يكون "ثابت" أو "نسبة مئوية".',
            'deposit_fixed_amount.required_if' => '⚠️ مبلغ التأمين مطلوب عند اختيار نوع "ثابت".',
            'deposit_percentage.required_if' => '⚠️ نسبة التأمين مطلوبة عند اختيار نوع "نسبة مئوية".',
            'duration_days.required' => '⚠️ مدة المزاد مطلوبة.',
            'duration_days.min' => '⚠️ مدة المزاد يجب أن تكون يوم واحد على الأقل.',
            'duration_days.max' => '⚠️ مدة المزاد لا يمكن أن تتجاوز 30 يوم.',
            'images.required' => '⚠️ يجب رفع صورة واحدة على الأقل.',
            'images.min' => '⚠️ يجب رفع صورة واحدة على الأقل.',
            'images.max' => '⚠️ لا يمكن رفع أكثر من 30 صورة.',
            'images.*.max' => '⚠️ حجم الصورة يجب ألا يتجاوز 4 ميجا بايت.',
            'reel_video.max' => '⚠️ لا يمكن رفع فيديو يتجاوز حجمه 30 ميغابايت.',
            'reel_video.mimes' => '⚠️ صيغة الفيديو غير مدعومة. الصيغ المقبولة: mp4, mov, avi, webm.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // التحقق من إجمالي حجم الصور
            $totalSize = 0;
            $images = $this->file('images');
            
            if ($images) {
                foreach ($images as $image) {
                    $totalSize += $image->getSize();
                }

                if ($totalSize > 100 * 1024 * 1024) { // 100 MB
                    $validator->errors()->add('images', '⚠️ إجمالي حجم الصور يجب ألا يتجاوز 100 ميجا بايت.');
                }
            }

            // التحقق من min_accept_price ألا يكون أكبر من starting_price
            $minAccept = $this->input('min_accept_price');
            $startingPrice = $this->input('starting_price');
            
            if ($minAccept !== null && $startingPrice !== null && $minAccept > $startingPrice) {
                $validator->errors()->add('min_accept_price', '⚠️ الحد الأدنى للموافقة لا يمكن أن يكون أكبر من سعر البداية.');
            }
        });
    }
}
