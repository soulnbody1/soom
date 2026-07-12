# TASK 05 — Refund Applied Deposit Amounts Correctly

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إصلاح المحاسبة الخاصة بعربونات المزادات التي تم تطبيقها على Settlement، بحيث يمكن:

1. رد المبلغ الموجود في:
   ```text
   applied_amount_minor
   ```
   عندما تُلغى أو تُبطل Settlement ويصبح هذا المبلغ قابلًا للرد.

2. منع رد أي مبلغ:
   - سبق رده.
   - تمت مصادرته.
   - ما زال مستخدمًا في Settlement فعالة.
   - غير مدفوع أصلًا.

3. الحفاظ على توازن الحقول المالية داخل Deposit.

4. جعل عملية رد العربون Idempotent وآمنة تحت التزامن.

5. عدم خلط:
   ```text
   held_amount_minor
   applied_amount_minor
   forfeited_amount_minor
   refunded_amount_minor
   ```

هذه المهمة تخص فقط:

```text
Applied deposit refund accounting
Deposit bucket transitions
Refundable amount calculation
Deposit refund confirmation
Settlement invalidation relationship
```

لا تنفذ الآن Refund provider lifecycle الكامل أو Retry/Backoff أو Seller Deposit final policy أو Winner Default بالكامل إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لهذه المهمة.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/RefundAuctionDepositAction.php
app/Services/Auction/Actions/CancelAuctionAction.php
app/Services/Auction/Actions/FinalizeAuctionAction.php
app/Services/Auction/Actions/MarkWinnerDefaultedAction.php
app/Repositories/Auction/AuctionDepositRepository.php
app/Repositories/Auction/AuctionRefundRepository.php
app/Repositories/Auction/AuctionSettlementRepository.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/RefundTransaction.php
app/Models/Auction/AuctionSettlement.php
app/Models/Auction/PaymentTransaction.php
app/Domain/Auction/Enums/
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

لا تعتمد على التقارير السابقة.

ارسم Flow فعلي للمبالغ داخل Deposit:

```text
required_amount_minor
held_amount_minor
applied_amount_minor
forfeited_amount_minor
refunded_amount_minor
```

واشرح كيف تنتقل القيم بين هذه الحقول في الكود الحالي.

---

# 2. المشكلة الحالية التي يجب التحقق منها

الاشتباه الحالي:

- `CancelAuctionAction` أو Flow آخر قد ينشئ Refund تشمل:
  ```text
  applied_amount_minor
  ```
- لكن `RefundAuctionDepositAction::confirmSucceeded()` تتحقق فقط من:
  ```text
  held_amount_minor
  ```
- وبالتالي Refund تخص مبلغًا Applied تفشل عند التأكيد.

مثال:

```text
Deposit paid = 100
held_amount_minor = 0
applied_amount_minor = 100
refund planned = 100
```

ثم:

```text
refund amount > held_amount_minor
```

فتُرفض العملية رغم أن المبلغ مدفوع وقابل للرد بعد إبطال Settlement.

تحقق من السبب الجذري في الكود الفعلي ثم أصلحه.

---

# 3. تعريف Buckets المالية

يجب أن يكون معنى كل حقل واضحًا وثابتًا:

## held_amount_minor

مبلغ مدفوع ومحتجز، لكنه لم يُطبق بعد على Settlement ولم يُرد ولم يُصادر.

## applied_amount_minor

مبلغ تم استخدامه لتقليل ما يدفعه الفائز داخل Settlement.

## forfeited_amount_minor

مبلغ تمت مصادرته نهائيًا حسب سياسة موثقة، ولا يمكن رده.

## refunded_amount_minor

مبلغ تم رده بنجاح فعليًا.

## required_amount_minor

المبلغ المطلوب الأصلي للعربون.

يجب ألا تتداخل هذه القيم بطريقة تجعل نفس المال محسوبًا في أكثر من Bucket بصورة نهائية.

---

# 4. معادلة سلامة Deposit

عرّف Invariant واضحة حسب التصميم الفعلي.

المبدأ العام:

```text
held
+ applied
+ forfeited
+ refunded
<= total amount successfully paid for this deposit
```

وإذا كان الدفع كاملًا ومغلقًا:

```text
held
+ applied
+ forfeited
+ refunded
= total captured deposit amount
```

لا تستخدم `required_amount_minor` وحدها كدليل على أن المال تم تحصيله.

استخدم PaymentTransaction الناجحة كمصدر للأموال المقبوضة.

أضف Validation/Assertion داخل Domain أو Action عند التحديثات الحساسة.

---

# 5. متى يكون Applied Amount قابلًا للرد؟

المبلغ الموجود في:

```text
applied_amount_minor
```

لا يكون قابلًا للرد ما دامت Settlement:

```text
Current
Active
Paid أو PaymentPending
مرتبطة بالفائز الحالي
```

يصبح قابلًا للرد فقط عندما يحدث سبب واضح مثل:

```text
Settlement voided
Auction cancelled
Dispute resolution requiring refund
Winner result revoked
Administrative financial reversal
```

لا تجعل أي Action ترد Applied amount دون إبطال أو إلغاء أثر الـSettlement أولًا.

---

# 6. الترتيب المحاسبي الصحيح عند رد Applied Amount

داخل Transaction واحدة:

1. Lock AuctionDeposit.
2. Lock Current/related Settlement.
3. Lock RefundTransaction.
4. Lock source PaymentTransaction.
5. تأكد أن Settlement لم تعد فعالة أو تم Void لها.
6. احسب Applied amount القابل للرد.
7. تأكد أن المبلغ:
   - لم يُرد.
   - لم يُصادر.
   - لا يزال موجودًا في applied bucket.
8. عند نجاح Refund فعليًا:
   - قلل `applied_amount_minor`.
   - زد `refunded_amount_minor`.
9. لا تنقل المبلغ إلى `held_amount_minor` إلا إذا كان التصميم يتطلب مرحلة وسيطة واضحة.
10. لا تعدّل Seller net أو Platform fee هنا إلا إذا كان ذلك ضروريًا لمعادلة Settlement، وعندها وثّق التعديل.
11. Audit.
12. Outbox إن كان موجودًا في Flow الحالي.
13. Commit.

يجب أن يكون التغيير ذريًا.

---

# 7. Refundable Amount Calculation

أنشئ Method أو Rule واضحة، مثل:

```text
calculateRefundableDepositAmount()
```

لكن لا تجعلها تعتمد على حقول Deposit فقط.

يجب أن تراعي:

```text
captured amount
succeeded refunds
active pending refunds
forfeited amount
currently applied amount
currently held amount
settlement state
```

المعادلة المفاهيمية:

```text
refund_available =
captured_amount
- succeeded_refunds
- active_refunds
- forfeited_amount
- non-refundable applied amount
```

وعند Void Settlement:

```text
applied amount may become refundable
```

لا تستخدم Float.

---

# 8. RefundTransaction Allocation

إذا كانت Refund تغطي جزءًا من held وجزءًا من applied، لا تعتمد على استنتاج لاحق غامض.

احفظ Allocation واضحًا، مثل:

```text
held_refund_amount_minor
applied_refund_amount_minor
```

أو JSON allocation موثوق ومختبر، أو تصميم مكافئ Typed.

الأفضل أعمدة صريحة إذا كانت الحاجة ثابتة.

مثال:

```text
refund amount = 100
held portion = 20
applied portion = 80
```

وعند النجاح:

```text
held -= 20
applied -= 80
refunded += 100
```

لا تخصم المبلغ كله من Bucket واحدة بالخطأ.

---

# 9. منع رد المبلغ المصادر

إذا:

```text
forfeited_amount_minor > 0
```

فهذا الجزء غير قابل للرد، إلا عبر Admin reversal مستقل خارج نطاق هذه المهمة.

لا تجعل Cancellation أو Refund Action تعيد مبلغًا مصادرًا تلقائيًا.

أضف اختبارًا واضحًا.

---

# 10. منع Over-Refund

داخل نفس Transaction:

1. اجمع Refunds الناجحة.
2. اجمع Refunds الفعالة:
   ```text
   Pending
   Processing
   ```
3. احسب المتاح.
4. ارفض أي مبلغ أكبر من المتاح.
5. استخدم Lock على Deposit وPaymentTransaction.

لا تعتمد على Query قبل Transaction.

---

# 11. Idempotency

`confirmSucceeded()` يجب أن تكون Idempotent.

إذا كانت Refund:

```text
Succeeded
```

ثم استُدعيت مرة أخرى:

- لا تخصم من `held_amount_minor` مرة ثانية.
- لا تخصم من `applied_amount_minor` مرة ثانية.
- لا تزيد `refunded_amount_minor` مرة ثانية.
- لا تكرر Audit أو Outbox الحساسة.
- أرجع النتيجة الحالية بأمان.

استخدم Database state وLocks.

---

# 12. علاقة Settlement بالحسابات

عند Void Settlement التي استخدمت Deposit:

- لا تترك:
  ```text
  deposit_applied_minor
  ```
  داخل Settlement القديمة وكأنه ما زال فعالًا دون توضيح.
- احتفظ بالتاريخ، لكن أضف حالة/metadata تدل أن Settlement:
  ```text
  Voided
  Superseded
  Cancelled
  ```
- لا تعدّل التاريخ المالي ليبدو كأن العربون لم يُطبق أصلًا.
- يجب أن تظل Audit trail واضحة:
  ```text
  Applied at settlement creation
  Later reversed/refunded after void
  ```

---

# 13. DTOs وRepositories

استخدم DTOs Typed عند الحاجة، مثل:

```text
ConfirmDepositRefundDTO
DepositRefundAllocationDTO
UpdateDepositBucketsDTO
```

لكن لا تنشئ Generic Refund DTO.

القاعدة:

```text
Action decides accounting transition.
Repository locks and persists exact values.
```

لا تضع Business Logic داخل Repository.

---

# 14. الاستثناءات والرسائل

أضف Exceptions واضحة، مثل:

```text
AppliedDepositStillInActiveSettlementException
DepositRefundAmountExceedsAvailableException
ForfeitedDepositIsNotRefundableException
DepositRefundAllocationMismatchException
DepositAlreadyFullyRefundedException
RefundAlreadySucceededException
```

الرسائل للمستخدم تكون عربية.

أمثلة:

```text
لا يمكن رد العربون المطبق على تسوية ما زالت فعالة.
قيمة المبلغ المطلوب رده تتجاوز المبلغ المتاح.
لا يمكن رد الجزء المصادر من العربون.
توزيع مبلغ الرد لا يطابق قيمة عملية الاسترداد.
```

---

# 15. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Refund held amount only

```text
held = 100
applied = 0
refund = 100
```

المتوقع بعد النجاح:

```text
held = 0
applied = 0
refunded = 100
```

## B. Refund applied amount after settlement void

```text
held = 0
applied = 100
settlement = Voided
refund = 100
```

المتوقع:

```text
held = 0
applied = 0
refunded = 100
```

## C. Active settlement blocks applied refund

```text
applied = 100
settlement = Current/PaymentPending
```

المتوقع:

```text
Refund rejected
```

## D. Mixed allocation

```text
held = 20
applied = 80
refund = 100
```

بعد Void Settlement:

```text
held = 0
applied = 0
refunded = 100
```

## E. Forfeited amount

```text
forfeited = 50
```

لا يمكن رده.

## F. Over-refund

```text
captured = 100
already refunded = 70
new refund = 40
```

المتوقع:

```text
Rejected
```

## G. Pending refund reservation

```text
captured = 100
pending refund = 60
new refund = 50
```

المتوقع:

```text
Rejected
```

لأن Active Refunds تحجز المبلغ.

## H. Idempotent success

استدعِ confirm success مرتين.

المتوقع:

- buckets تتغير مرة واحدة.
- refunded amount لا تتضاعف.
- Audit/Outbox لا تتكرر.

## I. Concurrent confirmation

شغّل عمليتين متزامنتين على MySQL لنفس Refund.

المتوقع:

- عملية مالية واحدة فقط.
- لا negative buckets.
- لا double refunded amount.

## J. Combined historical settlement

- Settlement قديمة Voided.
- Settlement جديدة Current.
- Deposit القديمة مرتبطة بالتسوية القديمة.
- تأكد أن رد applied amount لا يؤثر على Settlement الجديدة أو Deposit الفائز الجديد.

---

# 16. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع Integration Tests على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات:

- Locks.
- Concurrent confirmation.
- Unique constraints.
- Bucket integrity تحت التزامن.

---

# 17. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=AppliedDepositRefund
php artisan test --configuration=phpunit.mysql.xml --filter=AppliedDepositRefund
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

وشغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أمر لم يتم تشغيله.

---

# 18. ممنوعات هذه المهمة

لا تنفذ الآن:

- Refund provider retry/backoff.
- Manual refund administration الكامل.
- Seller Deposit final lifecycle.
- Winner Default الكامل.
- Non-winner deposits release.
- General Outbox consumers.
- Scheduler concurrency العامة.
- Resource privacy العامة.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة للحساب الصحيح للعربون المطبق.

لا تنفذ Refactor واسع.

---

# 19. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_05_APPLIED_DEPOSIT_REFUND_REPORT.md
```

ويحتوي على:

1. السبب الجذري للمشكلة.
2. تعريف كل Deposit bucket.
3. الـInvariants المالية النهائية.
4. متى يصبح Applied amount قابلًا للرد.
5. تصميم Refund allocation.
6. كيفية حساب Refundable amount.
7. كيفية منع Forfeited refund.
8. كيفية منع Over-refund.
9. كيفية ضمان Idempotency.
10. الملفات المنشأة.
11. الملفات المعدلة.
12. Migrations الجديدة.
13. الاختبارات الجديدة.
14. نتائج Feature Tests.
15. نتائج MySQL concurrency test.
16. الأوامر التي شُغلت.
17. أي فحص تعذر تشغيله.
18. النقاط المؤجلة إلى TASK 06.

---

# 20. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- يمكن رد `applied_amount_minor` بعد Void Settlement.
- لا يمكن رده أثناء Settlement فعالة.
- لا يمكن رد `forfeited_amount_minor`.
- Refund allocation واضحة بين held وapplied.
- `refunded_amount_minor` لا تتضاعف.
- لا تصبح buckets سالبة.
- Pending refunds تحجز المبلغ.
- Over-refund ممنوعة.
- العملية Idempotent.
- Concurrent confirmations لا تفسد الحسابات.
- Settlement history تظل صحيحة.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
