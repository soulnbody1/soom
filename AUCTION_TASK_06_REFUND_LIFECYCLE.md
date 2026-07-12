# TASK 06 — Complete Auction Refund Lifecycle

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إكمال دورة الـRefund داخل نظام المزادات بصورة تشغيلية حقيقية وآمنة، بحيث لا تبقى Refunds في حالة `Pending` بلا معالجة، ولا تتحول إلى `Succeeded` دون تنفيذ فعلي أو إثبات إداري موثق.

الدورة المطلوبة:

```text
Pending
→ Processing
→ Succeeded
```

مع الحالات البديلة:

```text
Failed
ManualReview
Cancelled
```

ويجب أن تدعم:

- Provider adapter واضح.
- Manual admin confirmation عند الدفع خارج النظام.
- Retry وBackoff.
- Processing lease.
- `attempt_count`.
- `last_error`.
- `next_retry_at`.
- `provider_refund_id`.
- Idempotency.
- Audit وOutbox.
- Concurrency safety.
- الانتقال إلى `ManualReview` بعد تجاوز الحد الأقصى للمحاولات.

هذه المهمة تخص Refund Lifecycle فقط.

لا تنفذ الآن:

- Seller Deposit final policy.
- Non-winner deposit release.
- Winner Default.
- General Cancellation refactor.
- General Outbox refactor.
- General Scheduler refactor.
- Resource privacy.
- General DTO/Repository cleanup.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لإكمال Refund Lifecycle.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/RefundAuctionDepositAction.php
app/Services/Auction/Actions/*
app/Jobs/Auction/*
app/Repositories/Auction/AuctionRefundRepository.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Repositories/Auction/AuctionDepositRepository.php
app/Models/Auction/RefundTransaction.php
app/Models/Auction/PaymentTransaction.php
app/Models/Auction/AuctionDeposit.php
app/Domain/Auction/Enums/
app/Domain/Auction/Exceptions/
app/DTO/Auction/
app/Http/Controllers/Auction/
app/Http/Requests/Auction/
app/Policies/Auction/
routes الخاصة بالمزادات
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
config/auction.php
```

راجع تنفيذ TASK 04 وTASK 05 إن كانا موجودين، ولا تكسر:

- Refund source of truth.
- `source_payment_transaction_id` أو التصميم المكافئ.
- held/applied allocation.
- Idempotent confirmation.
- Double-refund prevention.

لا تعتمد على التقارير السابقة دون مراجعة الكود.

---

# 2. تعريف حالات Refund

استخدم الأسماء الفعلية الموجودة في المشروع، لكن يجب أن يكون المعنى النهائي واضحًا:

## Pending

تم إنشاء Refund Plan، لكن لم تبدأ محاولة تنفيذ الرد.

## Processing

Worker أو Admin بدأ التنفيذ وتم حجز Refund بواسطة Lease.

## Succeeded

تم رد الأموال فعليًا، أو تم تأكيد الرد اليدوي بإثبات إداري موثق.

## Failed

فشلت محاولة قابلة لإعادة المحاولة، وتم تسجيل الخطأ وموعد المحاولة التالية.

## ManualReview

فشل غير قابل لإعادة المحاولة، أو تم تجاوز الحد الأقصى للمحاولات، وتحتاج Refund تدخل Admin.

## Cancelled

تم إلغاء Refund قبل النجاح بسبب موثق.

لا تسمح بإلغاء Refund بعد نجاحها.

---

# 3. حقول الـSchema المطلوبة

راجع الـSchema الحالية، وأضف Migration آمنة عند الحاجة لدعم حقول مثل:

```text
status
attempt_count
last_error
next_retry_at
processing_started_at
processing_token
lease_expires_at
provider
provider_refund_id
provider_response
manual_confirmed_by
manual_confirmed_at
manual_confirmation_reason
succeeded_at
failed_at
cancelled_at
cancelled_by
cancellation_reason
```

لا تضف كل حقل إذا كان له بديل موجود فعليًا.

يجب أن يكون `provider_refund_id` فريدًا عند وجوده، ويفضل:

```text
UNIQUE(provider, provider_refund_id)
```

إذا كان المعرّف فريدًا داخل كل Provider فقط.

---

# 4. Refund Processor Contract

أنشئ Contract صغيرًا وواضحًا، مثل:

```php
interface AuctionRefundProcessorInterface
{
    public function process(RefundTransaction $refund): RefundProcessingResult;
}
```

أو تصميم Typed مكافئ.

يجب أن تميز النتيجة بين:

```text
Succeeded
RetryableFailure
NonRetryableFailure
ManualReviewRequired
```

مثال مفاهيمي:

```php
final readonly class RefundProcessingResult
{
    public function __construct(
        public RefundProcessingOutcome $outcome,
        public ?string $providerRefundId,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $providerResponse,
    ) {}
}
```

الـProcessor لا يفتح Transactions ولا يحفظ Models.

```text
Processor talks to provider and returns a result.
Action decides and orchestrates.
Repository locks and persists.
```

---

# 5. اختيار الـProcessor

اختر Processor بناءً على مصدر الدفع الأصلي الموثوق:

```text
source PaymentTransaction
payment provider
payment method
manual/offline
```

لا تسمح للمستخدم بتحديد Provider من Request بصورة موثوقة.

إذا لم يوجد Provider API فعلي:

- لا تنشئ Integration وهمية.
- استخدم `ManualReview` أو Manual Refund Processor واضحًا.
- لا تحول Refund إلى `Succeeded` تلقائيًا.
- نفّذ Manual Admin Confirmation موثقة.

---

# 6. بدء المعالجة

أنشئ Action واضحة مثل:

```text
ProcessAuctionRefundAction
```

داخل Transaction قصيرة:

1. Lock Refund.
2. تأكد أن الحالة تسمح بالمعالجة:
   ```text
   Pending
   Failed مع next_retry_at مستحق
   Processing مع Lease منتهية
   ```
3. ارفض الحالات:
   ```text
   Succeeded
   Cancelled
   ManualReview
   ```
4. زد `attempt_count` ذريًا.
5. أنشئ `processing_token` فريدًا.
6. حدّث:
   ```text
   status = Processing
   processing_started_at
   lease_expires_at
   last_error = null
   ```
7. Commit.

بعد Commit:

8. استدعِ Processor خارج Transaction.
9. استقبل النتيجة.
10. افتح Transaction جديدة.
11. Lock Refund مرة أخرى.
12. تأكد أن `processing_token` يطابق المحاولة الحالية.
13. طبّق النتيجة.
14. Audit وOutbox.
15. Commit.

ممنوع استدعاء Provider API داخل Database Transaction طويلة.

---

# 7. Processing Lease

يجب منع Workerين من معالجة Refund نفسها.

المطلوب:

- Worker واحد فقط يحصل على Lease.
- Worker ثانٍ يتجاوز Refund المحجوزة.
- إذا مات Worker، يمكن إعادة المحاولة بعد انتهاء Lease.
- محاولة قديمة لا تستطيع كتابة نتيجتها فوق محاولة أحدث.
- لا تظل Refund في `Processing` للأبد.

استخدم `processing_token` و`lease_expires_at` أو تصميمًا مكافئًا.

---

# 8. نجاح Refund

عند نتيجة Success، داخل Transaction:

1. Lock Refund.
2. إذا كانت `Succeeded` بالفعل، أرجعها دون تطبيق مالي جديد.
3. تحقق من `processing_token`.
4. تحقق من `provider_refund_id` وUniqueness.
5. Lock source PaymentTransaction.
6. Lock Deposit أو Settlement obligation.
7. طبّق Refund allocation المنشأة في TASK 05 مرة واحدة فقط.
8. حدّث:
   ```text
   status = Succeeded
   provider_refund_id
   provider_response
   succeeded_at
   processing_token = null
   lease_expires_at = null
   ```
9. حدّث PaymentTransaction إلى `Refunded/Reversed` فقط بعد نجاح Refund كاملة.
10. Audit.
11. Outbox:
    ```text
    auction.refund_succeeded
    ```
12. Commit.

يجب أن تكون العملية Idempotent بالكامل.

---

# 9. الفشل القابل لإعادة المحاولة

عند Retryable failure، داخل Transaction:

1. Lock Refund.
2. تحقق من Token.
3. حدّث:
   ```text
   status = Failed
   last_error
   failed_at
   next_retry_at
   processing_token = null
   lease_expires_at = null
   ```
4. Audit.
5. Outbox:
   ```text
   auction.refund_failed
   ```
6. Commit.

لا تعدّل PaymentTransaction أو Deposit buckets.

---

# 10. Backoff

استخدم Backoff قابلًا للضبط من Config، مثل:

```text
attempt 1 → 1 minute
attempt 2 → 5 minutes
attempt 3 → 15 minutes
attempt 4 → 1 hour
attempt 5 → ManualReview
```

لا توزع الأرقام داخل الكود.

ضعها في:

```text
config/auction.php
```

أو Config مخصص.

بعد `max_attempts` انقل Refund إلى `ManualReview`.

---

# 11. الفشل غير القابل لإعادة المحاولة

إذا رجع Provider نتيجة نهائية مثل:

```text
invalid original transaction
refund not supported
payment already reversed externally
manual verification required
```

انقل Refund مباشرة إلى:

```text
ManualReview
```

مع حفظ الخطأ والـProvider response المنقحة.

لا تعتبرها `Succeeded`.

---

# 12. Manual Admin Confirmation

أنشئ Use Case مثل:

```text
ConfirmAuctionRefundManuallyAction
```

ويجب أن يتطلب:

```text
refund_id
confirmation_reference
reason
evidence أو note عند الحاجة
```

مع Permission مستقلة مثل:

```text
auction.refunds.confirm_manual
```

داخل Transaction:

1. Lock Refund.
2. ارفض `Succeeded` و`Cancelled`.
3. تحقق أن Manual confirmation مسموحة.
4. تحقق من Permission.
5. تحقق من Reason وReference.
6. Lock source PaymentTransaction.
7. Lock obligation.
8. طبّق الحسابات مرة واحدة.
9. حدّث:
   ```text
   status = Succeeded
   provider = manual
   provider_refund_id = confirmation_reference
   manual_confirmed_by
   manual_confirmed_at
   manual_confirmation_reason
   succeeded_at
   ```
10. Audit.
11. Outbox.
12. Commit.

لا تسمح لأي Admin عادي دون Permission الخاصة.

---

# 13. إلغاء Refund

أنشئ Use Case مثل:

```text
CancelAuctionRefundAction
```

يسمح فقط في:

```text
Pending
Failed
ManualReview
```

ويرفض:

```text
Succeeded
Cancelled
Processing مع Lease فعالة
```

يتطلب Actor وReason.

إلغاء Refund لا يغير PaymentTransaction ولا Deposit buckets.

---

# 14. Job المعالجة

أنشئ أو أصلح Job مثل:

```text
ProcessPendingAuctionRefundsJob
```

المطلوب:

- تجلب فقط:
  ```text
  Pending
  Failed مع next_retry_at مستحق
  Processing مع Lease منتهية
  ```
- تستخدم `chunkById` أو `lazyById` و`skipLocked` حسب MySQL.
- لا تستخدم `get()` لكل السجلات.
- لا تعالج Refund نفسها مرتين.
- Queue retry لا تكرر الأثر المالي.
- يفضل Dispatch Job مستقلة لكل Refund بعد Claim آمن.

---

# 15. Scheduler

سجل الـJob/Command في المكان الصحيح للمشروع.

تحقق فعليًا عبر:

```bash
php artisan schedule:list
```

`withoutOverlapping()` ليست بديلًا عن Database lease.

---

# 16. Audit

سجل أحداثًا واضحة:

```text
refund_created
refund_processing_started
refund_processing_failed
refund_retry_scheduled
refund_moved_to_manual_review
refund_manually_confirmed
refund_succeeded
refund_cancelled
```

Metadata المناسبة:

```text
attempt_count
provider
provider_refund_id
error_code
error_message
next_retry_at
actor
reason
```

لا تحفظ Secrets أو Provider response حساسة دون تنقية.

---

# 17. Outbox

أنشئ أحداثًا على الأقل:

```text
auction.refund_processing
auction.refund_failed
auction.refund_manual_review
auction.refund_succeeded
auction.refund_cancelled
```

في هذه المهمة يكفي إنشاء الأحداث الصحيحة باستخدام الـOutbox الحالية.

تنفيذ Consumers العامة سيكون في TASK 13.

---

# 18. DTOs وRepositories

استخدم DTOs Typed عند الحاجة، مثل:

```text
StartRefundProcessingDTO
CompleteRefundProcessingDTO
FailRefundProcessingDTO
ConfirmManualRefundDTO
CancelRefundDTO
```

لا تنشئ Generic Refund DTO.

لا تجعل Processor يحفظ Models.

لا تضع Business Logic داخل Repository.

---

# 19. الاستثناءات والرسائل

أضف Exceptions واضحة، مثل:

```text
RefundNotProcessableException
RefundLeaseAlreadyAcquiredException
RefundProcessingTokenMismatchException
RefundAlreadySucceededException
RefundManualConfirmationNotAllowedException
RefundManualConfirmationUnauthorizedException
RefundCancellationNotAllowedException
DuplicateProviderRefundReferenceException
```

كل الرسائل التي تصل للمستخدم تكون عربية.

---

# 20. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Pending → Processing

- Refund Pending.
- Worker يبدأ.
- تصبح Processing.
- `attempt_count` تزيد مرة واحدة.
- Lease وToken محفوظان.

## B. Worker duplication

Workerان متزامنان على MySQL:

- واحد فقط يحصل على Lease.
- Processor يُستدعى مرة واحدة.
- لا Duplicate refund.

## C. Provider success

- تصبح Succeeded.
- `provider_refund_id` محفوظة.
- Deposit allocation تطبق مرة واحدة.
- PaymentTransaction تتغير بعد النجاح فقط.
- Audit وOutbox مرة واحدة.

## D. Success idempotency

أعد Completion مرتين:

- لا double refunded amount.
- لا double bucket updates.
- لا Audit/Outbox مالية مكررة.

## E. Retryable failure

- status = Failed.
- `next_retry_at` صحيحة.
- Payment وDeposit بلا تغيير مالي.

## F. Backoff

اختبر الجدول من Config.

## G. Max attempts

بعد الحد الأقصى:

```text
ManualReview
```

## H. Non-retryable failure

ينتقل مباشرة إلى ManualReview.

## I. Lease expiry

- Lease منتهية يمكن استردادها.
- Attempt قديمة لا تكتب بعد Token mismatch.

## J. Manual confirmation

- Admin مخول + Reason + Reference → Success.
- بدون Permission → رفض.
- Reason فارغ → رفض.
- إعادة confirmation → Idempotent.

## K. Cancel refund

- Pending → Cancelled.
- Failed → Cancelled.
- Succeeded → رفض.
- Processing active → رفض.

## L. Duplicate provider reference

Refund ثانية بنفس:

```text
provider + provider_refund_id
```

تفشل.

## M. Concurrent success completion

عمليتان متزامنتان على MySQL:

- تطبيق مالي مرة واحدة.
- لا negative buckets.
- لا duplicate provider reference.

---

# 21. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع Integration Tests على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات Locks وLeases والتزامن.

---

# 22. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan schedule:list
php artisan migrate:status
php artisan test --filter=AuctionRefundLifecycle
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionRefundLifecycle
```

وشغّل عند توفره:

```bash
vendor/bin/pint --test
```

وشغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أمر لم يتم تشغيله.

---

# 23. ممنوعات هذه المهمة

لا تنفذ الآن:

- Seller Deposit final refund/forfeit policy.
- Non-winner deposit release.
- Winner Default full flow.
- General cancellation policy refactor.
- General Outbox consumer implementation.
- General Scheduler cleanup.
- General Resource privacy.
- Full DTO/Repository cleanup خارج Refund files.

لا تنشئ Provider integration وهمية.

---

# 24. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_06_REFUND_LIFECYCLE_REPORT.md
```

ويحتوي على:

1. Flow القديم.
2. السبب الجذري.
3. State machine النهائية.
4. Refund processor contract.
5. Provider selection.
6. Lease design.
7. Retry and backoff design.
8. Manual confirmation flow.
9. Refund cancellation flow.
10. Database fields and constraints.
11. DTOs المنشأة.
12. Repositories المعدلة.
13. Actions وJobs المنشأة.
14. Permissions الجديدة.
15. Audit events.
16. Outbox events.
17. الاختبارات الجديدة.
18. نتائج Feature Tests.
19. نتائج MySQL concurrency tests.
20. نتيجة `schedule:list`.
21. الأوامر التي شُغلت.
22. أي فحص تعذر تشغيله.
23. أي مخاطر متبقية.

---

# 25. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- Refund لا تبقى Pending بلا معالج.
- Pending → Processing → Succeeded تعمل.
- Retryable failure يُعاد جدولته.
- Non-retryable failure ينتقل ManualReview.
- Max attempts ينتقل ManualReview.
- Lease تمنع Workerين من المعالجة.
- Lease المنتهية قابلة للاسترداد.
- Attempt قديمة لا تكتب فوق Attempt جديدة.
- Success Idempotent.
- Provider refund reference فريدة.
- Manual confirmation بصلاحية وسبب.
- PaymentTransaction لا تتغير قبل نجاح Refund.
- Deposit buckets تتغير مرة واحدة فقط.
- Audit وOutbox صحيحة.
- MySQL concurrency tests ناجحة.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
