# TASK 08 — Complete Seller Deposit Lifecycle

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إكمال دورة عربون البائع داخل نظام المزادات بصورة مالية وتشغيلية كاملة، بحيث يكون مصير عربون البائع واضحًا ومحددًا في كل حالة من حالات المزاد، ولا يظل:

```text
Held
PendingReview
Applied
RefundPending
Forfeited
```

بلا سبب أو نهاية واضحة.

المطلوب دعم مصير عربون البائع في الحالات التالية على الأقل:

```text
Auction approved
Seller deposit required
Seller deposit not required
Seller deposit paid
Seller deposit payment rejected ثم resubmitted
Auction rejected
Auction cancelled by seller
Auction cancelled by admin
Auction cancelled by system
Auction unsold
Auction completed
Seller breach
Winner default
Dispute resolution
Platform fault
```

هذه المهمة تخص فقط:

```text
Seller deposit requirement
Seller deposit state transitions
Seller deposit refund policy
Seller deposit forfeiture policy
Seller deposit release triggers
Seller deposit idempotency
Seller deposit reconciliation
```

لا تنفذ الآن:

- Winner Default الكامل.
- General Cancellation refactor.
- General Configuration Snapshot refactor.
- General Outbox refactor.
- General Scheduler refactor.
- Resource privacy.
- Full DTO/Repository cleanup.
- Non-winner deposits.
- Refund provider integration الجديدة.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لإكمال Seller Deposit Lifecycle.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/CreateAuctionAction.php
app/Services/Auction/Actions/ReviewAuctionAction.php
app/Services/Auction/Actions/SubmitPaymentSubmissionAction.php
app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
app/Services/Auction/Actions/CancelAuctionAction.php
app/Services/Auction/Actions/FinalizeAuctionAction.php
app/Services/Auction/Actions/ResolveAuctionDisputeAction.php
app/Services/Auction/Actions/MarkWinnerDefaultedAction.php
app/Services/Auction/Actions/*
app/Repositories/Auction/AuctionDepositRepository.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Repositories/Auction/AuctionRefundRepository.php
app/Repositories/Auction/AuctionRepository.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/Auction.php
app/Models/Auction/PaymentTransaction.php
app/Models/Auction/RefundTransaction.php
app/Domain/Auction/Enums/
app/DTO/Auction/
app/Policies/Auction/
config/auction.php
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

راجع نتائج TASK 02 إلى TASK 07 إن كانت موجودة، ولا تكسر:

- One successful payment per obligation.
- Payment state/deadline validation.
- Refund source of truth.
- Applied amount accounting.
- Refund lifecycle.
- Non-winner deposit release.

لا تعتمد على التقارير السابقة دون مراجعة الكود الفعلي.

---

# 2. المشكلة الحالية التي يجب التحقق منها

الاشتباه الحالي أن مصير Seller Deposit غير مكتمل أو غير موحد في حالات مثل:

```text
Completed
Unsold
Seller cancellation
Admin cancellation
System cancellation
Seller breach
Winner default
Dispute resolution
```

وقد يحدث واحد أو أكثر من الآتي:

- بقاء Seller Deposit في `Held` بلا نهاية.
- ردها في حالة كان يجب مصادرتها.
- مصادرتها في حالة كان يجب ردها.
- إنشاء Refund مرتين.
- عدم وجود Audit للسبب.
- اختلاف السلوك بين CancelAuctionAction وResolveAuctionDisputeAction.
- الاعتماد على حالة المزاد فقط دون معرفة سبب الإلغاء.
- عدم وجود فرق بين:
  ```text
  seller fault
  platform fault
  buyer fault
  neutral outcome
  ```

تحقق من الكود الفعلي أولًا.

---

# 3. تعريف Seller Deposit

Seller Deposit هي التزام مالي على البائع لضمان الجدية أو التزامه بالتسليم.

يجب ألا تُعامل مثل Bidder Deposit.

لا تفترض أن نفس السياسة تنطبق على الاثنين.

يجب أن تكون لها:

```text
deposit_type = seller
owner_id = auction.seller_id
auction_id = auction.id
required_amount_minor
captured payment transaction
status
held_amount_minor
forfeited_amount_minor
refunded_amount_minor
```

وأي Buckets فعلية موجودة بعد المهام السابقة.

---

# 4. متى يكون Seller Deposit مطلوبًا؟

المبلغ المطلوب يأتي من:

```text
Auction Configuration Snapshot
```

وليس من Request البائع.

إذا:

```text
seller_deposit_required_minor = 0
```

فالمطلوب:

- لا تنشئ Deposit مالية بقيمة صفر إلا إذا كان وجود سجل صفري ضروريًا للتتبع ومبررًا.
- لا تسمح Payment Submission بقيمة صفر.
- بعد اعتماد المزاد ينتقل مباشرة إلى:
  ```text
  Scheduled
  ```
  أو الحالة المناسبة حسب باقي الشروط.
- لا يدخل:
  ```text
  AwaitingSellerDeposit
  ```

إذا المبلغ أكبر من صفر:

```text
Approved
→ AwaitingSellerDeposit
```

حتى يتم اعتماد الدفع بنجاح.

---

# 5. حالات Seller Deposit

استخدم الـEnums الفعلية الموجودة، لكن يجب أن يكون السلوك واضحًا.

قد تشمل:

```text
Required
PendingSubmission
PendingReview
Held
RefundPending
RefundProcessing
Refunded
Forfeited
Cancelled
RejectedSubmission
```

لا تضف حالة بلا حاجة.

يجب الفصل بين:

```text
Payment submission rejected
```

و:

```text
Deposit obligation rejected permanently
```

رفض محاولة دفع لا يعني أن Seller Deposit أصبحت منتهية نهائيًا.

يجب السماح بـ:

```text
Submit
→ Reject
→ Resubmit
→ Approve
```

إذا ما زالت المهلة والحالة تسمحان.

---

# 6. سياسة مصير Seller Deposit

يجب أن تكون السياسة واضحة ومقروءة من Snapshot الخاصة بالمزاد أو من مصدر موثوق مؤقتًا إلى أن تُنفذ TASK 11.

لا توزع قواعد المصير داخل أكثر من Action.

أنشئ Rule أو Policy Resolver صغيرة مثل:

```text
SellerDepositDispositionResolver
```

تستقبل:

```text
auction
termination reason
actor type
breach type
current deposit state
```

وترجع قرارًا Typed مثل:

```text
Refund
Forfeit
PartialRefund
KeepHeld
NoAction
```

لا تجعل Resolver تحفظ البيانات.

---

# 7. الحالات المطلوبة بالتفصيل

## A. Auction Rejected Before Seller Deposit Payment

إذا لم يدفع البائع العربون أصلًا:

- أغلق الالتزام.
- لا تنشئ Refund.
- لا تسجل أن المال رُد.
- لا تترك Deposit في PendingSubmission بلا نهاية.

إذا كان قد دفع قبل الرفض لسبب استثنائي:

- طبّق سياسة واضحة.
- غالبًا Refund كاملة ما لم يكن هناك سبب موثق للمصادرة.

---

## B. Seller Deposit Paid and Auction Scheduled

بعد اعتماد الدفع:

```text
Deposit = Held
Auction = Scheduled
```

ولا تُرد في هذه المرحلة.

---

## C. Auction Unsold

حدد السياسة بوضوح.

الافتراض المنطقي غالبًا:

```text
Unsold بسبب عدم وجود مزايدات أو عدم تحقق Reserve
→ Refund Seller Deposit
```

إلا إذا كانت هناك سياسة Platform fee أو administrative charge موثقة.

لا تخترع خصمًا أو مصادرة بلا Configuration.

يجب أن تنشأ Refund Plan واحدة فقط عند Transition إلى Unsold.

---

## D. Auction Completed Successfully

بعد اكتمال التسليم والاستلام:

```text
Seller fulfilled obligation
→ Refund Seller Deposit
```

أو Release/Refund حسب طريقة التحصيل.

إذا تم تحصيل المال فعليًا:

```text
Create Refund Plan
```

لا تغيّر Deposit مباشرة إلى Refunded قبل نجاح Refund.

---

## E. Seller Cancels Before Auction Starts

فرّق بين:

```text
Draft cancellation
Pending review cancellation
Scheduled cancellation
```

إذا لم يُدفع العربون:

- أغلق الالتزام فقط.

إذا دُفع:

- طبّق السياسة المحددة.
- قد تكون:
  ```text
  full refund
  partial forfeiture
  full forfeiture
  ```
- يجب أن يعتمد القرار على سبب الإلغاء والمرحلة.

لا تضع مصادرة ثابتة دون Snapshot/Policy واضحة.

---

## F. Seller Cancels After Auction Starts

هذه حالة أشد.

يجب أن تدعم Policy مثل:

```text
full forfeiture
partial forfeiture
admin review
```

مع:

```text
reason
actor
evidence
audit
```

لا تنفذ خصمًا ماليًا مبهمًا.

إذا كانت السياسة غير موجودة حاليًا، استخدم `ManualReview` بدل اتخاذ قرار مالي خطير تلقائي.

---

## G. Admin Cancellation

فرّق بين:

```text
seller fault
platform fault
legal/compliance issue
fraud suspicion
administrative neutral cancellation
```

المصير يختلف.

أمثلة:

```text
platform fault → refund
seller breach → forfeit
neutral admin cancellation → refund
fraud/compliance → manual review
```

لا تجعل كل Admin cancellation تؤدي لنفس القرار.

---

## H. System Cancellation

مثل:

```text
seller deposit deadline expired
auction setup invalid
system reconciliation decision
```

إذا لم يُدفع العربون:

- أغلق الالتزام.
- لا Refund.

إذا دُفع ثم حدث خطأ من المنصة:

- Refund.

إذا كان الإلغاء بسبب إخلال البائع:

- طبّق forfeiture policy.

---

## I. Winner Default

Winner Default بحد ذاته لا يعني أن البائع أخطأ.

في الغالب:

```text
Seller Deposit تبقى Held
```

حتى:

- اختيار Alternative Winner.
- Unsold.
- Completion.
- Cancellation.
- Dispute resolution.

لا تردها أو تصادرها تلقائيًا لمجرد Default الفائز.

---

## J. Seller Breach

أمثلة:

```text
refusal to hand over
item materially misrepresented
item unavailable
seller no-show
fraud
```

يجب أن تؤدي حسب السياسة إلى:

```text
full forfeiture
partial forfeiture
manual review
```

لا يمكن رد الجزء المصادر لاحقًا إلا عبر Admin reversal مستقل خارج هذه المهمة.

---

## K. Dispute Resolution

يجب أن يكون قرار النزاع قادرًا على تحديد مصير Seller Deposit صراحة:

```text
refund seller deposit
forfeit seller deposit
partial forfeit
keep held pending further action
```

لا تجعل ResolveAuctionDisputeAction تغير Auction status فقط.

لكن لا تنفذ General Cancellation refactor هنا؛ فقط اجعل Seller Deposit disposition تُستدعى من المسار الصحيح.

---

# 8. Partial Forfeiture

إذا كانت السياسة تدعم مصادرة جزئية:

مثال:

```text
captured = 100
forfeited = 30
refundable = 70
```

المطلوب:

```text
forfeited_amount_minor += 30
Refund Plan = 70
```

ولا يجوز:

- رد الـ30.
- تجاوز captured amount.
- إنشاء Refund أكبر من المتاح.

إذا Partial Forfeiture غير موجودة في متطلبات المشروع، لا تضفها من نفسك.

---

# 9. Idempotency

كل Trigger يجب أن يكون Idempotent:

```text
Completed twice
Unsold reconciliation twice
Cancellation retry
Dispute resolution retry
```

النتيجة:

- Refund Plan واحدة.
- Forfeiture واحدة.
- لا تتكرر Audit المالية.
- لا تتكرر Outbox المالية.
- لا تتغير Buckets مرتين.

استخدم:

- Lock على Deposit.
- Existing Refund checks.
- Database constraints.
- Captured source payment.
- Active refund reservation.

---

# 10. Action مركزية

أنشئ Action أو Service صغيرة مثل:

```text
ResolveSellerDepositDispositionAction
```

أو:

```text
ApplySellerDepositPolicyAction
```

تقوم بـ:

1. Lock Auction عند الحاجة.
2. Lock Seller Deposit.
3. Lock source PaymentTransaction.
4. تحديد Trigger/Reason.
5. استدعاء Resolver.
6. التحقق من captured amount.
7. تنفيذ واحد من:
   - KeepHeld
   - CloseWithoutRefund
   - CreateRefundPlan
   - ApplyForfeiture
   - ApplyPartialForfeitureAndRefund
   - ManualReview
8. Audit.
9. Outbox.
10. Commit.

لا تجعل Repository تقرر السياسة.

---

# 11. Refund Integration

استخدم Refund source/lifecycle التي نُفذت في TASK 04 وTASK 06.

لا تنشئ Refund مباشرة بطريقة موازية.

المطلوب:

```text
Seller Deposit refundable
→ create Refund Plan linked to source PaymentTransaction
→ Pending
→ Processing
→ Succeeded
```

لا تغيّر Deposit إلى `Refunded` قبل نجاح Refund.

---

# 12. Forfeiture Integration

عند المصادرة:

داخل Transaction:

1. Lock Deposit.
2. تحقق من captured amount.
3. تحقق من عدم وجود Refund فعالة تغطي نفس المبلغ.
4. حدد المبلغ المصادر.
5. قلل `held_amount_minor` أو Bucket المناسب.
6. زد `forfeited_amount_minor`.
7. حدّث status.
8. Audit.
9. Outbox.
10. Commit.

لا تستخدم Float.

لا تجعل Buckets سالبة.

---

# 13. Reconciliation

أضف Rule أو Query تكشف:

```text
Auction terminal state
+ seller deposit still Held
+ no active reason to keep it
```

وكذلك:

```text
Seller deposit refunded/forfeited incorrectly for auction state
```

يمكن إضافتها إلى `ReconcileAuctionsAction`.

لا تجعل Reconciliation تطبق قرارًا ماليًا تلقائيًا دون Use Case وLocks.

---

# 14. DTOs وRepositories

استخدم DTOs Typed عند الحاجة، مثل:

```text
SellerDepositDispositionDTO
ApplySellerDepositForfeitureDTO
PlanSellerDepositRefundDTO
```

لا تنشئ Generic Deposit Policy DTO.

القاعدة:

```text
Resolver decides disposition.
Action orchestrates.
Repository locks and persists.
Refund service processes actual refund.
```

---

# 15. الاستثناءات والرسائل

أضف Exceptions واضحة، مثل:

```text
SellerDepositDispositionNotAllowedException
SellerDepositAlreadyResolvedException
SellerDepositForfeitureExceedsAvailableException
SellerDepositRefundAlreadyPlannedException
SellerDepositPolicyRequiresManualReviewException
SellerDepositSourcePaymentMissingException
```

الرسائل للمستخدم أو الأدمن تكون عربية.

---

# 16. Audit

سجل أحداثًا مثل:

```text
seller_deposit_required
seller_deposit_payment_approved
seller_deposit_held
seller_deposit_refund_planned
seller_deposit_refunded
seller_deposit_forfeited
seller_deposit_partially_forfeited
seller_deposit_closed_without_payment
seller_deposit_manual_review_required
```

Metadata المهمة:

```text
trigger
reason
policy
captured_amount
refund_amount
forfeited_amount
actor
source_payment_transaction_id
refund_transaction_id
```

لا تكرر Audit عند إعادة التشغيل Idempotently.

---

# 17. Outbox

أنشئ أحداثًا عند الحاجة:

```text
auction.seller_deposit_refund_planned
auction.seller_deposit_refunded
auction.seller_deposit_forfeited
auction.seller_deposit_manual_review
```

تنفيذ Consumers العامة سيكون في TASK 13.

---

# 18. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Zero seller deposit

```text
required = 0
auction approved
```

المتوقع:

- لا Payment Submission.
- لا AwaitingSellerDeposit.
- الانتقال للحالة الصحيحة.

## B. Rejected auction before payment

- Deposit obligation موجودة لكن غير مدفوعة.

المتوقع:

- لا Refund.
- الالتزام يغلق.
- لا Held.

## C. Reject then resubmit payment

```text
submit
reject
resubmit
approve
```

المتوقع:

- Seller Deposit تصبح Held مرة واحدة.
- Payment ناجحة واحدة.

## D. Unsold

- Seller Deposit مدفوعة.
- Auction تصبح Unsold.

المتوقع:

- Refund Plan واحدة حسب السياسة.
- لا Forfeiture بلا سبب.

## E. Completed

- Seller نفذ التسليم.
- Auction Completed.

المتوقع:

- Refund Plan واحدة.
- Deposit لا تصبح Refunded قبل نجاح Refund.

## F. Seller cancellation before start

اختبر السياسة المحددة:

- Refund.
- Partial forfeiture.
- ManualReview.

بحسب Config/Snapshot.

## G. Seller cancellation after start

يجب ألا تُرد تلقائيًا إذا السياسة تتطلب Forfeiture أو ManualReview.

## H. Admin cancellation — platform fault

المتوقع:

- Refund كاملة.

## I. Admin cancellation — seller fault

المتوقع:

- Forfeiture أو ManualReview حسب السياسة.

## J. Winner default

المتوقع:

- Seller Deposit تبقى Held.
- لا Refund ولا Forfeiture تلقائيًا.

## K. Seller breach

المتوقع:

- Forfeiture صحيحة.
- لا Refund للجزء المصادر.

## L. Partial forfeiture

إذا مدعومة:

```text
captured = 100
forfeited = 30
refund = 70
```

المتوقع:

- Buckets متوازنة.
- Refund واحدة.
- لا Over-refund.

## M. Idempotency

شغّل نفس Trigger مرتين.

المتوقع:

- لا Refund ثانية.
- لا Forfeiture ثانية.
- لا Duplicate Audit/Outbox.

## N. Concurrent resolution

شغّل عمليتين متزامنتين على MySQL.

المتوقع:

- Disposition واحدة فقط.
- لا negative buckets.
- لا refund/forfeit double application.

## O. Reconciliation

Terminal auction + Seller Deposit Held بلا سبب.

المتوقع:

- Reconciliation تكتشفها.
- لا تطبق قرارًا ماليًا عشوائيًا خارج Use Case.

---

# 19. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع Integration Tests على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات:

- Concurrent disposition.
- Locks.
- Refund uniqueness.
- Bucket integrity.

---

# 20. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=SellerDeposit
php artisan test --configuration=phpunit.mysql.xml --filter=SellerDeposit
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

وشغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# 21. ممنوعات هذه المهمة

لا تنفذ الآن:

- Winner Default الكامل.
- Non-winner deposit release.
- General cancellation redesign.
- Configuration Snapshot الكاملة.
- General Outbox consumers.
- General Scheduler concurrency.
- Resource privacy.
- Full DTO/Repository cleanup خارج الملفات المطلوبة.

لا تنشئ Refund processor جديدًا؛ استخدم TASK 06.

لا تنشئ Refund source جديدًا؛ استخدم TASK 04.

---

# 22. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_08_SELLER_DEPOSIT_LIFECYCLE_REPORT.md
```

ويحتوي على:

1. Flow القديم.
2. السبب الجذري للمشكلات.
3. الحالات النهائية لـSeller Deposit.
4. سياسة كل Trigger.
5. Zero deposit flow.
6. Payment reject/resubmit flow.
7. Refund flow.
8. Forfeiture flow.
9. Partial forfeiture إن كانت مدعومة.
10. Idempotency design.
11. Reconciliation rule.
12. Actions/Resolvers المنشأة.
13. DTOs المنشأة.
14. Repositories المعدلة.
15. Audit events.
16. Outbox events.
17. الاختبارات الجديدة.
18. نتائج Feature Tests.
19. نتائج MySQL concurrency tests.
20. الأوامر التي شُغلت.
21. أي فحص تعذر تشغيله.
22. المخاطر المؤجلة إلى TASK 09 أو TASK 10 أو TASK 11.

---

# 23. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- Seller Deposit صفرية لا تنشئ Payment Flow.
- Seller Deposit المدفوعة لا تبقى Held بلا نهاية.
- Unsold وCompleted لهما مصير واضح.
- Seller cancellation وAdmin cancellation لا يعاملان بنفس الشكل عشوائيًا.
- Seller breach يمكن أن يؤدي إلى Forfeiture موثقة.
- Winner default لا يرد أو يصادر Seller Deposit تلقائيًا بلا سياسة.
- Refund لا تنشأ دون captured source payment.
- Forfeiture لا تتجاوز المبلغ المتاح.
- لا يمكن Refund الجزء المصادر.
- Disposition Idempotent.
- Concurrent disposition آمنة على MySQL.
- Reconciliation تكتشف Seller Deposits العالقة.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
