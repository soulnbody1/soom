# TASK 10 — Central Financial Cancellation Service

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

توحيد جميع مسارات إلغاء المزاد داخل Use Case مالية مركزية واحدة، بحيث لا يوجد أي مسار يغيّر:

```text
Auction.status = Cancelled
```

دون معالجة كاملة لكل الآثار المالية المرتبطة.

المطلوب أن تستخدم جميع مصادر الإلغاء نفس الـFinancial Cancellation Flow، مع اختلاف السبب والسياسة فقط.

المصادر التي يجب مراجعتها على الأقل:

```text
Seller cancellation
Admin cancellation
System cancellation
Dispute resolution cancellation
Compliance/fraud cancellation
Automatic cancellation jobs
```

هذه المهمة تخص فقط:

```text
Central cancellation orchestration
Cancellation reason and actor
Financial cancellation plan
Deposit/payment/settlement effects
Reuse by all cancellation entry points
Idempotency and concurrency
```

لا تنفذ الآن:

- Configuration Snapshot الكاملة.
- General Outbox consumers.
- General Scheduler refactor.
- Resource privacy.
- Full DTO/Repository cleanup.
- Refund provider lifecycle الجديدة.
- Winner Default الجديدة.
- Seller Deposit policy الجديدة.

استخدم ما تم تنفيذه في TASK 04 إلى TASK 09 بدل إنشاء Flows مالية موازية.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/CancelAuctionAction.php
app/Services/Auction/Actions/ResolveAuctionDisputeAction.php
app/Services/Auction/Actions/MarkWinnerDefaultedAction.php
app/Services/Auction/Actions/FinalizeAuctionAction.php
app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
app/Services/Auction/Actions/*
app/Jobs/Auction/*
app/Repositories/Auction/AuctionRepository.php
app/Repositories/Auction/AuctionDepositRepository.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Repositories/Auction/AuctionRefundRepository.php
app/Repositories/Auction/AuctionSettlementRepository.php
app/Models/Auction/Auction.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/AuctionSettlement.php
app/Models/Auction/PaymentTransaction.php
app/Models/Auction/RefundTransaction.php
app/Models/Auction/PaymentSubmission.php
app/Domain/Auction/Enums/
app/Domain/Auction/Rules/
app/Domain/Auction/Exceptions/
app/DTO/Auction/
app/Policies/Auction/
app/Http/Requests/Auction/
app/Http/Controllers/Auction/
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

راجع نتائج TASK 04 إلى TASK 09 إن كانت موجودة، ولا تكسر:

- Refund source of truth.
- Double-refund prevention.
- Applied deposit refund accounting.
- Refund lifecycle.
- Non-winner release.
- Seller deposit lifecycle.
- Winner default flow.

لا تعتمد على التقارير السابقة دون مراجعة الكود الفعلي.

---

# 2. المشكلة الحالية التي يجب التحقق منها

الاشتباه الحالي أن هناك أكثر من مسار للإلغاء، مثل:

```text
CancelAuctionAction
ResolveAuctionDisputeAction with cancel resolution
Admin cancellation
System job
```

وبعض هذه المسارات قد يفعل فقط:

```text
transition auction to Cancelled
```

دون:

- إغلاق Settlement.
- إلغاء أو Supersede Payment Submissions.
- إنشاء Refund Plans.
- معالجة Seller Deposit.
- معالجة Bidder Deposits.
- معالجة Winner Payment.
- معالجة Platform fees.
- Audit مالي كامل.
- Outbox events.

تحقق من الكود الفعلي أولًا.

---

# 3. تصميم مركزي واحد

أنشئ Application Service أو Action مركزية واضحة مثل:

```text
ExecuteAuctionCancellationAction
```

أو:

```text
CancelAuctionFinanciallyAction
```

وتكون هي المسؤولة الوحيدة عن التطبيق المالي الكامل للإلغاء.

المسارات الأخرى لا تنفذ آثارًا مالية بنفسها.

بدلًا من ذلك تستدعي المركزية بمدخل Typed مثل:

```text
AuctionCancellationContextDTO
```

يشمل مثلًا:

```text
auctionId
trigger
actorId
actorType
reasonCode
reasonText
disputeId
requestedAt
metadata
```

لا تجعل DTO Generic لكل المشروع.

---

# 4. أنواع Trigger المطلوبة

استخدم Enum واضحًا مثل:

```text
SellerRequested
AdminRequested
SystemTriggered
DisputeResolved
Compliance
Fraud
PlatformFault
SellerBreach
BuyerFault
NeutralAdministrative
```

لا تستخدم String عشوائية.

يجب أن يعرف الـFlow:

```text
من ألغى؟
لماذا؟
في أي حالة؟
من المتسبب؟
هل السبب مالي أم إداري؟
```

هذه المعلومات تؤثر على:

- Seller deposit disposition.
- Bidder deposits.
- Winner payment refunds.
- Fees.
- Manual review.

---

# 5. شروط السماح بالإلغاء

أنشئ Rule مركزية تحدد هل الإلغاء مسموح في الحالة الحالية.

راجع الحالات الفعلية، لكن يجب أن تكون القاعدة واضحة.

مثلًا:

```text
Draft
PendingReview
AwaitingSellerDeposit
Scheduled
Live
Ended
SettlementPending
PaymentPending
HandoverPending
Disputed
```

قد تسمح بالإلغاء بشروط مختلفة.

لكن لا تسمح بالإلغاء الصامت في:

```text
Completed
Cancelled
```

إلا عبر Administrative reversal مستقل خارج هذه المهمة.

يجب أن تتطابق:

```text
Policy
Action
State Machine
Cancellation Rule
```

---

# 6. Transaction Boundary

داخل Transaction واحدة:

1. Lock Auction.
2. Recheck current status.
3. Recheck cancellation permission/business rule.
4. Lock Current Settlement إن وجدت.
5. Lock Seller Deposit.
6. Lock Bidder Deposits.
7. Lock Payment Submissions المرتبطة.
8. Lock Successful PaymentTransactions المرتبطة.
9. Lock Existing RefundTransactions.
10. بناء Cancellation Financial Plan.
11. تطبيق Seller Deposit disposition عبر TASK 08.
12. تطبيق Bidder Deposit release/refund عبر TASK 07.
13. تطبيق Winner payment refund plan عبر TASK 04.
14. معالجة Applied deposit عبر TASK 05.
15. Supersede/Cancel pending submissions.
16. Void/Cancel current settlement.
17. Update Auction status.
18. Audit.
19. Outbox.
20. Commit.

لا تنفذ Provider API calls داخل Transaction.

Refund processing الفعلية تظل لاحقًا عبر TASK 06.

---

# 7. Cancellation Financial Plan

أنشئ DTO أو Value Object واضحًا يمثل الخطة قبل تنفيذها، مثل:

```text
AuctionCancellationPlan
```

وتحتوي مثلًا:

```text
sellerDepositDisposition
bidderDepositDispositions
winnerPaymentRefunds
settlementDisposition
paymentSubmissionDispositions
manualReviewRequired
auditMetadata
```

يجب أن تكون الخطة Typed وقابلة للاختبار.

لا تجعلها Array ضخمة غير موثقة.

---

# 8. معالجة Seller Deposit

لا تعِد كتابة قواعد Seller Deposit.

استخدم Resolver/Action التي تم تنفيذها في TASK 08.

يجب تمرير Trigger الصحيح، مثل:

```text
SellerRequested
PlatformFault
SellerBreach
NeutralAdministrative
DisputeResolved
```

والنتيجة قد تكون:

```text
Refund
Forfeit
PartialForfeit
KeepHeld
ManualReview
NoAction
```

لا ترد أو تصادر العربون مباشرة داخل Cancellation Action.

---

# 9. معالجة Bidder Deposits

استخدم Flow TASK 07.

المطلوب:

- Current winner deposit لا تعالج كـNon-winner عادية.
- كل Bidder Deposit مدفوعة وقابلة للرد تحصل على Refund Plan واحدة.
- غير المدفوعة تغلق دون Refund.
- لا Duplicate Refund.
- لا تظل Deposit Held بعد Cancelled.

---

# 10. Winner Payment

إذا توجد Successful PaymentTransaction للفائز:

- أنشئ Refund Plan مرتبطة بالـPaymentTransaction الأصلية.
- لا تنشئ Refund من Settlement amount fields فقط.
- لا تحول PaymentTransaction إلى Refunded/Reversed قبل نجاح Refund.
- لا تنشئ Refund ثانية إذا موجودة Pending/Processing/Succeeded.

إذا لا توجد دفعة ناجحة:

- لا تنشئ Refund.
- أغلق Payment obligation.
- Supersede pending submissions.

---

# 11. Settlement

عند الإلغاء:

```text
Current Settlement
→ Cancelled أو Voided
is_current = false
cancelled_at
cancel_reason
```

احتفظ بالتاريخ المالي.

لا تمسح:

```text
winning amount
deposit applied
amount paid
platform fee
seller net
winner identity
```

لكن يجب أن يكون واضحًا أن Settlement لم تعد فعالة.

إذا توجد Settlement تاريخية غير Current:

- لا تعدّلها إلا إذا كان هناك سبب مباشر.
- لا تجعلها Current.

---

# 12. Payment Submissions

جميع Submissions غير النهائية المرتبطة بالمزاد يجب أن تصبح:

```text
Cancelled
Superseded
Expired
```

وفق الـEnum الحالي.

يجب أن تحتوي Reason:

```text
auction_cancelled
```

لا تحذفها.

لا تعدّل Approved submissions التاريخية.

---

# 13. Payment Transactions

لا تغيّر PaymentTransaction الناجحة إلى Refunded/Reversed وقت الإلغاء.

القاعدة:

```text
Cancellation creates Refund Plan
Refund success changes PaymentTransaction
```

استخدم TASK 04 وTASK 06.

---

# 14. Platform Fees وSeller Net

راجع هل:

```text
platform_fee_minor
seller_net_minor
```

تم تحصيلهما أو تسويتهما فعليًا.

لا تصفر أرقام Settlement التاريخية.

إذا كانت هناك Payout للبائع خارج النظام:

- لا تخترع Reversal وهمية.
- ضع ManualReview إذا لا يوجد Payout integration.
- وثّق الحاجة.

لا تجعل النظام يدعي استرداد مال لم يعد تحت سيطرة المنصة.

---

# 15. Dispute Resolution

`ResolveAuctionDisputeAction` عند قرار:

```text
cancel
```

يجب أن يستدعي الـCentral Financial Cancellation Service.

لا ينفذ:

```text
auction status change only
```

يجب تمرير:

```text
trigger = DisputeResolved
reason
resolver admin
dispute id
liability
```

إذا كان قرار النزاع يحدد:

```text
seller fault
buyer fault
platform fault
```

يجب أن يصل هذا التصنيف إلى Seller Deposit Resolver.

---

# 16. Seller Cancellation

Controller/Action الخاصة بالبائع:

- تتحقق من Ownership.
- تتحقق من الحالة.
- تجمع السبب.
- تستدعي الـCentral Financial Cancellation Service.

لا تنفذ أي Financial Logic منفصل.

---

# 17. Admin Cancellation

Admin cancellation يجب أن يتطلب:

```text
reason_code
reason_text
liability
```

وPermission واضحة.

لا يكفي زر Cancel عام.

إذا كان السبب:

```text
seller breach
platform fault
compliance
fraud
neutral
```

يجب أن ينعكس في الخطة المالية.

---

# 18. System Cancellation

أي Job أو Scheduler يلغيان مزادًا يجب أن يستدعيا نفس الـCentral Service.

مثال:

```text
seller deposit deadline expired
invalid auction state
compliance timeout
```

لا تغيّر الحالة مباشرة من Job.

---

# 19. Manual Review

إذا كان النظام لا يستطيع تحديد مصير مبلغ معين آليًا:

- لا يخمن.
- لا يرد.
- لا يصادر.
- يضع:
  ```text
  ManualReview
  ```
- يسجل السبب.
- يمنع Completion الخاطئة للعملية المالية.

قد يظل Auction Cancelled، لكن يجب أن يكون هناك Financial Review status واضح.

---

# 20. Idempotency

إعادة تنفيذ الإلغاء يجب أن:

- لا تنشئ Refund ثانية.
- لا تصادر Deposit مرتين.
- لا تغلق Settlement مرتين بشكل مضر.
- لا تكرر Supersede للـSubmissions.
- لا تكرر Audit/Outbox المالية.
- لا تغيّر خطة سابقة.

استخدم:

- Lock Auction.
- Cancellation marker.
- Unique cancellation operation key.
- Existing refunds.
- Existing dispositions.
- Database constraints.

إذا Auction بالفعل Cancelled:

- أعد النتيجة الحالية Idempotently.
- أو Conflict واضح إذا السبب مختلف ويتطلب مراجعة.
- لا تنشئ خطة جديدة تلقائيًا.

---

# 21. Concurrency

شغّل عمليتين متزامنتين على نفس Auction:

```text
Seller cancellation
Admin cancellation
```

أو:

```text
Dispute cancellation
System cancellation
```

المتوقع:

- Cancellation مالية واحدة.
- Refund plans واحدة.
- Seller deposit disposition واحدة.
- Settlement closure واحدة.
- لا negative buckets.
- لا duplicate audit/outbox الحساسة.

يجب أن تحسم الأولوية حسب أول Transaction تنجح.

العملية الثانية تقرأ النتيجة الحالية ولا تعيد التنفيذ.

---

# 22. Policies والصلاحيات

أنشئ Permissions واضحة مثل:

```text
auction.cancel.seller
auction.cancel.admin
auction.cancel.compliance
auction.disputes.resolve
```

لا تستخدم Permission عامة واحدة لكل شيء.

Seller cancellation تعتمد على Ownership والحالة.

Admin cancellation تعتمد على Permission وReason.

Dispute cancellation تعتمد على resolver permission.

---

# 23. DTOs وRepositories

استخدم DTOs Typed، مثل:

```text
AuctionCancellationRequestDTO
AuctionCancellationContextDTO
AuctionCancellationPlanDTO
CancelSettlementDTO
SupersedePaymentSubmissionDTO
```

لا تنشئ Generic Cancellation DTO لكل المشروع.

القاعدة:

```text
Rules/Resolvers decide.
Central action orchestrates.
Repositories lock and persist.
Existing financial actions execute dispositions.
```

---

# 24. الاستثناءات والرسائل

أضف Exceptions واضحة، مثل:

```text
AuctionCancellationNotAllowedException
AuctionAlreadyCancelledException
AuctionCancellationReasonRequiredException
AuctionCancellationLiabilityRequiredException
AuctionCancellationFinancialPlanConflictException
AuctionCancellationRequiresManualReviewException
AuctionCancellationAlreadyProcessedException
```

الرسائل للمستخدم أو الأدمن تكون عربية.

---

# 25. Audit

سجل حدثًا مركزيًا:

```text
auction_cancellation_started
auction_cancellation_plan_created
auction_cancelled
auction_cancellation_manual_review
```

وسجل الأحداث المالية الفرعية عبر Actions المختصة.

Metadata المهمة:

```text
trigger
actor
reason_code
reason_text
liability
seller_deposit_disposition
bidder_refund_count
winner_payment_refund_count
settlement_id
manual_review_items
cancellation_operation_key
```

لا تكرر Audit في Retry Idempotent.

---

# 26. Outbox

أنشئ أحداثًا مثل:

```text
auction.cancellation_started
auction.cancelled
auction.cancellation_manual_review
auction.cancellation_financial_plan_created
```

مع الاعتماد على Actions المالية لإنشاء أحداث Refund/Forfeit الخاصة بها.

تنفيذ Consumers العامة سيكون في TASK 13.

---

# 27. Reconciliation

أضف Check يكتشف:

```text
Auction.status = Cancelled
```

لكن يوجد أحد الآتي:

- Current Settlement ما زالت Active.
- Pending Payment Submission غير Superseded.
- Successful Payment بلا Refund Plan أو ManualReview.
- Bidder Deposit ما زالت Held.
- Seller Deposit بلا disposition.
- Financial plan غير مكتملة.

لا تجعل Reconciliation تطبق إصلاحًا ماليًا عشوائيًا.

يجب أن تشير إلى Use Case الصحيحة.

---

# 28. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Seller cancellation before payment

المتوقع:

- Auction Cancelled.
- لا Refund لمال غير مدفوع.
- obligations مغلقة.

## B. Seller cancellation after seller deposit payment

المتوقع:

- Seller deposit disposition حسب policy.
- لا duplicate refund/forfeit.

## C. Admin cancellation — platform fault

المتوقع:

- Seller deposit refund.
- Bidder deposits refunds.
- Winner payment refund إن وُجدت.

## D. Admin cancellation — seller fault

المتوقع:

- Seller deposit forfeiture أو ManualReview.
- Bidder/winner funds refundable.

## E. Dispute resolution cancel

المتوقع:

- يستخدم نفس central service.
- كل Financial effects تُطبق.
- لا status-only cancellation.

## F. System cancellation

المتوقع:

- يستخدم نفس central service.
- لا direct state mutation.

## G. Current settlement

المتوقع:

- تصبح Cancelled/Voided.
- is_current = false.
- التاريخ محفوظ.

## H. Pending submissions

المتوقع:

- Superseded/Cancelled.
- لا يمكن اعتمادها بعد الإلغاء.

## I. Successful payments

المتوقع:

- Refund Plans.
- PaymentTransactions تظل Succeeded حتى نجاح Refund.

## J. Combined financial case

مثال:

```text
seller deposit = 20 paid
winner deposit = 10 paid/applied
winner remaining payment = 90 paid
other bidder deposits = 30 + 30 paid
```

عند الإلغاء:

- كل مبلغ له Plan صحيح.
- لا Double Refund.
- Seller deposit حسب liability.
- الإجمالي لا يتجاوز المدفوع.

## K. Idempotency

شغّل نفس الإلغاء مرتين.

المتوقع:

- خطة واحدة.
- نفس عدد Refunds.
- نفس dispositions.
- لا duplicate audit/outbox.

## L. Concurrent seller/admin cancellation

شغّل عمليتين متزامنتين على MySQL.

المتوقع:

- واحدة فقط تنفذ.
- لا duplicate financial changes.

## M. Completed auction

المتوقع:

- Cancellation العادية تُرفض.
- لا reversal صامت.

## N. Already cancelled

المتوقع:

- Idempotent result.
- لا خطة جديدة.

## O. Manual review

حالة Payout أو Financial uncertainty.

المتوقع:

- لا Refund أو Forfeit عشوائية.
- ManualReview مسجلة.

## P. Reconciliation

Cancelled auction مع Financial inconsistency.

المتوقع:

- يتم اكتشافها.

---

# 29. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع Integration Tests على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات:

- Concurrent cancellation.
- Refund uniqueness.
- Lock behavior.
- Financial idempotency.

---

# 30. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan migrate:status
php artisan test --filter=AuctionCancellation
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionCancellation
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

وشغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# 31. ممنوعات هذه المهمة

لا تنفذ الآن:

- Configuration Snapshot الكاملة.
- General Outbox consumers.
- General Scheduler concurrency.
- Resource privacy.
- Full DTO/Repository cleanup خارج الملفات المطلوبة.

لا تعِد بناء Refund lifecycle أو Seller Deposit lifecycle أو Non-winner release.

استخدم ما تم تنفيذه في المهام السابقة.

---

# 32. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_10_CENTRAL_CANCELLATION_REPORT.md
```

ويحتوي على:

1. جميع مسارات الإلغاء القديمة.
2. المشكلات الموجودة في كل مسار.
3. التصميم المركزي الجديد.
4. Cancellation triggers.
5. Cancellation context.
6. Financial plan.
7. Seller deposit integration.
8. Bidder deposit integration.
9. Winner payment integration.
10. Settlement handling.
11. Payment submission handling.
12. Dispute cancellation integration.
13. System cancellation integration.
14. Manual review cases.
15. Idempotency design.
16. Concurrency protection.
17. Actions/Rules المنشأة.
18. DTOs المنشأة.
19. Repositories المعدلة.
20. Permissions الجديدة.
21. Audit events.
22. Outbox events.
23. Reconciliation checks.
24. الاختبارات الجديدة.
25. نتائج Feature Tests.
26. نتائج MySQL concurrency tests.
27. الأوامر التي شُغلت.
28. أي فحص تعذر تشغيله.
29. المخاطر المؤجلة إلى TASK 11.

---

# 33. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- يوجد Financial Cancellation Service مركزية واحدة.
- كل مسارات الإلغاء تستخدمها.
- لا يوجد Status-only cancellation.
- Seller/Buyer/Admin/System/Dispute triggers موثقة.
- Seller deposit تُعالج عبر TASK 08.
- Bidder deposits تُعالج عبر TASK 07.
- Winner payments تُعالج عبر TASK 04/06.
- Applied deposits تُعالج عبر TASK 05.
- Pending submissions تُلغى أو تُعمل Supersede.
- Current settlement تُغلق تاريخيًا.
- PaymentTransaction لا تصبح Refunded قبل نجاح Refund.
- Cancellation Idempotent.
- Concurrent cancellation آمنة على MySQL.
- Reconciliation تكتشف أي Cancelled auction غير متوازنة ماليًا.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
