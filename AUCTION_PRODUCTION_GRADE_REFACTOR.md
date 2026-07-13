# Auction System — Production-Grade Corrective Refactor

## 1. المشروع ونطاق المهمة

المشروع موجود في:

```text
C:\Users\pc\Desktop\SB\soom
```

اقرأ المشروع كاملًا، ثم راجع جميع الملفات والوثائق الخاصة بنظام المزادات قبل تعديل أي شيء، خصوصًا:

```text
C:\Users\pc\Desktop\SB\soom\Auction.md
C:\Users\pc\Desktop\SB\soom\AUCTION_CORRECTIVE_IMPLEMENTATION.md
C:\Users\pc\Desktop\SB\soom\AUCTION_CURRENT_SYSTEM_AUDIT.md
C:\Users\pc\Desktop\SB\soom\AUCTION_DATABASE_DESIGN.md
C:\Users\pc\Desktop\SB\soom\AUCTION_FULL_FLOW_AR.md
C:\Users\pc\Desktop\SB\soom\AUCTION_SECURITY_REVIEW.md
C:\Users\pc\Desktop\SB\soom\AUCTION_STATE_MACHINES.md
C:\Users\pc\Desktop\SB\soom\AUCTION_TARGET_ARCHITECTURE.md
C:\Users\pc\Desktop\SB\soom\AUCTION_API_DOCUMENTATION.md
```

هذه المهمة تخص نظام المزادات فقط.

لا تعدّل أي Module خارج المزادات إلا إذا كان هناك Dependency حقيقي وضروري، وفي هذه الحالة وثّق السبب بوضوح في التقرير النهائي.

النظام لم يدخل Production بعد، ولذلك مسموح:

* تعديل Schema المزادات.
* تعديل Routes وAPI Contracts.
* حذف Legacy Auction Code غير المستخدم.
* تعديل الحالات والـRelationships.
* إعادة تنظيم الملفات.
* إنشاء Migrations تصحيحية جديدة.

ممنوع:

* استخدام `php artisan migrate:fresh`.
* حذف أو إعادة إنشاء جداول المشروع خارج نظام المزادات.
* الحفاظ على Legacy Compatibility غير الضرورية.
* تنفيذ Refactor شكلي فقط.
* إنشاء طبقات أو Interfaces بلا استخدام حقيقي.
* التوقف عند التحليل أو الخطة دون تنفيذ.

المطلوب تنفيذ التصحيحات كاملة، ثم تشغيل الاختبارات والفحوصات وتقديم تقرير نهائي دقيق.

---

# 2. الهدف الأساسي

حوّل نظام المزادات الحالي إلى نظام Production-Grade حقيقي من حيث:

1. صحة الـBusiness Flow.
2. حماية الأموال ومنع تكرار المعاملات.
3. Concurrency وRace Conditions.
4. Database Integrity.
5. Authorization وIDOR Protection.
6. الخصوصية ومنع تسريب البيانات.
7. Auditability.
8. الأداء والتوسع.
9. وضوح المسؤوليات بين الطبقات.
10. جودة الاختبارات.
11. تعريب جميع الرسائل التي تصل للمستخدم.

اختيار الفائز لا يعني اكتمال المزاد.

المسار الطبيعي المطلوب هو:

```text
Draft
→ Pending Review
→ Awaiting Seller Deposit
→ Scheduled
→ Live
→ Ended
→ Settlement Pending
→ Payment Pending
→ Handover Pending
→ Completed
```

مع دعم المسارات البديلة:

```text
Rejected
Cancelled
Unsold
Winner Defaulted
Disputed
```

لا يصبح المزاد `Completed` إلا بعد:

1. انتهاء المزاد.
2. اختيار الفائز.
3. إنشاء Settlement صحيحة.
4. تطبيق العربون.
5. سداد المبلغ المتبقي.
6. اعتماد الدفع.
7. تأكيد التسليم.
8. تأكيد الاستلام أو صدور قرار إداري موثق.

---

# 3. الهيكلة المعمارية الإلزامية

استخدم الهيكلة الحالية مع استكمالها بصورة عملية:

```text
app/
├── Domain/Auction/
│   ├── Enums/
│   ├── Exceptions/
│   ├── ValueObjects/
│   └── Rules/
├── DTO/Auction/
│   ├── Contracts/
│   ├── Filters/
│   └── *.php
├── Services/Auction/
│   ├── Actions/
│   ├── Queries/
│   └── Support/
├── Repositories/Auction/
├── Models/Auction/
├── Http/Controllers/Auction/
├── Http/Requests/Auction/
├── Http/Resources/Auction/
├── Policies/Auction/
├── Jobs/Auction/
└── Notifications/Auction/
```

لا تنشئ ملفًا إلا إذا كانت له مسؤولية حقيقية.

ممنوع إنشاء:

```text
GenericRepository
BaseRepository
CrudRepository
RepositoryInterface لكل Repository بلا سبب
GenericCrudDTO
God Service
God Repository
God DTO
```

---

# 4. حدود المسؤوليات

## Controllers

يجب أن تكون Thin جدًا.

مسموح داخلها فقط:

* استقبال Form Request.
* إنشاء Input DTO.
* استدعاء Action أو Query Object.
* إرجاع Resource.

ممنوع داخل Controllers:

* Eloquent Queries.
* `DB::transaction`.
* `lockForUpdate`.
* Business Rules.
* State Transitions.
* حسابات مالية.
* `Storage`.
* `Model::create`.
* `$model->update`.
* `$model->save`.
* `$model->delete`.
* Try/Catch عام ومتكرر.

انقل منطق رفع إيصالات الدفع من Controller إلى Action أو Storage Service مناسب.

## Actions / Services

مسؤولة عن:

* Business Rules.
* Authorization المرتبط بالـUse Case بعد Policy/Gate.
* ترتيب خطوات العملية.
* Transaction Boundaries.
* State Transitions.
* استخدام Repositories.
* بناء Persistence DTOs.
* إنشاء Outbox Messages.
* إنشاء Audit Logs.

ممنوع أن تنفذ Actions أو Services عمليات Eloquent مباشرة مثل:

```php
Auction::create(...)
AuctionBid::query()
$model->save()
$model->update(...)
$model->delete()
```

أي استثناء متبقٍ يجب أن يكون له سبب معماري قوي وموثق.

## Repositories

مسؤولة عن:

* قراءة وكتابة البيانات.
* `create`.
* `update`.
* `save`.
* `delete` عندما يكون الحذف مسموحًا.
* Locks.
* Eager Loading.
* Aggregate Queries.
* Persistence.
* `chunkById`.
* `lazyById`.
* Atomic Updates.

ممنوع داخل Repository:

* Business Decisions.
* تحديد ما إذا كان المزاد يجب إلغاؤه.
* Authorization.
* Notifications.
* ترجمة الرسائل.
* HTTP Responses.
* State Machine decisions.
* حساب سياسة Refund أو Winner Default من نفسها.

الـAction تقرر ماذا يحدث، والـRepository تنفذ الوصول إلى البيانات وحفظ النتيجة.

## Query Objects

تستخدم لعمليات القراءة والعرض:

```text
PublicAuctionQuery
SellerAuctionQuery
ParticipantAuctionQuery
AdminAuctionQuery
AuctionBidHistoryQuery
PaymentReviewQuery
```

وتحتوي فقط على:

* Filters.
* Visibility.
* Search.
* Sorting.
* Pagination.
* Eager Loading.
* Read-only aggregations.

---

# 5. إنشاء طبقة DTO للمزادات

أنشئ المسار:

```text
C:\Users\pc\Desktop\SB\soom\app\DTO\Auction
```

الهدف هو كتابة Mapping الأعمدة مرة واحدة بصورة Explicit وآمنة، وليس إخفاء الأعمدة باستخدام Magic أو Reflection.

أنشئ Base DTO صغيرًا فقط، مثل:

```php
abstract readonly class BaseAuctionDTO implements JsonSerializable
{
    abstract public function toArray(): array;

    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
```

ويمكن إنشاء Contract مستقل للـPersistence:

```php
interface PersistenceDTO
{
    public function toPersistenceArray(): array;
}
```

ممنوع أن يحتوي `BaseAuctionDTO` على:

* Eloquent.
* Models.
* `create`.
* `update`.
* `delete`.
* Transactions.
* Authorization.
* Validation.
* Requests.
* Storage.
* Repository calls.
* Reflection لتحويل كل Properties تلقائيًا إلى أعمدة.
* قراءة `$fillable` تلقائيًا.
* جميع أعمدة جميع جداول المزادات.

لا تجعل Repository تستقبل `BaseAuctionDTO` بصورة عامة.

استخدم Type محدد لكل عملية:

```php
AuctionRepository::create(CreateAuctionRecordDTO $dto)
AuctionRepository::updateState(Auction $auction, UpdateAuctionStateDTO $dto)
AuctionBidRepository::createAcceptedBid(CreateBidRecordDTO $dto)
AuctionSettlementRepository::create(CreateSettlementDTO $dto)
AuctionRefundRepository::create(CreateRefundDTO $dto)
AuctionOutboxRepository::store(CreateOutboxMessageDTO $dto)
```

## الفصل بين Input DTO وPersistence DTO

بيانات الـRequest غير الموثوقة يجب ألا تنتقل مباشرة إلى Repository في العمليات الحساسة.

مثال الإنشاء:

```text
StoreAuctionRequest
→ CreateAuctionInputDTO
→ CreateAuctionAction
→ Active Configuration Version
→ Business Rules
→ CreateAuctionRecordDTO
→ AuctionRepository::create()
```

`CreateAuctionInputDTO` يحتوي فقط على البيانات التي يسمح للبائع بإرسالها.

ممنوع أن يحتوي على:

```text
platform_fee_basis_points
platform_fee_fixed_amount
seller_deposit_amount
bidder_deposit_amount
winner_payment_deadline_hours
handover_deadline_hours
extension_window_seconds
extension_duration_seconds
maximum_extension_count
status
seller_id
configuration_version_id
```

هذه القيم تأتي من إعدادات المنصة النشطة، ثم توضع داخل Trusted Persistence DTO.

أنشئ DTOs الفعلية المطلوبة فقط، وقد تشمل:

```text
CreateAuctionInputDTO
CreateAuctionRecordDTO
UpdateAuctionStateDTO
CreateBidRecordDTO
RegisterParticipantDTO
CreateDepositDTO
SubmitPaymentInputDTO
CreatePaymentSubmissionDTO
ApprovePaymentDTO
CreateSettlementDTO
CreateRefundDTO
CreateOutboxMessageDTO
CreateAuditLogDTO
PublicAuctionFiltersDTO
SellerAuctionFiltersDTO
ParticipantAuctionFiltersDTO
AdminAuctionFiltersDTO
```

لا تنشئ DTO لتمرير متغير أو متغيرين فقط دون حاجة.

---

# 6. Configuration Versions وSnapshot

جدول:

```text
auction_configuration_versions
```

موجود لكنه غير مستخدم فعليًا.

نفّذ النظام كاملًا:

* Model.
* Repository.
* Admin Actions عند الحاجة.
* Active Configuration lookup.
* Versioning.
* Effective dates.
* Validation.
* Snapshot عند إنشاء المزاد.

عند إنشاء المزاد:

1. اجلب النسخة النشطة.
2. تأكد أنها صالحة في الوقت الحالي.
3. خذ Snapshot كاملًا للإعدادات المؤثرة.
4. احفظ `configuration_version_id`.
5. احفظ Snapshot داخل المزاد أو جدول Snapshot مخصص.
6. لا تجعل تعديل إعدادات المنصة لاحقًا يغيّر شروط مزاد موجود.

يجب أن تأتي من Configuration Version:

* Seller deposit.
* Bidder deposit.
* Platform fee.
* Minimum bid increment rules.
* Auto-extension settings.
* Maximum extension count.
* Winner payment deadline.
* Handover deadline.
* Terms version.
* Refund and forfeiture policies.
* Alternative winner policy.

البائع لا يتحكم في هذه القيم.

---

# 7. Money والعملات

استخدم Minor Units فقط، بدون Float.

يجب دعم:

```text
JOD = 3 decimal places
EGP = 2 decimal places
USD = 2 decimal places
```

أصلح جميع Requests التي تستخدم Regex ثابتًا بخانتين عشريتين.

يجب أن تعتمد Validation على العملة نفسها.

قيم مثل:

```text
10.001 JOD
```

يجب قبولها.

وقيم مثل:

```text
10.001 EGP
```

يجب رفضها.

لا تسمح لـ`currency_code` بقيمة غير مدعومة تؤدي إلى خطأ 500.

استخدم Validation واضحة ورسالة عربية.

راجع جميع:

* Starting amount.
* Reserve amount.
* Bid amount.
* Deposit amount.
* Settlement amount.
* Platform fee.
* Seller net.
* Refund amount.
* Payment submission amount.

امنع:

* Floats.
* القسمة التي تنتج Float.
* Rounding غير محدد.
* القيم السالبة.
* Overflow.
* Refund أكبر من المبلغ المدفوع.
* Applied Deposit أكبر من الحد المسموح دون معالجة الفرق.

---

# 8. Public Resources والخصوصية

ممنوع استخدام Resource واحدة لجميع أنواع المستخدمين.

أنشئ Resources منفصلة على الأقل:

```text
PublicAuctionResource
ParticipantAuctionResource
SellerAuctionResource
AdminAuctionResource
```

## PublicAuctionResource

ممنوع أن تعرض:

```text
reserve_amount
seller_deposit
bidder_deposit internal details
platform_fee
seller_net
settlement financial details
internal seller_id
winner private details
configuration internals
payment submissions
refund provider details
audit logs
```

لا ترجع Eloquent Models خامًا داخل Resources.

استخدم Resources واضحة لـ:

```text
Category
Country
State
City
Seller public profile
Winning bid public summary
```

راجع Query Objects حتى لا تنفذ Eager Loading لبيانات لا يجب عرضها أصلًا.

أضف اختبارات تثبت أن الـPublic API لا تكشف هذه البيانات.

---

# 9. Policies وAuthorization وIDOR

أنشئ Policies واضحة لكل عملية:

* View public auction.
* View seller auction.
* Update draft.
* Submit for review.
* Cancel auction.
* Register as participant.
* Place bid.
* Submit deposit payment.
* Submit settlement payment.
* Approve or reject payment.
* Confirm seller handover.
* Confirm winner receipt.
* Open dispute.
* Resolve dispute.
* Mark winner defaulted.
* Reassign winner.
* Process refunds.
* View financial information.

لا تعتمد فقط على:

```text
role:admin,user
```

راجع جميع Routes وControllers وActions.

أي عملية تعتمد على `public_id` أو `auction_id` يجب أن تتحقق من Ownership وVisibility.

لا تسمح لأي مستخدم مسجل بتنفيذ Handover أو View Settlement دون أن يكون:

* البائع الصحيح.
* الفائز الصحيح.
* Admin مخولًا.

---

# 10. Migrations وسلامة الـSchema

راجع Migrations المزادات كاملة.

يوجد خطر في وجود Migration حديثة تحذف جداول المزاد بينما إنشاء الـSchema الأساسي موجود في Migration قديمة سبق تسجيلها كمكتملة.

أنشئ Migration تصحيحية حديثة ومستقلة تقوم صراحة بـ:

1. حذف جداول المزادات القديمة فقط، عند الحاجة.
2. إعادة إنشاء Schema المزادات الجديدة كاملًا.
3. إنشاء Foreign Keys.
4. إنشاء Unique Constraints.
5. إنشاء Indexes.
6. إنشاء Check Constraints المناسبة إذا كان MySQL يدعمها.
7. الحفاظ على جداول المشروع الأخرى.

لا تعتمد على إعادة تشغيل Migration قديمة.

ممنوع استخدام `migrate:fresh`.

راجع ترتيب الحذف والإنشاء وفق الـForeign Keys.

راجع أنواع الأعمدة، خصوصًا:

* IDs.
* Public ULIDs.
* Minor monetary units.
* Currency.
* Status.
* Idempotency keys.
* Provider transaction IDs.
* Timestamps.
* JSON snapshots.
* Locking fields.
* Outbox leasing fields.

أضف Constraints لمنع التكرار، حسب التصميم، مثل:

```text
UNIQUE(payment_submission_id) على payment_transactions
UNIQUE(provider, provider_transaction_id)
UNIQUE(auction_id, scoped idempotency key)
UNIQUE deposit per required owner/type where appropriate
UNIQUE active settlement per auction/winner where appropriate
UNIQUE provider_refund_id
```

استخدم Constraints صحيحة ولا تعتمد على Application Checks وحدها.

---

# 11. Place Bid وConcurrency

احتفظ بفكرة Append-only Bids.

داخل Transaction الخاصة بالمزايدة:

1. Lock المزاد.
2. إعادة فحص الحالة.
3. إعادة فحص الوقت.
4. فحص Participant.
5. فحص Deposit.
6. قراءة Current Leading Bid.
7. حساب Minimum Accepted Amount.
8. التحقق من العملة والمبلغ.
9. إنشاء Bid append-only.
10. تحديث Leading Bid.
11. تنفيذ Auto Extension ذرّيًا.
12. تحديث Counters المناسبة.
13. إنشاء Outbox Message.
14. Commit.

راجع `QueryException` handling.

ممنوع اعتبار أي `QueryException` Duplicate Idempotency Key.

تحقق من:

* SQLSTATE.
* Constraint name.
* Error code.

أعد نتيجة العملية القديمة فقط إذا كان الخطأ خاصًا فعلًا بمفتاح الـIdempotency المقصود.

أي Deadlock أو SQL error آخر يجب ألا يتم ابتلاعه.

انقل الاستعلامات الثقيلة خارج Transaction قدر الإمكان، خصوصًا:

```text
COUNT(*)
COUNT(DISTINCT bidder_id)
MAX(...)
```

استخدم أحد الحلول:

* Atomic counters.
* Cached counters.
* Post-commit job.
* Reconciliation job.

لا تطل مدة Lock بدون ضرورة.

اجعل Idempotency Key Scoped على الأقل بواسطة:

```text
auction_id
bidder_id
operation
idempotency_key
```

وأضف اختبارين متزامنين حقيقيين على MySQL يثبتان أن مزايدتين لا تفسدان السعر أو Leading Bid.

---

# 12. Finalization وSettlement

نفّذ Finalization بصورة Idempotent وقابلة لإعادة التشغيل بأمان.

الحالات:

## لا توجد Bids

```text
Unsold
```

## توجد Bids لكن Reserve لم يتحقق

```text
Unsold
```

## Reserve تحقق

1. حدد Winning Bid.
2. أنشئ Settlement مستقلة.
3. طبّق عربون الفائز المؤهل فقط.
4. احسب Remaining Amount.
5. احسب Platform Fee.
6. احسب Seller Net.
7. احفظ Payment Deadline من الـConfiguration Snapshot.
8. أنشئ Outbox Event.
9. أنشئ Audit Log.

إذا كان:

```text
remaining_amount = 0
```

لا تجعل الـSettlement في `PaymentPending`.

انقلها مباشرة إلى الحالة المالية المناسبة، ثم إلى `HandoverPending` حسب الـState Machine.

إذا كان العربون أكبر من المبلغ المطلوب:

* طبق فقط المبلغ اللازم.
* أنشئ Refund للفرق.
* لا تجعل `deposit_applied` يتجاوز Winning Amount.

لا تسمح بإنشاء أكثر من Settlement فعالة لنفس نتيجة الفوز.

---

# 13. Winner Default وAlternative Winner

أعد تصميم `MarkWinnerDefaultedAction` بصورة صحيحة.

عند Default الفائز:

1. Lock المزاد.
2. Lock Winning Bid.
3. Lock Settlement الحالية.
4. تأكد أن المهلة انتهت أو أن هناك قرارًا إداريًا مخولًا.
5. أغلق Settlement الحالية كـDefaulted أو Voided.
6. نفذ سياسة مصادرة العربون أو رده.
7. سجّل السبب والمنفذ والتوقيت.
8. أنشئ Audit Log.
9. أنشئ Outbox Event.

عند اختيار فائز بديل:

* استبعد `defaulted_bidder_id` بالكامل، وليس Winning Bid واحدة فقط.
* لا تختار Bid أخرى لنفس المستخدم المتعثر.
* تحقق أن المشارك البديل:

  * ما زال Eligible.
  * لديه Deposit صالحة ومدفوعة.
  * لم يرد عربونه.
  * لم تلغ مشاركته.
  * وافق على Terms Version الصحيحة.
  * ليس محظورًا.
* طبق سياسة ترتيب الفائز البديل بوضوح.

ممنوع إعادة استخدام Settlement القديمة.

أنشئ Settlement جديدة بالكامل للفائز البديل تشمل:

* Alternative winning amount.
* Deposit applied الخاص به.
* Remaining amount.
* Platform fee.
* Seller net.
* Payment deadline الجديدة.
* Winner reassignment reference.

احتفظ بتاريخ جميع محاولات الفوز والتسويات السابقة.

إذا لم يوجد فائز بديل مؤهل:

```text
Unsold
```

أو الحالة المحددة في السياسة.

---

# 14. Payment Submissions

اجعل دورة الدفع آمنة وIdempotent.

## رفع الإيصال

استخدم Private Storage فقط:

```text
spaces_private
visibility = private
```

لا تخزن Public URL دائمة.

استخدم Temporary Signed URL عند العرض للمستخدم المخول.

لا ترفع الملف بصورة نهائية قبل التحقق من Idempotency والكيانات، بما يسبب Orphan Files.

استخدم أحد التصميمين:

### التصميم المفضل

* Temporary upload.
* Transaction.
* Lock target.
* Revalidation.
* Create submission.
* Commit.
* Finalize file association.
* Cleanup on failure.

أو تصميم مكافئ يضمن عدم وجود Orphan Files.

داخل Transaction النهائية أعد تحميل وقفل:

* Auction.
* Deposit أو Settlement.
* Current winner عند Settlement payment.
* Payment target.

ثم أعد فحص:

* حالة المزاد.
* حالة الهدف.
* Ownership.
* المبلغ المطلوب.
* Deadline.
* عدم الدفع سابقًا.
* عدم إلغاء المزاد.
* عدم تغيير الفائز.

لا تعتمد على Models قرئت قبل رفع الملف ثم أصبحت قديمة.

---

# 15. اعتماد المدفوعات ومنع التكرار

امنع اعتماد أكثر من Submission لنفس الالتزام المالي.

قبل الاعتماد:

1. Lock Payment Submission.
2. Lock Deposit أو Settlement.
3. Lock Auction.
4. تحقق أن Submission ما زالت Pending Review.
5. تحقق أن الهدف لم يدفع سابقًا.
6. تحقق أن المزاد ما زال يسمح بالدفع.
7. تحقق أن Settlement تخص نفس الفائز الحالي.
8. تحقق من المبلغ.
9. تحقق من Provider transaction uniqueness.
10. أنشئ Payment Transaction.
11. حدّث الهدف.
12. حدّث Auction State عند الحاجة.
13. أنشئ Outbox.
14. Commit.

أضف Database Constraints تمنع:

* Transaction ثانية لنفس Submission.
* Provider Transaction مكررة.
* تطبيق Deposit مرتين.
* دفع Settlement مرتين.
* اعتماد Submission قديمة بعد تغيير الفائز.

اجعل Approval وRejection Idempotent بصورة واضحة.

لا تجعل Retry يؤدي إلى مضاعفة المدفوعات.

---

# 16. Refund Lifecycle

نفّذ Refund lifecycle حقيقية:

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

لا يتحول Refund إلى `Succeeded` بمجرد تغيير Status.

يجب دعم:

* Provider refund processor حقيقي أو Adapter واضح.
* Manual admin confirmation موثق إذا كان الرد خارج النظام.
* Provider refund ID.
* Retry count.
* Last error.
* Next retry time.
* Backoff.
* Processing lease.
* Idempotency.
* Audit trail.

اجعل `confirmSucceeded` Idempotent.

إعادة تشغيلها لا يجب أن تزيد:

```text
refunded_amount
```

مرتين.

امنع:

* Refund بقيمة صفر أو سالبة.
* Refund أكبر من refundable amount.
* Provider refund ID مكرر.
* إنشاء أكثر من Refund فعالة لنفس الجزء المالي دون سبب.

أنشئ Refund Plan مناسب عند:

* Auction cancellation.
* Rejection.
* Unsold.
* Winner payment.
* Winner default.
* Alternative winner.
* Completion.
* Deposit excess.

---

# 17. Seller Deposit Lifecycle

عرّف دورة واضحة لعربون البائع:

* متى يكون مطلوبًا؟
* ماذا يحدث إذا كانت قيمته صفرًا؟
* متى يرد؟
* متى يصادر؟
* ماذا يحدث عند Rejection؟
* ماذا يحدث عند Cancellation؟
* ماذا يحدث عند Unsold؟
* ماذا يحدث عند Completed؟
* ماذا يحدث عند Seller breach؟
* من يملك قرار المصادرة؟
* كيف يوثق السبب؟

إذا كان:

```text
seller_deposit_required_minor = 0
```

فبعد اعتماد المزاد لا تنقله إلى `AwaitingSellerDeposit`.

انقله مباشرة إلى:

```text
Scheduled
```

بعد استكمال شروط الجدولة.

لا تنشئ Payment Submission بقيمة صفر.

---

# 18. Cancellation

`CancelAuctionAction` يجب ألا يغير Status فقط.

نفّذ سياسة إلغاء كاملة تراعي:

* من يطلب الإلغاء؟
* حالة المزاد.
* هل بدأ المزاد؟
* هل توجد Bids؟
* هل يوجد Winner؟
* هل توجد Settlement؟
* هل توجد Payments؟
* هل توجد Refunds سابقة؟
* Seller deposit.
* Bidder deposits.
* Platform fees.
* Administrative cancellation reason.

داخل Transaction:

1. Lock Auction.
2. Revalidate cancellation permission.
3. تحديد Cancellation Policy.
4. تحديث الحالة.
5. إنشاء Refund Plan.
6. إلغاء أو Void الالتزامات المالية غير المنفذة.
7. إنشاء Audit Log.
8. إنشاء Outbox Events.
9. Commit.

اجعل العملية Idempotent.

---

# 19. Handover وDisputes

لا يسمح بتنفيذ Seller Handover إلا للبائع الصحيح.

لا يسمح بتنفيذ Winner Receipt إلا للفائز الحالي الصحيح.

لا يصبح المزاد `Completed` إلا بعد تحقق الشروط المحددة.

احفظ:

* Seller confirmed at.
* Winner confirmed at.
* Confirmed by IDs.
* Handover notes.
* Optional evidence.
* Deadline.
* Dispute status.

عند اختلاف الطرفين أو الاعتراض:

```text
Disputed
```

نفّذ:

* Open dispute.
* Admin review.
* Resolution action.
* Resolution reason.
* Audit trail.
* Financial consequences إن وجدت.

لا تسمح لمستخدم مسجل عادي بإكمال Handover لمزاد لا يخصه.

---

# 20. Outbox

الـOutbox الحالية لا يجب أن تكون مجرد `Log::info()` ثم `Published`.

نفّذ Consumers أو Listeners حقيقية للأحداث.

أمثلة:

```text
AuctionApproved
AuctionScheduled
AuctionStarted
BidPlaced
AuctionExtended
AuctionEnded
AuctionFinalized
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
DisputeOpened
DisputeResolved
```

يجب أن يحتوي Outbox Processor على:

* Lease.
* Attempt count.
* Retry.
* Backoff.
* Failed status.
* Error message.
* `available_at`.
* Dead-letter strategy أو Manual review.
* Idempotent consumer handling.

لا تعتبر الرسالة Published إلا بعد نجاح Consumer الفعلي.

لا تنشئ Notifications أو Broadcasts داخل Transaction الأساسية قبل Commit.

---

# 21. Scheduler وJobs

تحقق من تسجيل جميع Scheduled Commands عبر:

```bash
php artisan schedule:list
```

اجعل Start/End/Finalize/Reconcile Jobs آمنة عند تشغيل أكثر من Worker.

لكل Auction:

```text
Transaction
→ lockForUpdate أو skipLocked
→ recheck
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

حسب الحالة.

ممنوع تحميل جميع السجلات دفعة واحدة.

اجعل Jobs:

* Idempotent.
* Retry-safe.
* Timezone-safe.
* لا تنفذ نفس State Transition مرتين.
* لا ترسل نفس Notification مرتين.

راجع:

```text
StartDueAuctionsAction
EndDueAuctionsAction
FinalizeAuctionsAction
ReconcileAuctionsAction
RefundPendingAuctionDepositsJob
Outbox processing jobs
```

---

# 22. Models والعلاقات

راجع جميع علاقات User والمزادات.

أزل العلاقات القديمة التي تعتمد على أعمدة غير موجودة مثل:

```text
user_id
winner_id
```

إذا كان التصميم الجديد يستخدم:

```text
bidder_id
winning_bid_id
seller_id
```

أنشئ العلاقات الصحيحة.

راجع:

* User → seller auctions.
* User → bids.
* User → participants.
* User → deposits.
* User → winning bids.
* Auction → winning bid.
* Auction → current leading bid.
* Auction → settlements.
* Settlement → winner.
* Payment submission → payable target.
* Refund → source transaction/deposit.
* Handover confirmations.

لا تستخدم Relationship توحي بوجود Winner مباشر إذا كانت الحقيقة مستخرجة من Winning Bid، إلا إذا كان هناك Snapshot مقصود وموثق.

---

# 23. التعريب والاستثناءات

أنشئ أو أكمل:

```text
lang/ar/auction.php
lang/en/auction.php
lang/ar/validation.php
```

جميع الرسائل التي تصل للمستخدم يجب أن تكون عربية في الواجهة العربية.

القيم الداخلية تبقى بالإنجليزية:

```text
pending_review
scheduled
live
completed
```

ويضاف:

```text
status_label
```

من ملفات الترجمة.

استخدم Domain Exceptions واضحة مثل:

```text
AuctionNotOpenForBiddingException
BidBelowMinimumException
ParticipantDepositRequiredException
AuctionPaymentDeadlineExpiredException
PaymentAlreadyApprovedException
WinnerNoLongerCurrentException
RefundAmountExceedsAvailableException
UnauthorizedHandoverException
InvalidAuctionStateTransitionException
```

اربطها بـCentral Exception Handler.

لا تستخدم `RuntimeException` أو رسائل إنجليزية عشوائية للمستخدم.

---

# 24. الحذف والـAudit

المزادات والسجلات المالية ليست CRUD عادية.

ممنوع Physical Delete لـ:

* Bid.
* Deposit.
* Payment Submission.
* Payment Transaction.
* Settlement.
* Refund.
* Winner reassignment.
* Audit log.
* Financial outbox history.

يمكن السماح بحذف Draft فقط إذا:

* لم يقدم للمراجعة.
* لم يبدأ.
* لا توجد Participants.
* لا توجد Bids.
* لا توجد Payments.
* لا توجد Financial Records.

غير ذلك استخدم حالات مثل:

```text
Cancelled
Rejected
Voided
Failed
Archived
```

كل عملية حساسة يجب أن تسجل:

* Actor.
* Action.
* Entity.
* Old state.
* New state.
* Reason.
* Timestamp.
* Metadata.
* Request correlation/idempotency key عند الحاجة.

---

# 25. الاختبارات الإلزامية

الاختبارات الحالية غير كافية.

أنشئ Feature وIntegration Tests حقيقية تغطي على الأقل:

## Auction creation

* Seller can create valid draft.
* Platform configuration overrides seller input.
* Seller cannot set platform fee.
* Seller cannot set deposits.
* Configuration snapshot is saved.
* Unsupported currency is rejected.
* JOD accepts 3 decimal places.
* EGP rejects 3 decimal places.

## Review

* Admin approve.
* Admin reject with reason.
* Zero seller deposit moves directly to Scheduled.
* Required seller deposit moves to AwaitingSellerDeposit.

## Participants and deposits

* Registration creates Participant, not zero Bid.
* Deposit requirement enforced.
* Duplicate registration prevented.
* Deposit payment approval is idempotent.

## Bidding

* Valid bid.
* Bid below minimum rejected.
* Auction state checked inside transaction.
* Auction time checked inside transaction.
* Auto extension.
* Maximum extensions.
* Duplicate idempotency request returns same result.
* Non-idempotency SQL errors are rethrown.
* Two concurrent bids on MySQL result in one valid leading state.

## Finalization

* No bids → Unsold.
* Reserve not reached → Unsold.
* Reserve reached → Settlement.
* Deposit applied once.
* Remaining zero skips PaymentPending.
* Excess deposit creates refund.

## Payments

* Private receipt storage.
* Unauthorized receipt access rejected.
* Duplicate settlement approvals prevented.
* Provider transaction uniqueness.
* Old submission rejected after winner changes.
* Cancelled auction payment cannot be approved.
* Retry does not duplicate transaction.

## Winner default

* Defaulted bidder excluded بالكامل.
* Another bid from same defaulted bidder cannot win.
* Ineligible alternative bidder skipped.
* Old settlement not reused.
* New settlement created.
* No eligible alternative → Unsold.

## Refunds

* Zero refund rejected.
* Over-refund rejected.
* Success confirmation idempotent.
* Provider refund ID unique.
* Retry-safe processing.
* Cancellation generates correct refund plan.

## Handover

* Only seller confirms seller handover.
* Only current winner confirms receipt.
* Random authenticated user rejected.
* Completion only after required confirmations.
* Dispute blocks normal completion.

## Privacy

* Public resource does not expose reserve.
* Public resource does not expose seller net.
* Public resource does not expose platform fee.
* Public resource does not expose private winner/payment details.

## Scheduler and Outbox

* Scheduled jobs are registered.
* Job reruns are idempotent.
* Concurrent scheduler runs do not duplicate transitions.
* Outbox message is not Published before consumer succeeds.
* Failed consumer is retried.

شغّل MySQL Integration Tests، ولا تعتمد على SQLite فقط لإثبات:

* Row locks.
* Deadlocks.
* `skipLocked`.
* Decimal behavior.
* Unique constraints.
* Concurrent transactions.

---

# 26. الأداء

راجع N+1 وQuery Counts.

لا تنفذ Aggregations ثقيلة داخل Transaction الخاصة بالمزايدة.

راجع Indexes المطلوبة على الأقل لـ:

```text
auctions(status, starts_at)
auctions(status, ends_at)
auction_bids(auction_id, amount_minor, id)
auction_bids(auction_id, bidder_id)
auction_participants(auction_id, bidder_id)
auction_deposits(auction_id, user_id, type, status)
payment_submissions(status, created_at)
payment_transactions(provider, provider_transaction_id)
refund_transactions(status, available_at)
outbox_messages(status, available_at)
auction_settlements(auction_id, status)
```

حدد Indexes بناءً على الاستعلامات الفعلية وليس بالتخمين فقط.

أضف Query-count tests للـList/Show endpoints المهمة.

أصلح Load Test بحيث:

* لا يستخدم Token واحدًا لكل المستخدمين.
* لا يعتبر 422 نجاحًا.
* يختبر مزايدين متعددين.
* يسجل latency وerror rate.
* يختبر Race Conditions.
* يوثق البيئة والنتائج الفعلية.

---

# 27. ترتيب التنفيذ

نفّذ بالترتيب التالي لتقليل المخاطر:

## المرحلة الأولى — P0

1. Migration safety.
2. Authorization وIDOR.
3. Public resource privacy.
4. Money/JOD validation.
5. Configuration versions and snapshots.
6. Payment integrity.
7. Duplicate transaction prevention.
8. Winner default correctness.
9. Refund idempotency.
10. Handover authorization.

## المرحلة الثانية — P1

1. Seller deposit lifecycle.
2. Cancellation financial flow.
3. Settlement edge cases.
4. Alternative winner flow.
5. Disputes.
6. Scheduler concurrency.
7. Real Outbox consumers.
8. Notifications and queues.

## المرحلة الثالثة — P2

1. DTO layer.
2. Complete Repository boundary.
3. Thin Controllers.
4. Separate Resources.
5. Central Exceptions.
6. Localization.
7. Relationships cleanup.
8. Remove legacy auction namespaces and code.

## المرحلة الرابعة — P3

1. Feature tests.
2. MySQL concurrency tests.
3. Query-count tests.
4. Scheduler tests.
5. Outbox tests.
6. Load tests.
7. Documentation update.

لا تجعل المرحلة الثالثة مجرد تنظيم للكود الخاطئ قبل إصلاح P0.

---

# 28. الفحوصات المطلوبة

بعد التنفيذ شغّل ما يناسب المشروع، على الأقل:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan schedule:list
php artisan migrate:status
php artisan test
```

وشغّل كذلك:

```bash
php -l
```

على جميع ملفات PHP المعدلة والمنشأة.

إذا كان المشروع يستخدم أدوات إضافية، شغّلها مثل:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
```

لا تدّعِ نجاح اختبار لم يتم تشغيله فعليًا.

إذا تعذر تشغيل أمر بسبب البيئة، اذكر:

* الأمر.
* سبب التعذر.
* ما الذي تم فحصه بديلًا.
* ما الذي ما زال يحتاج تشغيلًا محليًا.

---

# 29. البحث عن المخالفات المتبقية

بعد الانتهاء، ابحث داخل نظام المزادات عن أي استخدام مباشر متبقٍ لـ:

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

صنّف كل نتيجة:

* تم نقلها.
* بقاؤها مقصود.
* سبب بقائها.
* الطبقة التي توجد بها.

يجب ألا توجد عمليات كتابة مباشرة داخل Controllers أو Actions أو Services إلا باستثناء واضح ومبرر.

ابحث أيضًا عن:

```text
winner_id
user_id
platform_fee_basis_points داخل StoreAuctionRequest
seller_deposit_amount داخل StoreAuctionRequest
bidder_deposit_amount داخل StoreAuctionRequest
reserve_amount داخل PublicAuctionResource
seller_net داخل PublicAuctionResource
```

وتأكد من تنظيفها أو تبريرها.

---

# 30. التوثيق النهائي

حدّث وثائق المزادات النهائية فقط لتطابق الكود الفعلي، ولا تترك توثيقًا يصف تصميمًا غير منفذ.
9. Repositories المنشأة أو المعدلة.
10. Direct Eloquent Usage المتبقي وسبب بقائه.
11. State Machines النهائية.
12. Payment flow النهائي.
13. Refund flow النهائي.
14. Winner default flow النهائي.
15. Cancellation flow النهائي.
16. Outbox consumers.
17. Policies والصلاحيات.
18. الاختبارات المنشأة.
19. أوامر الفحص التي تم تشغيلها.
20. نتائج الاختبارات الفعلية.
21. أي فحوصات تعذر تشغيلها.
22. المخاطر أو النقاط المتبقية، إن وجدت.

---

# 31. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا عند تحقق الآتي:

* البائع لا يتحكم في إعدادات المنصة.
* كل مزاد يحتفظ بـConfiguration Snapshot ثابتة.
* JOD تعمل بثلاث خانات دون Float.
* Public API لا تكشف بيانات مالية حساسة.
* Policies تمنع IDOR.
* Controllers لا تحتوي Business Logic أو Storage أو Eloquent.
* Actions لا تنفذ Persistence مباشرة.
* Repositories تنفذ عمليات قاعدة البيانات الفعلية.
* DTOs Typed وصريحة ولا تعتمد على Magic.
* Base DTO صغيرة ولا تحتوي CRUD.
* Place Bid آمنة تحت التزامن.
* QueryException لا تُبتلع بصورة خاطئة.
* المدفوعات لا يمكن اعتمادها مرتين.
* Provider Transactions لا تتكرر.
* Settlement القديمة لا يعاد استخدامها لفائز بديل.
* Defaulted bidder يُستبعد بالكامل.
* Refund success لا يطبق مرتين.
* Seller deposit لها Lifecycle واضحة.
* Cancellation تنشئ Financial Plan صحيحًا.
* Handover لا ينفذه إلا الطرف الصحيح.
* Outbox تنفذ Consumers حقيقية.
* Scheduler آمنة مع أكثر من Worker.
* Migrations لا تعتمد على إعادة تشغيل Migration قديمة.
* العلاقات القديمة المكسورة تمت إزالتها.
* توجد اختبارات MySQL حقيقية للـConcurrency.
* التوثيق النهائي يطابق التنفيذ.
* لا توجد ادعاءات غير مثبتة بنتائج أوامر فعلية.

ابدأ بالمراجعة الفعلية للكود الحالي، ثم نفّذ التصحيحات كاملة.

لا تتوقف عند كتابة خطة.

لا تطلب موافقة بين المراحل.

لا تنفذ Refactor شكليًا.

اتخذ أفضل قرار معماري بسيط وآمن عند وجود تفصيلة غير موضحة، ووثّق القرار في التقرير النهائي.

الأولوية دائمًا لصحة الأموال، سلامة البيانات، التزامن، الأمان، ثم نظافة الكود.
