<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('whatsapp', description: 'رقم واتساب الدعم بالصيغة الدولية، مثل ‎+962790000000. أرسل قيمة فارغة لإخفاء القناة.')]
#[BodyParameter('phone', description: 'رقم هاتف الدعم بالصيغة الدولية. أرسل قيمة فارغة لإخفاء القناة.')]
#[BodyParameter('email', description: 'البريد الإلكتروني للدعم. أرسل قيمة فارغة لإخفاء القناة.')]
#[BodyParameter('availability', description: 'أوقات عمل الدعم كما تظهر للمستخدم، مثل «من الأحد إلى الخميس، 9 صباحًا حتى 5 مساءً».')]
final class SaveSupportContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s-]{5,}$/'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s-]{5,}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'availability' => ['nullable', 'string', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp.regex' => __('auction.validation.support_contact_phone'),
            'phone.regex' => __('auction.validation.support_contact_phone'),
        ];
    }
}
