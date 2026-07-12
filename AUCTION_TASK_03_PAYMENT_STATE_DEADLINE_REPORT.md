# AUCTION TASK 03 - Payment State, Deadline, Ownership Report

## 1. Flow قبل الإصلاح

### Seller Deposit
- `SubmitPaymentSubmissionAction` كان يتحقق من أن المستخدم هو البائع فقط، ثم ينشئ/يقفل `AuctionDeposit`.
- لم يكن هناك تحقق مركزي من أن حالة المزاد هي `awaiting_seller_deposit`.
- الاعتماد كان يفحص بعض خصائص الـdeposit لكنه لم يطابق المبلغ exact، ولم يعيد ترتيب فحص paid/current/state بشكل واضح.

### Bidder Deposit
- الإرسال كان يكتفي بوجود participant، ثم ينشئ/يقفل deposit.
- لم يكن يمنع الدفع بعد نهاية المزاد أو في حالات مثل `ended` و`cancelled`.
- لم يكن يفحص أهلية participant بشكل مركزي.

### Winner Settlement Payment
- الإرسال كان يبحث عن settlement الحالي حسب winner، لكنه لم يطبق كل قواعد الحالة والمهلة والمبلغ داخل Rule واحدة.
- الاعتماد كان يفحص `is_current` و`Paid` جزئياً، لكنه كان يسمح بمبلغ أقل من المتبقي، ولم يقارن settlement المرتبطة بالـsubmission مع current settlement المعاد تحميلها من المزاد.

## 2. السبب الجذري
- قواعد الدفع كانت موزعة بين submit/review، وبعضها غير موجود.
- لم تكن حالة المزاد والمهلة وملكية الهدف والمبلغ والعملة تفحص بنفس السياسة عند الإرسال والاعتماد.
- Admin deadline override لم يكن موجوداً كصلاحية مستقلة مع reason/audit.

## 3. الحالات المسموحة
- Seller Deposit: فقط `awaiting_seller_deposit`.
- Bidder Deposit: فقط `scheduled` أو `live` قبل `auction.ends_at`.
- Winner Settlement: فقط `payment_pending` مع settlement حالية `current_marker = 1`, `is_current = true`, و`status = payment_pending`.

## 4. الحالات المرفوضة
- Seller Deposit: `draft`, `scheduled`, `cancelled`, `rejected` وأي حالة ليست `awaiting_seller_deposit`.
- Bidder Deposit: `draft`, `pending_review`, `awaiting_seller_deposit`, `ended`, `settlement_pending`, `payment_pending`, `handover_pending`, `completed`, `cancelled`, `unsold`, `defaulted`, `disputed`.
- Winner Settlement: أي حالة ليست `payment_pending`، وأي settlement غير current أو paid/cancelled/defaulted/disputed/completed.

## 5. سياسة Deadline المختارة
- Seller Deposit: لا يوجد حقل deadline مستقل حالياً، ولم تتم إضافة واحد.
- Bidder Deposit: `auction.ends_at` هو deadline للإرسال والاعتماد. الاعتماد بعد النهاية يحتاج override.
- Winner Settlement: `settlement.payment_due_at` هو deadline للإرسال والاعتماد. الاعتماد بعد المهلة يحتاج override.

## 6. Admin Override
- الصلاحية المستقلة: `auction.payment.override_deadline`.
- لا تضاف تلقائياً لكل admin عبر `config('auction.admin_permissions')`.
- تتطلب:
  - `override_deadline = true`
  - `override_reason` غير فارغ
  - مستخدم لديه permission صريحة أو ضمن config.
- يتم حفظ:
  - `overridden_by`
  - `overridden_at`
  - `override_reason`
  - `original_deadline`
- لا يتجاوز override:
  - Ownership
  - Current target
  - Paid state
  - Auction cancelled/wrong state
  - Winner/settlement changes

## 7. Rule/Policy المركزية
- أضيفت `App\Services\Auction\Support\PaymentEligibilityRule`.
- الأكشنز تنسق transaction والتحميل.
- Rule تقرر:
  - حالة المزاد لكل نوع payment.
  - ملكية الالتزام.
  - target current.
  - amount/currency exact match.
  - deadline.
  - override permission/reason.

## 8. Repository Locks
- أضيفت aliases واضحة:
  - `AuctionRepository::lockAuctionForPayment()`
  - `AuctionDepositRepository::lockDepositForPayment()`
  - `AuctionSettlementRepository::lockCurrentSettlementForPayment()`
- الاعتماد يقفل:
  - `PaymentSubmission`
  - `Auction`
  - `Deposit` أو `Settlement`
  - current settlement عند winner payment
  - successful transaction obligation key

## 9. الملفات المنشأة
- `app/Services/Auction/Support/PaymentEligibilityRule.php`
- `database/migrations/2026_07_12_040000_add_payment_deadline_override_fields.php`
- `tests/Feature/Auction/PaymentStateTest.php`
- `tests/Feature/Auction/PaymentDeadlineTest.php`
- `tests/Feature/Auction/PaymentStateDeadlineMysqlTest.php`
- `AUCTION_TASK_03_PAYMENT_STATE_DEADLINE_REPORT.md`

## 10. الملفات المعدلة
- `app/Services/Auction/Actions/SubmitPaymentSubmissionAction.php`
- `app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php`
- `app/Repositories/Auction/AuctionRepository.php`
- `app/Repositories/Auction/AuctionDepositRepository.php`
- `app/Repositories/Auction/AuctionSettlementRepository.php`
- `app/Http/Requests/Auction/ReviewPaymentSubmissionRequest.php`
- `app/Http/Controllers/Auction/PaymentSubmissionController.php`
- `app/Models/Auction/PaymentSubmission.php`
- `app/Models/User.php`
- `app/Policies/Auction/PaymentSubmissionPolicy.php`
- `lang/en/auction.php`
- `lang/ar/auction.php`

## 11. Migrations
- أضيف migration:
  - `users.auction_permissions` JSON nullable
  - `payment_submissions.overridden_by`
  - `payment_submissions.overridden_at`
  - `payment_submissions.override_reason`
  - `payment_submissions.original_deadline`
- `php artisan migrate:status` أظهر migration الجديدة Pending في قاعدة التطوير الحالية. لم يتم تشغيل migrate على قاعدة التطوير.

## 12. Permissions الجديدة
- `auction.payment.override_deadline`
- أضيفت إلى `PaymentSubmissionPolicy::overrideDeadline`.
- يتم التحقق منها أيضاً داخل `PaymentEligibilityRule` عند محاولة override.

## 13. الاختبارات الجديدة
- `PaymentStateTest`
  - Seller state/owner/amount/currency/zero deposit.
  - Bidder scheduled/live/ended/cancelled/registration/eligibility/ownership.
  - Winner current settlement/winner/amount/currency/paid/handover/stale target.
- `PaymentDeadlineTest`
  - Bidder deadline submit/review.
  - Winner deadline submit/review.
  - Override without permission/reason.
  - Override success with audit fields.
  - Override cannot bypass stale/current/cancelled/paid.
- `PaymentStateDeadlineMysqlTest`
  - MySQL stale settlement after winner changes rejects approval without creating `PaymentTransaction`.

## 14. Feature Tests
- `php artisan test --filter=PaymentState`
  - Passed: 4
  - Skipped: 1 MySQL-only test
- `php artisan test --filter=PaymentDeadline`
  - Passed: 3
- `php artisan test --filter=Payment`
  - Passed: 18
  - Skipped: 4 MySQL-only tests in default connection

## 15. MySQL Tests
- `php artisan test --configuration=phpunit.mysql.xml --filter=Payment`
  - Passed: 22
  - Assertions: 104
  - ملاحظة: Laravel أظهر warning: `Option --configuration cannot be used more than once`، لكن الأمر استخدم بيئة MySQL وشغل الاختبارات بنجاح.

## 16. الأوامر التي شغلت
- `composer dump-autoload` - passed.
- `php artisan optimize:clear` - passed.
- `php artisan route:list` - passed, 183 routes.
- `php artisan migrate:status` - passed, migration الجديدة Pending في dev DB.
- `php artisan test --filter=PaymentState` - passed.
- `php artisan test --filter=PaymentDeadline` - passed.
- `php artisan test --filter=Payment` - passed.
- `php artisan test --configuration=phpunit.mysql.xml --filter=Payment` - passed.
- `vendor/bin/pint --test` - failed على كامل المشروع بسبب 150 style issue قديمة خارج نطاق المهمة.
- `vendor/bin/pint --test <modified files>` - passed على 15 ملفاً.
- `php -l` للملفات المعدلة والمنشأة - passed.

## 17. فحوص تعذر تشغيلها
- لم يتعذر تشغيل الاختبارات المطلوبة.
- `vendor/bin/pint --test` على كامل المشروع لم ينجح بسبب مشاكل style موجودة مسبقاً في ملفات كثيرة خارج نطاق هذه المهمة.

## 18. المخاطر المتبقية
- migration الجديدة لم تطبق على قاعدة التطوير الحالية؛ يجب تشغيلها عند اعتماد التغيير.
- لا يوجد seller deposit deadline مستقل في schema الحالي، لذلك لم تتم إضافة سياسة deadline له.
- `PaymentSubmissionStatus` الحالي لا يحتوي `expired/superseded/cancelled`، ولم تتم إضافة حالات جديدة لتجنب توسيع lifecycle خارج نطاق المهمة.
