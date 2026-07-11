# Auction System — Final Corrective Pass

## معلومات المشروع

المشروع موجود في:

```text
C:\Users\pc\Desktop\SB\soom
```

اقرأ الملف الحالي كاملًا، ثم راجع الكود الفعلي الخاص بالمزادات، ولا تعتمد فقط على التقارير السابقة:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_PRODUCTION_GRADE_REFACTOR.md
C:\Users\pc\Desktop\SB\soom\AUCTION_FINAL_IMPLEMENTATION_REPORT.md
C:\Users\pc\Desktop\SB\soom\AUCTION_FULL_FLOW_AR.md
C:\Users\pc\Desktop\SB\soom\AUCTION_DATABASE_DESIGN.md
C:\Users\pc\Desktop\SB\soom\AUCTION_STATE_MACHINES.md
C:\Users\pc\Desktop\SB\soom\AUCTION_SECURITY_REVIEW.md
C:\Users\pc\Desktop\SB\soom\AUCTION_API_DOCUMENTATION.md
```

هذه مهمة تنفيذية وليست مراجعة نظرية.

لا تتوقف عند كتابة خطة أو تقرير.

نفّذ كل التصحيحات المطلوبة داخل نظام المزادات، ثم شغّل الاختبارات والفحوصات، وبعدها أنشئ تقريرًا نهائيًا مطابقًا للكود الفعلي.

---

# نطاق العمل

المهمة تخص نظام المزادات فقط، بما يشمل:

```text
app/Domain/Auction
app/DTO/Auction
app/Services/Auction
app/Repositories/Auction
app/Models/Auction
app/Http/Controllers/Auction
app/Http/Requests/Auction
app/Http/Resources/Auction
app/Policies/Auction
app/Jobs/Auction
app/Notifications/Auction
routes الخاصة بالمزادات
migrations الخاصة بالمزادات
tests الخاصة بالمزادات
translations الخاصة بالمزادات
```

لا تعدّل أجزاء خارج المزادات إلا إذا كان هناك Dependency حقيقي، مع توثيق السبب.

ممنوع:

```text
php artisan migrate:fresh
```

وممنوع حذف جداول المشروع خارج المزادات.

لا تحافظ على Legacy Auction Code غير المستخدم؛ النظام لم يدخل Production بعد.

---

# الهدف النهائي

المطلوب نظام مزادات Production-Grade من حيث:

1. صحة الحسابات المالية.
2. سلامة الـSettlement.
3. منع تكرار المدفوعات والـRefunds.
4. Concurrency وRace Conditions.
5. صحة Winner Default وAlternative Winner.
6. Authorization وIDOR.
7. الخصوصية.
8. Database Integrity.
9. وضوح المعمارية.
10. اكتمال Repository وDTO boundaries.
11. Outbox وScheduler فعليان.
12. اختبارات حقيقية على MySQL.
13. تعريب الرسائل.
14. عدم وجود كود شكلي أو غير مستخدم.

لا تعتبر المهمة مكتملة بسبب نجاح Syntax أو وجود ملفات كثيرة.

يجب إثبات صحة الـFlow باختبارات فعلية.

---

# 1. إصلاح النموذج المالي للـSettlement

يوجد خطأ مالي في منطق إنشاء التسوية.

إذا كانت:

```text
winning_amount = 100
deposit_applied = 10
```

يجب أن تكون:

```text
amount_due = 90
amount_paid = 0
```

وليس:

```text
amount_due = 90
amount_paid = 10
```

لأن `amount_due` هو أصلًا المبلغ المتبقي بعد تطبيق العربون.

نفّذ تعريفًا واضحًا وثابتًا للأعمدة:

```text
winning_amount_minor
deposit_applied_minor
amount_due_minor
amount_paid_minor
remaining_amount_minor
platform_fee_minor
seller_net_minor
```

ويجب أن تتحقق المعادلات التالية دائمًا:

```text
amount_due_minor =
winning_amount_minor - deposit_applied_minor
```

```text
remaining_amount_minor =
amount_due_minor - amount_paid_minor
```

```text
deposit_applied_minor + amount_paid_minor + remaining_amount_minor
=
winning_amount_minor
```

مع منع القيم السالبة.

استخدم `intdiv()` للحسابات المالية، ولا تستخدم القسمة `/` التي تنتج Float.

راجع:

```text
FinalizeAuctionAction
SubmitPaymentSubmissionAction
ReviewPaymentSubmissionAction
Settlement Resources
Settlement Repositories
Settlement DTOs
Settlement Tests
```

أصلح أي اختبار كان يتوقع الحساب القديم الخاطئ.

---

# 2. إصلاح حالة تغطية العربون لكامل قيمة المزاد

إذا كان:

```text
deposit_applied_minor >= winning_amount_minor
```

يجب:

1. تطبيق فقط قيمة المزاد المطلوبة.
2. جعل `amount_due_minor = 0`.
3. جعل `amount_paid_minor = 0`.
4. اعتبار التسوية مدفوعة دون طلب Payment Submission جديدة.
5. الانتقال للحالة المناسبة قبل التسليم.
6. تحديد `handover_due_at`.
7. إنشاء Refund للزيادة إن كان العربون أكبر من المبلغ المطلوب.

راجع Schema:

إذا كان `payment_due_at` يمكن أن يكون غير مطلوب، اجعله Nullable بصورة صحيحة.

لكن لا تجعل الحقول Nullable عشوائيًا دون توافق مع الـState Machine.

أضف اختبارات للحالات:

```text
deposit < winning amount
deposit = winning amount
deposit > winning amount
deposit = 0
```

---

# 3. إعادة تصميم Settlements لدعم Alternative Winner

الوضع الحالي يفرض:

```text
UNIQUE(auction_id)
```

على جدول `auction_settlements`، بينما Winner Default يحتاج Settlement جديدة للفائز البديل.

أعد تصميم Schema بحيث يمكن وجود أكثر من Settlement تاريخية لنفس المزاد، مع وجود Settlement واحدة فعالة فقط.

التصميم يمكن أن يشمل:

```text
sequence_number
is_current
superseded_at
superseded_by_settlement_id
previous_settlement_id
winner_reassignment_id
status
voided_at
void_reason
```

أو تصميم أبسط مكافئ.

المهم:

* الاحتفاظ بكل Settlement تاريخية.
* عدم تعديل Settlement الفائز القديم لتصبح للفائز الجديد.
* عدم إعادة استخدام المدفوعات القديمة.
* عدم إعادة استخدام العربون القديم.
* عدم وجود أكثر من Settlement Current لنفس المزاد.
* دعم Database Constraint حقيقي لمنع Settlements فعالة مكررة.

أنشئ Migration تصحيحية واضحة.

لا تعتمد على Application Check وحده.

---

# 4. إصلاح Winner Default بالكامل

راجع وأعد بناء:

```text
MarkWinnerDefaultedAction
Alternative winner queries
Winner reassignment repository
Winner reassignment model
Settlement creation
Deposit handling
```

عند تعثر الفائز:

1. Lock المزاد.
2. Lock الـWinning Bid.
3. Lock الـCurrent Settlement.
4. Lock Participant وDeposit للفائز.
5. التحقق من انتهاء `payment_due_at`.
6. أو السماح بقرار Admin استثنائي موثق بسبب وصلاحية منفصلة.
7. تغيير حالة Settlement القديمة إلى Defaulted أو Voided.
8. تطبيق سياسة العربون:

   * Forfeited.
   * Partially forfeited.
   * Refundable.
9. تسجيل السبب والمنفذ والتوقيت.
10. إنشاء Audit Log.
11. إنشاء Outbox Event.

عند البحث عن فائز بديل:

* استبعد `defaulted_bidder_id` بالكامل.
* لا تستبعد Winning Bid فقط.
* لا تسمح لأي Bid أخرى لنفس المستخدم المتعثر بالفوز.
* رتب الـBids حسب قواعد المزاد الصحيحة.
* تحقق من أهلية المشارك.
* تحقق من Terms Acceptance من جدول:

  ```text
  auction_terms_acceptances
  ```

  وليس من حقل غير موجود داخل Participant.
* تحقق من حالة العربون.
* تحقق أن العربون لم يتم رده أو مصادرته.
* تحقق أن المشارك ليس محظورًا أو ملغيًا.
* تحقق من نسخة الشروط الصحيحة.

بعد اختيار فائز بديل:

1. أنشئ Winner Reassignment Record.
2. أنشئ Winning Result جديدة أو حدّث المرجع الصحيح وفق التصميم.
3. أنشئ Settlement جديدة بالكامل.
4. احسب Deposit Applied للفائز الجديد.
5. احسب Amount Due.
6. احسب Platform Fee باستخدام `intdiv()`.
7. احسب Seller Net.
8. أنشئ Payment Deadline جديدة.
9. حدّث Current Settlement.
10. أنشئ Audit وOutbox.

إذا لم يوجد فائز بديل مؤهل:

```text
Unsold
```

أو الحالة المحددة داخل Configuration Snapshot.

---

# 5. سياسة عربونات غير الفائزين

الوضع الحالي يضع عربونات غير الفائزين في `RefundPending` مباشرة بعد Finalization، وهذا يتعارض مع Alternative Winner.

اجعل السياسة قادمة من Configuration Snapshot.

يجب دعم قرار واضح مثل:

```text
refund_all_non_winners_immediately
```

أو:

```text
hold_top_n_bidders_until_winner_payment
```

أو:

```text
hold_all_eligible_bidders_until_winner_payment
```

اختر تصميمًا بسيطًا وآمنًا، ولا تضع قيمًا ثابتة داخل الكود.

إذا كانت المنصة تدعم Alternative Winner، فلا ترد عربون المرشحين المحتملين قبل انتهاء فرصة إعادة التعيين.

أضف حالات واضحة للعربون:

```text
Required
PendingSubmission
PendingReview
Held
Applied
RefundPending
RefundProcessing
Refunded
Forfeited
Released
RejectedSubmission
```

لا تجعل رفض Payment Submission يفسد أصل التزام الـDeposit بالكامل.

---

# 6. إصلاح إعادة تقديم إيصال عربون مرفوض

Payment Submission هي محاولة دفع، بينما Deposit هي الالتزام المالي.

عند رفض محاولة دفع:

* اجعل Payment Submission `Rejected`.
* لا تعتبر Deposit منتهية نهائيًا.
* اسمح بإنشاء محاولة دفع جديدة.
* أعد حالة Deposit إلى الحالة الصحيحة مثل:

  ```text
  PendingSubmission
  ```

  أو:

  ```text
  PendingReview
  ```

  حسب وجود محاولة جديدة.

عند إرسال محاولة جديدة:

* Lock Deposit.
* تأكد أنها تسمح بمحاولة جديدة.
* حدّث حالتها بصورة صحيحة.
* لا تتركها في `Rejected` مع Submission جديدة معلقة.

أضف اختبار:

```text
submit → reject → resubmit → approve
```

---

# 7. إصلاح Validation للعملات وJOD

استخدم نفس Currency-aware Validation في:

```text
StoreAuctionRequest
PlaceBidRequest
Payment Submission Requests
Refund Requests
Admin financial requests
```

لا تستخدم:

```php
regex:/^\d+(\.\d{1,2})?$/
```

بصورة ثابتة.

يجب دعم:

```text
JOD = 3 decimal places
EGP = 2
USD = 2
```

أمثلة:

```text
10.001 JOD → valid
10.001 EGP → invalid
10.01 EGP → valid
ABC currency → invalid validation response
```

أي Currency غير مدعومة يجب أن ترجع Validation Error عربية، ولا تصل إلى `Money` فتسبب 500.

لا تستخدم Float في أي تحويل مالي.

---

# 8. إصلاح Submit Payment Flow والـRace Conditions

أعد بناء:

```text
SubmitPaymentSubmissionAction
PaymentSubmissionRepository
Receipt storage flow
```

المشكلة الحالية أن Deposit أو Settlement تتم قراءتها قبل رفع الملف، ثم تستخدم لاحقًا بعد أن تصبح قديمة.

التصميم المطلوب:

1. تحقق مبدئي من المدخلات.
2. تحقق من Idempotency قبل رفع الملف النهائي.
3. ارفع الملف بصورة Temporary أو مع Cleanup مضمون.
4. افتح Transaction.
5. Lock Auction.
6. أعد تحميل وLock Payment Target:

   * Deposit.
   * Settlement.
7. أعد تحميل Current Winner عند Settlement Payment.
8. أعد التحقق من:

   * Ownership.
   * Auction State.
   * Target State.
   * Current Winner.
   * Amount Due.
   * Deadline.
   * عدم الدفع سابقًا.
   * عدم إلغاء المزاد.
   * عدم وجود Submission فعالة مكررة.
9. أنشئ Payment Submission.
10. حدّث حالة الهدف.
11. أنشئ Audit وOutbox.
12. Commit.
13. اربط الملف النهائي.
14. عند أي فشل احذف الملف المؤقت.

امنع:

```text
amount <= 0
```

ولا تسمح بإنشاء Payment Submission بقيمة صفر.

---

# 9. إصلاح Payment Approval

أعد بناء:

```text
ReviewPaymentSubmissionAction
PaymentTransactionRepository
Deposit payment approval
Settlement payment approval
```

داخل Transaction:

1. Lock Payment Submission.
2. Lock Auction.
3. Lock Deposit أو Settlement.
4. Lock Current Winner عند الحاجة.
5. تحقق أن Submission ما زالت Pending Review.
6. تحقق أن Auction ليست Cancelled أو Rejected.
7. تحقق أن Settlement تخص الفائز الحالي.
8. تحقق أن Deadline لم تنتهِ، إلا بإجراء Admin موثق.
9. تحقق أن الالتزام لم يدفع سابقًا.
10. تحقق من المبلغ.
11. تحقق من Currency.
12. تحقق من Provider Transaction uniqueness.
13. أنشئ Payment Transaction.
14. حدّث Payment Target.
15. حدّث Auction State.
16. أنشئ Audit.
17. أنشئ Outbox.
18. Commit.

لا تستخدم مباشرة:

```text
provider_reference
```

المقدم من المستخدم كـ`provider_transaction_id` نهائي وموثوق.

اجعل الرقم النهائي:

* مدخلًا من Admin عند الاعتماد.
* أو قادمًا من Payment Provider Adapter.
* أو يتم التحقق منه عبر API.

أضف Constraints مثل:

```text
UNIQUE(payment_submission_id)
UNIQUE(provider, provider_transaction_id)
```

وفق Schema النهائي.

امنع اعتماد محاولتين مختلفتين لنفس الالتزام المالي.

---

# 10. إصلاح Refund Lifecycle

أعد بناء Refunds كدورة حقيقية:

```text
Pending
→ Processing
→ Succeeded
```

مع:

```text
Failed
Cancelled
ManualReview
```

عند الحاجة.

يجب أن تكون `confirmSucceeded()` Idempotent.

إذا كانت Refund بالفعل `Succeeded`:

* لا تزد `refunded_amount_minor`.
* لا تنشئ Audit مكررًا.
* لا تنشئ Outbox مكررًا.
* أرجع النتيجة الحالية بأمان.

داخل Transaction:

1. Lock Refund.
2. Lock Deposit أو Payment Source.
3. تحقق من الحالة.
4. تحقق من المبلغ القابل للرد.
5. تحقق من Provider Refund ID.
6. حدّث Refund.
7. حدّث refunded amount ذريًا.
8. حدّث حالة Deposit.
9. Audit.
10. Outbox.
11. Commit.

امنع:

```text
refund amount <= 0
refund amount > refundable amount
duplicate provider_refund_id
duplicate success confirmation
```

أضف حقولًا عند الحاجة:

```text
attempt_count
last_error
next_retry_at
processing_started_at
lease_expires_at
provider_refund_id
manual_confirmed_by
manual_confirmation_reason
```

أنشئ Provider Adapter واضحًا أو Manual Admin Confirmation Flow موثقًا.

لا تغيّر الحالة إلى `Succeeded` دون تنفيذ فعلي أو إثبات إداري.

---

# 11. بناء Financial Cancellation Flow

`CancelAuctionAction` لا يجب أن تغير Status فقط.

أعد بناء العملية حسب حالة المزاد.

داخل Transaction:

1. Lock Auction.
2. تحقق من صلاحية الإلغاء.
3. حدد مصدر الطلب:

   * Seller.
   * Admin.
   * System.
4. تحقق من الحالة.
5. تحقق من Bids.
6. تحقق من Deposits.
7. تحقق من Settlement.
8. تحقق من Payments.
9. حدد Cancellation Policy.
10. غيّر الحالة.
11. أنشئ Refund Plan.
12. Void الالتزامات غير المنفذة.
13. تعامل مع Seller Deposit.
14. تعامل مع Bidder Deposits.
15. تعامل مع Settlement إن وجدت.
16. ألغِ Payment Submissions غير المناسبة.
17. أنشئ Audit.
18. أنشئ Outbox.
19. Commit.

أضف حالات واختبارات للإلغاء في:

```text
Draft
PendingReview
AwaitingSellerDeposit
Scheduled
Live
Ended
PaymentPending
HandoverPending
```

لا تسمح Policy بشيء تمنعه الـState Machine، أو العكس.

اجعل القواعد متوافقة في مكان واحد واضح.

---

# 12. استكمال Seller Deposit Lifecycle

حدد بوضوح:

* متى يكون مطلوبًا؟
* ماذا لو كانت قيمته صفرًا؟
* ماذا يحدث عند Rejection؟
* ماذا يحدث عند Cancellation؟
* ماذا يحدث عند Unsold؟
* ماذا يحدث عند Completed؟
* متى يرد؟
* متى يصادر؟
* من يملك قرار المصادرة؟
* كيف يتم توثيق السبب؟

إذا كان:

```text
seller_deposit_required_minor = 0
```

فلا تنتقل إلى:

```text
AwaitingSellerDeposit
```

وانتقل مباشرة إلى الحالة المناسبة للجدولة.

لا تنشئ Payment Submission بقيمة صفر.

---

# 13. استكمال Repository Boundary

راجع كل ما يخص المزادات وابحث عن:

```text
::create(
::query(
->save(
->update(
->delete(
DB::transaction
lockForUpdate
Storage::
```

لا توجد عمليات Persistence مباشرة داخل:

```text
Controllers
Actions
Services
Jobs
State Machines
Metrics Recorders
```

إلا إذا كان هناك سبب معماري حقيقي ومكتوب في التقرير.

انقل العمليات مثل:

```text
PaymentMethod::create()
$model->save()
AuctionBid::query()
AuctionWinnerReassignment::create()
AuctionMedia::create()
AuctionView::create()
AuctionDeposit::where()
```

إلى Repositories مناسبة.

لكن لا تنقل Business Logic إلى Repository.

القاعدة:

```text
Action decides.
Repository persists.
```

الـTransaction Boundary تبقى داخل Action أو Application Service.

---

# 14. استكمال DTO Layer

الـDTOs الموجودة يجب أن تستخدم فعليًا.

لا تترك DTOs منشأة دون استخدام مثل:

```text
CreateBidRecordDTO
CreateSettlementDTO
CreateOutboxMessageDTO
```

استخدم Type محدد في Repository signatures.

مثال:

```php
public function createAcceptedBid(CreateBidRecordDTO $dto): AuctionBid
```

```php
public function createSettlement(CreateSettlementDTO $dto): AuctionSettlement
```

```php
public function storeOutbox(CreateOutboxMessageDTO $dto): OutboxMessage
```

لا تمرر Arrays خام من Actions إلى Repositories في عمليات الإنشاء والتحديث المعقدة.

استخدم camelCase داخل PHP DTOs:

```text
winningAmountMinor
depositAppliedMinor
paymentDueAt
```

واستخدم `toPersistenceArray()` لتحويلها إلى أسماء قاعدة البيانات:

```text
winning_amount_minor
deposit_applied_minor
payment_due_at
```

لا تستخدم Reflection أو Magic Mapping.

لا تجعل Base DTO تحتوي CRUD أو Eloquent أو Repository logic.

احذف أي DTO غير مستخدمة ولا تضف قيمة فعلية.

---

# 15. تنظيف Resources والخصوصية

لا تستخدم `AuctionResource` عامة في كل Endpoints.

استخدم Resources حسب الـAudience:

```text
PublicAuctionResource
ParticipantAuctionResource
SellerAuctionResource
AdminAuctionResource
WinnerAuctionResource
```

عند الحاجة.

ممنوع أن يرى Participant أو Winner بدون صلاحية:

```text
seller_net
platform_fee
reserve_amount
seller internal financial data
private deposits
payment submissions
refund provider details
audit logs
configuration internals
```

راجع Endpoints مثل:

```text
submit review
cancel
confirm seller handover
confirm winner receipt
resolve dispute
mark winner default
```

ولا ترجع Resource تكشف بيانات أوسع من المستخدم الحالي.

لا ترجع Models خامًا داخل Resources.

استخدم Resources فرعية لـ:

```text
Category
Country
State
City
Seller public profile
Bid public summary
Settlement admin summary
```

أضف Privacy Tests حقيقية.

---

# 16. حماية Bid History

المسار العام لعرض الـBids يجب ألا يسمح بعرض مزاد غير Public.

طبق Policy أو Query Visibility صحيحة.

الحالات مثل:

```text
Draft
PendingReview
Rejected
AwaitingSellerDeposit
Cancelled
Disputed
```

لا يجب أن تكون متاحة للعامة بمجرد معرفة `public_id`.

دعم عرض مختلف لـ:

* Public.
* Seller.
* Participant.
* Admin.

لا تكشف هوية المزايد كاملة للعامة.

---

# 17. إصلاح Auction Media Orphan Files

عند رفع صور المزاد داخل عملية الإنشاء أو التحديث:

* لا تترك ملفات على Spaces إذا فشل SQL أو حدث Rollback.
* استخدم Temporary Upload أو Compensation Cleanup.
* افصل Database Transaction عن Final File Commit بطريقة آمنة.
* احذف الملفات الجديدة عند فشل العملية.
* لا تحذف الملفات القديمة قبل نجاح Update بالكامل.

أضف اختبار أو Service-level test لفشل العملية بعد رفع الملف.

---

# 18. نقل Metrics خارج Bid Transaction

لا تنفذ داخل Transaction المزايدة:

```text
COUNT(*)
COUNT(DISTINCT bidder_id)
MAX(...)
```

انقل هذه العمليات إلى:

* Atomic Counters.
* Post-commit Job.
* Event Consumer.
* Reconciliation Command.

يجب أن تظل Transaction المزايدة قصيرة:

```text
lock
validate
insert bid
update leading bid
auto extend
store outbox
commit
```

أي Metrics ثقيلة تنفذ بعد Commit.

---

# 19. Outbox حقيقية

الـOutbox لا يجب أن تصبح Published بمجرد نجاح:

```php
Event::dispatch()
```

مع عدم وجود Listener.

نفّذ Consumers حقيقية على الأقل للأحداث المهمة:

```text
AuctionScheduled
AuctionStarted
BidPlaced
AuctionExtended
AuctionEnded
WinnerSelected
PaymentSubmitted
PaymentApproved
PaymentRejected
WinnerDefaulted
AlternativeWinnerSelected
RefundProcessing
RefundSucceeded
RefundFailed
SellerHandoverConfirmed
WinnerReceiptConfirmed
AuctionCompleted
AuctionCancelled
DisputeOpened
DisputeResolved
```

قد تكون Consumers:

* Notifications.
* Broadcast events.
* Emails.
* Internal jobs.
* Reconciliation triggers.

يجب ألا تصبح الرسالة Published إلا بعد نجاح Consumer الفعلي.

دورة الرسالة:

```text
Pending
→ Processing
→ Published
```

وعند الفشل:

```text
Failed retryable
→ Pending at next_retry_at
```

وبعد الحد الأقصى:

```text
DeadLetter أو ManualReview
```

أضف:

```text
attempt_count
last_error
available_at
processing_started_at
lease_expires_at
published_at
dead_lettered_at
```

اجعل Consumers Idempotent.

---

# 20. Scheduler وJobs

راجع:

```text
StartDueAuctionsAction
EndDueAuctionsAction
FinalizeAuctionAction
ReconcileAuctionsAction
Refund jobs
Outbox jobs
Winner default jobs
```

كل State Transition تلقائية يجب أن تكون:

```text
Transaction
→ lockForUpdate أو skipLocked
→ recheck state and time
→ transition
→ outbox
→ commit
```

استخدم:

```php
chunkById()
lazyById()
cursor()
```

ولا تستخدم `get()` لتحميل أعداد كبيرة.

`withoutOverlapping()` وحدها ليست كافية لمنع Race Conditions بين Workers.

أضف اختبارات تثبت أن تشغيل Job مرتين لا يكرر:

* State transition.
* Settlement.
* Refund.
* Notification.
* Outbox message.

تحقق عبر:

```bash
php artisan schedule:list
```

---

# 21. إصلاح Migrations

لا تعتمد على Migration قديمة سبق تسجيلها كمكتملة لإعادة إنشاء جداول حذفتها Migration جديدة.

أنشئ Migration تصحيحية حديثة ومستقلة تقوم صراحة بـ:

1. فحص Schema الحالية.
2. حذف جداول المزادات القديمة فقط إذا كان ذلك مطلوبًا.
3. إعادة إنشاء Schema الجديدة كاملة.
4. إنشاء Foreign Keys.
5. إنشاء Indexes.
6. إنشاء Unique Constraints.
7. إنشاء Check Constraints المناسبة.
8. إنشاء Configuration data المطلوبة.
9. الحفاظ على جداول المشروع الأخرى.

ممنوع:

```text
migrate:fresh
```

اختبر Migration على سيناريوهين:

```text
قاعدة بيانات فارغة
قاعدة بيانات نفذت Migrations القديمة مسبقًا
```

وأثبت أن النتيجة النهائية واحدة.

راجع Constraint الخاصة بالـSettlements لأنها يجب أن تدعم Winner Reassignment.

---

# 22. State Machine

راجع الحالات والانتقالات كاملة.

يجب ألا يوجد تعارض بين:

```text
Policies
Actions
State Machine
Database Constraints
Scheduler
```

المسار الطبيعي:

```text
Draft
→ PendingReview
→ AwaitingSellerDeposit أو Scheduled
→ Scheduled
→ Live
→ Ended
→ SettlementPending
→ PaymentPending أو HandoverPending
→ HandoverPending
→ Completed
```

المسارات البديلة:

```text
Rejected
Cancelled
Unsold
WinnerDefaulted
Disputed
```

وثّق بدقة:

* متى يدخل المزاد `Ended`.
* متى يدخل `SettlementPending`.
* متى يصبح `Unsold`.
* متى ينتقل مباشرة إلى `HandoverPending`.
* متى يسمح بـWinner Default.
* متى يسمح بـCancellation.
* كيف يخرج من Disputed.
* متى يصبح Completed.

لا تكرر Rules داخل أكثر من Action بصورة متضاربة.

---

# 23. Policies وIDOR

راجع كل Route وAction.

أضف أو أصلح Policies لـ:

```text
view public auction
view seller auction
view participant auction
view admin financial data
update draft
submit review
cancel
register
place bid
submit payment
review payment
confirm seller handover
confirm winner receipt
open dispute
resolve dispute
mark winner default
reassign winner
process refund
view receipt
view bid history
```

لا تعتمد فقط على Middleware role.

استخدم Ownership وCurrent Winner وAuction State.

Temporary Receipt URL لا يصدر إلا لمستخدم مخول.

---

# 24. الاختبارات الإلزامية

لا تكتفِ بـUnit Tests أو String Checks.

أنشئ Feature وIntegration Tests فعلية.

## Settlement

* Deposit is not counted twice.
* Amount due is correct.
* Amount paid starts at zero.
* Full deposit coverage.
* Excess deposit refund.
* Partial payments if supported.
* Full payment transition.

## Winner default

* Deadline must expire.
* Admin override requires reason.
* Defaulted bidder fully excluded.
* Another bid from same bidder cannot win.
* Terms acceptance checked from correct table.
* Refunded deposit candidate is skipped.
* New settlement is created.
* Old settlement remains historical.
* No candidate leads to Unsold.

## Payments

* Zero payment rejected.
* Stale settlement rejected.
* Winner changed before approval.
* Auction cancelled before approval.
* Duplicate approval prevented.
* Duplicate provider transaction prevented.
* Reject then resubmit.
* Receipt stays private.
* Unauthorized receipt access rejected.

## Refunds

* Zero refund rejected.
* Over-refund rejected.
* `confirmSucceeded()` twice does not duplicate amount.
* Duplicate provider refund ID rejected.
* Failed refund retry.
* Manual confirmation audited.

## Cancellation

* Draft cancellation.
* Scheduled cancellation.
* Live cancellation policy.
* Cancellation with deposits.
* Cancellation with payments.
* Refund plan generated once.
* Retry does not duplicate refunds.

## Concurrency

شغّل MySQL Integration Tests حقيقية لـ:

```text
two simultaneous bids
two simultaneous payment approvals
two scheduler workers
two refund confirmations
two winner default executions
```

لا تعتمد على SQLite لإثبات Row Locks.

## Privacy

اختبر عدم ظهور:

```text
reserve_amount
seller_net
platform_fee
private settlement data
private payment data
```

في Public وParticipant responses.

## Outbox

* Consumer success marks Published.
* No listener/consumer does not mark Published.
* Failure schedules retry.
* Max retries moves to dead letter.
* Rerun does not duplicate notification.

## Migration

اختبر Migration على قاعدة قديمة وعلى قاعدة فارغة.

---

# 25. فحوصات الجودة

بعد التنفيذ شغّل:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan schedule:list
php artisan migrate:status
php artisan test
```

وشغّل حسب المشروع:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
```

وشغّل Syntax check لجميع ملفات PHP المعدلة.

لا تدّعِ نجاح أمر لم يتم تشغيله.

إذا تعذر تشغيل اختبار، اذكر السبب بوضوح.

---

# 26. البحث النهائي عن المخالفات

بعد اكتمال التنفيذ ابحث داخل نظام المزادات عن:

```text
::create(
::query(
->save(
->update(
->delete(
DB::transaction
lockForUpdate
Storage::
UploadedFile
```

أنشئ جدولًا في التقرير النهائي يوضح:

```text
المخالفة
الملف
هل تم نقلها؟
سبب بقائها إن بقيت
```

ابحث أيضًا عن:

```text
amount_paid_minor => deposit_applied
unique('auction_id') داخل settlements
terms_accepted_at داخل participant
regex بخانتين للمزايدات
AuctionResource في endpoints الحساسة
provider_reference كمصدر موثوق نهائي
markAsPublished مباشرة بعد Event::dispatch
Refund Succeeded غير idempotent
```

يجب ألا تبقى أي نقطة منها بدون تبرير صحيح.

---

# 27. التقرير النهائي

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_FINAL_CORRECTIVE_REPORT.md
```

يحتوي على:

1. ملخص تنفيذي.
2. المشكلات التي تم إصلاحها.
3. الحسابات المالية النهائية.
4. Schema الـSettlements الجديدة.
5. Winner Default Flow.
6. Alternative Winner Flow.
7. Deposit lifecycle.
8. Payment lifecycle.
9. Refund lifecycle.
10. Cancellation lifecycle.
11. DTOs المستخدمة فعليًا.
12. Repository boundaries.
13. Resources والصلاحيات.
14. Scheduler وOutbox.
15. Migrations الجديدة.
16. الملفات المنشأة.
17. الملفات المعدلة.
18. الملفات المحذوفة.
19. الاختبارات المنشأة.
20. نتائج الأوامر الفعلية.
21. Direct Eloquent usage المتبقي.
22. أي مخاطر متبقية.

لا تكتب في التقرير أن النظام Production-Ready إلا إذا كانت شروط القبول التالية كلها متحققة ومثبتة.

---

# 28. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا تحقق الآتي:

* الحساب المالي للـSettlement صحيح.
* Deposit لا يحتسب مرتين.
* `amount_paid_minor` يبدأ بصفر عند إنشاء Settlement.
* Full-deposit flow يعمل.
* Excess deposit refund يعمل.
* Multiple historical settlements مدعومة.
* Settlement واحدة فقط تكون Current.
* Defaulted bidder يستبعد بالكامل.
* Terms Acceptance تقرأ من الجدول الصحيح.
* Alternative Winner تنشئ Settlement جديدة.
* Winner Default لا يحدث قبل Deadline دون Admin override موثق.
* عربونات المرشحين البدلاء لا ترد قبل تطبيق السياسة.
* Reject ثم Resubmit للدفع يعمل.
* JOD تقبل 3 خانات في Place Bid.
* Payment Submission تعيد Lock وفحص الهدف.
* Zero payment ممنوعة.
* Payment Approval تمنع Stale وDuplicate payments.
* Provider Transaction ID موثوقة.
* Refund confirmation Idempotent.
* Over-refund ممنوع.
* Cancellation تنشئ Financial Plan.
* Seller Deposit لها Lifecycle كاملة.
* Controllers Thin.
* Actions لا تنفذ Persistence مباشرة.
* DTOs مستخدمة فعليًا.
* لا توجد DTOs شكلية غير مستخدمة.
* Public وParticipant Resources لا تكشف بيانات حساسة.
* Bid History محمية بالـVisibility.
* Media uploads لا تترك Orphan Files.
* Metrics خارج Bid Transaction.
* Outbox لها Consumers حقيقية وRetry.
* Scheduler آمنة مع عدة Workers.
* Migration لا تعتمد على Migration قديمة.
* توجد MySQL Concurrency Tests.
* جميع الاختبارات المطلوبة ناجحة.
* التقرير النهائي يطابق الكود الفعلي.

ابدأ بالتنفيذ الآن.

لا تتوقف عند الخطة.

لا تطلب موافقة بين المراحل.

لا تنفذ تغييرات شكلية فقط.

الأولوية بالترتيب:

```text
Financial correctness
→ Database integrity
→ Concurrency
→ Security
→ Business flow
→ Architecture
→ Clean code
→ Documentation
```
