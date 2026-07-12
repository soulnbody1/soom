# TASK 02 — One Successful Payment Per Financial Obligation

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف

إصلاح دورة المدفوعات داخل نظام المزادات بحيث:

- يمكن أن توجد عدة محاولات دفع لنفس الالتزام المالي.
- يمكن رفض محاولة ثم إرسال محاولة جديدة.
- لا يمكن أن توجد أكثر من دفعة ناجحة واحدة لنفس الالتزام المالي.
- لا يمكن اعتماد محاولتين مختلفتين لنفس Deposit أو Settlement.
- تكون الحماية على مستوى الـApplication والـDatabase معًا.
- تكون العمليات Idempotent وآمنة تحت التزامن.

هذه المهمة تخص مشكلة الدفع المكرر فقط.

لا تنفذ الآن إصلاحات Refunds أو Cancellation أو Winner Default أو Outbox أو Resources إلا إذا كان تعديل صغير ضروريًا مباشرة لتحقيق هذه المهمة، ويجب توثيقه.

---

# 1. المشكلة الحالية

راجع فعليًا الملفات والـFlow الحالي، خصوصًا:

```text
app/Services/Auction/Actions/SubmitPaymentSubmissionAction.php
app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Models/Auction/PaymentSubmission.php
app/Models/Auction/PaymentTransaction.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/AuctionSettlement.php
app/Domain/Auction/Enums/
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

الاشتباه الحالي:

1. يمكن للمستخدم إنشاء محاولتين أو أكثر لنفس:
   - Seller deposit.
   - Bidder deposit.
   - Winner settlement.

2. كل محاولة تستخدم `idempotency_key` مختلفة، ولذلك لا تعتبر Duplicate Request.

3. كل Submission يتم Lock لها بصورة منفصلة عند الاعتماد.

4. قد يعتمد الأدمن Submission A ثم يعتمد Submission B لنفس الالتزام المالي.

5. قد ينتج عن ذلك:
   - أكثر من `PaymentTransaction` ناجحة لنفس Deposit.
   - أكثر من `PaymentTransaction` ناجحة لنفس Settlement.
   - تحديث نفس Deposit أو Settlement مرتين.
   - تكرار Outbox أو Audit.
   - تحصيل أموال مرتين.

لا تفترض صحة الوصف دون مراجعة الكود والـSchema الفعليين.

---

# 2. تعريف الالتزام المالي

أنشئ مفهومًا واضحًا في التصميم يسمى مثلًا:

```text
Financial Obligation
```

لكن لا تنشئ Aggregate أو طبقات معقدة إذا لم تكن ضرورية.

الالتزامات الحالية على الأقل:

```text
seller_deposit
bidder_deposit
winner_settlement
```

كل Payment Submission يجب أن ترتبط صراحة بالتزام مالي واحد فقط.

كل Payment Transaction ناجحة يجب أن ترتبط بالتزام مالي واحد فقط.

يجب أن يكون هناك مفتاح ثابت يمكنه تعريف الالتزام بصورة فريدة، مثل:

```text
obligation_type
obligation_id
```

أو تصميم Polymorphic واضح مثل:

```text
payable_type
payable_id
```

أو أعمدة صريحة موجودة بالفعل إذا كانت تكفي.

لا تضف Generic Payment architecture واسعة خارج احتياج المزادات.

---

# 3. القاعدة المالية الأساسية

يُسمح بتاريخ محاولات متعددة:

```text
Submission 1 → Rejected
Submission 2 → Rejected
Submission 3 → Approved
```

لكن بعد نجاح الدفع:

```text
Submission 4 → يجب ألا تعتمد
```

ويجب ألا تنشأ:

```text
PaymentTransaction ناجحة ثانية
```

لنفس الالتزام.

إذا كان المشروع لا يدعم Partial Payments حاليًا، فطبّق:

```text
Exactly one successful payment per obligation
```

إذا وجدت دعمًا فعليًا ومدروسًا للـPartial Payments، لا تفترضه أو تكمله من نفسك. وثّق وجوده، ثم طبّق قيودًا تمنع تجاوز المبلغ المطلوب بدل فرض دفعة واحدة.

الوضع المتوقع حاليًا هو عدم دعم Partial Payments إلا إذا أثبت الكود عكس ذلك.

---

# 4. Database Constraints

لا تعتمد على Application Checks وحدها.

أنشئ أو عدّل Migration آمنة خاصة بهذه المهمة.

يجب أن تمنع قاعدة البيانات:

- أكثر من Payment Transaction ناجحة لنفس Payment Submission.
- تكرار Provider Transaction ID.
- أكثر من دفعة ناجحة لنفس Deposit أو Settlement.
- تجاوز قواعد الالتزام المالي حتى تحت Race Condition.

راجع إمكانات MySQL المستخدمة في المشروع.

إذا لم يكن من الممكن استخدام Partial Unique Index بشرط `status = succeeded` مباشرة، استخدم تصميمًا مناسبًا مثل:

```text
successful_obligation_key
```

تكون:

```text
NULL للمعاملات غير الناجحة
قيمة فريدة ثابتة للالتزام عند النجاح
```

مع Unique Index.

مثال مفاهيمي فقط:

```text
deposit:123
settlement:456
```

أو استخدم جدولًا مستقلًا للـCaptured Payment إذا كان ذلك أبسط وأكثر أمانًا.

لا تستخدم String key غير مضبوطة بدون Enum/Factory واضحة.

يجب أن تكون Constraints متوافقة مع:

- MySQL.
- Migrations الحالية.
- Existing development data.
- Historical rejected submissions.

راجع وأضف عند الحاجة:

```text
UNIQUE(payment_submission_id)
UNIQUE(provider, provider_transaction_id)
UNIQUE(successful_obligation_key)
```

ولا تضفها عميانيًا إذا كانت الأعمدة أو الـFlow مختلفين.

---

# 5. Submit Payment Submission Flow

راجع إرسال Payment Submission.

يجب السماح بـ:

```text
Rejected → Resubmit
```

لكن يجب منع أو تنظيم وجود أكثر من Active Submission لنفس الالتزام.

عرّف الحالات الفعالة، مثل:

```text
PendingReview
Processing
Approved
```

وقد تسمح Submission واحدة فقط في حالة فعالة لكل التزام.

إذا كانت هناك Submission:

```text
PendingReview
```

لنفس الالتزام، فلا تنشئ واحدة ثانية إلا إذا كان هناك سبب وظيفي واضح.

النتيجة المطلوبة غالبًا:

```text
PendingReview موجودة → Conflict
Rejected موجودة → يمكن إنشاء Submission جديدة
Approved موجودة → الالتزام مدفوع ولا يسمح بمحاولة جديدة
```

داخل Transaction:

1. Lock Auction.
2. Lock Deposit أو Settlement.
3. إعادة تحميل الالتزام.
4. فحص أن الالتزام غير مدفوع.
5. فحص عدم وجود Active Submission أخرى.
6. فحص المبلغ.
7. إنشاء Submission.
8. تحديث حالة الالتزام عند الحاجة.
9. Commit.

لا تعتمد فقط على Query قبل الـTransaction.

---

# 6. Review Payment Submission Flow

أعد بناء أو أصلح Approval داخل Transaction واحدة.

الترتيب المطلوب:

1. Lock Payment Submission.
2. Lock Auction.
3. Lock Deposit أو Settlement.
4. إعادة تحميل الالتزام المالي.
5. التأكد أن Submission:
   ```text
   PendingReview
   ```
6. التأكد أن الالتزام لم يدفع بالفعل.
7. التأكد من عدم وجود PaymentTransaction ناجحة لنفس الالتزام.
8. التأكد من عدم وجود Successful obligation key مستخدمة.
9. التحقق من المبلغ المطلوب.
10. التحقق من Currency.
11. إنشاء Payment Transaction.
12. تحديث الالتزام.
13. تحديث Submission.
14. إنشاء Audit.
15. إنشاء Outbox إن كان موجودًا في الـFlow الحالي.
16. Commit.

يجب أن تكون الخطوات 11 و12 و13 داخل نفس Transaction.

إذا اصطدمت العملية بـUnique Constraint بسبب عملية متزامنة أخرى:

- لا تعتبر ذلك نجاحًا صامتًا.
- أعد تحميل الالتزام.
- إذا كان الالتزام دفع بالفعل، أرجع Conflict/Already Paid بصورة واضحة.
- لا تنشئ Transaction أو Audit أو Outbox مكررة.

---

# 7. Idempotency

فرّق بوضوح بين:

## Request Idempotency

نفس المستخدم يكرر نفس الطلب بنفس:

```text
idempotency_key
```

فيحصل على نفس النتيجة.

## Business Idempotency

محاولتان مختلفتان أو أدمنان مختلفان يحاولان دفع نفس الالتزام.

هذه يجب أن تمنعها قاعدة:

```text
One successful payment per obligation
```

لا يكفي `idempotency_key` وحدها.

اختبر الحالتين.

---

# 8. Deposit State Handling

راجع حالات Deposit الحالية.

المطلوب:

```text
PendingSubmission
→ PendingReview
→ Held / Paid / Applied حسب النوع
```

وعند رفض Submission:

```text
Submission = Rejected
Deposit = PendingSubmission
```

أو الحالة المناسبة التي تسمح بمحاولة جديدة.

لا تجعل رفض محاولة واحدة يضع الالتزام كله في حالة نهائية تمنع إعادة المحاولة.

وعند اعتماد أول محاولة:

- يصبح الالتزام مدفوعًا.
- أي Submission أخرى Pending لنفس الالتزام يجب:
  - رفضها تلقائيًا.
  - أو إلغاؤها.
  - أو منع اعتمادها.

اختر تصميمًا واحدًا واضحًا ووثّقه.

---

# 9. Settlement State Handling

عند اعتماد Winner Payment:

- Settlement الحالية فقط هي التي يمكن دفعها.
- بعد الدفع لا يسمح بأي Payment Transaction ناجحة إضافية لنفس Settlement.
- لا يسمح لمحاولة قديمة أن تعتمد بعد تغيير الفائز أو تغيير Current Settlement.
- لا يسمح بمحاولة Settlement غير Current.

في هذه المهمة ركّز على منع الدفع المكرر فقط.

التحقق الكامل من Deadlines والحالات سيتم في TASK 03، لكن لا تترك ثغرة واضحة تسمح بدفع Settlement قديمة إذا كان منع التكرار يعتمد على Current obligation identity.

---

# 10. DTOs وRepositories

التزم بالمعمارية الحالية:

```text
Action decides.
Repository reads and persists.
```

لا تستخدم:

```text
Model::create()
$model->save()
$model->update()
```

داخل Controller أو Action إذا كانت القاعدة الحالية تنقل Persistence للRepository.

استخدم DTOs Typed للعمليات المالية المعقدة عند الحاجة، مثل:

```text
CreatePaymentSubmissionDTO
CreatePaymentTransactionDTO
MarkObligationPaidDTO
```

لكن لا تنشئ Base DTO ضخمة أو Generic Payment DTO.

لا تقم الآن بتنظيف كل Direct Eloquent في المشروع؛ فقط الملفات التي تمس هذه المشكلة.

---

# 11. الأخطاء والرسائل

أضف Domain/Application Exceptions واضحة، مثل:

```text
PaymentObligationAlreadyPaidException
ActivePaymentSubmissionAlreadyExistsException
DuplicateProviderTransactionException
PaymentSubmissionAlreadyReviewedException
PaymentSubmissionDoesNotMatchObligationException
```

استخدم رسائل عربية للمستخدم.

لا تستخدم:

```text
RuntimeException
Exception
```

عامة إذا كان المشروع يملك Central Exception Handling.

---

# 12. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. نفس Submission مرتين

```text
Approve Submission A
Approve Submission A again
```

المتوقع:

- Payment Transaction واحدة.
- الالتزام لا يتحدث مرتين.
- Audit/Outbox لا تتكرر.
- النتيجة Idempotent أو Conflict واضح حسب العقد.

## B. محاولتان مختلفتان لنفس Deposit

```text
Submission A → PendingReview
Submission B → PendingReview أو محاولة إنشاء ثانية
```

اختبر التصميم المختار:

- إما منع إنشاء B.
- أو السماح بها لكن منع اعتماد أكثر من واحدة.

ثم:

```text
Approve A
Approve B
```

المتوقع:

- A فقط تنجح.
- B تفشل بـConflict/Already Paid.
- PaymentTransaction واحدة.
- Deposit مدفوعة مرة واحدة.

## C. محاولتان مختلفتان لنفس Settlement

نفس السيناريو السابق لكن لـWinner Settlement.

## D. Reject ثم Resubmit

```text
Submission A → Rejected
Submission B → Approved
```

المتوقع:

- مسموح.
- PaymentTransaction واحدة.
- Deposit/Settlement في الحالة الصحيحة.

## E. Concurrent Approval على MySQL

شغّل عمليتين متزامنتين على Submissionين مختلفتين لنفس الالتزام.

المتوقع:

- واحدة فقط تنجح.
- الثانية تفشل بدون Corruption.
- قاعدة البيانات تملك PaymentTransaction ناجحة واحدة فقط.

## F. Duplicate Provider Transaction

اعتماد التزامين مختلفين بنفس:

```text
provider + provider_transaction_id
```

يجب أن يفشل الثاني.

## G. Different idempotency keys

أثبت أن اختلاف `idempotency_key` لا يسمح بدفع الالتزام مرتين.

---

# 13. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

مثل:

```text
soom_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard داخل اختبارات الـIntegration يمنع التشغيل على قاعدة لا تنتهي بـ`_testing`.

لا تعتمد على SQLite لإثبات:

- Row locks.
- Unique constraints تحت التزامن.
- Concurrent approvals.

---

# 14. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=AuctionPayment
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionPayment
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

شغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# 15. ممنوعات هذه المهمة

لا تصلح الآن:

- Cancellation refunds.
- Refund processing lifecycle.
- Winner default.
- Non-winner deposits release.
- Seller deposit final lifecycle.
- General Resource privacy.
- Outbox consumers العامة.
- Scheduler concurrency.
- Configuration Snapshot الكاملة.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لمنع الدفع المكرر.

لا تنفذ Refactor واسع.

---

# 16. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_02_PAYMENT_UNIQUENESS_REPORT.md
```

ويحتوي على:

1. السبب الجذري.
2. تعريف Financial Obligation النهائي.
3. كيف يتم ربط Submission بالالتزام.
4. كيف يتم ربط Transaction بالالتزام.
5. Database Constraints الجديدة.
6. كيفية منع Active Submissions المكررة.
7. كيفية منع Successful Payments المكررة.
8. Reject ثم Resubmit Flow.
9. الملفات المنشأة.
10. الملفات المعدلة.
11. Migrations الجديدة.
12. الاختبارات الجديدة.
13. نتائج الاختبارات الفعلية.
14. نتيجة MySQL concurrency test.
15. الأوامر التي شُغلت.
16. أي فحص تعذر تشغيله.
17. أي مخاطر متبقية.

---

# 17. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- لا يمكن أن توجد دفعتان ناجحتان لنفس Deposit.
- لا يمكن أن توجد دفعتان ناجحتان لنفس Settlement.
- اختلاف `idempotency_key` لا يتجاوز القاعدة.
- Concurrent approvals لا تنتجان دفعًا مزدوجًا.
- قاعدة البيانات تفرض القاعدة، وليس الكود فقط.
- Reject ثم Resubmit يعمل.
- Approved obligation لا يقبل Submission ناجحة جديدة.
- Provider Transaction ID لا تتكرر.
- Payment Transaction لا تنشأ مرتين لنفس Submission.
- لا توجد Arrays عشوائية في العمليات المالية الجديدة إذا كان DTO Typed أنسب.
- الاختبارات تعمل على MySQL مخصصة.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
