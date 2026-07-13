# TASK 11 — Complete Auction Configuration Snapshot

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إكمال نظام Configuration Snapshot للمزادات بحيث تصبح كل قاعدة مالية وتشغيلية مؤثرة على دورة المزاد محفوظة كنسخة ثابتة مرتبطة بالمزاد نفسه، ولا تعتمد العمليات اللاحقة على Config عامة قابلة للتغيير.

المطلوب أن يظل سلوك المزاد ثابتًا حتى لو تغيرت إعدادات المنصة بعد:

```text
Auction creation
Auction approval
Seller deposit payment
Auction scheduling
Auction start
Winner selection
Settlement creation
Winner default
Cancellation
Refund planning
```

هذه المهمة تخص فقط:

```text
Auction configuration snapshot completeness
Snapshot creation timing
Snapshot immutability
Snapshot versioning
Snapshot reads in business flows
Legacy/current auction handling
Snapshot validation and tests
```

لا تنفذ الآن:

- General Outbox consumers.
- General Scheduler concurrency.
- Resource privacy.
- Full DTO/Repository cleanup.
- Financial flow جديد غير موجود.
- تغيير سياسات المزادات نفسها دون ضرورة.

المطلوب هو جعل القواعد الحالية تقرأ من Snapshot ثابتة، وليس إعادة اختراع كل Business Policies.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/CreateAuctionAction.php
app/Services/Auction/Actions/ReviewAuctionAction.php
app/Services/Auction/Actions/FinalizeAuctionAction.php
app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
app/Services/Auction/Actions/MarkWinnerDefaultedAction.php
app/Services/Auction/Actions/CancelAuctionAction.php
app/Services/Auction/Actions/ResolveAuctionDisputeAction.php
app/Services/Auction/Actions/*
app/Domain/Auction/
app/DTO/Auction/
app/Repositories/Auction/AuctionRepository.php
app/Repositories/Auction/AuctionConfigurationRepository.php
app/Models/Auction/Auction.php
app/Models/Auction/AuctionConfiguration.php
app/Models/Auction/AuctionConfigurationVersion.php
app/Models/Auction/AuctionConfigurationSnapshot.php
app/Models/Auction/AuctionSettlement.php
app/Models/Auction/AuctionDeposit.php
config/auction.php
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

راجع أي مكان يقرأ مباشرة من:

```text
config('auction...')
global platform settings
latest configuration version
current auction configuration
request-provided financial fields
```

وابحث في المشروع عن كلمات مثل:

```text
seller_deposit
bidder_deposit
platform_fee
payment_deadline
handover_deadline
alternative_winner
hold_top_n
refund_policy
forfeit_policy
reserve_price
minimum_increment
currency
terms_version
```

لا تعتمد على التقارير السابقة دون مراجعة الكود الفعلي.

---

# 2. المشكلة الحالية التي يجب التحقق منها

الاشتباه الحالي أن بعض البيانات محفوظة داخل Snapshot، لكن قرارات لاحقة ما زالت تقرأ من:

```text
config/auction.php
current configuration row
latest active configuration version
request values
```

وهذا قد يؤدي إلى تغيير سلوك مزاد قائم بعد تعديل إعدادات المنصة.

مثال:

```text
Auction A approved with winner payment deadline = 48 hours
Platform config later changed to 12 hours
Winner default flow reads current config
Auction A is defaulted after 12 hours instead of 48
```

أمثلة أخرى:

- تغيير Seller Deposit بعد إنشاء المزاد.
- تغيير Bidder Deposit أثناء التسجيل.
- تغيير Platform Fee قبل Settlement.
- تغيير Alternative Winner policy بعد Finalization.
- تغيير Deposit hold policy قبل دفع الفائز.
- تغيير Forfeiture policy قبل Winner Default.
- تغيير Cancellation refund policy بعد بدء المزاد.
- تغيير Terms version بعد قبول المشاركين.

تحقق من الكود الفعلي وحدد كل القراءات غير الثابتة.

---

# 3. تحديد توقيت إنشاء Snapshot

حدد توقيتًا واحدًا واضحًا.

التصميم المفضل غالبًا:

## عند إنشاء Draft

يمكن ربط المزاد بـConfiguration Version مبدئية لأغراض العرض.

لكن لا تعتبرها نهائية إذا كان البائع يستطيع تعديل خصائص تؤثر على السياسة قبل الإرسال.

## عند Submit for Review أو Approval

يجب تثبيت Snapshot النهائية قبل أن يدخل المزاد دورة مالية أو تشغيلية ملزمة.

الاختيار المفضل:

```text
Snapshot is finalized atomically during auction approval
```

لأن اعتماد الأدمن هو لحظة قبول المنصة للمزاد وشروطه.

إذا كان التصميم الحالي يثبتها وقت الإنشاء، لا تغيّر التوقيت دون سبب قوي؛ لكن يجب أن تضمن:

- Snapshot موجودة قبل إنشاء أي Deposit obligation.
- Snapshot موجودة قبل Scheduling.
- Snapshot غير قابلة للتغيير بعد بدء الالتزامات المالية.

وثّق القرار النهائي.

---

# 4. Snapshot ليست مجرد `configuration_id`

لا يكفي تخزين:

```text
auction_configuration_version_id
```

إذا كانت بيانات الـVersion نفسها قابلة للتعديل أو الحذف.

المطلوب واحد من تصميمين صحيحين:

## A. Immutable Configuration Version

- Version row غير قابلة للتعديل بعد استخدامها.
- Auction تشير إلى version ثابتة.
- لا يمكن حذفها إذا مرتبطة بمزاد.

أو:

## B. Materialized Snapshot

- نسخ القيم المؤثرة إلى جدول Snapshot مرتبط بالمزاد.
- القيم لا تتغير بعد التثبيت.

يمكن استخدام الاثنين معًا:

```text
source_configuration_version_id
+ materialized snapshot values
```

لا تعتمد على JSON فقط دون Validation وTyped access إذا كانت الحقول معروفة وثابتة.

---

# 5. الحقول المطلوبة داخل Snapshot

راجع القواعد الفعلية في المشروع، ويجب أن تشمل Snapshot كل قيمة تؤثر على قرار لاحق.

على الأقل تحقق من وجود ما يلي إن كان مستخدمًا:

## Currency and money precision

```text
currency_id
currency_code
currency_minor_unit
```

## Bidding rules

```text
minimum_bid_increment_minor
reserve_price_policy
auto_extend_enabled
auto_extend_window_seconds
auto_extend_duration_seconds
maximum_extensions
```

## Seller deposit

```text
seller_deposit_required_minor
seller_deposit_payment_deadline_minutes
seller_deposit_refund_policy
seller_deposit_forfeit_policy
seller_cancellation_policy_before_start
seller_cancellation_policy_after_start
seller_breach_policy
```

## Bidder deposit

```text
bidder_deposit_required_minor
bidder_deposit_payment_deadline_policy
bidder_deposit_hold_policy
alternative_candidate_hold_policy
alternative_candidate_limit
```

## Winner and settlement

```text
winner_payment_deadline_minutes
handover_deadline_minutes
platform_fee_type
platform_fee_value
platform_fee_min_minor
platform_fee_max_minor
deposit_application_policy
```

## Winner default

```text
winner_default_deposit_policy
winner_default_forfeit_type
winner_default_forfeit_value
alternative_winner_enabled
alternative_winner_selection_policy
maximum_reassignments
```

## Cancellation and refunds

```text
seller_fault_cancellation_policy
buyer_fault_cancellation_policy
platform_fault_cancellation_policy
neutral_cancellation_policy
refund_processing_mode
```

## Terms and compliance

```text
terms_version_id
terms_hash
required_acceptance_scope
```

## Operational limits

```text
payment_submission_review_deadline
manual_override_allowed
manual_review_required_for_fraud
```

لا تضف حقولًا غير مستخدمة لمجرد وجودها هنا.

لكن أي قيمة تؤثر فعليًا على Business Decision يجب أن تكون ثابتة داخل Snapshot أو immutable version.

---

# 6. Platform Fee Snapshot

Platform Fee من أخطر القيم.

يجب ألا تُحسب Settlement باستخدام Config الحالية.

المطلوب:

```text
platform_fee_type
platform_fee_value
platform_fee_min_minor
platform_fee_max_minor
```

تُقرأ من Snapshot.

ويجب حفظ الناتج المحسوب داخل Settlement:

```text
platform_fee_minor
seller_net_minor
```

Snapshot تحدد القاعدة.

Settlement تحفظ النتيجة التاريخية.

لا تعِد حساب Settlement قديمة بعد تغيير Config.

---

# 7. Deposit Policies Snapshot

كل القرارات التالية يجب أن تعتمد على Snapshot:

```text
Seller deposit required amount
Bidder deposit required amount
Non-winner hold policy
Top N candidate count
Seller deposit refund/forfeit policy
Winner default deposit disposition
Applied deposit policy
```

لا تقرأها من `config()` داخل:

```text
FinalizeAuctionAction
MarkWinnerDefaultedAction
ReleaseNonWinnerDepositsAction
ResolveSellerDepositDispositionAction
CancelAuctionFinanciallyAction
```

---

# 8. Deadline Snapshot

كل Deadline يجب أن تأتي من Snapshot وقت إنشاء الالتزام.

مثال:

```text
winner_payment_deadline_minutes = 2880
```

عند إنشاء Settlement:

```text
payment_due_at = settlement_created_at + snapshot value
```

بعد ذلك لا تعِد حساب `payment_due_at` من Snapshot أو Config مرة أخرى.

نفس المبدأ:

```text
seller deposit due at
handover due at
registration/deposit cutoff
```

Snapshot تحدد مدة الإنشاء.

الكيان المالي يحفظ Timestamp النهائية.

---

# 9. Terms Snapshot

لا يكفي تخزين Terms الحالية على Participant فقط.

المطلوب أن يعرف المزاد:

```text
أي Terms version كانت مطلوبة؟
```

ويجب أن تتحقق Alternative Winner eligibility من:

```text
acceptance.auction_id
acceptance.user_id
acceptance.terms_version_id = auction snapshot terms_version_id
```

إذا كانت Terms قابلة للتعديل داخل نفس row، أضف:

```text
terms_hash
```

أو استخدم Version immutable.

لا تجعل تحديث نص الشروط يغيّر معنى قبول قديم.

---

# 10. Snapshot Immutability

بعد تثبيت Snapshot:

- لا يوجد Update endpoint يغيرها.
- لا يوجد Model fillable يسمح بتعديلها عشوائيًا.
- لا يمكن تغيير `configuration_version_id` للمزاد.
- لا يمكن حذف Configuration Version مستخدمة.
- لا يمكن تعديل Immutable Version مستخدمة.
- أي تعديل سياسة جديد ينشئ Version جديدة.

أضف Guards على مستوى:

```text
Application
Model events only if suitable
Database foreign keys/constraints
Admin configuration service
```

لا تعتمد على Frontend.

---

# 11. Snapshot Reader Typed

أنشئ Value Object أو DTO Typed مثل:

```text
AuctionConfigurationSnapshotData
```

أو:

```text
AuctionRulesSnapshot
```

ويحتوي Accessors واضحة.

مثال:

```php
$snapshot->winnerPaymentDeadlineMinutes();
$snapshot->sellerDepositRequiredMinor();
$snapshot->nonWinnerHoldPolicy();
$snapshot->platformFeePolicy();
```

لا تكرر:

```php
$data['winner_payment_deadline_minutes'] ?? config(...)
```

في كل Action.

ممنوع fallback صامت إلى Current Config لمزاد لديه Snapshot ناقصة.

إذا Snapshot ناقصة:

```text
fail explicitly
manual review / configuration error
```

ولا تستخدم أحدث Config تلقائيًا.

---

# 12. Legacy / Existing Auctions

النظام لم يُطلق بعد حسب سياق المشروع، لذلك يمكن إصلاح البيانات الحالية بصورة منظمة، لكن:

- لا تستخدم `migrate:fresh`.
- لا تمسح جداول المزادات.
- لا تنشئ Snapshot وهمية بلا مصدر.

أنشئ Migration/Command آمنة لتعبئة Snapshots للمزادات الحالية.

الاستراتيجية المطلوبة:

1. إذا Auction مرتبطة بـConfiguration Version:
   - أنشئ Snapshot منها.
2. إذا توجد قيم مالية تاريخية في Auction/Deposits/Settlement:
   - استخدمها عند الحاجة بدل Current Config.
3. إذا لا يمكن تحديد قيمة موثوقة:
   - علّم المزاد:
     ```text
     configuration_review_required
     ```
     أو سجل تقريرًا واضحًا.
4. لا تخمن سياسة مالية خطيرة.

بما أن النظام غير Production، يمكن تعديل Fixtures الحالية، لكن يجب أن تكون Migrations آمنة.

---

# 13. Snapshot Completeness Validation

أنشئ Validator مركزيًا يتحقق قبل Approval من:

- الحقول المطلوبة موجودة.
- المبالغ غير سالبة.
- النسب ضمن الحدود.
- min <= max.
- deadlines موجبة أو nullable حسب السياسة.
- Top N أكبر من صفر عند استخدام policy المناسبة.
- Alternative winner policy متوافقة مع hold policy.
- Terms version موجودة.
- Currency موجودة ونشطة.
- Fee policy قابلة للحساب.
- Refund/forfeit policies معروفة.

لا تسمح Approval مع Snapshot ناقصة.

---

# 14. Source Priority

الترتيب الصحيح:

## للمزاد القائم بعد تثبيت Snapshot

```text
Auction Snapshot
→ entity timestamps/results
```

ولا تستخدم Current Config.

## لإنشاء مزاد جديد قبل Snapshot

```text
Current active configuration version
```

ثم تُنسخ/تثبت.

## للـSettlement التاريخية

استخدم القيم المحفوظة داخل Settlement للنتيجة، وSnapshot لفهم السياسة.

---

# 15. إزالة Fallbacks الخطرة

ابحث عن أنماط مثل:

```php
$snapshotValue ?? config('auction...')
$auction->config?->value ?? default
latestConfiguration()
activeConfiguration()
```

داخل Flows لمزاد قائم.

أزلها أو اجعلها:

```text
Explicit configuration exception
```

لا تسمح بتغيير السياسة بصمت.

يمكن استخدام Default فقط عند إنشاء Configuration جديدة، وليس عند تشغيل مزاد قائم.

---

# 16. Repository Boundaries

أنشئ أو أكمل Repositories واضحة:

```text
AuctionConfigurationRepository
AuctionConfigurationSnapshotRepository
```

المطلوب:

- Load active version for new auction.
- Lock version during snapshot creation.
- Create immutable snapshot.
- Load snapshot by auction.
- Detect missing/incomplete snapshot.

لا تضع Business Decisions داخل Repository.

---

# 17. Action Integration

راجع وأصلح على الأقل:

## ReviewAuctionAction

- ينشئ/يثبت Snapshot.
- ينشئ Seller Deposit obligation من Snapshot.
- يحدد الحالة التالية من Snapshot.

## Submit/Review Payment

- يتحقق من amount/currency/deadline من Snapshot أو obligation timestamps.

## FinalizeAuctionAction

- يحسب Settlement fees/deadlines من Snapshot.
- يطبق Deposit policy من Snapshot.
- يحدد non-winner hold policy من Snapshot.

## MarkWinnerDefaultedAction

- يقرأ deadline/default/alternative policies من Snapshot.
- ينشئ Settlement الجديدة من نفس Snapshot.

## Seller Deposit Disposition

- يقرأ refund/forfeit policy من Snapshot.

## Central Cancellation

- يقرأ liability policies من Snapshot.

## Dispute Resolution

- لا يتجاوز Snapshot دون Override موثق.

---

# 18. Admin Configuration Versioning

راجع كيفية تعديل إعدادات المزادات.

المطلوب:

- إنشاء Version جديدة بدل تعديل المستخدمة.
- `effective_from`.
- `created_by`.
- `status = Draft/Active/Retired`.
- Version واحدة Active عند الحاجة.
- Retired versions تظل قابلة للقراءة.
- لا يمكن حذف Version مستخدمة.
- تفعيل Version جديدة لا يغير مزادات قائمة.

لا تنفذ UI جديدة، ركز على Backend.

---

# 19. Audit

سجل أحداثًا مثل:

```text
auction_configuration_snapshot_created
auction_configuration_snapshot_validation_failed
auction_configuration_version_activated
auction_configuration_version_retired
auction_configuration_legacy_review_required
```

Metadata المهمة:

```text
auction_id
source_version_id
snapshot_id
snapshot_hash
created_by
validation_errors
```

لا تسجل Snapshot كاملة إذا تحتوي بيانات حساسة، لكن يمكن حفظ Hash.

---

# 20. Snapshot Hash

يفضل إنشاء Hash ثابت مثل:

```text
sha256(canonical serialized snapshot)
```

ويُحفظ في:

```text
snapshot_hash
```

المطلوب:

- ترتيب Canonical للحقول.
- نفس القيم تنتج نفس Hash.
- أي تعديل غير مصرح يظهر كاختلاف.
- لا تستخدم JSON غير مرتب.

Hash ليست بديلًا عن Immutability، لكنها Integrity check.

---

# 21. الاستثناءات والرسائل

أضف Exceptions واضحة، مثل:

```text
AuctionConfigurationSnapshotMissingException
AuctionConfigurationSnapshotIncompleteException
AuctionConfigurationSnapshotImmutableException
AuctionConfigurationVersionInUseException
AuctionConfigurationVersionNotActiveException
AuctionTermsVersionMismatchException
AuctionConfigurationRequiresManualReviewException
```

الرسائل للمستخدم أو الأدمن تكون عربية.

---

# 22. DTOs وValue Objects

استخدم Types واضحة مثل:

```text
AuctionConfigurationSnapshotData
PlatformFeePolicyData
SellerDepositPolicyData
BidderDepositPolicyData
WinnerDefaultPolicyData
CancellationPolicyData
```

لكن لا تنشئ God DTO ضخمة بلا تقسيم.

يمكن أن تكون Snapshot root تحتوي Value Objects أصغر.

لا تستخدم Arrays متداخلة مجهولة.

---

# 23. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Snapshot creation

- Active config version موجودة.
- Auction approved.

المتوقع:

- Snapshot تنشأ مرة واحدة.
- مرتبطة بالمزاد.
- source version محفوظة.
- hash محفوظ.

## B. Approval retry

شغّل Approval مرتين Idempotently.

المتوقع:

- Snapshot واحدة.
- لا duplicate deposit obligation.

## C. Config changes after approval

1. Approve Auction A بقيمة:
   ```text
   seller deposit = 100
   winner deadline = 48h
   platform fee = 5%
   ```
2. فعّل Config جديدة:
   ```text
   seller deposit = 200
   winner deadline = 12h
   platform fee = 8%
   ```

المتوقع:

- Auction A تظل على 100 و48h و5%.
- Auction B الجديدة تستخدم 200 و12h و8%.

## D. Seller deposit amount

Payment validation لمزاد قديم تستخدم Snapshot لا Current Config.

## E. Finalization fee

Settlement لمزاد قديم تحسب Fee من Snapshot القديمة.

## F. Winner deadline

Settlement تستخدم deadline من Snapshot القديمة.

## G. Winner default policy

بعد تغيير Config، Auction القديمة تستخدم Default policy القديمة.

## H. Non-winner hold policy

بعد تغيير Config، Auction القديمة تستخدم hold policy القديمة.

## I. Seller cancellation policy

بعد تغيير Config، Auction القديمة تستخدم cancellation policy القديمة.

## J. Terms version

- Candidate accepted Terms version X.
- Auction snapshot requires X.
- النجاح.

ثم Candidate accepted Y فقط والمزاد يتطلب X:

```text
Rejected
```

## K. Missing snapshot

أي Flow مالي على Auction بلا Snapshot:

```text
Fails explicitly
```

ولا يستخدم Current Config.

## L. Incomplete snapshot

Approval ترفض.

## M. Snapshot immutability

محاولة تعديل Snapshot بعد التثبيت:

```text
Rejected
```

## N. Configuration version in use

محاولة حذف/تعديل Version مستخدمة:

```text
Rejected
```

## O. New version activation

- Old auctions unchanged.
- New auctions use new version.

## P. Legacy backfill

Auction مرتبطة بـVersion قديمة:

- Snapshot صحيحة.

Auction لا يمكن تحديد Config لها:

- Review required.
- لا تخمين.

## Q. Snapshot hash

- Canonical same data → same hash.
- changed field → different hash.

## R. Concurrent approval

شغّل عمليتي Approval متزامنتين على MySQL.

المتوقع:

- Snapshot واحدة.
- One seller deposit obligation.
- No duplicate financial setup.

---

# 24. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع Integration Tests على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات:

- Concurrent snapshot creation.
- Unique auction snapshot.
- Configuration version locks.
- Immutable constraints.

---

# 25. قيود قاعدة البيانات المطلوبة

راجع الحاجة إلى:

```text
UNIQUE(auction_id)
```

على جدول Snapshot.

Foreign keys:

```text
auction_id
source_configuration_version_id
terms_version_id
currency_id
```

مع سياسات Delete مناسبة:

- Snapshot لا تُحذف عند Retire configuration.
- Version مستخدمة لا تُحذف.
- Auction deletion حسب سياسة المشروع، وليس Cascade عشوائيًا لو التاريخ المالي مطلوب.

لا تضف Cascade يمسح تاريخًا ماليًا مهمًا.

---

# 26. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan migrate:status
php artisan test --filter=AuctionConfigurationSnapshot
php artisan test --configuration=phpunit.mysql.xml --filter=AuctionConfigurationSnapshot
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

وشغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# 27. ممنوعات هذه المهمة

لا تنفذ الآن:

- General Outbox consumers.
- General Scheduler concurrency.
- Resource privacy.
- Full DTO/Repository cleanup خارج Configuration files.
- إعادة بناء Financial flows السابقة.

لا تضف Fallback إلى Current Config لمزاد قائم.

لا تغيّر مزادًا قائمًا تلقائيًا عند تفعيل Configuration Version جديدة.

---

# 28. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_11_CONFIGURATION_SNAPSHOT_REPORT.md
```

ويحتوي على:

1. جميع القراءات القديمة من Current Config.
2. المخاطر الناتجة عنها.
3. توقيت إنشاء Snapshot النهائي.
4. Snapshot schema النهائي.
5. قائمة كل الحقول المثبتة.
6. Immutability design.
7. Versioning design.
8. Snapshot reader/value objects.
9. Platform fee integration.
10. Deposit policy integration.
11. Deadline integration.
12. Terms version integration.
13. Winner default integration.
14. Cancellation integration.
15. Legacy/backfill strategy.
16. Snapshot hash design.
17. Actions المعدلة.
18. DTOs/Value Objects المنشأة.
19. Repositories المعدلة.
20. Migrations الجديدة.
21. Audit events.
22. الاختبارات الجديدة.
23. نتائج Feature Tests.
24. نتائج MySQL concurrency tests.
25. الأوامر التي شُغلت.
26. أي فحص تعذر تشغيله.
27. أي مخاطر متبقية.

---

# 29. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- كل مزاد معتمد لديه Snapshot ثابتة.
- Snapshot لا تتغير بعد التثبيت.
- تغيير Config لا يغير مزادًا قائمًا.
- Seller/Bidder deposits تقرأ من Snapshot.
- Platform fee تقرأ من Snapshot.
- Winner payment deadline تقرأ من Snapshot عند إنشاء Settlement.
- Winner default policy تقرأ من Snapshot.
- Non-winner hold policy تقرأ من Snapshot.
- Seller deposit disposition تقرأ من Snapshot.
- Cancellation policy تقرأ من Snapshot.
- Terms version ثابتة ومتحقق منها.
- لا يوجد fallback صامت إلى Current Config.
- Missing/incomplete snapshot تفشل بوضوح.
- Concurrent approval تنشئ Snapshot واحدة.
- Legacy backfill لا يخمن سياسة مالية خطيرة.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
