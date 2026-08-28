<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[BodyParameter('resolution', description: 'قرار حسم النزاع: complete لإكمال التسوية، أو resume_handover لاستئناف التسليم، أو cancel لإلغاء المزاد.')]
#[BodyParameter('note', description: 'ملاحظة المشرف التي توثّق مبررات القرار.')]
#[BodyParameter('seller_deposit_disposition', description: 'مصير تأمين البائع بعد الحسم: الاسترداد أو المصادرة الكاملة أو الجزئية أو إبقاؤه محجوزًا أو إحالته للمراجعة اليدوية أو عدم اتخاذ إجراء.')]
#[BodyParameter('seller_deposit_forfeit_amount_minor', description: 'المبلغ المُصادَر من تأمين البائع بالوحدة الصغرى للعملة، ويُستخدم مع المصادرة الجزئية.')]
final class ResolveAuctionDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(['complete', 'resume_handover', 'cancel'])],
            'note' => ['required', 'string', 'max:1000'],
            'seller_deposit_disposition' => ['nullable', 'string', Rule::in(['refund', 'forfeit', 'partial_forfeit', 'keep_held', 'manual_review', 'no_action'])],
            'seller_deposit_forfeit_amount_minor' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
