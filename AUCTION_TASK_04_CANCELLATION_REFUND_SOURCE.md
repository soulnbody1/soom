# TASK 04 — Cancellation Financial Source of Truth and Double-Refund Prevention

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إصلاح الـFinancial Cancellation Flow داخل نظام المزادات بحيث:

1. لا يتم إنشاء أكثر من Refund لنفس الأموال.
2. لا يتم رد نفس Deposit مرة من سجل الـDeposit ومرة أخرى من PaymentTransaction المرتبطة بها.
3. يكون لكل Refund مصدر مالي واحد واضح وقابل للتتبع.
4. لا تتحول PaymentTransaction إلى `Reversed` أو `Refunded` قبل نجاح Refund فعليًا.
5. تكون عملية الإلغاء Idempotent وآمنة تحت إعادة المحاولة والتزامن.
6. يتم الاحتفاظ بتاريخ كل Payment وRefund دون حذف أو تعديل تاريخي مضلل.

هذه المهمة تخص فقط:

```text
Cancellation financial planning
Refund source selection
Double-refund prevention
Payment reversal timing
Cancellation idempotency
```

لا تنفذ الآن Refund provider lifecycle الكامل، أو Winner Default، أو Seller Deposit final policies، أو Non-winner release، إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لتحقيق هذه المهمة.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/CancelAuctionAction.php
app/Services/Auction/Actions/ResolveAuctionDisputeAction.php
app/Services/Auction/Actions/RefundAuctionDepositAction.php
app/Repositories/Auction/AuctionRefundRepository.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Repositories/Auction/AuctionDepositRepository.php
app/Repositories/Auction/AuctionSettlementRepository.php
app/Models/Auction/PaymentTransaction.php
app/Models/Auction/RefundTransaction.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/AuctionSettlement.php
app/Models/Auction/PaymentSubmission.php
app/Domain/Auction/Enums/
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

لا تعتمد على التقارير السابقة.

ارسم العلاقات الفعلية بين:

```text
AuctionDeposit
PaymentSubmission
PaymentTransaction
RefundTransaction
Settlement
Cancellation
```

قبل التعديل.

---

# 2. المشكلة الحالية التي يجب التحقق منها

الاشتباه الحالي هو أن `CancelAuctionAction` قد تنشئ Refund من:

```text
AuctionDeposit
```

ثم تنشئ Refund أخرى من:

```text
PaymentTransaction
```

لنفس العربون المدفوع.

مثال:

```text
Bidder paid deposit = 100
Deposit refundable amount = 100
PaymentTransaction amount = 100
```

إذا أُنشئت Refund من الاثنين:

```text
Refund A = 100 from Deposit
Refund B = 100 from PaymentTransaction
Total planned refund = 200
```

بينما المبلغ الحقيقي المدفوع هو 100 فقط.

تحقق من الكود الفعلي، ثم أصلح السبب الجذري.

---

# 3. تحديد المصدر المالي الحقيقي للـRefund

يجب اختيار Source of Truth واحد وواضح.

التصميم المفضل:

```text
RefundTransaction ترتبط بالـPaymentTransaction الأصلية التي استقبلت الأموال.
```

ويكون الـDeposit أو Settlement هو الالتزام المالي، وليس مصدر الأموال نفسه.

بمعنى:

```text
Deposit / Settlement = financial obligation
PaymentTransaction = captured money
RefundTransaction = reversal of captured money
```

إذا كان الكود الحالي يستخدم Refund مرتبطة مباشرة بـDeposit، يمكنك الحفاظ على المرجع المحاسبي للـDeposit، لكن يجب أن يوجد أيضًا مرجع واضح للـPaymentTransaction الأصلية.

يجب ألا تنشأ Refund قابلة للتنفيذ دون معرفة:

```text
ما هي الدفعة الأصلية التي سيتم ردها؟
```

إذا كانت هناك حالة Manual payment بلا PaymentTransaction أصلية، وثّقها وصمم Manual Refund Source واضحًا، ولا تخلطها مع Provider payment.

---

# 4. التصميم المطلوب للـRefund Source

يجب أن تحتوي RefundTransaction على مرجع مالي واضح مثل:

```text
source_payment_transaction_id
obligation_type
obligation_id
```

أو تصميم مكافئ موجود فعليًا في المشروع.

المهم:

- كل Refund تعرف الدفعة الأصلية.
- كل Refund تعرف الالتزام المرتبط.
- لا يمكن إنشاء Refundين لنفس الجزء المالي.
- يمكن حساب:
  ```text
  refundable_amount =
  original_successful_payment
  - succeeded_refunds
  - active_refunds
  ```
- لا تعتمد على `deposit.held_amount_minor` وحده كإثبات أن المال دُفع فعليًا.

---

# 5. منع Refund مزدوجة على مستوى قاعدة البيانات

لا تعتمد على Application checks فقط.

أضف Constraints مناسبة، مثل:

```text
UNIQUE(source_payment_transaction_id, refund_sequence_or_full_refund_marker)
```

إذا كان النظام يسمح Full Refund واحدة فقط.

أو إذا كان يدعم Partial Refunds:

- اسمح بعدة Refunds.
- لكن امنع تجاوز المبلغ الأصلي.
- استخدم Lock على PaymentTransaction.
- احسب المبالغ داخل Transaction.
- امنع Active duplicate refund لنفس الجزء.

إذا لم يكن Partial Refund مدعومًا حاليًا، لا تضفه ضمن هذه المهمة.

طبّق:

```text
One full refund per successful payment transaction
```

إلا إذا أثبت الكود أن Partial Refund جزء حقيقي من النظام.

---

# 6. Cancellation Refund Planning

داخل Cancellation transaction:

1. Lock Auction.
2. Lock جميع Deposits المتعلقة.
3. Lock Settlements المتعلقة.
4. Lock Successful PaymentTransactions المتعلقة.
5. اجلب Existing RefundTransactions.
6. حدد ما الذي يستحق الرد حسب الحالة الحالية.
7. لكل مبلغ:
   - حدد PaymentTransaction الأصلية.
   - تحقق أنه لم يُرد.
   - تحقق أنه لا توجد Refund فعالة أو ناجحة تغطيه.
8. أنشئ Refund Plan مرة واحدة فقط.
9. لا تغيّر PaymentTransaction إلى Refunded/Reversed.
10. حدّث الالتزامات إلى حالة مثل:
    ```text
    RefundPending
    ```
    عند الحاجة.
11. Audit.
12. Outbox إن كان موجودًا حاليًا.
13. Commit.

إعادة تنفيذ Cancellation يجب ألا تنشئ Refunds جديدة.

---

# 7. حالة PaymentTransaction

المطلوب:

## عند نجاح الدفع

```text
PaymentTransaction.status = Succeeded
```

## عند إنشاء Refund Pending

تظل:

```text
PaymentTransaction.status = Succeeded
```

ولا تتحول إلى:

```text
Reversed
Refunded
```

لأن المال لم يُرد بعد.

## عند نجاح Refund كاملة

يمكن تحويلها إلى:

```text
Refunded
```

أو:

```text
Reversed
```

وفق الـEnum الحالي، لكن فقط بعد نجاح Refund.

## عند Refund جزئية

إذا كان النظام لا يدعم Partial Refund، لا تضفها.

إذا كان موجودًا فعلًا، استخدم حالة مثل:

```text
PartiallyRefunded
```

ولا تخترعها بلا حاجة.

---

# 8. حالات RefundTransaction

في هذه المهمة استخدم الحالات الموجودة فعليًا، لكن يجب أن يكون الفرق واضحًا بين:

```text
Pending
Processing
Succeeded
Failed
Cancelled
```

إنشاء Refund Plan يعني:

```text
RefundTransaction = Pending
```

ولا يعني أن PaymentTransaction عُكست.

---

# 9. Idempotency

يجب أن تكون Cancellation Idempotent على مستويين:

## Request Idempotency

نفس طلب الإلغاء يتكرر بنفس المفتاح.

## Business Idempotency

طلبان مختلفان أو عاملان مختلفان يحاولان إلغاء نفس المزاد.

النتيجة:

- Auction واحدة تصبح Cancelled.
- Refund Plan واحدة فقط لكل مبلغ.
- لا تتكرر Audit entries الحساسة دون حاجة.
- لا تتكرر Outbox messages.
- لا تتغير PaymentTransaction أكثر من مرة.

استخدم Database constraints وLocks.

---

# 10. الإلغاء من أكثر من مسار

راجع:

```text
CancelAuctionAction
ResolveAuctionDisputeAction with cancel resolution
System cancellation jobs
Admin cancellation
Seller cancellation
```

في هذه المهمة لا تعِد بناء كل سياسات الإلغاء، لكن يجب ألا يوجد مسار ينشئ Refunds بطريقة مختلفة تؤدي للتكرار.

إذا كان `ResolveAuctionDisputeAction` ينفذ إلغاء مالي مستقلًا، وجّه التنفيذ المالي إلى Application Service أو Method مركزية واحدة.

لا تنقل Business Policy إلى Repository.

---

# 11. التعامل مع Deposit وSettlement

## Deposit

الـDeposit لا تمثل المال المقبوض وحدها.

لا تنشئ Refund فقط لأن:

```text
held_amount_minor > 0
```

بدون التحقق من PaymentTransaction الأصلية الناجحة.

## Settlement

إذا كان هناك Winner payment ناجحة:

- Refund ترتبط بالـPaymentTransaction الخاصة بها.
- Settlement تتحول إلى الحالة المناسبة بعد إنشاء خطة الإلغاء.
- لا تعدّل PaymentTransaction إلى Refunded قبل نجاح Refund.

## Deposit applied to settlement

في هذه المهمة:

- لا تنفذ بعد منطق رد `applied_amount_minor` بالكامل؛ هذا سيكون TASK 05.
- لكن لا تنشئ Refund مزدوجة لهذا المبلغ.
- إذا اكتشفت مبلغًا Applied يحتاج ردًا، أنشئ Plan واضحًا أو اتركه بحالة تحتاج TASK 05، ووثّق ذلك.
- لا تدّعِ اكتمال رد Applied amount في هذه المهمة إن لم تنفذه.

---

# 12. DTOs وRepositories

التزم بالمعمارية:

```text
Action decides.
Repository locks and persists.
```

استخدم DTOs Typed عند الحاجة، مثل:

```text
CreateCancellationRefundDTO
CreateRefundTransactionDTO
MarkPaymentRefundPendingDTO
```

لا تنشئ Generic Financial DTO.

لا تستخدم Arrays كبيرة غير Typed إذا كان DTO أوضح.

لا تنقل سياسة الإلغاء إلى Repository.

---

# 13. الاستثناءات والرسائل

أنشئ Exceptions واضحة، مثل:

```text
RefundAlreadyPlannedException
PaymentAlreadyFullyRefundedException
RefundSourcePaymentMissingException
RefundAmountExceedsCapturedPaymentException
DuplicateCancellationRefundException
```

الرسائل للمستخدم تكون عربية.

لا تستخدم Exception عامة.

---

# 14. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Deposit paid via PaymentTransaction

السيناريو:

```text
Deposit required = 100
PaymentTransaction succeeded = 100
Cancel auction
```

المتوقع:

- RefundTransaction واحدة فقط.
- amount = 100.
- مرتبطة بالـPaymentTransaction الأصلية.
- لا توجد Refund ثانية من Deposit.
- PaymentTransaction تظل Succeeded أثناء Pending Refund.

## B. إعادة Cancellation

```text
Cancel auction
Run cancellation again
```

المتوقع:

- لا تنشأ Refund ثانية.
- لا تتكرر Financial plan.
- لا يتغير PaymentTransaction بشكل خاطئ.

## C. Concurrent cancellation

شغّل عمليتين متزامنتين على MySQL.

المتوقع:

- Refund واحدة.
- Auction state صحيحة.
- لا Duplicate key corruption.
- لا مبلغ مضاعف.

## D. Pending refund

بعد إنشاء Refund Pending:

```text
PaymentTransaction.status = Succeeded
```

وليس Reversed/Refunded.

## E. Successful refund

بعد نجاح Refund:

- PaymentTransaction تتحول للحالة المالية المناسبة.
- لا تتكرر عند إعادة confirm success.
- هذا الاختبار يمكن أن يستخدم Flow الموجود دون إعادة بناء Lifecycle كاملة.

## F. Deposit without successful payment

إذا وجد Deposit بدون PaymentTransaction ناجحة:

- لا تنشئ Refund مالية حقيقية.
- إما لا يوجد Refund.
- أو تسجل ManualReview حسب التصميم الحالي.
- لا تفترض أن المال تم تحصيله.

## G. Settlement payment

```text
Winner payment succeeded
Cancel auction
```

المتوقع:

- Refund واحدة للـPaymentTransaction الخاصة بالـSettlement.
- لا Refund إضافية من Settlement amount fields.

## H. Combined deposit + settlement payment

إذا دفع المستخدم:

```text
Deposit = 10
Winner remaining payment = 90
```

ثم ألغي المزاد:

المتوقع:

- Refund للـ10 مرتبطة بدفعة العربون الأصلية.
- Refund للـ90 مرتبطة بدفعة التسوية الأصلية.
- الإجمالي = 100.
- ليس 110 أو 200.
- لا يوجد تداخل.

---

# 15. بيئة الاختبار

استخدم MySQL مخصصة تنتهي بـ:

```text
_testing
```

ولا تستخدم قاعدة التطوير.

أضف Guard يمنع الاختبارات على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات:

- Locks.
- Concurrent cancellation.
- Unique constraints.

---

# 16. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=CancellationRefund
php artisan test --configuration=phpunit.mysql.xml --filter=CancellationRefund
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

وشغّل Syntax check للملفات المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# 17. ممنوعات هذه المهمة

لا تنفذ الآن:

- Refund provider retries.
- Refund backoff/lease الكامل.
- Applied deposit refund accounting الكامل.
- Winner default.
- Seller deposit final policy.
- Non-winner release.
- General Resource cleanup.
- General Outbox consumer implementation.
- Scheduler concurrency العامة.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لمنع Double Refund.

لا تنفذ Refactor واسع.

---

# 18. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_04_CANCELLATION_REFUND_SOURCE_REPORT.md
```

ويحتوي على:

1. السبب الجذري للـDouble Refund.
2. Source of Truth النهائي.
3. العلاقة النهائية بين Deposit وPaymentTransaction وRefundTransaction.
4. كيفية حساب Refundable amount.
5. Database constraints الجديدة.
6. كيفية منع تكرار Refund Plan.
7. حالة PaymentTransaction أثناء Pending Refund.
8. متى تصبح Refunded/Reversed.
9. الملفات المنشأة.
10. الملفات المعدلة.
11. Migrations الجديدة.
12. الاختبارات الجديدة.
13. نتائج Feature Tests.
14. نتائج MySQL concurrency test.
15. الأوامر التي شُغلت.
16. أي فحص تعذر تشغيله.
17. النقاط المؤجلة إلى TASK 05 أو TASK 06.

---

# 19. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- لا يمكن إنشاء Refund من Deposit وPaymentTransaction لنفس المال.
- كل Refund لها PaymentTransaction أصلية واضحة أو Manual source موثق.
- Cancellation لا تنشئ Refund Plan مرتين.
- إعادة تنفيذ Cancellation لا تكرر Refunds.
- Concurrent cancellation تنتج Refund واحدة.
- PaymentTransaction لا تصبح Reversed/Refunded عند إنشاء Pending Refund.
- PaymentTransaction تتغير فقط بعد نجاح Refund.
- لا يتم رد مبلغ أكبر من الأموال المقبوضة.
- اختبارات Combined deposit + settlement تثبت الإجمالي الصحيح.
- MySQL concurrency test ناجح.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.