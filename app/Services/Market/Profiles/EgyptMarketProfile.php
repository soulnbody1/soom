<?php

declare(strict_types=1);

namespace App\Services\Market\Profiles;

use App\Contracts\Market\MarketProfile;

final class EgyptMarketProfile implements MarketProfile
{
    public function marketCode(): string
    {
        return 'EG';
    }

    public function categorySourceMarketCode(): string
    {
        return 'JO';
    }

    public function supportContactSourceMarketCode(): string
    {
        return 'JO';
    }

    public function auctionConfiguration(): array
    {
        return [
            'seller_deposit_minor' => 70000,
            'bidder_deposit_minor' => 35000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'minimum_bid_increment_minor' => 7000,
            'extension_window_seconds' => 300,
            'extension_duration_seconds' => 600,
            'maximum_extension_count' => 6,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'non_winner_deposit_policy' => 'hold_all_eligible_bidders_until_winner_payment',
            'non_winner_deposit_hold_count' => 1,
            'alternative_winner_enabled' => true,
            'winner_default_deposit_policy' => [
                'disposition' => 'full_forfeit',
                'forfeit_amount_minor' => 0,
            ],
            'seller_deposit_policy' => [
                'unsold' => 'refund',
                'completed' => 'refund',
                'seller_cancellation_before_start' => 'refund',
                'seller_cancellation_after_start' => 'manual_review',
                'admin_cancellation_platform_fault' => 'refund',
                'admin_cancellation_seller_fault' => 'forfeit',
                'admin_cancellation_neutral' => 'refund',
                'admin_cancellation_fraud_or_compliance' => 'manual_review',
                'system_cancellation_platform_fault' => 'refund',
                'system_cancellation_seller_fault' => 'forfeit',
                'system_cancellation_neutral' => 'refund',
                'winner_default' => 'keep_held',
                'seller_breach' => 'forfeit',
                'dispute_complete' => 'refund',
                'dispute_cancel' => 'manual_review',
                'dispute_resume_handover' => 'keep_held',
            ],
        ];
    }

    public function auctionTerms(): array
    {
        return [
            'title' => 'شروط وأحكام المزادات في جمهورية مصر العربية (مسودة بانتظار المراجعة القانونية)',
            'body' => <<<'TERMS'
هذه النسخة مسودة تشغيلية للسوق المصري وهي بانتظار مراجعة قانونية مصرية معتمدة. يجوز للإدارة تعديلها وإصدار نسخة جديدة في أي وقت دون التأثير على المزادات القائمة التي ارتبطت بنسخة سابقة.

١. نطاق التطبيق
تسري هذه الشروط على كل مزاد يُنشأ داخل السوق المصري. جميع المبالغ الواردة في المزاد مقوَّمة بالجنيه المصري، ولا يجوز خلط عملات أو أسواق داخل المزاد الواحد.

٢. تأمين البائع
يلتزم البائع بسداد تأمين قبل نشر المزاد. يُرد التأمين بالكامل عند إتمام البيع أو عند عدم بيع المعروض. يُصادر التأمين إذا أُلغي المزاد بسبب يرجع إلى البائع أو إذا أخلّ بالتسليم بعد رسو المزاد.

٣. تأمين المزايد
يلتزم كل مزايد بسداد تأمين قبل المشاركة. يُرد تأمين غير الفائزين بعد سداد الفائز، ويُحتجز تأمين المزايد التالي في الترتيب لحين اكتمال السداد. تُخصم قيمة تأمين الفائز من المبلغ المستحق.

٤. المزايدة
لا تُقبل مزايدة تقل عن الحد الأدنى للزيادة المعلن في المزاد. المزايدة المقدَّمة ملزمة ولا يجوز سحبها. إذا وردت مزايدة خلال نافذة الإغلاق يُمدَّد وقت المزاد تلقائيًا بالمدة المعلنة وبعدد مرات لا يتجاوز الحد المقرر.

٥. سداد الفائز
يلتزم الفائز بسداد كامل المبلغ المستحق خلال المهلة المعلنة في المزاد اعتبارًا من إخطاره. السداد في السوق المصري يتم حاليًا بالتحويل البنكي اليدوي، ولا يُعتد بالسداد إلا بعد مراجعة الإدارة واعتماده.

٦. عمولة المنصة
تستحق المنصة عمولة بالنسبة المعلنة في إعدادات المزاد وقت إنشائه، وتُحسب على قيمة الرسو. تُخصم العمولة من مستحقات البائع عند التسوية.

٧. التسليم
يلتزم الطرفان بإتمام التسليم خلال المهلة المعلنة بعد اكتمال السداد. يؤكد الفائز الاستلام عبر المنصة، ولا تُفرج المنصة عن مستحقات البائع قبل هذا التأكيد أو قبل صدور قرار إداري في حالة النزاع.

٨. الإخلال
إذا تخلّف الفائز عن السداد خلال المهلة، يجوز للمنصة مصادرة تأمينه وعرض المزاد على المزايد التالي في الترتيب أو إلغاء المزاد. وإذا تخلّف البائع عن التسليم، يجوز مصادرة تأمينه ورد كامل ما سدده الفائز.

٩. المنازعات
تُقيَّد المنازعة عبر المنصة خلال المدة المقررة. تفصل الإدارة في المنازعة استنادًا إلى المستندات والسجل المحفوظ، ويكون قرارها نافذًا على مستوى المنصة دون إخلال بحق أي طرف في اللجوء إلى الجهات القضائية المختصة في جمهورية مصر العربية.

١٠. البيانات والسجل
يُحفظ سجل المزايدات والمدفوعات والتسليم لدى المنصة، ويُعتد به دليلًا عند الفصل في أي منازعة.
TERMS,
        ];
    }

    public function paymentMethods(): array
    {
        return [
            [
                'code' => 'eg_manual_transfer',
                'name' => 'تحويل بنكي يدوي',
                'channel' => 'manual',
                'rail' => 'transfer',
                'requires_manual_review' => true,
                'is_active' => true,
                'display_order' => 1,
                'instructions' => 'حوِّل المبلغ المستحق بالجنيه المصري إلى الحساب البنكي المعلن، ثم ارفع صورة إيصال التحويل. تُراجع الإدارة الإيصال قبل اعتماد السداد.',
                'allowed_purposes' => null,
            ],
        ];
    }
}
