# إعادة تصميم وبناء نظام المزادات بالكامل — Production-Grade Auction Platform

## السياق الأساسي والقرار المعماري

نظام المزادات لم يتم إطلاقه للعملاء حتى الآن، ولا توجد بيانات إنتاجية أو توافق خلفي يجب الحفاظ عليه.

لذلك:

* لا تحافظ على أي كود قديم لمجرد أنه موجود.
* لا تحافظ على أي جدول أو اسم عمود أو API Response أو Route أو Migration إذا كان تصميمه غير صحيح.
* يمكنك حذف جميع جداول وملفات ومكونات نظام المزادات الحالية وإعادة بنائها من الصفر.
* يمكنك تغيير أسماء الجداول والأعمدة والعلاقات والحالات والـ APIs بالكامل.
* يمكنك دمج ملفات أو حذفها أو تقسيمها أو نقلها حسب التصميم الأفضل.
* لا تنشئ Compatibility Layer للكود القديم.
* لا تترك Deprecated Code أو Legacy Paths أو دوال بديلة تؤدي نفس الوظيفة.
* لا تحافظ على Migrations ترقيعية متعددة إذا كان من الأنظف إنشاء Schema جديد واضح من الصفر.

المطلوب ليس تحسين شكل الملفات، بل بناء نظام مزادات حقيقي:

* صحيح وظيفيًا.
* آمن ماليًا.
* آمن مع التزامن.
* قابل للتدقيق والمراجعة.
* قابل للتوسع أفقيًا.
* مناسب لملايين المستخدمين المسجلين.
* قادر على التعامل مع مزادات ساخنة تحتوي على عدد كبير من المزايدين المتزامنين.
* قابل للتشغيل في بيئة Production متعددة الخوادم.

لا تدّعِ أن النظام يتحمل ملايين المستخدمين لمجرد أن الكود منظم. يجب إثبات قابلية التوسع من خلال التصميم، الـ Constraints، اختبارات التزامن، Query Analysis واختبارات الضغط.

---

# الهدف النهائي

إعادة بناء نظام المزادات بالكامل بطريقة Production-Grade وفقًا لأفضل الممارسات المستخدمة في المنصات الكبيرة، مع الالتزام العملي بـ:

* Clean Architecture.
* SOLID.
* Single Responsibility Principle.
* DRY.
* Separation of Concerns.
* Domain-Driven Design بشكل عملي وغير مبالغ فيه.
* Explicit Dependencies.
* Type Safety.
* Database Integrity.
* Financial Integrity.
* Idempotency.
* Observability.
* Horizontal Scalability.
* Laravel Best Practices.

لا تستخدم هذه المبادئ بشكل شكلي، ولا تنشئ Interfaces أو Layers أو Abstractions بدون فائدة حقيقية.

الأولوية بالترتيب:

1. صحة الـ Business Flow.
2. سلامة الأموال والمعاملات.
3. سلامة التزامن ومنع Race Conditions.
4. سلامة قاعدة البيانات.
5. الأمان والخصوصية.
6. قابلية التدقيق.
7. الأداء وقابلية التوسع.
8. وضوح الكود وسهولة صيانته.

---

# قاعدة صارمة قبل التنفيذ

لا تبدأ بكتابة الكود مباشرة.

ابدأ أولًا بمراجعة كاملة لكل ما يتعلق بالمزادات داخل المشروع، بما في ذلك:

* Routes.
* Controllers.
* Requests.
* Resources.
* Models.
* Services.
* Repositories.
* Query Objects.
* Policies.
* Gates.
* Middleware.
* Events.
* Listeners.
* Jobs.
* Notifications.
* Commands.
* Scheduler.
* Configurations.
* Payment Slips.
* Deposits.
* Refunds.
* User relations.
* Categories.
* Countries, States and Cities.
* Uploaded files.
* Database migrations.
* Indexes and Foreign Keys.
* أي ملفات خارج مجلد Auction ترتبط بالمزاد.
* أي Frontend Contract يمكن استنتاجه من الـ API الحالي.

قبل التنفيذ أنشئ تحليلًا يوضح:

1. الـ Current Flow الحالي.
2. جميع المشاكل الموجودة.
3. الملفات والدوال والجداول الميتة أو المكررة.
4. العمليات التي لها أكثر من Implementation.
5. العلاقات غير الصحيحة.
6. مشاكل N+1.
7. مشاكل الخصوصية.
8. مشاكل التزامن.
9. مشاكل الأموال.
10. حالات المزاد الحالية والانتقالات الخاطئة.
11. التصميم النهائي المقترح.
12. الجداول النهائية.
13. الـ State Machines.
14. الـ Use Cases.
15. الـ Events والـ Jobs المطلوبة.
16. خطة حذف النظام القديم وبناء النظام الجديد.

بعد هذا التحليل، نفذ التصميم الجديد كاملًا. لا تكتفِ بكتابة خطة أو توصيات فقط.

---

# النطاق المسموح تغييره

يمكنك حذف وإعادة إنشاء كل ما يتعلق بنظام المزادات، بما في ذلك:

* جميع جداول المزادات الحالية.
* جميع Migrations الخاصة بالمزاد.
* جميع Models القديمة.
* جميع Controllers القديمة.
* جميع Services وRepositories القديمة.
* جميع Requests وResources القديمة.
* جميع Jobs وEvents وListeners القديمة.
* جميع Routes القديمة.
* جميع حالات المزاد القديمة.
* جميع أسماء الأعمدة غير الاحترافية.
* جميع العلاقات الخاطئة الموجودة داخل User أو Ad أو أي Model آخر.
* أي نظام Payment Slip عام وغير آمن مرتبط بالمزاد.
* أي Dead Code أو ملفات غير مستخدمة.

لكن:

* لا تحذف جداول أو ملفات خارج نطاق المزاد بدون التأكد من عدم استخدامها في أجزاء أخرى.
* راجع جميع Dependencies قبل الحذف.
* لا تستخدم `migrate:fresh` على قاعدة المشروع كاملة.
* أنشئ Migrations واضحة تحذف أو تستبدل جداول المزاد فقط.
* احتفظ بنسخة احتياطية أو وثّق خطوات الاسترجاع قبل أي Migration مدمرة.

---

# المعمارية المطلوبة

استخدم Feature-Based Modular Structure واضحة.

التنظيم المقترح، ويمكن تعديله إذا كان هناك تنظيم أفضل يتوافق مع المشروع:

```text
app/
├── Domain/
│   └── Auction/
│       ├── Enums/
│       ├── ValueObjects/
│       ├── States/
│       ├── Exceptions/
│       ├── Events/
│       ├── Policies/
│       ├── Rules/
│       └── Contracts/
│
├── Application/
│   └── Auction/
│       ├── Actions/
│       ├── Commands/
│       ├── Queries/
│       ├── DTOs/
│       └── Services/
│
├── Infrastructure/
│   └── Auction/
│       ├── Repositories/
│       ├── Persistence/
│       ├── Payments/
│       ├── Storage/
│       └── Notifications/
│
├── Http/
│   ├── Controllers/Auction/
│   ├── Requests/Auction/
│   └── Resources/Auction/
│
├── Models/
│   └── Auction/
│
├── Jobs/Auction/
├── Listeners/Auction/
├── Notifications/Auction/
└── Console/Commands/Auction/
```

إذا كان المشروع لا يحتاج فصل Domain/Application/Infrastructure بهذا الحجم، استخدم بنية أبسط، لكن حافظ على فصل المسؤوليات.

لا تنشئ Layer لمجرد الشكل.

---

# تحديد نموذج المزاد

راجع المتطلبات الحالية وحدد هل النظام:

* Auction واحد لمنتج واحد.
* أم Auction يحتوي على Lot واحد.
* أم Auction Event يحتوي على عدة Lots.

لا تنشئ Multi-Lot Architecture معقدة بدون احتياج حقيقي.

إذا كان النظام الحالي يعرض عنصرًا واحدًا في كل مزاد، استخدم Single-Lot Design واضح، مع إمكانية التوسع مستقبلًا بدون تعقيد حالي غير ضروري.

وثّق هذا القرار داخل:

```text
AUCTION_ASSUMPTIONS_AND_POLICIES.md
```

---

# الجداول الأساسية المطلوبة

صمم Schema جديدًا نظيفًا. الأسماء النهائية يمكن تعديلها، لكن يجب فصل المسؤوليات على الأقل بالشكل التالي:

```text
auctions
auction_media
auction_participants
auction_bids
auction_deposits
payment_submissions
payment_transactions
refund_transactions
auction_settlements
auction_status_history
auction_activity_logs
auction_terms_versions
auction_terms_acceptances
auction_metrics
auction_views
outbox_messages
```

يمكن إضافة أو حذف جدول إذا كان هناك سبب معماري واضح.

إذا كانت المنصة تحتفظ بأموال فعلية أو أرصدة داخلية، أضف Ledger حقيقيًا:

```text
ledger_accounts
ledger_transactions
ledger_entries
```

ولا تنشئ Ledger صوريًا إذا كان الدفع بالكامل لدى مزود خارجي والمنصة لا تحتفظ بالأرصدة.

---

# تصميم المعرفات

استخدم:

* `BIGINT UNSIGNED` للمعرفات الداخلية عالية الأداء.
* `public_id` باستخدام ULID أو UUID لعرضه في الـ API بدل كشف IDs متسلسلة.

مثال:

```text
id BIGINT UNSIGNED
public_id CHAR(26) UNIQUE
```

لا تستخدم UUID عشوائيًا كمفتاح Clustered Primary Key إذا كان سيضر أداء قاعدة البيانات بدون داعٍ.

جميع Foreign Keys الداخلية تستخدم `BIGINT UNSIGNED`.

---

# تصميم جدول Auctions

يجب أن يحتوي المزاد على البيانات الأساسية وقواعده التي تم أخذ Snapshot لها وقت الإنشاء.

الحقول المتوقعة تشمل حسب الحاجة:

```text
id
public_id
seller_id
category_id
title
description
status
currency_code
starting_amount
reserve_amount
minimum_bid_increment
current_leading_bid_id
winning_bid_id
starts_at
original_ends_at
ends_at
extension_window_seconds
extension_duration_seconds
maximum_extension_count
extension_count
seller_deposit_amount
bidder_deposit_amount
platform_fee_type
platform_fee_value
winner_payment_deadline_hours
handover_deadline_hours
terms_version_id
published_at
started_at
ended_at
finalized_at
cancelled_at
completed_at
created_at
updated_at
```

لا تستخدم كل الحقول بدون داعٍ، لكن يجب أن تكون جميع القواعد المهمة محفوظة داخل المزاد نفسه كـ Snapshot.

تغيير إعدادات الأدمن لاحقًا لا يجب أن يغير مزادًا تم إنشاؤه أو نشره بالفعل.

---

# الأموال والعملات

ممنوع استخدام:

```php
float
double
(float)
```

في أي عملية مالية.

يشمل ذلك:

* سعر البداية.
* Reserve Price.
* قيمة المزايدة.
* Minimum Increment.
* العربون.
* العمولات.
* الدفع.
* الاسترداد.
* التسوية.
* صافي البائع.
* الضرائب.

استخدم أحد الحلين:

1. Integer Minor Units، مثل القروش أو الفلس.
2. Decimal String مع Money Value Object موثوق.

يفضل استخدام Integer Minor Units عندما تكون العملات المدعومة معروفة.

كل مبلغ يجب أن يكون مرتبطًا بـ:

```text
currency_code CHAR(3)
```

مثل:

```text
JOD
EGP
USD
```

لا تكتب اسم العملة أو رمزها داخل Controller أو Resource بشكل Hardcoded.

أنشئ `Money` Value Object مسؤولًا عن:

* القيمة.
* العملة.
* الجمع.
* الطرح.
* المقارنة.
* منع العملات المختلفة.
* التحويل إلى API Format.

---

# حالة المزاد

أنشئ PHP Enum واضحًا لحالة المزاد.

الحالات المقترحة:

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
unsold
cancelled
defaulted
disputed
```

لا تستخدم جميع الحالات إذا لم تكن مطلوبة، لكن لا تدمج مراحل مختلفة في حالة واحدة.

قواعد مهمة:

* اختيار الفائز لا يعني أن المزاد `completed`.
* انتهاء وقت المزايدة لا يعني إتمام البيع.
* `extended` ليست حالة مزاد.
* المزاد أثناء التمديد يظل `live`.
* `closed` العامة غير واضحة؛ استخدم حالة دقيقة مثل `cancelled`, `ended`, `unsold` أو `completed`.

---

# State Machine

أنشئ State Machine مركزية.

ممنوع تعديل حالة المزاد مباشرة باستخدام:

```php
$auction->update(['status' => ...]);
```

من Controllers أو Repositories أو Jobs عشوائية.

كل Transition يجب أن يمر من Action أو Transition Service مخصص.

كل Transition يجب أن يحدد:

* الحالة الحالية المسموحة.
* الحالة الجديدة.
* المستخدم أو النظام المسموح له.
* شروط التنفيذ.
* Database Transaction.
* Locks المطلوبة.
* الأحداث الناتجة.
* العمليات المالية الناتجة.
* Audit Log.
* Idempotency.
* ماذا يحدث إذا تم تنفيذ العملية مرة أخرى.

أمثلة Actions:

```text
CreateAuction
SubmitAuctionForReview
ApproveAuction
RejectAuction
SubmitSellerDeposit
ApproveSellerDeposit
PublishAuction
StartAuction
PlaceBid
ExtendAuction
FinalizeAuction
CancelAuction
CreateSettlement
SubmitWinnerPayment
ApproveWinnerPayment
MarkHandoverCompleted
CompleteAuction
MarkWinnerDefaulted
ReassignWinner
OpenDispute
ResolveDispute
```

لا تستخدم Action ضخمًا يحتوي على كل العمليات.

---

# قواعد الوقت

وقت السيرفر هو المصدر الوحيد للحقيقة.

جميع الأوقات تخزن UTC.

جميع استجابات الـ API ترسل ISO-8601 مع Timezone واضح.

ممنوع قبول مزايدة اعتمادًا على `status = live` فقط.

داخل عملية المزايدة، وبعد الحصول على Lock، يجب التحقق من:

```text
status = live
starts_at <= server_time
server_time < ends_at
```

عند وصول الوقت إلى `ends_at` بالضبط، المزايدة مرفوضة.

الـ Scheduler ليس الحماية الأساسية من المزايدات المتأخرة.

حتى إذا تعطل Scheduler، يجب أن ترفض الـ Business Logic أي مزايدة بعد النهاية.

استخدم Clock abstraction أو Laravel Date/Carbon بطريقة تسمح باختبار الوقت.

---

# سجل المزايدات

جدول `auction_bids` يجب أن يكون Append-Only.

ممنوع:

* تعديل قيمة Bid قديم.
* حذف Bid مقبول.
* استخدام سجل واحد لكل مستخدم وتحديثه مع كل زيادة.
* استخدام Bid بقيمة صفر لتسجيل المستخدم أو العربون.
* استخدام `is_winning` كـ User ID.

كل مزايدة جديدة تنشئ سجلًا جديدًا.

الحقول المتوقعة:

```text
id
public_id
auction_id
bidder_id
amount
currency_code
sequence_number
previous_bid_id
idempotency_key
client_request_id
server_received_at
accepted_at
created_at
```

حسب السياسة، يمكن تسجيل محاولات المزايدات المرفوضة في جدول منفصل أو Audit Log، وليس داخل جدول المزايدات المقبولة بالضرورة.

أضف Constraints مثل:

```text
UNIQUE (auction_id, sequence_number)
UNIQUE (auction_id, bidder_id, idempotency_key)
```

أو التصميم المكافئ المناسب.

يجب أن يكون ترتيب المزايدات حتميًا.

في حالة وصول مزايدتين بنفس المبلغ في وقت متقارب، يحدد الفائز بناءً على:

1. المبلغ الأعلى.
2. ثم `sequence_number` أو وقت القبول داخل السيرفر.
3. لا تعتمد على Clock العميل.

---

# قواعد المزايدة

يجب التحقق من:

* المزاد موجود.
* المزاد مرئي ومتاح للمستخدم.
* المستخدم ليس صاحب المزاد.
* المستخدم غير محظور.
* المستخدم Participant مؤهل.
* شروط المزاد تم قبولها.
* العربون المطلوب معتمد ومحتجز.
* المزاد في الوقت الصحيح.
* المزاد في الحالة الصحيحة.
* المبلغ بنفس عملة المزاد.
* المبلغ الجديد يحقق الحد الأدنى.
* Request غير مكرر.

يجب أن تكون أول مزايدة:

```text
>= starting_amount
```

والمزايدات التالية:

```text
>= current_amount + minimum_bid_increment
```

حدد السياسة الدقيقة لأول مزايدة داخل الوثائق.

لا تقبل `current_price` من الـ Client باعتباره مصدر الحقيقة.

المبلغ الحالي يجب أن يُقرأ من قاعدة البيانات داخل Transaction.

---

# Reserve Price

استخدم اسمًا واضحًا:

```text
reserve_amount
```

العلاقة الصحيحة غالبًا:

```text
reserve_amount >= starting_amount
```

الـ Reserve Price هو أقل مبلغ يقبل البائع البيع عنده، وليس أقل من سعر البداية.

حدد بوضوح:

* هل يوجد Reserve أم لا؟
* هل قيمة Reserve مرئية؟
* هل يظهر فقط أن السعر لم يصل للـ Reserve؟
* ماذا يحدث إذا انتهى المزاد دون الوصول له؟
* هل المزاد يتحول إلى `unsold`؟
* هل يسمح للأدمن أو البائع بقبول أعلى عرض يدويًا؟

لا تسمح بتغيير Reserve بعد بدء المزاد.

---

# Auto Extension / Anti-Sniping

التمديد يجب أن يكون جزءًا من قواعد المزاد، وليس حالة مستقلة.

خزّن:

```text
original_ends_at
ends_at
extension_window_seconds
extension_duration_seconds
extension_count
maximum_extension_count
last_extended_at
```

حدد السياسة:

* إذا تم قبول Bid داخل آخر N ثوانٍ، يتم تمديد المزاد M ثانية.
* هل كل Bid جديد داخل النافذة يمدد الوقت؟
* ما الحد الأقصى لعدد التمديدات؟
* ما الحد الأقصى لمدة المزاد الإجمالية؟

يجب تنفيذ التمديد داخل نفس Transaction الخاصة بقبول المزايدة.

يجب إرسال Event بعد نجاح الـ Commit.

---

# المشاركون في المزاد

استخدم جدولًا مستقلًا مثل:

```text
auction_participants
```

يمثل:

* تسجيل المستخدم في المزاد.
* قبوله للشروط.
* حالة أهليته.
* العربون المرتبط به.
* الحظر أو التعليق.
* وقت التسجيل.

حقول مقترحة:

```text
id
auction_id
user_id
eligibility_status
deposit_id
terms_acceptance_id
registered_at
approved_at
suspended_at
suspension_reason
created_at
updated_at
```

أضف:

```text
UNIQUE (auction_id, user_id)
```

لا تستخدم `auction_bids` لتمثيل التسجيل.

---

# التزامن وRace Conditions

عملية `PlaceBid` هي أخطر عملية في النظام.

يجب تنفيذها داخل Database Transaction.

يجب استخدام:

```php
lockForUpdate()
```

على سجل المزاد أو سجل الـ Aggregate المسؤول عن السعر الحالي.

الترتيب الصحيح:

1. بدء Transaction.
2. قراءة المزاد باستخدام Lock.
3. إعادة التحقق من الحالة.
4. إعادة التحقق من الوقت.
5. إعادة التحقق من العربون والأهلية.
6. قراءة السعر الحالي داخل Transaction.
7. التحقق من Minimum Increment.
8. إنشاء Bid جديد Append-Only.
9. تحديث `current_leading_bid_id`.
10. تنفيذ التمديد إن لزم.
11. تحديث Counters الضرورية.
12. Commit.
13. إرسال Events بعد Commit.

ممنوع:

* قراءة السعر قبل Transaction ثم الاعتماد عليه.
* الاعتماد على Redis Lock وحده كضمان لصحة الأموال أو ترتيب المزايدات.
* الاعتماد على Cache باعتبارها مصدر الحقيقة.
* تنفيذ Notifications داخل Transaction.
* تنفيذ HTTP Calls داخل Transaction.

قاعدة البيانات والـ Constraints هما مصدر الحقيقة النهائي.

---

# المزادات شديدة النشاط

صمم النظام بحيث لا يمنع التوسع لمزادات تحتوي على عدد ضخم من الطلبات المتزامنة.

ابدأ بحل Database Transaction + Row Lock الصحيح.

بعد ذلك قيّم الأداء من خلال Load Test.

إذا ظهر Hot Row Bottleneck حقيقي، اقترح ونفذ عند الحاجة تصميمًا مناسبًا مثل:

* Per-Auction Command Queue.
* Partitioned Queue by Auction ID.
* Redis Streams أو Kafka كطبقة استقبال، مع بقاء قاعدة البيانات مصدر الحقيقة.
* Single Writer per Auction.
* Optimistic Concurrency Versioning.
* Sharding strategy مستقبلية.

لا تضف Kafka أو Microservices أو Distributed Systems بدون دليل أنها مطلوبة.

ابدأ بـ Modular Monolith قوي قابل للتوسع أفقيًا، ثم وثّق نقطة الانتقال إذا تجاوزت الأحمال قدرة التصميم الحالي.

---

# Idempotency

كل عملية مالية أو حساسة يجب أن تدعم Idempotency.

يشمل ذلك:

* Place Bid.
* Register Participant.
* Submit Deposit.
* Approve Payment.
* Reject Payment.
* Refund Deposit.
* Finalize Auction.
* Create Settlement.
* Submit Winner Payment.
* Complete Handover.
* Complete Auction.
* Webhook processing.

استخدم Idempotency Key مرتبطًا بـ:

```text
actor_id
operation_type
aggregate_id
idempotency_key
```

أضف Unique Constraint مناسب.

عند تكرار نفس الطلب:

* لا يتم إنشاء سجل جديد.
* لا يتم خصم مبلغ جديد.
* لا يتم إرسال إشعار مكرر.
* يتم إرجاع نفس النتيجة السابقة قدر الإمكان.

لا تدّعِ Exactly-Once Processing.

استخدم At-Least-Once Delivery مع Idempotent Consumers وDatabase Constraints.

---

# العربون

افصل بين:

```text
Seller Deposit
Bidder Deposit
```

ولا تفترض أنهما بنفس القيمة أو النسبة أو السياسة.

لا تستخدم حالات مبهمة تجمع الدفع والمراجعة والاحتجاز والاسترداد في حالة واحدة.

## Payment Submission Status

```text
pending_review
approved
rejected
cancelled
```

## Deposit Lifecycle Status

```text
pending
held
refund_pending
refunded
apply_pending
applied_to_settlement
forfeited
```

إيصال مرفوض لا يعني عربونًا مصادرًا.

العربون لا يصبح `held` إلا بعد:

* نجاح الدفع الإلكتروني.
* أو اعتماد الإيصال اليدوي رسميًا.

العربون لا يصبح `refunded` بمجرد تغيير Status في قاعدة البيانات.

يجب وجود Refund Transaction حقيقي.

---

# محاولات الدفع

كل محاولة دفع أو إيصال يجب أن تكون Append-Only.

ممنوع استخدام:

```php
updateOrCreate()
```

بطريقة تستبدل المحاولة القديمة.

يجب الاحتفاظ بـ:

* جميع الإيصالات.
* جميع محاولات الدفع.
* سبب الرفض.
* من قام بالمراجعة.
* وقت المراجعة.
* المحاولة التي تم اعتمادها.
* External Reference.
* Provider Response عند الحاجة.

لا تربط الإيصال بالعربون عن طريق String مثل:

```text
PAYMENT_SLIP_123
```

استخدم Foreign Key صريح.

---

# Payment Transactions

أنشئ جدولًا واضحًا للمعاملات المالية:

```text
payment_transactions
```

حقول متوقعة:

```text
id
public_id
user_id
auction_id
deposit_id
settlement_id
type
amount
currency_code
status
provider
provider_transaction_id
idempotency_key
initiated_at
completed_at
failed_at
failure_code
failure_reason
metadata
created_at
updated_at
```

أضف Unique Constraint على Provider Transaction ID عند توفره.

الـ Status لا يصبح `completed` إلا بناءً على نتيجة مؤكدة من مزود الدفع أو اعتماد يدوي موثق.

---

# Refund Transactions

أنشئ جدولًا مستقلًا:

```text
refund_transactions
```

يحتوي على:

```text
id
payment_transaction_id
deposit_id
amount
currency_code
status
provider_refund_id
idempotency_key
requested_by
processed_by
requested_at
processed_at
completed_at
failed_at
failure_reason
created_at
updated_at
```

الحالات مثل:

```text
pending
processing
completed
failed
cancelled
```

لا تعدّل العربون إلى `refunded` إلا بعد اكتمال Refund Transaction.

في حالة فشل الاسترداد:

* يظل قابلًا لإعادة المحاولة.
* لا يتم إنشاء Refund مكرر.
* تسجل تفاصيل الخطأ.
* يتم تنبيه الإدارة.

---

# Settlement الفائز

اختيار أعلى مزايد لا يعني إتمام المزاد.

أنشئ `auction_settlements`.

الحقول المتوقعة:

```text
id
public_id
auction_id
winning_bid_id
winner_id
winning_amount
currency_code
deposit_applied_amount
remaining_amount
platform_fee_amount
seller_net_amount
status
payment_due_at
paid_at
handover_due_at
handover_completed_at
completed_at
defaulted_at
created_at
updated_at
```

أضف:

```text
UNIQUE (auction_id)
UNIQUE (winning_bid_id)
```

حسب التصميم النهائي.

حالات التسوية المقترحة:

```text
winner_payment_pending
payment_under_review
paid
handover_pending
completed
winner_defaulted
reassigned
cancelled
disputed
```

---

# Finalization

عملية إنهاء المزاد يجب أن تكون:

* Transactional.
* Idempotent.
* آمنة مع التزامن.
* قابلة لإعادة المحاولة.
* غير قابلة لتكرار Side Effects.

التدفق:

1. الحصول على Lock على المزاد.
2. إعادة قراءة الحالة.
3. إعادة قراءة `ends_at`.
4. رفض التنفيذ إذا لم ينته الوقت.
5. عدم تكرار Finalization إذا تم بالفعل.
6. تحديد أعلى Bid مؤهل بترتيب حتمي.
7. التحقق من Reserve Price.
8. إذا لا توجد Bids: `unsold`.
9. إذا لم يصل Reserve: `unsold` أو الحالة التي تحددها السياسة.
10. إنشاء Settlement واحد فقط.
11. تحديث `winning_bid_id`.
12. تغيير حالة المزاد إلى `settlement_pending` أو `payment_pending`.
13. إنشاء عمليات Refund المطلوبة بدون ادعاء اكتمالها.
14. كتابة Status History وAudit Log.
15. Commit.
16. إرسال Events من خلال Outbox.
17. معالجة الإشعارات والاستردادات عبر Queue.

تشغيل Finalization مرتين يجب ألا يكرر:

* Settlement.
* الفائز.
* Refund.
* تطبيق العربون.
* الإشعارات.
* تغيير الحالة.

---

# تعثر الفائز

حدد ونفذ سياسة واضحة عند عدم دفع الفائز باقي المبلغ قبل الموعد.

يجب دعم واحد أو أكثر من الخيارات حسب Business Policy:

* مصادرة العربون.
* الانتقال إلى ثاني أعلى مزايد مؤهل.
* طلب قبول ثاني أعلى مزايد.
* إنشاء Settlement جديد مرتبط بالتسوية السابقة.
* إعادة المزاد.
* تحويل المزاد إلى `defaulted` أو `unsold`.

لا تغير الفائز بصمت.

يجب تسجيل:

* الفائز السابق.
* سبب التعثر.
* العربون المصادر.
* الشخص الذي اتخذ القرار.
* الفائز البديل.
* المبلغ الجديد.
* وقت القرار.
* جميع Events الناتجة.

لا تسمح بأكثر من Settlement نشطة لنفس المزاد.

---

# التسليم والاستلام

لا تحول المزاد إلى `completed` قبل:

* تأكيد سداد المبلغ.
* تنفيذ التسليم أو الاستلام.
* تأكيد الطرف المخول.
* انتهاء أي شروط إضافية.

حدد:

* من يؤكد التسليم؟
* من يؤكد الاستلام؟
* ماذا يحدث عند عدم تطابق التأكيدات؟
* هل يوجد Dispute Window؟
* ما مهلة التسليم؟
* ماذا يحدث عند فشل التسليم؟

أنشئ Audit Trail كامل.

---

# إلغاء المزاد

الإلغاء يجب أن يكون Use Case مستقلًا.

لا تستخدم مجرد:

```php
status = cancelled
```

حدد سياسة الإلغاء حسب الحالة:

* Draft: يمكن الحذف أو الإلغاء بسهولة.
* Scheduled بدون مشاركين: يمكن الإلغاء حسب الصلاحيات.
* Live بدون Bids: حسب السياسة.
* Live مع Bids: يحتاج صلاحية خاصة وسببًا إلزاميًا.
* Ended أو Settlement Pending: لا يلغى بالطريقة العادية.
* Completed: لا يلغى، بل يتم فتح Dispute أو Reversal Process.

عند الإلغاء:

* سجل السبب.
* سجل المنفذ.
* أنشئ Refund Requests للعربون المستحق.
* لا تعتبر الأموال مستردة قبل تنفيذ Refund.
* أرسل إشعارات.
* سجل Audit Event.
* اجعل العملية Idempotent.

---

# Terms and Conditions

لا تستخدم Boolean فقط باسم:

```text
terms_accepted
```

أنشئ:

```text
auction_terms_versions
auction_terms_acceptances
```

احفظ:

```text
user_id
auction_id
terms_version_id
acceptance_type
accepted_at
ip_address
user_agent
```

أنواع القبول مثل:

```text
seller
bidder
winner
```

حسب الحاجة.

تعديل الشروط يجب أن ينشئ Version جديدًا.

لا تتغير الشروط التي وافق عليها مستخدم سابق.

---

# Auction Configuration

يجب أن يكون هناك مصدر واحد للحقيقة لإعدادات المزاد.

الإعدادات الممكنة:

```text
default_currency
default_duration
minimum_duration
maximum_duration
seller_deposit_type
seller_deposit_value
bidder_deposit_type
bidder_deposit_value
minimum_bid_increment
extension_window
extension_duration
maximum_extensions
winner_payment_deadline
handover_deadline
platform_fee
terms_version
```

عند إنشاء المزاد، خذ Snapshot للقيم المهمة داخل المزاد.

أي Configuration غير مستخدمة فعليًا يجب حذفها.

ممنوع وجود Validation يسمح بمدة 30 يومًا بينما Configuration تسمح بـ 365 يومًا بدون قاعدة واضحة.

---

# الخصوصية وهوية المزايد

حدد سياسة واضحة لهوية المزايدين.

الافتراضي الآمن:

* لا يظهر الاسم الكامل للمزايد للعامة.
* لا يظهر رقم الهاتف أو البريد.
* يمكن عرض Masked Bidder Identifier.
* لا تظهر بيانات الفائز إلا للأطراف المخولة وبعد المرحلة المناسبة.
* صاحب المزاد لا يرى بيانات دفع المزايدين.
* الأدمن المالي المخول فقط يرى إيصالات الدفع.
* المستخدم يرى بياناته هو فقط.

أنشئ Resources منفصلة:

```text
PublicAuctionResource
ParticipantAuctionResource
SellerAuctionResource
AdminAuctionResource
PublicBidResource
AdminBidResource
SettlementResource
```

لا تعيد نفس Resource لجميع الأدوار إذا كانت الحقول مختلفة.

---

# إيصالات الدفع والملفات الخاصة

إيصالات الدفع يجب أن تحفظ في Private Storage.

ممنوع:

* Public URL دائم.
* إرجاع Path الخام.
* عرض الإيصال لأي مستخدم غير مخول.
* حذف الإيصال بعد دخوله في عملية مالية.
* السماح للعميل بتحديد مبلغ مالي حساس من نفسه.

استخدم:

* Temporary Signed URL.
* Authorization قبل إنشاء الرابط.
* مدة صلاحية قصيرة.
* MIME Validation حقيقية.
* File Size Limit.
* Image Re-encoding عند الحاجة.
* Malware Scan إذا كانت البنية تدعمه.

لا تسمح بـ SVG للمحتوى المرفوع من المستخدم إلا مع Sanitization موثوق، والأفضل منعه.

---

# عمليات الملفات وDatabase Transactions

لا تحذف ملفًا نهائيًا داخل Transaction ثم تعتمد على Database Rollback لإعادته.

التدفق الصحيح عند استبدال الملفات:

1. التحقق من الملف.
2. رفع الملف الجديد.
3. إنشاء سجل مؤقت أو Pending.
4. تحديث قاعدة البيانات داخل Transaction.
5. Commit.
6. حذف الملف القديم After Commit.
7. تشغيل Cleanup Job للملفات اليتيمة.

إذا فشلت قاعدة البيانات بعد رفع الملف:

* يجب تنظيف الملف الجديد.

إذا فشل حذف الملف القديم:

* لا تفشل العملية الأساسية.
* سجل المشكلة.
* أرسل Cleanup Job قابلًا لإعادة المحاولة.

---

# Controllers

الـ Controller مسؤول فقط عن:

* استقبال Form Request.
* استدعاء Action أو Use Case.
* إرجاع Resource أو Response.

ممنوع داخل Controller:

* Business Logic.
* Queries.
* Transactions.
* حساب الأموال.
* تغيير Status مباشرة.
* Eager Loading عشوائي.
* Try/Catch عام.
* تنسيق Response يدوي متكرر.
* إرسال Notifications.
* التعامل المباشر مع Storage.

استخدم Route Model Binding عند الحاجة:

```php
public function show(Auction $auction)
```

بدل:

```php
public function show(int $id)
```

لكن استخدم Scoped Binding أو Custom Binding حتى لا يعرض السجل بدون Authorization أو Visibility صحيحة.

افصل Controllers حسب نوع المستخدم عند الحاجة:

```text
PublicAuctionController
SellerAuctionController
ParticipantAuctionController
AdminAuctionController
AdminAuctionPaymentController
```

لا تنشئ Controller ضخمًا يحتوي على جميع العمليات.

---

# Routes

افصل Routes بوضوح إلى:

```text
Public Routes
Authenticated User Routes
Seller Routes
Admin Routes
Financial Admin Routes
Internal/Webhook Routes
```

استخدم Route Prefix وإصدار API:

```text
/api/v1/auctions
```

استخدم Action Endpoints واضحة بدل Generic Status Update:

```text
POST /auctions/{auction}/publish
POST /auctions/{auction}/cancel
POST /auctions/{auction}/bids
POST /auctions/{auction}/deposits
POST /auction-payments/{payment}/approve
POST /auction-payments/{payment}/reject
POST /auction-settlements/{settlement}/confirm-payment
```

ممنوع Endpoint عام يسمح للعميل بإرسال:

```text
status
amount
payable_type
payable_id
```

وتغيير عمليات حساسة بشكل مفتوح.

---

# Authentication and Authorization

استخدم Middleware في Routes للمصادقة والأدوار العامة.

استخدم Policies أو Gates لصلاحيات السجل المحدد.

فرق بين:

* المستخدم مسجل الدخول.
* المستخدم Admin.
* المستخدم Financial Admin.
* المستخدم صاحب المزاد.
* المستخدم Participant.
* المستخدم الفائز.
* المستخدم مسموح له برؤية هذه الوثيقة المالية.

لا تكرر:

```php
$this->middleware('auth:sanctum');
```

داخل Controllers إذا كانت Route Group مؤمنة بالفعل.

لا تكتب `isAdmin()` عشوائيًا داخل كل Request أو Service إذا كان هناك Policy واضحة.

لكن لا تعتمد على Middleware `admin` وحده للتحقق من ملكية سجل معين.

---

# Form Requests

ضع Requests داخل:

```text
app/Http/Requests/Auction
```

يجب أن تحتوي أساسًا على:

```php
authorize()
rules()
messages()
attributes()
```

يمكن استخدام:

```php
prepareForValidation()
after()
```

عند وجود احتياج حقيقي فقط.

لا تستخدم:

```php
failedValidation()
failedAuthorization()
```

داخل كل Request.

عالج الأخطاء مركزيًا.

أي حقل يتم قبوله في Validation يجب أن:

* يستخدم.
* يحفظ.
* أو يحذف من Request.

ممنوع قبول حقول مثل:

```text
attributes
reel_video
amount
duration
```

ثم تجاهلها بصمت.

تحقق من العلاقات المركبة، مثل:

* State تابعة لـ Country.
* City تابعة لـ State.
* Attribute تابع للتصنيف.
* Option تابع للـ Attribute.
* العملة مدعومة.
* المدة ضمن الإعدادات.
* Reserve ضمن القواعد.

---

# Resources

ضع Resources داخل:

```text
app/Http/Resources/Auction
```

الـ Resource مسؤول عن Mapping البيانات فقط.

ممنوع داخل Resource:

* Query.
* `exists()`.
* `count()`.
* Lazy Loading.
* Business Logic.
* حساب حالة الدفع.
* استدعاء Service.
* Authorization Queries.
* قراءة Storage مباشرة.

استخدم:

```php
whenLoaded()
whenCounted()
whenAggregated()
when()
```

يجب تحميل جميع البيانات المطلوبة مسبقًا.

يفضل ألا يحتوي Resource إلا على `toArray()`، مع نقل الحالات والـ labels إلى Enums أو Value Objects.

لا تكرر دوال مثل:

```text
getDepositStatusLabel
getAuctionStatusLabel
```

داخل Resources.

استخدم Enum يحتوي على:

```php
label()
```

إذا كان ذلك مناسبًا.

---

# Models

ضع Models الخاصة بالمزاد داخل:

```text
app/Models/Auction
```

يحتوي Model فقط على:

* Relationships.
* Casts.
* Fillable أو Guarded.
* Attributes البسيطة.
* Scopes البسيطة والمستخدمة.
* Domain-neutral Eloquent behavior.

ممنوع داخل Model:

* Business Workflow معقد.
* Payment Processing.
* إرسال Events يدويًا بطريقة عشوائية.
* Finalization.
* Refund Logic.
* استعلامات ضخمة.
* دوال عشوائية كثيرة.
* تغيير حالات مباشرة من أماكن متعددة.

يمكن وضع Scope بسيط داخل Model.

الاستعلامات المعقدة توضع في Query Objects أو Repositories متخصصة.

لا تستخدم `$with` لتحميل عدد كبير من العلاقات افتراضيًا.

حمّل العلاقات حسب كل Use Case.

---

# Services, Actions and Use Cases

قسم النظام حسب حالات الاستخدام، وليس حسب CRUD فقط.

أمثلة:

```text
CreateAuctionAction
PublishAuctionAction
RegisterParticipantAction
PlaceBidAction
FinalizeAuctionAction
CancelAuctionAction
ApproveDepositAction
RefundDepositAction
CreateSettlementAction
HandleWinnerDefaultAction
CompleteHandoverAction
```

لا تنشئ `AuctionService` ضخمًا يحتوي على عشرات الدوال.

لا تنشئ Service منفصلة لكل سطر كود.

المعيار هو المسؤولية الفعلية.

كل Action يجب أن يكون واضحًا في:

* Input DTO.
* Authorization expectations.
* Transaction boundary.
* Output.
* Exceptions.
* Events.
* Idempotency.

---

# Repositories and Query Objects

ضع Repositories داخل:

```text
app/Repositories/Auction
```

استخدم Repository فقط عندما يقدم فائدة حقيقية، مثل:

* عزل استعلامات Aggregate.
* استعلامات معقدة.
* Locks.
* أكثر من مصدر بيانات.
* سهولة اختبار Domain Logic.
* تجميع Query Logic متكرر.

لا تنشئ Repository عام مليئًا بدوال CRUD مثل:

```text
find
findAll
create
update
delete
```

بدون فائدة.

استخدم Query Objects لشاشات:

* قائمة المزادات العامة.
* تفاصيل المزاد.
* لوحة البائع.
* لوحة الأدمن.
* تقارير الدفع.
* تقارير الاسترداد.
* مزادات المستخدم.

ممنوع وجود Business Logic داخل Repository.

---

# Exceptions and Error Handling

لا تستخدم:

```php
catch (\Exception $e)
```

داخل كل Controller ثم ترجع 400.

أنشئ Domain Exceptions واضحة:

```text
AuctionNotLiveException
AuctionNotStartedException
AuctionEndedException
BidTooLowException
BidderNotEligibleException
DepositNotVerifiedException
InvalidAuctionTransitionException
AuctionAlreadyFinalizedException
DuplicateOperationException
ReserveNotMetException
PaymentAlreadyProcessedException
RefundAlreadyProcessedException
UnauthorizedAuctionActionException
```

حوّل Exceptions مركزيًا إلى HTTP Status مناسب.

أمثلة:

```text
401 Unauthenticated
403 Forbidden
404 Not Found
409 Conflict
422 Validation Error
429 Rate Limited
500 Internal Error
```

لا تعرض:

* SQL Errors.
* Stack Trace.
* Provider Secrets.
* Internal Exception Message.

للمستخدم في Production.

استخدم RFC 7807 أو Response Format موحد للمشروع.

---

# Database Constraints

لا تعتمد على PHP فقط.

أضف Constraints وIndexes تمنع البيانات غير الصحيحة حتى مع وجود Bug في التطبيق.

أمثلة:

* `ends_at > starts_at`.
* المبالغ غير سالبة.
* Currency Code موجود.
* Sequence Number غير مكرر.
* Idempotency Key غير مكرر.
* Settlement واحدة فعالة لكل مزاد.
* Winning Bid تنتمي لنفس المزاد.
* Participant واحد لكل مستخدم داخل المزاد.
* Deposit واحدة فعالة حسب السياسة.
* Terms Acceptance صحيحة.
* Refund لا يتجاوز المبلغ المدفوع.
* Applied Deposit لا يتجاوز Settlement Amount.
* Current Leading Bid تنتمي لنفس المزاد.

استخدم Foreign Keys واضحة.

تجنب Polymorphic Relations داخل الجداول المالية الأساسية.

إذا كان لا بد من Morph Relations، استخدم:

```php
Relation::enforceMorphMap()
```

بقيم ثابتة لا تعتمد على Namespace.

---

# سياسات الحذف

ممنوع Hard Delete للسجلات التالية بعد بدء الـ Financial Flow:

* Auctions المنشورة.
* Bids.
* Participants.
* Deposits.
* Payments.
* Refunds.
* Settlements.
* Terms Acceptances.
* Status History.
* Audit Logs.

يمكن استخدام:

* Status.
* Archiving.
* Soft Delete في السجلات المناسبة.
* Anonymization للبيانات الشخصية عند الحاجة.

لا تستخدم `cascadeOnDelete()` بطريقة تسمح بحذف سجل مالي عند حذف User أو Category أو City.

استخدم:

* `restrictOnDelete()`.
* `nullOnDelete()` للبيانات الوصفية.
* Snapshot للبيانات المهمة.

حذف User لا يجب أن يمحو تاريخ المزايدات أو المدفوعات.

---

# العلاقات خارج موديول المزاد

راجع جميع Models خارج Auction.

خصوصًا:

* User.
* Category.
* Ad.
* Country.
* State.
* City.
* Media.
* Payment Slip.
* Notification.

احذف أو صحح العلاقات القديمة مثل:

* علاقة المعلن التي تعتمد على `winner_id`.
* علاقة Ad بمزاد بدون وجود `ad_id`.
* أي Foreign Key أو Relation لا تتوافق مع Schema الجديدة.

نفذ بحثًا شاملًا عن جميع أسماء الجداول والكلاسات القديمة بعد الحذف.

يجب ألا يتبقى أي Reference ميت.

---

# Counters and Metrics

لا تنفذ:

```php
$bids()->count()
```

لكل مزاد داخل قائمة كبيرة.

استخدم حسب الحاجة:

* Denormalized Counters.
* `withCount()`.
* Aggregated Read Models.
* Background Metrics.
* Cache.

حقول محتملة داخل المزاد:

```text
bids_count
unique_bidders_count
views_count
participants_count
```

لكن لا تجعل Counters المصدر النهائي للحقيقة المالية.

يجب أن توجد آلية Reconciliation لإعادة حسابها عند الحاجة.

---

# Pagination

ممنوع إرجاع قوائم ضخمة بدون Pagination.

استخدم:

* Cursor Pagination للـ Bids والسجلات الكبيرة.
* Pagination مناسبة لقوائم المزادات.
* Filters مفهرسة.
* Stable Sort.

تجنب Offset Pagination في جداول تحتوي ملايين السجلات عندما يكون Cursor Pagination أنسب.

أضف Limits قصوى للـ Per Page.

---

# Indexing

راجع كل Query فعلية وأنشئ Indexes بناءً عليها.

أمثلة محتملة:

```text
auctions(status, starts_at)
auctions(status, ends_at)
auctions(seller_id, status, created_at)
auctions(category_id, status, starts_at)
auction_bids(auction_id, sequence_number)
auction_bids(auction_id, amount, accepted_at)
auction_bids(bidder_id, created_at)
auction_participants(auction_id, user_id)
auction_deposits(auction_id, user_id, status)
payment_transactions(status, created_at)
refund_transactions(status, created_at)
auction_settlements(status, payment_due_at)
outbox_messages(status, available_at)
```

لا تضف Indexes عشوائية.

استخدم `EXPLAIN` ويفضل `EXPLAIN ANALYZE` حيث يدعم محرك قاعدة البيانات.

وثق:

* Query.
* Index المستخدم.
* Rows examined.
* سبب اختيار الـ Index.

راجع أثر الـ Indexes على عمليات الكتابة، خاصة `auction_bids`.

---

# N+1

ممنوع وجود أي Database Query داخل Resource.

راجع جميع Endpoints الأساسية باستخدام Query Log أو أدوات قياس.

أنشئ اختبارات تمنع زيادة عدد Queries مع زيادة عدد النتائج.

راجع خصوصًا:

* قائمة المزادات.
* تفاصيل المزاد.
* Recent Bids.
* Participants.
* Deposits.
* Admin Payment Review.
* Finalization.
* Refund Processing.
* Notifications.

لا تنفذ Query داخل Loop.

استخدم Batch Loading وEager Loading وAggregates.

---

# Cache

استخدم Cache للأشياء المناسبة فقط:

* Public Auction Details.
* قائمة المزادات.
* Configuration.
* Category Metadata.
* Read-only Metrics.
* Rate Limiting.

لا تستخدم Cache كمصدر الحقيقة لـ:

* السعر الحالي.
* الفائز.
* حالة الدفع.
* حالة Refund.
* حالة Settlement.

يجب أن يكون Cache قابلًا للحذف وإعادة البناء.

أنشئ Cache Invalidation واضحًا عبر Events.

تجنب Cache Stampede باستخدام Lock أو Stale-While-Revalidate عند الحاجة.

لا تخزن بيانات خاصة بمستخدم ثم تعرضها لمستخدم آخر بسبب Cache Key ناقص.

---

# Redis

يمكن استخدام Redis في:

* Cache.
* Queue.
* Rate Limiting.
* Distributed Locks للأعمال غير المالية.
* Real-time Broadcasting.
* Temporary Sessions.
* Hot Auction Read Models.

لكن لا تعتمد على Redis وحده لضمان:

* الفائز.
* ترتيب الـ Bids.
* خصم الأموال.
* Settlement.
* Refund.

قاعدة البيانات والـ Constraints تبقى مصدر الحقيقة.

---

# Queues

نفذ العمليات غير المتزامنة عبر Queue:

* Notifications.
* Emails.
* SMS.
* Payment Provider Calls عند ملاءمة ذلك.
* Refund Processing.
* Metrics.
* Search Indexing.
* File Cleanup.
* Auction Finalization.
* Settlement Deadline Checks.
* Failed Payment Retry.
* Outbox Publishing.

كل Job يجب أن يحدد:

```text
tries
backoff
timeout
retryUntil
failed handling
```

كل Job يجب أن يكون Idempotent.

لا تنفذ Job يغيّر أموالًا بدون Constraints تمنع التكرار.

---

# Outbox Pattern

استخدم Transactional Outbox للأحداث الحساسة.

المشكلة التي يجب منعها:

1. Database Commit ينجح.
2. Dispatch Event يفشل.
3. لا تصل Notification أو Payment Job.

التدفق المطلوب:

1. تحديث الـ Aggregate.
2. إنشاء Outbox Message داخل نفس Transaction.
3. Commit.
4. Worker ينشر الرسالة.
5. Consumer يعالجها بشكل Idempotent.
6. يتم تعليم الرسالة بأنها Processed.

استخدم Unique Event ID.

لا ترسل أحداثًا حساسة قبل Commit.

---

# Scheduler

سجل جميع Jobs المطلوبة فعليًا.

يشمل:

* Finalize Expired Auctions.
* Start Scheduled Auctions إذا كان Status يحتاج ذلك.
* Check Winner Payment Deadlines.
* Check Handover Deadlines.
* Retry Pending Refunds.
* Reconcile Payments.
* Publish Outbox Messages.
* Clean Orphan Files.
* Rebuild Metrics عند الحاجة.

استخدم:

```php
withoutOverlapping()
onOneServer()
```

عند ملاءمة ذلك.

وثّق إعداد Production:

```text
php artisan schedule:run
php artisan queue:work
```

أو Supervisor/Horizon/Systemd/Docker deployment المناسب.

لا يكفي وجود Job داخل الملفات بدون تسجيله أو تشغيل Worker.

---

# Real-Time Updates

إذا كانت واجهة المزاد تحتاج تحديثات فورية، استخدم:

* Laravel Reverb.
* WebSockets.
* Server-Sent Events.
* أو Provider مناسب.

لكن:

* Broadcast لا يقرر صحة Bid.
* Broadcast يتم بعد Commit.
* كل رسالة تحتوي على Auction Version أو Sequence Number.
* العميل يتجاهل الرسائل الأقدم.
* العميل يعيد Sync من API عند فقد الرسائل.

لا تعتمد على WebSocket وحده كمصدر للحالة.

---

# Notifications

الإشعارات تعمل Async.

أنشئ Events واضحة مثل:

```text
AuctionPublished
AuctionStarted
BidAccepted
UserOutbid
AuctionExtended
AuctionEnded
WinnerSelected
WinnerPaymentRequired
WinnerDefaulted
DepositRefundRequested
DepositRefundCompleted
AuctionCompleted
AuctionCancelled
```

لا تجعل نجاح العملية الأساسية يعتمد على نجاح الإشعار.

استخدم Dedupe Key لمنع الإشعارات المكررة.

دعم Localization.

لا ترسل معلومات مالية أو شخصية حساسة في Push Notification بشكل مكشوف.

---

# Rate Limiting and Abuse Protection

أضف Rate Limits إلى:

* Place Bid.
* Register Auction Participant.
* Upload Payment Slip.
* Submit Deposit.
* Public Auction Views.
* Admin Payment Actions.
* Webhooks.

يجب أن تكون الحدود حسب:

* User.
* Auction.
* IP.
* Endpoint.

عند الحاجة.

لا تعتمد على Rate Limiting بدل:

* Idempotency.
* Authorization.
* Database Constraints.

أضف حماية من:

* Request Replay.
* Duplicate Submissions.
* Automated Bid Spam.
* File Upload Abuse.
* Enumeration of Public IDs.
* Brute Force.

---

# Fraud and Auction Abuse

ضع قواعد على الأقل لمنع:

* Seller bidding on own auction.
* User bidding بدون أهلية.
* User using the same account as seller and bidder.
* Bid after suspension.
* Repeated payment submissions.
* Bid manipulation.
* Unauthorized bid withdrawal.
* تغيير شروط المزاد بعد بدايته.
* تغيير Reserve بعد البداية.
* تغيير Minimum Increment بعد البداية.
* إلغاء المزاد لتجنب البيع بعد وصول سعر مناسب.

أنشئ Audit Data يمكن استخدامه مستقبلًا لكشف:

* Shill Bidding.
* Related Accounts.
* Shared IP or Device.
* Bid Shielding.
* Collusion.

لا تنفذ نظام Fraud معقدًا بدون متطلبات، لكن لا تحذف البيانات اللازمة للتحقيق مستقبلًا.

حدد سياسة Bid Withdrawal صراحة. الافتراضي الآمن هو عدم السماح للمستخدم بحذف أو سحب Bid مقبول بنفسه.

---

# Audit Trail

أنشئ Audit Log غير قابل للتلاعب من خلال التطبيق العادي.

يجب أن يسجل:

```text
actor_id
actor_type
auction_id
aggregate_type
aggregate_id
action
old_status
new_status
reason
request_id
idempotency_key
ip_address
user_agent
metadata
created_at
```

يشمل:

* إنشاء المزاد.
* تعديل المسودة.
* إرسال المراجعة.
* الاعتماد.
* الرفض.
* النشر.
* تغيير الوقت قبل النشر.
* الإلغاء.
* التمديد.
* اختيار الفائز.
* اعتماد الدفع.
* رفض الدفع.
* الاسترداد.
* المصادرة.
* التعثر.
* تغيير الفائز.
* التسليم.
* النزاعات.
* إجراءات الأدمن.

لا تخزن أسرارًا أو بيانات بنكية كاملة داخل Metadata.

---

# Observability

أضف Structured Logging.

كل Request حساس يجب أن يحتوي على:

```text
request_id
correlation_id
auction_id
actor_id
operation
duration
result
error_code
```

أضف Metrics قابلة للمراقبة مثل:

* Bid attempts per second.
* Accepted bids.
* Rejected bids حسب السبب.
* Bid transaction latency.
* Lock wait time.
* Auction finalization lag.
* Queue lag.
* Failed jobs.
* Refund failures.
* Payment reconciliation mismatches.
* Outbox pending messages.
* Auctions انتهت ولم يتم Finalize لها.
* Settlements تجاوزت Payment Deadline.

أضف Alerts للحالات الحرجة.

لا تكتب بيانات حساسة داخل Logs.

---

# API Design

استخدم API Versioning.

استخدم Response Format موحدًا.

استخدم Public IDs بدل IDs الداخلية.

أضف OpenAPI Documentation.

كل Endpoint يجب أن يوضح:

* Authentication.
* Authorization.
* Request.
* Response.
* Error Codes.
* Idempotency behavior.
* Rate Limits.
* Pagination.
* State Requirements.

لا تعرض أعمدة Database مباشرة لمجرد أنها موجودة.

---

# Performance and Scale

صمم النظام ليستطيع التوسع أفقيًا:

* التطبيق Stateless قدر الإمكان.
* Sessions خارج Local Disk.
* Shared Object Storage.
* Shared Cache/Redis.
* Queue مركزية.
* Database مناسبة للإنتاج.
* عدم الاعتماد على Local Files.
* عدم الاعتماد على Memory داخل Process بين الطلبات.
* عدم الاعتماد على Cron داخل Server واحد بدون Coordination.

دعم ملايين المستخدمين لا يعني أن كل المستخدمين متصلون في نفس اللحظة.

حدد واختبر سيناريوهات واضحة:

* ملايين المستخدمين المسجلين.
* مئات الآلاف من المزادات.
* ملايين أو عشرات الملايين من Bids تاريخيًا.
* آلاف المستخدمين على مزاد ساخن.
* عدد كبير من Bid Attempts في وقت قصير.
* عدد كبير من الإشعارات والـ Queue Jobs.

لا تضف Read Replicas أو Partitioning أو Sharding لمجرد الشكل.

صمم Schema وQueries بحيث تسمح بإضافتها عند الحاجة.

وثّق متى تصبح مطلوبة.

---

# Database Scaling Strategy

جهز التصميم لـ:

* Primary Database للكتابة.
* Read Replicas للقراءات غير الحساسة مستقبلًا.
* عدم قراءة السعر الحالي أو حالة الدفع من Replica متأخرة.
* Connection Pooling.
* Backup and Point-in-Time Recovery.
* Slow Query Monitoring.
* Index Monitoring.
* Archiving Strategy.
* Table Growth Monitoring.

اقرأ العمليات الحساسة من Primary.

يمكن قراءة Public Lists من Replica عند توفرها.

---

# Partitioning and Archiving

لا تنفذ Partitioning بدون Benchmark.

لكن قيّم الجداول المتوقع نموها جدًا:

```text
auction_bids
auction_activity_logs
auction_views
outbox_messages
payment_transactions
```

وثّق استراتيجية مستقبلية لـ:

* Date Partitioning.
* Hash Partitioning by Auction ID.
* Archiving old completed auctions.
* Moving historical analytics to Data Warehouse.

يجب ألا يؤدي Archiving إلى حذف السجل القانوني أو المالي.

---

# Search

إذا كانت قوائم المزادات تحتاج بحثًا متقدمًا:

* ابدأ باستعلامات Database مفهرسة إذا كانت كافية.
* استخدم Laravel Scout مع Meilisearch أو OpenSearch فقط عند وجود احتياج حقيقي.
* Search Index ليست مصدر الحقيقة.
* تحديث Search Index يتم Async.
* يجب تحمل تأخير بسيط في ظهور النتائج.
* التفاصيل الحساسة تأتي من Database.

---

# اختبارات الوحدة والتكامل

لا تعتبر العمل مكتملًا بدون Tests.

استخدم قاعدة بيانات Production-like لاختبارات:

* Locks.
* Transactions.
* Constraints.
* Concurrency.

لا تعتمد على SQLite فقط لاختبارات التزامن.

أنشئ على الأقل:

## Auction Lifecycle Tests

1. إنشاء Draft.
2. إرسال المزاد للمراجعة.
3. اعتماد المزاد.
4. رفض المزاد.
5. نشر المزاد.
6. منع النشر بدون المتطلبات.
7. بدء المزاد.
8. إنهاء المزاد.
9. مزاد بدون Bids.
10. مزاد لم يصل Reserve.
11. مزاد وصل Reserve.
12. إلغاء مزاد حسب كل حالة.
13. منع Transition غير مسموحة.

## Time Tests

14. رفض Bid قبل `starts_at`.
15. قبول Bid عند بداية المزاد.
16. رفض Bid عند `ends_at`.
17. رفض Bid بعد النهاية.
18. التمديد داخل Extension Window.
19. عدم التمديد خارج Extension Window.
20. عدم تجاوز Maximum Extensions.

## Bid Tests

21. قبول أول Bid صحيح.
22. رفض Bid أقل من سعر البداية.
23. رفض Bid أقل من Minimum Increment.
24. حفظ كل Bid كسجل مستقل.
25. الحفاظ على Bid History.
26. رفض Seller من المزايدة.
27. رفض User غير مؤهل.
28. رفض User بدون عربون معتمد.
29. ترتيب Bids بنفس المبلغ.
30. منع Bid مكرر بنفس Idempotency Key.

## Concurrency Tests

31. مزايدتان متزامنتان على نفس السعر.
32. عشرات المزايدات المتزامنة.
33. Sequence Number لا يتكرر.
34. Leading Bid واحدة فقط.
35. عدم فقد أي Bid مقبول.
36. Finalization أثناء وصول Bid عند وقت النهاية.
37. Finalization مرتين بالتزامن.
38. Payment Approval مرتين.
39. Refund مرتين.
40. Job Retry لا يكرر Side Effects.

## Deposit and Payment Tests

41. Payment Submission Pending لا يعني Held.
42. رفض إيصال لا يعني Forfeited.
43. رفع محاولة ثانية مع الاحتفاظ بالأولى.
44. المستخدم لا يحدد مبلغ العربون من نفسه.
45. اعتماد إيصال مرتبط بالمبلغ الصحيح.
46. منع اعتماد إيصال مزاد ملغي.
47. عدم إعادة تنشيط مزاد ملغي.
48. Refund Pending لا يعني Refunded.
49. Refund Success.
50. Refund Failure.
51. Refund Retry.
52. عدم استرداد أكثر من المبلغ المدفوع.
53. تطبيق عربون الفائز على Settlement مرة واحدة.

## Settlement Tests

54. إنشاء Settlement واحدة فقط.
55. حساب المبلغ المتبقي بدقة.
56. حساب العمولة بدقة.
57. عدم استخدام Float.
58. Payment Deadline.
59. Winner Default.
60. Forfeiture حسب السياسة.
61. Reassign إلى المزايد التالي.
62. منع أكثر من فائز نشط.
63. إتمام الدفع.
64. إتمام التسليم.
65. تحويل المزاد إلى Completed في الوقت الصحيح فقط.

## Authorization and Privacy Tests

66. Public User لا يرى Draft.
67. Public User لا يرى Pending Review.
68. المستخدم لا يرى إيصال مستخدم آخر.
69. البائع لا يرى إيصالات المزايدين.
70. Admin غير مالي لا يعتمد دفعات إذا كانت الصلاحيات منفصلة.
71. Public Resource لا يعرض بيانات حساسة.
72. Bidder Identity مخفية حسب السياسة.
73. Signed URL ينتهي.
74. Storage الخاص ليس Public.

## Database Tests

75. Constraints تمنع End قبل Start.
76. Constraints تمنع Duplicate Sequence.
77. Constraints تمنع Duplicate Settlement.
78. Foreign Keys صحيحة.
79. Cascade Delete لا يحذف السجلات المالية.
80. Winning Bid تنتمي لنفس Auction.

## Query and Performance Tests

81. عدم وجود N+1 في قائمة المزادات.
82. عدم وجود N+1 في تفاصيل المزاد.
83. عدم وجود N+1 في Bids.
84. Finalization لا ينفذ Query داخل Loop.
85. Cursor Pagination تعمل بشكل ثابت.
86. Query Count موثق.

## Scheduler and Queue Tests

87. Scheduler يلتقط المزادات المنتهية.
88. `withoutOverlapping` يمنع التكرار.
89. Failed Job يمكن إعادة محاولته.
90. Outbox Event ينشر مرة واحدة منطقيًا.
91. Notification Retry لا يكرر الإشعار.
92. Queue Worker Failure لا يفقد العملية.

## Storage Tests

93. فشل رفع ملف لا يترك Database Record خاطئًا.
94. فشل Database لا يترك Orphan File.
95. فشل حذف ملف قديم ينشئ Cleanup Job.
96. SVG مرفوض.
97. MIME المزيف مرفوض.
98. الملفات الخاصة لا تظهر للعامة.

---

# Load Testing

أنشئ Load Tests باستخدام أداة مثل:

```text
k6
Artillery
Locust
```

اختبر سيناريوهات مثل:

1. تصفح قائمة المزادات.
2. فتح تفاصيل مزاد.
3. تسجيل عدد كبير من المشاركين.
4. مزاد ساخن مع Bids متزامنة.
5. نهاية مزاد مع Finalization.
6. ضغط على Queue وNotifications.
7. Payment Webhooks مكررة.
8. Refund Jobs متزامنة.

قِس:

```text
p50
p95
p99
throughput
error rate
database CPU
database connections
lock wait time
deadlocks
queue lag
memory
```

لا تكتب أن النظام يتحمل ملايين المستخدمين بدون تقرير نتائج.

حدد بيئة الاختبار ومواصفات السيرفر وقاعدة البيانات.

إذا فشل الاختبار:

* حدد عنق الزجاجة.
* حسّن التصميم.
* أعد الاختبار.
* وثّق الحدود الحالية بصدق.

---

# Deadlocks

تعامل مع احتمالية Deadlocks.

وحّد ترتيب Locks في العمليات.

أضف Retry محدودًا للـ Transactions عند Deadlock.

لا تعمل Retry غير محدود.

كل Retry يجب أن يكون آمنًا بسبب Idempotency.

سجل Deadlock Metrics.

---

# Security

راجع على الأقل:

* SQL Injection.
* Mass Assignment.
* IDOR.
* Broken Access Control.
* File Upload Security.
* XSS في وصف المزاد.
* CSRF حسب نوع المصادقة.
* Replay Attacks.
* Rate Limiting.
* Webhook Signature Verification.
* Secret Management.
* Sensitive Logging.
* Enumeration.
* Excessive Data Exposure.
* Admin Permissions.
* Financial Authorization.

استخدم Sanitization أو Output Escaping للمحتوى النصي حسب مكان عرضه.

لا تثق في HTML قادم من المستخدم.

---

# Webhooks

إذا تم استخدام Payment Provider:

* تحقق من Signature.
* خزّن Webhook Event ID.
* أضف Unique Constraint.
* اجعل المعالجة Idempotent.
* لا تثق في ترتيب وصول Webhooks.
* أعد قراءة حالة العملية من Provider عند الحاجة.
* لا تعتبر Redirect من المتصفح دليلًا على نجاح الدفع.
* سجل Payload آمنًا بدون أسرار.
* نفذ Reconciliation Job.

---

# Reconciliation

أنشئ عملية دورية تراجع:

* Payment Transactions مقابل Provider.
* Refund Transactions مقابل Provider.
* Deposits مقابل Payments.
* Settlements مقابل Winning Bids.
* Applied Amounts.
* Auctions المنتهية بدون Settlement.
* Refunds العالقة.
* Outbox Messages العالقة.

أنتج تقريرًا أو Logs قابلة للتنبيه.

---

# Admin Operations

عمليات الأدمن المالية يجب أن تكون منفصلة بصلاحيات دقيقة.

مثل:

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

لا تستخدم `admin` فقط لكل شيء إذا كانت هناك عمليات مالية حساسة.

أي Override إداري يجب أن يتطلب:

* سببًا إلزاميًا.
* Audit Log.
* صلاحية خاصة.
* تأكيدًا إضافيًا في العمليات الخطيرة حسب تصميم النظام.

---

# عدم المبالغة المعمارية

رغم أن المطلوب نظام قوي، تجنب:

* Microservices بدون احتياج.
* Kafka بدون أحمال مثبتة.
* عشرات Interfaces لعمليات بسيطة.
* Repository لكل Model لمجرد الاسم.
* Service لكل دالة صغيرة.
* Event لكل Setter.
* Design Patterns بدون فائدة.

ابدأ بـ Modular Monolith قوي:

* Database صحيحة.
* Transactions صحيحة.
* Queues.
* Outbox.
* Redis.
* Object Storage.
* Observability.

واجعل حدوده واضحة بما يسمح بفصل الخدمات مستقبلًا إذا أثبتت الأحمال الحاجة.

---

# Migration Strategy

بما أن النظام لم يطلق للعملاء، الأفضل إنشاء Schema نظيف بدل استمرار Migrations القديمة.

نفذ:

1. حصر جميع جداول المزاد القديمة.
2. حصر Dependencies الخارجية.
3. إنشاء Backup.
4. حذف أو Rename الجداول القديمة بطريقة آمنة.
5. إنشاء Migrations جديدة نظيفة.
6. تحديث جميع Models والعلاقات.
7. تحديث جميع Routes والـ APIs.
8. حذف جميع الملفات القديمة.
9. حذف جميع Enums والحالات القديمة.
10. تنظيف Config وService Providers.
11. تحديث Seeders وFactories.
12. تشغيل جميع Tests.
13. التأكد من عدم وجود Reference لأسماء قديمة.

لا تترك Migration تضيف عمودًا ثم أخرى تغيره ثم أخرى تحذفه إذا كان النظام غير مستخدم بعد.

يفضل إنشاء Initial Auction Schema واضح.

---

# البيانات التجريبية

أنشئ Factories وSeeders واقعية لاختبار:

* مزادات Draft.
* مزادات Scheduled.
* مزادات Live.
* مزادات ممتدة.
* مزادات منتهية.
* مزادات بدون Bids.
* مزادات لم تصل Reserve.
* مزادات مع آلاف Bids.
* Winner Payment Pending.
* Winner Defaulted.
* Refund Pending.
* Disputed Auction.

لا تستخدم بيانات مالية غير منطقية.

---

# Coding Standards

استخدم:

```php
declare(strict_types=1);
```

حيث يتوافق مع المشروع.

استخدم:

* Typed Properties.
* Return Types.
* Enums.
* Readonly DTOs عند المناسب.
* Constructor Injection.
* Final Classes عند المناسب.
* Named Arguments باعتدال.
* Laravel Collections عندما تحسن الوضوح.
* Native PHP عندما يكون أبسط وأسرع.

تجنب:

* Magic Strings.
* Magic Numbers.
* Static Helpers غير الضرورية.
* Global State.
* Fat Controllers.
* God Services.
* Query Logic داخل Resources.
* Arrays ضخمة غير typed بين Layers.
* Exceptions عامة بدون معنى.

صحح المصطلحات:

* SOLID وليس SOLD.
* Single Responsibility Principle.
* Clean Architecture.
* DRY.

---

# النتيجة المطلوبة من الـ Agent

لا تكتفِ بالشرح.

نفذ النظام الجديد كاملًا.

قبل التنفيذ قدم خطة مختصرة وواضحة، ثم ابدأ مباشرة.

لا تطلب الحفاظ على أي شيء قديم.

إذا وجدت أن حذف جميع جداول المزادات وإعادة إنشائها هو الحل الصحيح، افعل ذلك.

إذا وجدت أسماء غير احترافية، غيّرها.

إذا وجدت Flow غير منطقي، غيّره.

إذا وجدت ميزة موجودة لكنها غير مكتملة أو خطرة، إما أكملها بشكل صحيح أو احذفها مع توضيح السبب.

لا تترك:

```text
TODO
FIXME
Placeholder
Temporary solution
Legacy fallback
Unused class
Dead code
```

---

# ملفات التوثيق المطلوبة

بعد التنفيذ أنشئ الملفات التالية:

```text
AUCTION_CURRENT_SYSTEM_AUDIT.md
AUCTION_TARGET_ARCHITECTURE.md
AUCTION_DATABASE_DESIGN.md
AUCTION_ERD.md
AUCTION_STATE_MACHINES.md
AUCTION_FULL_FLOW_AR.md
AUCTION_ASSUMPTIONS_AND_POLICIES.md
AUCTION_API_DOCUMENTATION.md
AUCTION_SECURITY_REVIEW.md
AUCTION_DEPLOYMENT_AND_OPERATIONS.md
```

## AUCTION_FULL_FLOW_AR.md

يشرح بالعربية وبالنقاط الـ Flow كاملًا من البداية للنهاية:

1. إعدادات الأدمن.
2. إنشاء نسخة شروط جديدة.
3. إنشاء المزاد كمسودة.
4. إضافة البيانات والصور.
5. تحديد سعر البداية.
6. تحديد Reserve.
7. تحديد Minimum Increment.
8. تحديد العربون.
9. تحديد وقت البداية والنهاية.
10. إرسال المزاد للمراجعة.
11. مراجعة الأدمن.
12. رفض أو اعتماد المزاد.
13. دفع عربون المعلن.
14. نشر وجدولة المزاد.
15. تسجيل المستخدم كمشارك.
16. قبول الشروط.
17. دفع عربون المزايد.
18. مراجعة الدفع.
19. بدء المزاد.
20. تقديم المزايدة.
21. التحقق من التزامن.
22. تحديث أعلى مزايدة.
23. التمديد التلقائي.
24. إخطار من تم تجاوزه.
25. انتهاء الوقت.
26. Finalization.
27. التحقق من Reserve.
28. تحديد الفائز.
29. إنشاء Settlement.
30. مطالبة الفائز بالدفع.
31. تطبيق العربون.
32. دفع باقي المبلغ.
33. مراجعة الدفع.
34. تعثر الفائز.
35. الانتقال لمزايد آخر عند تطبيق السياسة.
36. استرداد عربون غير الفائزين.
37. تسليم العنصر.
38. تأكيد الاستلام.
39. إتمام المزاد.
40. النزاعات.
41. الإلغاء.
42. Audit Trail.
43. الإشعارات.
44. حالات فشل الـ Queue أو الدفع أو Refund.
45. آليات Reconciliation.

---

# مخططات مطلوبة

أنشئ:

1. ERD كامل.
2. Auction State Diagram.
3. Deposit State Diagram.
4. Payment State Diagram.
5. Settlement State Diagram.
6. Sequence Diagram لعملية Place Bid.
7. Sequence Diagram لعملية Finalize Auction.
8. Sequence Diagram لعملية Refund.
9. Sequence Diagram لتعثر الفائز.
10. Sequence Diagram لمعالجة Payment Webhook.

يمكن كتابتها باستخدام Mermaid داخل ملفات Markdown.

---

# التقرير النهائي

في النهاية قدم تقريرًا يحتوي على:

## Created

جميع الملفات والجداول التي تم إنشاؤها.

## Modified

جميع الملفات التي تم تعديلها.

## Deleted

جميع الملفات والجداول والدوال القديمة التي تم حذفها.

## Database

* الجداول.
* الأعمدة.
* Foreign Keys.
* Unique Constraints.
* Indexes.
* Check Constraints.
* سبب كل قرار رئيسي.

## Business Decisions

جميع الافتراضات التي اتخذتها.

## Tests

* عدد الاختبارات.
* الاختبارات الناجحة.
* الاختبارات الفاشلة إن وجدت.
* سبب أي اختبار لم يتم تشغيله.

## Performance

* Query Counts.
* Slow Queries.
* Load Test Results.
* p95 وp99.
* Lock Wait.
* Throughput.
* حدود البيئة المستخدمة.

## Operations

* Scheduler setup.
* Queue setup.
* Redis setup.
* Storage setup.
* Environment variables.
* Monitoring.
* Alerts.
* Backup.
* Recovery.

## Remaining Risks

اذكر أي مخاطر متبقية بصدق.

لا تقل إن النظام يدعم ملايين المستخدمين بدون دليل عملي.

---

# معايير قبول نهائية

لا يعتبر العمل مكتملًا إلا إذا:

* تم حذف الـ Dead Code.
* لا توجد Paths قديمة مكررة.
* لا توجد Queries داخل Resources.
* لا توجد N+1 في الـ Endpoints الأساسية.
* لا يستخدم Float في الأموال.
* جميع Bids محفوظة Append-Only.
* Place Bid آمنة مع التزامن.
* Finalization آمنة ومتكررة التنفيذ.
* Payments وRefunds Idempotent.
* اختيار الفائز منفصل عن إتمام البيع.
* إيصالات الدفع Private.
* جميع العمليات المالية قابلة للتدقيق.
* Scheduler مسجل فعليًا.
* Jobs قابلة لإعادة المحاولة.
* Outbox يعمل.
* State Machines واضحة.
* Database Constraints موجودة.
* اختبارات التزامن تعمل على Database حقيقية مماثلة للإنتاج.
* Load Tests تم تشغيلها وتوثيقها.
* جميع الوثائق المطلوبة موجودة.
* جميع الاختبارات ناجحة.
* لا توجد TODOs أو حلول مؤقتة.
* الكود قابل للتوسع الأفقي.
* النظام يمكن تشغيله على أكثر من Application Server بدون تضارب.

المطلوب هو بناء نظام مزادات احترافي حقيقي، وليس فقط إعادة ترتيب الملفات.
