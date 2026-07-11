# مراجعة تصحيحية وإكمال نهائي لنظام المزادات

## المشروع

المشروع موجود في:

```text
C:\Users\pc\Desktop\SB\soom
```

المواصفات الأصلية موجودة في:

```text
C:\Users\pc\Desktop\SB\soom\Auction.md
```

تم تنفيذ إعادة بناء أولية لنظام المزادات، لكن التنفيذ الحالي غير مكتمل ويحتوي على مشكلات معمارية ووظيفية وأمنية ومالية واختبارات غير كافية.

المطلوب الآن ليس إنشاء خطة جديدة أو إعادة كتابة سطحية أخرى، بل:

> مراجعة التنفيذ الحالي فعليًا، الاحتفاظ بالأجزاء الصحيحة، وتصحيح وإكمال جميع الأجزاء الناقصة أو الخطرة حتى يصبح النظام Production-Grade فعلًا.

ابدأ بقراءة:

1. `Auction.md`
2. جميع ملفات التنفيذ الحالي.
3. جميع ملفات التوثيق `AUCTION_*.md`.
4. جميع Routes وControllers وActions وModels وMigrations وJobs وCommands والاختبارات.
5. الملفات الخارجية المرتبطة بالمزاد، خصوصًا `User`, `Category`, `PaymentMethod`, `filesystems`, `bootstrap/app.php`, `routes/console.php`.

لا تعتمد على التقارير السابقة باعتبارها صحيحة. تحقق من الكود الفعلي.

---

# قاعدة أساسية

نظام المزادات لم يطلق للعملاء بعد.

يمكنك:

* تعديل الجداول الحالية.
* حذف وإعادة إنشاء جداول المزادات.
* تغيير أسماء الأعمدة.
* تغيير Routes والـAPI Contracts.
* حذف الملفات القديمة.
* إعادة توزيع المسؤوليات.
* إعادة كتابة أجزاء التنفيذ الحالي.

لا تحافظ على أي تصميم خاطئ بسبب التوافق القديم.

لكن:

* لا تستخدم `migrate:fresh`.
* لا تحذف جداول المشروع غير المرتبطة بالمزاد.
* لا تسقط جدولًا عامًا مثل `payment_slips` قبل التأكد من عدم استخدامه خارج نظام المزادات.
* جميع التغييرات المدمرة يجب أن تكون Scoped داخل نطاق Auction ومراجعة Dependencies أولًا.
* احتفظ بالأجزاء الصحيحة بدل إعادة البناء دون داعٍ.

---

# الأجزاء الجيدة التي يجب البناء عليها

حافظ على المفاهيم الصحيحة الموجودة حاليًا بعد مراجعتها:

* Append-only auction bids.
* Public ULIDs مع Bigint داخلي.
* Auction participants المنفصلة.
* Deposits, payments, refunds, settlements المنفصلة.
* استخدام Actions لكل Use Case.
* State Enums.
* Money Value Object بعد تصحيحه.
* `lockForUpdate()` داخل عمليات المزايدة.
* إعادة التحقق من الوقت والحالة داخل Transaction.
* Minimum Bid Increment.
* Reserve Price.
* Auto Extension.
* Audit Logs.
* Transactional Outbox بعد جعله حقيقيًا.
* Scoped auction migrations.

لا تحذف هذه المفاهيم إلا إذا كان البديل أفضل ومبررًا بوضوح.

---

# الهيكلة المطلوبة

التنفيذ الحالي يستخدم:

```text
app\Application\Auction
app\Domain\Auction
```

المطلوب تبسيط الهيكلة لتتوافق مع هيكلة المشروع الحالية، بدون ادعاء تطبيق Clean Architecture كاملة بينما الـApplication Layer مرتبطة مباشرة بـEloquent وLaravel HTTP.

استخدم الهيكل التالي:

```text
app/
├── Domain/
│   └── Auction/
│       ├── Enums/
│       ├── Exceptions/
│       ├── ValueObjects/
│       └── Rules/
│
├── Services/
│   └── Auction/
│       ├── Actions/
│       ├── Queries/
│       └── Support/
│
├── Repositories/
│   └── Auction/
│
├── Models/
│   └── Auction/
│
├── Http/
│   ├── Controllers/Auction/
│   ├── Requests/Auction/
│   └── Resources/Auction/
│
├── Policies/Auction/
├── Jobs/Auction/
├── Notifications/Auction/
└── Console/Commands/Auction/
```

انقل محتوى:

```text
app\Application\Auction\Actions
```

إلى:

```text
app\Services\Auction\Actions
```

وانقل الخدمات المشتركة إلى:

```text
app\Services\Auction\Support
```

ثم احذف `app\Application\Auction` بعد التأكد من عدم وجود References له.

احتفظ بـ:

```text
app\Domain\Auction
```

فقط للمفاهيم الحقيقية التي لا تعتمد على HTTP أو Storage أو Eloquent قدر الإمكان، مثل:

* Enums.
* Domain Exceptions.
* Money.
* Currency metadata.
* قواعد الحالات.

ممنوع وضع `UploadedFile` أو Laravel Request أو Storage داخل Domain Layer.

---

# قواعد Service وRepository

الـActions هي الـApplication Services الأساسية، مثل:

```text
CreateAuctionAction
PlaceBidAction
FinalizeAuctionAction
CancelAuctionAction
ApprovePaymentAction
RefundDepositAction
CompleteHandoverAction
```

لا تنشئ `AuctionService` ضخمًا يحتوي عشرات الدوال.

أنشئ Repositories أو Query Objects فقط عندما توجد فائدة حقيقية.

أمثلة مناسبة:

```text
AuctionPublicQuery
AuctionSellerQuery
AuctionAdminQuery
AuctionPaymentReviewQuery
AuctionFinalizationRepository
AuctionBidRepository
```

لا تنشئ Generic Repository لكل Model يحتوي فقط:

```text
find
all
create
update
delete
```

ممنوع وجود Queries أو Transactions أو Business Logic داخل Controllers.

---

# المرحلة الأولى: إصلاح المشكلات الأمنية والمالية الحرجة

نفذ هذه المرحلة أولًا، ولا تنتقل للمرحلة التالية قبل نجاح اختباراتها.

## 1. إصلاح صلاحية إتمام التسليم

المسار الحالي الخاص بـ`complete-handover` لا يتحقق بشكل صحيح من هوية المنفذ.

ممنوع أن يستطيع أي مستخدم مسجل تحويل المزاد إلى `completed`.

أنشئ Flow واضحًا للتسليم:

```text
seller_handover_confirmed_at
buyer_receipt_confirmed_at
handover_completed_at
```

حدد القواعد:

* البائع يؤكد التسليم.
* الفائز يؤكد الاستلام.
* لا يصبح المزاد Completed إلا بعد تحقق الشرطين، أو قرار Admin موثق.
* إذا اختلف الطرفان، يتحول إلى `disputed`.
* كل عملية لها Action منفصلة وPolicy واضحة.

أنشئ مثلًا:

```text
ConfirmAuctionHandoverBySellerAction
ConfirmAuctionReceiptByWinnerAction
ResolveAuctionHandoverDisputeAction
```

وأضف اختبارات تمنع أي مستخدم آخر من التنفيذ.

---

## 2. إنشاء Policies حقيقية

أنشئ Policies على الأقل لـ:

```text
AuctionPolicy
PaymentSubmissionPolicy
AuctionSettlementPolicy
AuctionDepositPolicy
AuctionRefundPolicy
```

افصل صلاحيات الإدارة:

```text
auction.review
auction.approve
auction.cancel
auction.payment.review
auction.payment.approve
auction.refund.execute
auction.settlement.override
auction.dispute.resolve
```

لا تعتمد على `role:admin` لكل العمليات المالية.

لا تكرر شروط الملكية والصلاحية داخل كل Controller.

---

## 3. إعدادات الأدمن وقواعد المزاد

حاليًا يستطيع البائع إرسال قيم حساسة مثل:

```text
platform_fee_basis_points
seller_deposit_amount
bidder_deposit_amount
winner_payment_deadline_hours
handover_deadline_hours
extension_window_seconds
extension_duration_seconds
maximum_extension_count
```

هذا غير مقبول.

أنشئ نظام إعدادات مركزي Versioned، مثل:

```text
auction_configuration_versions
```

يحتوي على:

```text
currency_code
minimum_duration_minutes
maximum_duration_minutes
default_duration_minutes
seller_deposit_type
seller_deposit_value
bidder_deposit_type
bidder_deposit_value
minimum_bid_increment
extension_window_seconds
extension_duration_seconds
maximum_extension_count
winner_payment_deadline_hours
handover_deadline_hours
platform_fee_type
platform_fee_value
terms_version_id
is_active
effective_from
created_by
```

الأدمن فقط ينشئ أو يفعّل نسخة إعدادات.

عند إنشاء المزاد:

* يحسب النظام القيم من Configuration الحالية.
* يأخذ Snapshot داخل المزاد.
* لا يسمح للبائع بإلغاء عمولة المنصة أو تعديل العربون أو مهلة الدفع.
* يسمح للبائع فقط بالخيارات التي تحددها سياسة العمل صراحة.

أي Override إداري يجب أن:

* يحتاج صلاحية خاصة.
* يحتاج سببًا.
* يسجل Audit Log.

---

## 4. تصحيح Money والعملات

صحح `Money` بالكامل.

لا تفترض أن جميع العملات تحتوي خانتين عشريتين.

أنشئ Currency Metadata مثل:

```php
JOD => 3
EGP => 2
USD => 2
```

يجب دعم:

```text
1.001 JOD
```

بدقة.

ممنوع استخدام Floating Point بأي شكل، بما في ذلك:

```php
$minor / 100
```

استخدم String operations أو Integer operations فقط.

يجب أن يدعم Money:

* `fromDecimalString()`
* `fromMinorUnits()`
* `toDecimalString()`
* `add()`
* `subtract()`
* `compare()`
* `isSameCurrency()`
* منع العملات المختلفة.
* منع Overflow.
* منع القيم السالبة عند عدم السماح بها.
* Currency exponent الصحيح.

أنشئ اختبارات لجميع العملات المدعومة، خصوصًا JOD.

---

## 5. حماية إيصالات الدفع

أنشئ Disk منفصلًا:

```text
spaces_private
```

بـ:

```php
'visibility' => 'private',
'throw' => true,
```

جميع إيصالات الدفع والمستندات المالية تحفظ عليه فقط.

ممنوع:

* استخدام Public URL.
* إعادة Storage Path الخام.
* استخدام Disk الصور العامة.
* عرض الإيصال للبائع أو مستخدم آخر.
* حذف السجل أو الملف بعد دخوله في عملية مالية.

أنشئ Endpoint يولد:

```text
Temporary Signed URL
```

بعد Policy واضحة، لمدة قصيرة.

المسموح لهم فقط:

* صاحب الإيصال.
* المستخدم الإداري المالي المخول.

---

## 6. إصلاح رفع الملفات وIdempotency

حاليًا يتم رفع الملف قبل التحقق من وجود العملية المكررة، ما قد ينشئ Orphan Files.

التدفق الصحيح:

1. Validate request.
2. تحقق من Idempotency Record أولًا.
3. إذا كانت العملية موجودة، أعد نفس النتيجة بدون رفع ملف جديد.
4. ارفع الملف.
5. نفذ Database Transaction.
6. إذا فشلت قاعدة البيانات، احذف الملف الجديد.
7. لا ترفع الملف داخل Callback قد يتم Retry له بسبب Deadlock.
8. بعد Commit، نفذ أي عمليات تنظيف.

يجب أن يحتوي Idempotency Scope على:

```text
auction_id
actor_id
operation_type
idempotency_key
```

أو Scope مكافئ يمنع تداخل المفتاح بين مزادين.

---

## 7. منع تكرار المدفوعات

لا تعتمد فقط على أن العميل سيعيد نفس Idempotency Key.

أضف Database Constraints وقواعد تمنع:

* أكثر من Payment Submission معتمدة لنفس الالتزام المالي.
* دفع العربون مرتين.
* تطبيق العربون مرتين.
* دفع Settlement بأكثر من المبلغ المستحق.
* اعتماد نفس Provider Transaction مرتين.
* اعتماد محاولتين مختلفتين لنفس Deposit.
* إنشاء Payment Transaction مكررة.

أضف حقولًا وقيودًا واضحة مثل:

```text
approved_payment_submission_id
provider_transaction_id UNIQUE
payment_obligation_id
```

حسب التصميم النهائي.

---

## 8. Refund حقيقي

ممنوع أن تنشئ Refund بالحالة `pending` ثم تحولها مباشرة إلى `succeeded` بدون تنفيذ فعلي.

ادعم حالتين:

### Payment Provider Refund

```text
pending
processing
succeeded
failed
```

ولا يصبح Deposit `refunded` إلا بعد تأكيد Provider.

### Manual Refund

```text
pending_manual_transfer
awaiting_confirmation
succeeded
rejected
```

الأدمن المالي المخول يؤكد التحويل مع:

* External reference.
* Proof document عند الحاجة.
* processed_by.
* processed_at.
* reason.

كل Refund يجب أن يكون Idempotent وقابلًا لإعادة المحاولة.

---

## 9. إلغاء المزاد

أنشئ `CancelAuctionAction` كاملة.

لا يكفي تغيير Status.

يجب أن تتحقق من:

* حالة المزاد.
* وجود Participants.
* وجود Bids.
* وجود Deposits.
* من يطلب الإلغاء.
* سبب الإلغاء.
* هل توجد رسوم أو مصادرة عربون البائع؟
* هل يجب إنشاء Refund Requests؟
* هل يحتاج تدخل Admin؟

عند الإلغاء:

1. Lock المزاد.
2. Recheck الحالة.
3. منع Bids الجديدة.
4. تسجيل السبب والمنفذ.
5. إنشاء Refund Transactions المستحقة.
6. تحديث Status History.
7. إنشاء Outbox Events.
8. إرسال إشعارات بعد Commit.
9. منع تكرار العملية.

المعلن لا يستطيع إلغاء مزاد Live يحتوي على Bids بدون سياسة وصلاحية واضحة.

---

# المرحلة الثانية: إكمال الـBusiness Flow

## 1. عربون بقيمة صفر

إذا كان:

```text
seller_deposit_amount = 0
```

لا تجعل المزاد ينتظر إيصالًا بقيمة صفر.

انتقل تلقائيًا للحالة التالية مع Audit Log.

نفس الأمر للمزايد:

```text
bidder_deposit_amount = 0
```

يصبح المشارك مؤهلًا بدون Payment Submission وهمية.

---

## 2. إكمال Flow البائع

يجب أن يدعم النظام:

```text
draft
pending_review
rejected
awaiting_seller_deposit
scheduled
live
ended
settlement_pending
payment_pending
handover_pending
completed
cancelled
unsold
defaulted
disputed
```

حدد جميع Transitions المسموحة والممنوعة.

أضف:

* تعديل Draft.
* حذف Draft فقط.
* إعادة إرسال Rejected Auction بعد التعديل.
* عدم تعديل القيم الجوهرية بعد النشر.
* عدم تغيير Reserve أو Increment بعد البداية.
* عدم إعادة تنشيط مزاد ملغي بسبب اعتماد Payment قديم.

---

## 3. Winner Default Flow

اربط `MarkWinnerDefaultedAction` فعليًا بـ:

* Scheduler.
* Command.
* Tests.
* Notifications.
* Audit.

عند انتهاء مهلة دفع الفائز:

1. Lock Settlement والمزاد.
2. إعادة التحقق من الدفع.
3. تحويل Settlement إلى Defaulted.
4. تنفيذ سياسة العربون.
5. تحديد هل يتم الانتقال للمزايد التالي.
6. عدم تغيير الفائز بصمت.
7. تسجيل كل التفاصيل.

---

## 4. الفائز البديل

أنشئ Flow واضحًا للفائز التالي.

حدد:

* هل يتم اختياره تلقائيًا؟
* هل يحتاج قبولًا؟
* ما المهلة؟
* ما السعر المعتمد: آخر Bid الخاصة به.
* ماذا يحدث لو رفض أو تعثر؟
* ما الحد الأقصى للانتقالات؟

احتفظ بالتاريخ الكامل:

```text
previous_winner_id
replacement_winner_id
previous_settlement_id
replacement_settlement_id
reason
reassigned_at
```

لا تعدّل Settlement القديمة لإخفاء ما حدث.

---

## 5. Dispute Flow

وجود Status باسم `disputed` بدون Flow فعلي غير مقبول.

أنشئ على الأقل:

```text
auction_disputes
```

ويحتوي على:

```text
auction_id
settlement_id
opened_by
reason
description
status
assigned_to
resolution
resolved_by
opened_at
resolved_at
```

مع:

* OpenDisputeAction.
* ResolveDisputeAction.
* Policies.
* Audit Logs.
* Notifications.

---

## 6. Seller Deposit Lifecycle

حدد مصير عربون البائع:

* متى يصبح Held؟
* متى يتم رده؟
* متى تتم مصادرته؟
* ماذا يحدث عند إلغاء غير مبرر؟
* ماذا يحدث عند إتمام المزاد؟
* ماذا يحدث إذا لم يصل Reserve؟
* ماذا يحدث لو فشل التسليم؟

لا تترك عربون البائع معلقًا بلا Flow نهائي.

---

# المرحلة الثالثة: تشغيل النظام فعليًا

## 1. Scheduler

المشروع يستخدم Laravel الحديث مع:

```text
bootstrap/app.php
routes/console.php
```

سجل الجدولة في المكان الفعلي الذي يستخدمه المشروع، مثل:

```text
routes/console.php
```

أو `withSchedule()` داخل `bootstrap/app.php`.

لا تعتمد على `App\Console\Kernel` إذا لم يكن محملًا فعليًا.

سجل:

```text
StartScheduledAuctions
FinalizeExpiredAuctions
CheckWinnerPaymentDeadlines
CheckHandoverDeadlines
ProcessPendingRefunds
PublishOutboxMessages
ReconcileAuctionPayments
CleanupOrphanAuctionFiles
```

استخدم عند الحاجة:

```php
withoutOverlapping()
onOneServer()
```

تحقق بواسطة:

```bash
php artisan schedule:list
```

ويجب أن تظهر جميع Jobs المطلوبة.

---

## 2. Outbox حقيقي

التنفيذ الحالي الذي يكتب `Log::info()` ثم يعتبر الرسالة Published غير كافٍ.

نفذ Outbox Publisher حقيقيًا.

كل Outbox Message تحتوي:

```text
event_id
event_type
aggregate_type
aggregate_id
payload
status
attempts
available_at
locked_at
locked_by
processed_at
failed_at
last_error
```

يجب:

1. Claim الرسائل بأمان لمنع Workerين من معالجتها.
2. Dispatch Event أو Job أو Notification فعلية.
3. تعليم الرسالة Processed بعد النجاح فقط.
4. زيادة attempts عند الفشل.
5. استخدام Backoff.
6. إعادة الرسائل الفاشلة عند السماح.
7. دعم Idempotent Consumers.
8. عدم فقد الرسائل عند Crash.

لا تدّعِ Exactly Once.

استخدم At-Least-Once مع Idempotent Consumers.

---

## 3. Notifications فعلية

أنشئ إشعارات حقيقية بالعربية للأحداث المهمة:

```text
AuctionApproved
AuctionRejected
AuctionScheduled
AuctionStarted
BidAccepted
UserOutbid
AuctionExtended
AuctionEnded
WinnerSelected
WinnerPaymentRequired
WinnerDefaulted
RefundRequested
RefundCompleted
RefundFailed
HandoverRequired
AuctionCompleted
AuctionCancelled
DisputeOpened
DisputeResolved
```

تعمل عبر Queue وبعد Commit.

لا تجعل فشل الإشعار يفشل العملية الأساسية.

أضف Dedupe Key لمنع التكرار.

---

## 4. Rate Limiting

أضف Rate Limits واضحة خصوصًا لـ:

```text
Place Bid
Register Participant
Submit Payment
Upload Receipt
Public Auction Views
Admin Payment Approval
```

اجعل Place Bid محددة حسب:

```text
user_id + auction_id
```

مع IP عند الحاجة.

لا تستخدم Rate Limit بدل Idempotency أو Database Constraints.

---

# المرحلة الرابعة: إصلاح الأداء

## 1. لا تعِد حساب جميع Metrics بعد كل Bid

ممنوع تنفيذ:

```text
COUNT(*)
COUNT(DISTINCT bidder_id)
MAX(amount)
```

على جميع Bids أثناء Transaction الخاصة بالمزايدة.

حدّث القيم الضرورية Atomically:

```text
bids_count += 1
current_leading_bid_id = new_bid_id
```

وزد `unique_bidders_count` فقط عند أول Bid للمشارك.

Metrics غير الحرجة:

* تحدث بعد Commit.
* أو عبر Queue.
* أو عبر Reconciliation دوري.

قلل مدة Lock في `PlaceBidAction` لأقل وقت ممكن.

ممنوع داخل Transaction الخاصة بالمزايدة:

* Notifications.
* File operations.
* HTTP calls.
* Full aggregate counts.
* Heavy reports.

---

## 2. Query Layer

انقل استعلامات القراءة من Controllers إلى Queries أو Repositories.

مثل:

```text
PublicAuctionQuery
SellerAuctionQuery
AdminAuctionQuery
AuctionBidHistoryQuery
PaymentSubmissionReviewQuery
```

يجب أن تكون Controllers مسؤولة فقط عن:

* Request.
* Action/Query.
* Resource.

---

## 3. Pagination

استخدم Cursor Pagination لـ:

* Bids.
* Audit Logs.
* Payment Submissions.
* Refunds.
* Outbox.
* Large auction lists عند ملاءمة ذلك.

لا ترجع Collections ضخمة بدون Limit.

---

## 4. Indexes

راجع كل Query فعلية باستخدام:

```text
EXPLAIN
EXPLAIN ANALYZE
```

حيثما يتوفر.

راجع خصوصًا:

```text
auction_bids(auction_id, sequence_number)
auction_bids(auction_id, amount, accepted_at)
auctions(status, starts_at)
auctions(status, ends_at)
auction_participants(auction_id, user_id)
payment_submissions(status, created_at)
refund_transactions(status, created_at)
auction_settlements(status, payment_due_at)
outbox_messages(status, available_at)
```

لا تضف Indexes دون استخدام فعلي.

---

# المرحلة الخامسة: Controllers وResources وAPI

## 1. Thin Controllers

ممنوع داخل Controller:

* Eloquent Query.
* Transaction.
* Lock.
* Business Logic.
* State Transition.
* حساب مالي.
* Storage.
* Try/Catch عام.
* Authorization Logic معقد.

انقل كل Use Case إلى Action.

مثلًا:

```text
CancelAuctionAction
ApproveAuctionAction
RejectAuctionAction
ConfirmSellerHandoverAction
ConfirmWinnerReceiptAction
```

---

## 2. فصل Resources

لا تستخدم `AuctionResource` واحدة لكل المستخدمين.

أنشئ:

```text
PublicAuctionResource
ParticipantAuctionResource
SellerAuctionResource
AdminAuctionResource
PublicBidResource
PrivateBidResource
PaymentSubmissionResource
SettlementResource
```

الـPublic Resource لا تعرض:

* Reserve Amount إذا كانت مخفية.
* Seller Deposit.
* Payment Data.
* Seller Net Amount.
* Internal IDs.
* Winning Bid قبل الوقت المناسب.
* بيانات شخصية.

لا ترجع Eloquent Model خامًا داخل Resource:

```php
'category' => $this->whenLoaded('category')
```

أنشئ Resource واضحًا للتصنيف.

ممنوع تنفيذ Queries داخل Resources.

---

## 3. Visibility العامة

المسارات العامة يجب أن تعرض فقط المزادات المسموح بعرضها.

لا تعرض:

```text
draft
pending_review
rejected
awaiting_seller_deposit
cancelled
```

عن طريق تخمين `public_id`.

نفس القاعدة لمسار Bids العامة.

---

## 4. Payment Method Contract

حاليًا Resource تعرض `public_id` لكن Request تتوقع Integer ID.

وحّد العقد.

الـAPI يجب أن يستقبل:

```text
payment_method_id = public ULID
```

ويحوّله داخليًا إلى ID.

لا تعرض Internal IDs للواجهة ثم تطلب Public IDs في مكان آخر.

---

## 5. إصلاح علاقات User

راجع وصحح العلاقات القديمة.

خصوصًا:

```text
AuctionBid user_id
Auction winner_id
```

إذا كان جدول Bids يستخدم:

```text
bidder_id
```

يجب أن تستخدم العلاقة `bidder_id`.

إذا كان الفائز مرتبطًا بـ`winning_bid_id` أو Settlement، فلا تستخدم علاقة تعتمد على `winner_id` غير موجودة.

ابحث في المشروع كاملًا عن:

```text
winner_id
user_id داخل AuctionBid
old auction namespaces
old auction table names
```

واحذف جميع References الميتة.

---

# المرحلة السادسة: التعريب الكامل

الموقع موجه للعرب بشكل أساسي.

جميع الرسائل التي تصل للمستخدم يجب أن تكون عربية.

القيم الداخلية تبقى إنجليزية:

```text
pending_review
live
completed
```

لكن استجابة الـAPI ترجع:

```json
{
  "status": "pending_review",
  "status_label": "قيد المراجعة"
}
```

أنشئ:

```text
lang/ar/auction.php
lang/ar/validation.php
lang/en/auction.php
```

واجعل العربية هي اللغة الافتراضية للمشروع إن لم يؤثر ذلك على أجزاء أخرى:

```text
APP_LOCALE=ar
APP_FALLBACK_LOCALE=ar
```

أو اجعل النظام يعتمد Locale المستخدم بطريقة واضحة.

ممنوع كتابة رسائل مستخدم Hardcoded بالإنجليزية داخل:

* Controllers.
* Actions.
* Exceptions.
* Notifications.
* Requests.

استخدم:

```php
__('auction.created')
__('auction.errors.bid_too_low')
```

أضف `messages()` و`attributes()` في Form Requests عند الحاجة.

أمثلة الرسائل المطلوبة:

```text
تم إنشاء المزاد بنجاح.
تم إرسال المزاد للمراجعة.
المزاد غير متاح للمزايدة حاليًا.
لم يبدأ المزاد بعد.
انتهى وقت المزاد.
قيمة المزايدة أقل من الحد الأدنى المطلوب.
يجب اعتماد العربون قبل تقديم المزايدة.
لا يمكنك المزايدة على مزادك.
ليس لديك صلاحية لتنفيذ هذه العملية.
تم اعتماد الدفعة بنجاح.
تم رفض إثبات الدفع.
تم طلب استرداد العربون.
تم استرداد العربون بنجاح.
تعذر تنفيذ الاسترداد، وسيتم إعادة المحاولة.
```

يمكن أن تظل:

* Logs.
* Class names.
* Event names.
* Error codes.

بالإنجليزية.

---

# المرحلة السابعة: معالجة الأخطاء مركزيًا

استخدم:

```text
bootstrap/app.php
```

و`withExceptions()` لمعالجة Domain Exceptions.

احذف الـTry/Catch المكرر من Controllers.

أنشئ Error Codes مستقرة مثل:

```text
AUCTION_NOT_LIVE
AUCTION_NOT_STARTED
AUCTION_ENDED
BID_TOO_LOW
DEPOSIT_NOT_APPROVED
INVALID_STATE_TRANSITION
PAYMENT_ALREADY_PROCESSED
REFUND_ALREADY_PROCESSED
HANDOVER_NOT_AUTHORIZED
```

الاستجابة الموحدة تحتوي على:

```json
{
  "success": false,
  "message": "قيمة المزايدة أقل من الحد الأدنى المطلوب.",
  "error_code": "BID_TOO_LOW",
  "errors": {}
}
```

استخدم HTTP Status الصحيح:

```text
401
403
404
409
422
429
500
```

لا تعرض SQL أو Stack Trace أو Internal Exception Message في Production.

---

# المرحلة الثامنة: Migrations وقاعدة البيانات

راجع الـMigrations الحالية.

لا تستخدم Migration تقوم بـ`require` لملف Migration قديم وتشغيل `up()` يدويًا.

بما أن النظام غير مطلق:

* أنشئ Schema مزاد واضحة ونظيفة.
* افصل Migration إسقاط الجداول القديمة عن Migration إنشاء الجداول الجديدة.
* لا تعدل Migration قديمة بطريقة تربك بيئات مختلفة إلا إذا كان المشروع لم يشاركها مع أي بيئة، ووضح القرار.
* اجعل `down()` واضحًا داخل نطاق المزاد.
* لا تسقط جداول عامة دون مراجعة الاستخدامات.

أضف Constraints تمنع:

* أكثر من Settlement فعالة.
* Bid Sequence مكررة.
* Participant مكرر.
* Payment Provider Reference مكرر.
* Refund أكبر من المدفوع.
* أكثر من Approved Payment لنفس obligation.
* Winning Bid من مزاد آخر.
* `ends_at <= starts_at`.
* قيم مالية سالبة.
* Applied Deposit أكبر من Settlement.

لا تستخدم Cascade Delete على:

* Bids.
* Payments.
* Refunds.
* Settlements.
* Terms Acceptances.
* Audit Logs.

---

# المرحلة التاسعة: الاختبارات

التنفيذ الحالي يحتوي على عدد قليل جدًا من الاختبارات، ولا يعتبر مكتملًا.

لا تحسب الاختبارات الافتراضية مثل:

```php
expect(true)->toBeTrue();
```

ضمن إنجاز نظام المزادات.

أنشئ Feature وIntegration Tests فعلية.

## اختبارات دورة المزاد

1. إنشاء Draft.
2. تعديل Draft.
3. إرسال للمراجعة.
4. اعتماد.
5. رفض.
6. إعادة الإرسال.
7. جدولة.
8. بدء.
9. إنهاء.
10. مزاد بدون Bids.
11. Reserve لم يتحقق.
12. Reserve تحقق.
13. إلغاء حسب كل حالة.

## اختبارات المزايدة

14. رفض قبل البداية.
15. رفض عند وقت النهاية.
16. رفض بعد النهاية.
17. قبول أول Bid.
18. Minimum Increment.
19. Append-only.
20. Sequence Number.
21. Seller cannot bid.
22. Participant غير مؤهل.
23. Deposit غير معتمد.
24. Idempotency.
25. Auto Extension.
26. Maximum Extensions.

## اختبارات التزامن

27. مزايدتان متزامنتان.
28. عشرات Bids متزامنة.
29. Leading Bid واحدة.
30. Sequence غير مكررة.
31. Finalization متزامنة مع آخر Bid.
32. Finalization مرتين.
33. Payment Approval مرتين.
34. Refund مرتين.
35. Deadlock Retry.

اختبارات التزامن يجب أن تعمل على نفس نوع قاعدة بيانات Production، وليس SQLite Memory فقط.

## اختبارات الدفع

36. Private receipt.
37. Unauthorized receipt access.
38. Duplicate payment.
39. Different auctions with same idempotency key.
40. Multiple submissions with one approved only.
41. Rejected submission retains history.
42. Zero deposit skips payment.
43. Cancelled auction cannot reactivate from old payment.
44. JOD three-decimal precision.
45. No floating-point calculations.

## اختبارات التسوية والتسليم

46. Settlement واحدة.
47. Deposit applied once.
48. Remaining amount correct.
49. Platform fee correct.
50. Winner default.
51. Alternative winner.
52. Seller confirms handover.
53. Winner confirms receipt.
54. Unauthorized user rejected.
55. Dispute flow.
56. Completed only after required confirmations.

## اختبارات Scheduler وQueue

57. `schedule:list` يحتوي Jobs المزاد.
58. Expired auction finalizes.
59. Payment deadline detected.
60. Handover deadline detected.
61. Outbox processed.
62. Failed outbox retried.
63. Notification deduplicated.
64. Refund Job retry.

## اختبارات API وResources

65. Public Resource لا تعرض Reserve المخفي.
66. Public Resource لا تعرض بيانات مالية خاصة.
67. Draft غير متاحة للعامة.
68. Public bids visibility.
69. Payment Method ULID contract.
70. جميع الرسائل المطلوبة عربية.
71. Validation attributes عربية.
72. لا توجد Queries داخل Resources.
73. لا توجد N+1.

---

# المرحلة العاشرة: Load Testing

أصلح ملف k6 الحالي.

ممنوع استخدام Token واحد لكل Virtual Users باعتبارهم مزايدين مختلفين.

أنشئ Test Data تحتوي على:

* مزاد Hot Auction صالح.
* مجموعة Tokens لمستخدمين مختلفين مؤهلين.
* Bids متزايدة ومنظمة.
* Idempotency Key منفصلة.
* قراءة السعر الحالي أو توزيع مبالغ يضمن وجود Bids مقبولة.

لا تعتبر `422` نجاحًا عامًا.

قس النتائج منفصلة:

```text
accepted bids
conflicts
validation failures
rate limited
unexpected errors
```

حدد Thresholds مثل:

```text
http_req_failed
p95
p99
accepted bid latency
lock wait
deadlocks
database CPU
queue lag
```

شغل الاختبار في بيئة Production-like إذا كانت متاحة.

إذا لم تكن متاحة:

* لا تدّعِ تحمل ملايين المستخدمين.
* جهز الاختبار الصحيح.
* وثق بوضوح أنه لم ينفذ.
* اذكر البيئة المطلوبة لتشغيله.

---

# فحوصات إلزامية قبل الانتهاء

شغّل:

```bash
php artisan optimize:clear
php artisan route:list --path=auction
php artisan schedule:list
php artisan migrate:status
php artisan test
```

وشغّل:

* PHP lint.
* Static analysis إن كانت أداة موجودة.
* Code formatter.
* Search عن Classes وNamespaces القديمة.
* Query-count checks.
* Database constraints checks.

تحقق من عدم وجود:

```text
TODO
FIXME
Placeholder
temporary implementation
Log-only outbox
fake refund
unused action
dead route
dead relation
old namespace
```

---

# التقارير المطلوبة

حدّث جميع ملفات:

```text
AUCTION_*.md
```

بحيث تعكس الكود الحقيقي، وليس التصميم النظري.

أنشئ أو حدّث:

```text
AUCTION_CORRECTIVE_REVIEW.md
AUCTION_FINAL_ARCHITECTURE.md
AUCTION_FINAL_DATABASE_DESIGN.md
AUCTION_FINAL_FLOW_AR.md
AUCTION_SECURITY_REVIEW.md
AUCTION_PERFORMANCE_REPORT.md
AUCTION_LOAD_TEST_REPORT.md
AUCTION_TEST_REPORT.md
AUCTION_OPERATIONS.md
AUCTION_MIGRATION_REPORT.md
```

يجب أن يحتوي التقرير النهائي على:

## Corrected

المشكلات التي تم إصلاحها.

## Created

الملفات والجداول الجديدة.

## Modified

الملفات المعدلة.

## Deleted

الملفات والجداول والـNamespaces القديمة.

## Tests

* الاختبارات الفعلية الخاصة بالمزاد فقط.
* عددها.
* عدد Assertions.
* قاعدة البيانات المستخدمة.
* نتائج اختبارات التزامن.
* لا تدخل الاختبارات الافتراضية في العدد.

## Security

* Policies.
* Private storage.
* IDOR fixes.
* Financial permissions.
* Rate limits.

## Financial Integrity

* Money precision.
* Idempotency.
* Payment uniqueness.
* Refund behavior.
* Reconciliation.

## Operations

* Scheduler.
* Queue.
* Outbox.
* Notifications.
* Required workers.
* Required environment variables.

## Performance

* Queries الأساسية.
* Query count.
* Indexes.
* Lock duration.
* Load-test status.

## Remaining Risks

اذكر المخاطر المتبقية بصدق.

---

# قواعد التنفيذ

* لا تتوقف عند الخطة.
* لا تكتفِ بتعديل التوثيق.
* نفذ الكود والـMigrations والاختبارات.
* لا تطلب مني تأكيد كل خطوة صغيرة.
* لا تعد كتابة النظام بالكامل دون حاجة؛ أصلح الموجود واحتفظ بالأساس الجيد.
* لا تدّعِ اكتمال Feature لمجرد وجود Class أو جدول.
* لا تعتبر Outbox مكتملة إذا كانت تسجل Log فقط.
* لا تعتبر Refund مكتملة إذا غيرت Status فقط.
* لا تعتبر Scheduler مكتملة قبل ظهورها في `schedule:list`.
* لا تعتبر النظام معربًا طالما الرسائل الإنجليزية تصل للمستخدم.
* لا تعتبر النظام آمنًا بدون Policies واختبارات Authorization.
* لا تعتبر النظام قابلًا لملايين المستخدمين بدون Load Tests فعلية ونتائج موثقة.
* لا تستخدم تعقيدات Microservices أو Kafka بدون احتياج مثبت.
* استخدم Modular Monolith قويًا وواضحًا.
* استمر حتى تنتهي جميع مراحل التصحيح والاختبارات والتوثيق.

ابدأ الآن بمراجعة التنفيذ الحالي مقابل هذه القائمة وملف `Auction.md`، ثم نفذ التصحيحات كاملة.
