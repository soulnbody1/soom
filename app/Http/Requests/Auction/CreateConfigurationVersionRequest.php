<?php

declare(strict_types=1);

namespace App\Http\Requests\Auction;

use App\Domain\Auction\Enums\NonWinnerDepositHoldPolicy;
use App\Domain\Auction\Enums\SellerDepositDisposition;
use App\Domain\Auction\Enums\WinnerDefaultDepositDisposition;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

#[BodyParameter('configuration', description: 'محتوى إصدار إعدادات المزادات كاملًا.')]
#[BodyParameter('configuration.seller_deposit_minor', description: 'قيمة تأمين البائع بالوحدة الصغرى للعملة.')]
#[BodyParameter('configuration.bidder_deposit_minor', description: 'قيمة تأمين المزايد بالوحدة الصغرى للعملة.')]
#[BodyParameter('configuration.platform_fee_type', description: 'طريقة احتساب عمولة المنصة: نسبة مئوية أو مبلغ ثابت.')]
#[BodyParameter('configuration.platform_fee_basis_points', description: 'نسبة عمولة المنصة بنقاط الأساس، حيث 10000 تساوي مئة بالمئة.')]
#[BodyParameter('configuration.platform_fee_fixed_minor', description: 'قيمة عمولة المنصة الثابتة بالوحدة الصغرى للعملة.')]
#[BodyParameter('configuration.minimum_bid_increment_minor', description: 'الحد الأدنى للزيادة بين مزايدة وأخرى بالوحدة الصغرى للعملة.')]
#[BodyParameter('configuration.extension_window_seconds', description: 'المدة قبل انتهاء المزاد التي تؤدي فيها أي مزايدة إلى تمديده.')]
#[BodyParameter('configuration.extension_duration_seconds', description: 'مدة التمديد الواحد بالثواني.')]
#[BodyParameter('configuration.maximum_extension_count', description: 'أقصى عدد مرات يمكن فيها تمديد المزاد.')]
#[BodyParameter('configuration.winner_payment_deadline_hours', description: 'مهلة سداد الفائز بالساعات بعد انتهاء المزاد.')]
#[BodyParameter('configuration.handover_deadline_hours', description: 'مهلة تسليم المنتج بالساعات بعد اكتمال السداد.')]
#[BodyParameter('configuration.non_winner_deposit_policy', description: 'سياسة الإفراج عن تأمينات المزايدين غير الفائزين.')]
#[BodyParameter('configuration.non_winner_deposit_hold_count', description: 'عدد المزايدين الأعلى الذين تبقى تأميناتهم محجوزة، ويُستخدم فقط مع سياسة hold_top_n.')]
#[BodyParameter('configuration.alternative_winner_enabled', description: 'السماح بترحيل الفوز إلى المزايد التالي عند تخلّف الفائز.')]
#[BodyParameter('configuration.winner_default_deposit_policy', description: 'سياسة التعامل مع تأمين الفائز المتخلّف عن السداد.')]
#[BodyParameter('configuration.winner_default_deposit_policy.disposition', description: 'مصير تأمين الفائز المتخلّف.')]
#[BodyParameter('configuration.winner_default_deposit_policy.forfeit_amount_minor', description: 'المبلغ المُصادَر من تأمين الفائز المتخلّف بالوحدة الصغرى للعملة.')]
#[BodyParameter('configuration.seller_deposit_policy', description: 'خريطة تحدّد مصير تأمين البائع لكل حالة من حالات إنهاء المزاد، ويجب أن تغطي جميع الحالات المعرّفة في النظام.')]
#[BodyParameter('publish', description: 'نشر الإصدار فور إنشائه واعتماده الإصدار الساري، والقيمة الافتراضية true.')]
final class CreateConfigurationVersionRequest extends FormRequest
{
    /**
     * The exact seller-deposit policy triggers a configuration version must define
     * (canonical set from database/seeders/AuctionConfigurationSeeder.php).
     */
    public const SELLER_DEPOSIT_POLICY_KEYS = [
        'unsold',
        'completed',
        'seller_cancellation_before_start',
        'seller_cancellation_after_start',
        'admin_cancellation_platform_fault',
        'admin_cancellation_seller_fault',
        'admin_cancellation_neutral',
        'admin_cancellation_fraud_or_compliance',
        'system_cancellation_platform_fault',
        'system_cancellation_seller_fault',
        'system_cancellation_neutral',
        'winner_default',
        'seller_breach',
        'dispute_complete',
        'dispute_cancel',
        'dispute_resume_handover',
    ];

    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $sellerDispositions = array_column(SellerDepositDisposition::cases(), 'value');
        $winnerDispositions = array_column(WinnerDefaultDepositDisposition::cases(), 'value');

        return [
            'configuration' => ['required', 'array'],
            'configuration.seller_deposit_minor' => ['required', 'integer', 'min:0'],
            'configuration.bidder_deposit_minor' => ['required', 'integer', 'min:0'],
            'configuration.platform_fee_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'configuration.platform_fee_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'configuration.platform_fee_fixed_minor' => ['required', 'integer', 'min:0'],
            'configuration.minimum_bid_increment_minor' => ['required', 'integer', 'min:1'],
            'configuration.extension_window_seconds' => ['required', 'integer', 'min:0'],
            'configuration.extension_duration_seconds' => ['required', 'integer', 'min:0'],
            'configuration.maximum_extension_count' => ['required', 'integer', 'min:0'],
            'configuration.winner_payment_deadline_hours' => ['required', 'integer', 'min:1'],
            'configuration.handover_deadline_hours' => ['required', 'integer', 'min:1'],
            'configuration.non_winner_deposit_policy' => ['required', 'string', Rule::in(array_column(NonWinnerDepositHoldPolicy::cases(), 'value'))],
            'configuration.non_winner_deposit_hold_count' => [
                'exclude_unless:configuration.non_winner_deposit_policy,'.NonWinnerDepositHoldPolicy::HoldTopN->value,
                'required',
                'integer',
                'min:1',
            ],
            'configuration.alternative_winner_enabled' => ['required', 'boolean'],
            'configuration.winner_default_deposit_policy' => ['required', 'array'],
            'configuration.winner_default_deposit_policy.disposition' => ['required', Rule::in($winnerDispositions)],
            'configuration.winner_default_deposit_policy.forfeit_amount_minor' => ['required', 'integer', 'min:0'],
            'configuration.seller_deposit_policy' => ['required', 'array'],
            'configuration.seller_deposit_policy.*' => ['string', Rule::in($sellerDispositions)],
            'publish' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $policy = $this->input('configuration.seller_deposit_policy');

            if (! is_array($policy)) {
                return;
            }

            foreach (self::SELLER_DEPOSIT_POLICY_KEYS as $key) {
                if (! array_key_exists($key, $policy)) {
                    $validator->errors()->add(
                        "configuration.seller_deposit_policy.{$key}",
                        __('validation.required', ['attribute' => "seller_deposit_policy.{$key}"])
                    );
                }
            }
        });
    }
}
