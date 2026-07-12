# Auction System — Remaining Requirements Master Plan

## 1. معلومات المشروع

```text
Project:
C:\Users\pc\Desktop\SB\soom
```

هذا الملف هو المرجع الثابت لجميع الإصلاحات المتبقية في نظام المزادات بعد مراجعة نسخة `app(7)`.

الهدف من هذا الملف هو تقسيم العمل إلى مهام صغيرة مستقلة، بحيث يُعطى الـAgent في كل مرة ملف مهمة واحدة فقط، ثم تُراجع النتيجة قبل الانتقال للمهمة التالية.

لا يجب إرسال هذا الملف كاملًا للـAgent على أنه مهمة تنفيذ واحدة. يستخدم كـMaster Checklist لنا فقط.

---

## 2. قواعد العمل العامة

كل مهمة يجب أن تلتزم بالقواعد التالية:

1. مراجعة الكود الفعلي قبل التعديل وعدم الاعتماد على التقارير السابقة.
2. تنفيذ مشكلة واحدة محددة فقط في كل مرة.
3. عدم تنفيذ Refactor واسع خارج نطاق المشكلة الحالية.
4. عدم تعديل Modules خارج المزادات إلا لوجود Dependency ضروري وموثق.
5. عدم استخدام:
   ```text
   php artisan migrate:fresh
   ```
6. عدم حذف جداول المشروع خارج نظام المزادات.
7. عدم كسر الـAPI Contracts دون سبب قوي.
8. الحفاظ على:
   - Append-only bids.
   - Minor monetary units.
   - Public ULIDs.
   - Audit trail.
   - Historical financial records.
9. عدم استخدام Float في أي حساب مالي.
10. لا تعتبر المهمة ناجحة بسبب نجاح Syntax فقط.
11. يجب إضافة اختبارات تمنع رجوع المشكلة.
12. يجب ذكر الأوامر التي شُغلت فعليًا ونتائجها.
13. لا يدّعي الـAgent نجاح فحص لم يتم تشغيله.
14. بعد كل مهمة يجب إنشاء تقرير قصير يوضح:
    - المشكلة الأصلية.
    - السبب الجذري.
    - الملفات المعدلة.
    - التصميم النهائي.
    - الاختبارات الجديدة.
    - نتائج الأوامر.
    - أي نقطة لم تستطع البيئة اختبارها.

---

# 3. ترتيب الإصلاحات

## TASK 01 — Safe Auction Migrations

### المشكلة

Migration حديثة قد تحذف جداول المزادات القديمة دون إعادة إنشاء الـSchema الجديدة إذا كانت Migration الإنشاء القديمة مسجلة مسبقًا في جدول `migrations`.

### النتيجة المطلوبة

- سلسلة Migrations آمنة.
- قاعدة فارغة وقاعدة قديمة تصلان لنفس الـSchema النهائية.
- لا تعتمد Migration حديثة على إعادة تشغيل Migration قديمة.
- لا تستخدم `migrate:fresh`.
- لا تلمس أي جداول خارج المزادات.

### شروط القبول

- اختبار Fresh database.
- اختبار Legacy database نفذت Migration القديمة.
- `migrate` لا يحذف الجداول ويتركها مفقودة.
- جميع Auction tables وForeign Keys وIndexes موجودة بعد التنفيذ.
- Rollback behavior موثق بوضوح.

---

## TASK 02 — One Successful Payment Per Financial Obligation

### المشكلة

يمكن إنشاء محاولات دفع متعددة لنفس Deposit أو Settlement، وقد تعتمد محاولتان مختلفتان لأن كل Submission تقفل بصورة منفصلة.

### النتيجة المطلوبة

تعريف Financial Obligation واضح:

```text
Seller deposit
Bidder deposit
Winner settlement
```

ويُسمح بتاريخ محاولات دفع متعددة، لكن بدفعة ناجحة واحدة فقط لكل التزام، إلا لو كان النظام يدعم Partial Payments صراحة.

### شروط القبول

- لا يمكن اعتماد Submission ثانية بعد دفع الالتزام.
- لا يمكن إنشاء PaymentTransaction ناجحتين لنفس الالتزام.
- Constraints على مستوى قاعدة البيانات.
- Approval idempotent.
- اختبار محاولتين مختلفتين وليس نفس idempotency key فقط.
- التعامل الصحيح مع Reject ثم Resubmit.

---

## TASK 03 — Payment State and Deadline Validation

### المشكلة

إرسال واعتماد الدفع لا يتحققان من جميع الحالات والمهل المناسبة لكل Payment Purpose.

### النتيجة المطلوبة

قواعد صريحة:

```text
Seller deposit:
AwaitingSellerDeposit فقط

Bidder deposit:
Scheduled أو Live حسب سياسة التسجيل

Winner payment:
PaymentPending فقط
Current settlement فقط
Current winner فقط
قبل payment_due_at
```

ويكون Admin override منفصلًا بصلاحية وسبب إجباري وAudit.

### شروط القبول

- لا يعتمد دفع بعد إلغاء أو اكتمال المزاد.
- لا يعتمد Bidder deposit بعد Ended.
- لا يعتمد Winner payment بعد تغيير الفائز.
- لا يعتمد دفع بعد Deadline دون Override موثق.
- Submit وApprove يعيدان Lock/Revalidation.

---

## TASK 04 — Cancellation Financial Source of Truth

### المشكلة

Cancellation قد تنشئ Refund من Deposit وRefund آخر من PaymentTransaction لنفس الأموال.

كما يتم تحويل PaymentTransaction إلى `Reversed` قبل نجاح رد المال.

### النتيجة المطلوبة

- مصدر رد مالي واحد لكل مبلغ.
- ربط Refund بالأصل المالي الحقيقي دون تكرار.
- PaymentTransaction تظل `Succeeded` حتى نجاح Refund.
- بعد نجاح Refund فقط تتحول إلى الحالة المالية المناسبة.
- Cancellation idempotent.

### شروط القبول

- Deposit مدفوعة لا تنتج Refundين.
- إعادة تنفيذ Cancellation لا تكرر Refund Plan.
- Pending Refund لا تعني Reversed Payment.
- Failed Refund لا تغيّر الدفعة إلى Refunded/Reversed.
- اختبار مركب يشمل Deposit + PaymentTransaction الخاصة بها.

---

## TASK 05 — Refund Applied Deposit Amounts

### المشكلة

Cancellation قد تنشئ Refund من:

```text
held_amount_minor + applied_amount_minor
```

لكن تأكيد Refund يسمح فقط بما لا يتجاوز `held_amount_minor`.

### النتيجة المطلوبة

تعريف مالي صريح لكل Bucket:

```text
held_amount_minor
applied_amount_minor
forfeited_amount_minor
refunded_amount_minor
```

وتحديد كيفية عكس المبلغ المطبق على Settlement قبل أو أثناء Refund.

### شروط القبول

- يمكن رد مبلغ كان Applied إذا أُبطلت Settlement.
- لا يمكن رد مبلغ Forfeited.
- لا يمكن تجاوز Refundable amount.
- لا يمكن رد نفس المبلغ مرتين.
- الحقول المالية تظل متوازنة بعد نجاح Refund.

---

## TASK 06 — Real Refund Lifecycle

### المشكلة

دورة Refund ما زالت غير مكتملة تشغيليًا.

### النتيجة المطلوبة

```text
Pending
→ Processing
→ Succeeded
```

مع:

```text
Failed
ManualReview
Cancelled
```

ودعم:

- Provider adapter أو Manual admin confirmation.
- Retry.
- Backoff.
- Lease.
- attempt_count.
- last_error.
- next_retry_at.
- provider_refund_id unique.
- Idempotent success confirmation.
- Audit وOutbox.

### شروط القبول

- نجاح Refund مرتين لا يزيد المبلغ مرتين.
- Provider ID لا يتكرر.
- Failed refunds يعاد جدولتها.
- Manual confirmation تتطلب Actor وReason.
- لا تتحول Refund إلى Succeeded دون إثبات.

---

## TASK 07 — Non-Winner Deposit Release

### المشكلة

عند سياسة الاحتفاظ بعربونات غير الفائزين حتى دفع الفائز، لا يوجد Flow كامل لتحريرها بعد انتهاء الحاجة.

### النتيجة المطلوبة

تحرير العربونات عند:

- نجاح دفع الفائز.
- عدم وجود فائز بديل.
- انتهاء Alternative winner window.
- Unsold.
- Completed.
- Cancellation.
- الحالات التي تحددها Configuration Snapshot.

### شروط القبول

- لا توجد Deposits تظل Held بلا سبب نهائي.
- لا يُرد عربون مرشح بديل قبل انتهاء الحاجة إليه.
- Release/Refund Plan idempotent.
- اختبارات لكل نهاية للمزاد.

---

## TASK 08 — Seller Deposit Lifecycle

### المشكلة

مصير عربون البائع غير مكتمل في:

- Completed.
- Unsold.
- Seller cancellation.
- Admin cancellation.
- Seller breach.
- Winner default.
- Platform fault.

### النتيجة المطلوبة

سياسة ثابتة داخل Configuration Snapshot تحدد:

- Refundable.
- Forfeited.
- Partially forfeited.
- Release trigger.
- Actor and reason.

### شروط القبول

- كل حالة نهائية للمزاد تحدد مصير Seller deposit.
- لا يظل Seller deposit Held بلا نهاية.
- المصادرة تتطلب سببًا وAudit.
- لا تنشأ Refund مزدوجة.

---

## TASK 09 — Winner Default Financial Correctness

### المشكلة

Winner Default لا تعالج `applied_amount_minor` بصورة صحيحة، وقد تسمح بالـDefault في حالات غير مناسبة.

### النتيجة المطلوبة

- يسمح بالـDefault فقط للـCurrent unpaid settlement.
- المزاد في الحالة المناسبة.
- Deadline انتهت أو Admin override بصلاحية مستقلة وسبب.
- Applied deposit للفائز المتعثر:
  - Forfeited.
  - Refundable.
  - Partial.
  حسب Snapshot.
- لا يتم Default بعد الدفع.
- Alternative winner تستخدم Settlement جديدة.

### شروط القبول

- Paid settlement لا تتحول Defaulted.
- `payment_due_at = null` لا يسمح بالـDefault العشوائي.
- Defaulted bidder مستبعد بالكامل.
- Applied deposit لا تضيع بين Buckets.
- كل تغيير موثق.

---

## TASK 10 — Central Financial Cancellation Service

### المشكلة

الإلغاء من `CancelAuctionAction` يختلف عن الإلغاء الناتج من `ResolveAuctionDisputeAction`.

### النتيجة المطلوبة

Use Case مركزي واحد لمعالجة الإلغاء المالي، ويستدعى من:

- Seller cancellation.
- Admin cancellation.
- Dispute resolution cancellation.
- System cancellation.

ويكون القرار والسياق مختلفين، لكن التنفيذ المالي موحد.

### شروط القبول

- لا يوجد مسار يغيّر `Cancelled` فقط دون Financial Plan.
- جميع المسارات تنشئ نفس النتائج الصحيحة.
- State Machine وPolicy وActions متوافقة.
- التنفيذ Idempotent.

---

## TASK 11 — Configuration Snapshot Completeness

### المشكلة

بعض القواعد تقرأ لاحقًا من Configuration Version المرتبطة بدل Snapshot ثابتة داخل المزاد.

### النتيجة المطلوبة

Snapshot غير قابلة للتغيير تشمل على الأقل:

- Fee rules.
- Deposit amounts.
- Non-winner deposit policy.
- Seller deposit policy.
- Winner default policy.
- Alternative winner policy.
- Deadlines.
- Auto-extension rules.
- Terms version.
- Cancellation/refund policies.

### شروط القبول

- تعديل Configuration Version لا يغير مزادًا موجودًا.
- حذف أو تعطيل النسخة لا يكسر الـFlow.
- كل Action حساسة تقرأ Snapshot الخاصة بالمزاد.
- Snapshot تُختبر.

---

## TASK 12 — Audience-Specific Resources

### المشكلة

`AuctionResource` عامة تستخدم في Endpoints متعددة وتعرض بيانات مالية لا تخص كل Audience.

### النتيجة المطلوبة

استخدام Resources محددة:

```text
PublicAuctionResource
ParticipantAuctionResource
WinnerAuctionResource
SellerAuctionResource
AdminAuctionResource
```

مع Resources فرعية صريحة بدل Models خامًا.

### شروط القبول

- Winner لا يرى seller net أو platform fee.
- Public لا يرى reserve أو settlement private details.
- Participant لا يرى بيانات الدفع الخاصة بالآخرين.
- Admin يحصل على البيانات اللازمة.
- Privacy Feature Tests.

---

## TASK 13 — Real Outbox Side Effects

### المشكلة

الـOutbox تحسنت تقنيًا، لكن Consumers لا تنفذ Side Effects عملية كافية.

### النتيجة المطلوبة

Consumers حقيقية للأحداث المهمة:

- Notifications.
- Emails.
- Broadcast/realtime.
- Internal jobs.

مع:

- Retry.
- Backoff.
- Lease.
- Dead letter.
- Idempotent consumption.

### شروط القبول

- لا تصبح Published دون نجاح Consumer الحقيقي.
- فشل Consumer يعيد جدولة الرسالة.
- لا تُرسل Notification مرتين.
- الأحداث المالية المهمة تُنشأ من Actions.

---

## TASK 14 — Scheduler and Job Concurrency

### المشكلة

بعض Scheduler Actions وJobs تعتمد على `withoutOverlapping()` فقط أو تستخدم `get()` دون Locks كافية.

### النتيجة المطلوبة

لكل Transition تلقائية:

```text
select candidate ids
→ transaction per auction
→ lockForUpdate / skipLocked
→ recheck
→ transition
→ outbox
→ commit
```

### شروط القبول

- تشغيل عاملين لا يكرر Transition.
- لا تنشأ Settlement مرتين.
- لا تنشأ Refund مرتين.
- استخدام chunk/lazy/skipLocked حسب الحاجة.
- MySQL concurrency tests.

---

## TASK 15 — Complete DTO and Repository Boundaries

### المشكلة

بعض العمليات المالية ما زالت تمرر Arrays خامًا أو تستخدم Persistence خارج Repositories.

### النتيجة المطلوبة

DTOs Typed للعمليات المالية المعقدة فقط، مثل:

- CreateDepositDTO.
- CreatePaymentSubmissionDTO.
- CreatePaymentTransactionDTO.
- CreateRefundDTO.
- UpdateDepositAmountsDTO.
- CreateWinnerReassignmentDTO.

مع القاعدة:

```text
Action decides.
Repository reads/persists.
```

### شروط القبول

- لا Generic DTO.
- لا Generic Repository.
- لا Reflection mapping.
- لا Business Logic داخل Repository.
- Controllers لا تحتوي Storage أو Eloquent.
- أي Direct Eloquent متبقٍ موثق ومبرر.

---

## TASK 16 — Dedicated MySQL Testing Environment

### المشكلة

ملف MySQL tests يستخدم اسم قاعدة قد يكون قاعدة التطوير نفسها.

### النتيجة المطلوبة

قاعدة مخصصة مثل:

```text
soom_testing
```

ومستخدم محدود الصلاحيات.

### شروط القبول

- الاختبارات ترفض التشغيل على قاعدة غير منتهية بـ`_testing`.
- لا تستخدم قاعدة Development.
- Tests تغطي:
  - concurrent bids.
  - duplicate payment approval.
  - duplicate refund confirmation.
  - concurrent scheduler.
  - concurrent winner default.
- نتائج التشغيل موثقة.

---

# 4. Definition of Done للنظام كاملًا

لا يُعتبر نظام المزادات مكتملًا إلا بعد:

- نجاح جميع Tasks السابقة.
- مراجعة نهائية للكود الفعلي.
- عدم وجود أخطاء مالية معروفة.
- عدم وجود جداول قد تحذف عبر Migration غير آمنة.
- عدم وجود Payment أو Refund مزدوجة.
- عدم وجود Deposits محجوزة بلا نهاية.
- تطابق Policy وState Machine وActions.
- وجود اختبارات MySQL للتزامن.
- فصل Resources حسب الـAudience.
- اكتمال الـAudit والـOutbox.
- تشغيل الأوامر الفعلية وتوثيق النتائج.
