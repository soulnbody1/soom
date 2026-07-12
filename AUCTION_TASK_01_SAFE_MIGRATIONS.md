# TASK 01 — Safe Auction Migrations

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف

إصلاح Migrations الخاصة بنظام المزادات فقط، بحيث تكون آمنة على:

1. قاعدة بيانات فارغة.
2. قاعدة بيانات قديمة نفذت Migration إنشاء المزادات القديمة مسبقًا.
3. قاعدة بيانات تحتوي Schema مزادات جزئية أو قديمة.

هذه مهمة خاصة بالـMigrations فقط.

لا تنفذ أي Refactor آخر في Services أو Actions أو Repositories أو Resources إلا إذا كان ضروريًا مباشرة لتوافق الـSchema.

---

## المشكلة الحالية التي يجب التحقق منها

راجع فعليًا الملف:

```text
database/migrations/2026_07_11_180000_rebuild_auction_schema.php
```

وراجع جميع Migrations المزادات، خصوصًا Migration الإنشاء القديمة القريبة من:

```text
database/migrations/2025_06_12_090000_create_auctions_table.php
```

الاشتباه الحالي هو أن Migration حديثة قد تقوم بـ:

```text
drop auction tables
```

دون إعادة إنشاء الـSchema الجديدة داخل نفس مسار الـMigration، اعتمادًا على أن Migration قديمة ستعمل مرة أخرى.

هذا غير آمن؛ لأن Laravel لن يعيد تشغيل Migration قديمة مسجلة بالفعل في جدول:

```text
migrations
```

وقد تكون النتيجة حذف جداول المزادات وتركها مفقودة.

لا تفترض صحة هذا الوصف. تحقق من الكود الفعلي أولًا، ثم أصلح السبب الجذري.

---

# المتطلبات الإلزامية

## 1. عدم استخدام migrate:fresh

ممنوع استخدام:

```bash
php artisan migrate:fresh
```

وممنوع حذف أو إعادة إنشاء جداول خارج نظام المزادات.

---

## 2. Migration حديثة مستقلة

أنشئ Migration حديثة ومستقلة أو أعد تصميم سلسلة Migrations المزادات بطريقة صحيحة بحيث:

- لا تعتمد على إعادة تشغيل Migration قديمة.
- أي Migration تحذف Auction tables تعيد إنشاء الـSchema المطلوبة صراحة ضمن مسار آمن ومفهوم.
- لا تترك النظام بدون جداول إذا حدثت ترقية من Schema قديمة.
- النتيجة النهائية واحدة سواء كانت قاعدة البيانات Fresh أو Legacy.

بما أن MySQL DDL ليست Transactional بالكامل، لا تدّعِ أن `DB::transaction()` وحدها تحمي عمليات `DROP/CREATE`.

اختر استراتيجية واضحة وآمنة، مثل:

- إنشاء الجداول الجديدة بأسماء مؤقتة ثم التحقق ثم التبديل، إذا كانت هناك بيانات يجب الحفاظ عليها.
- أو Rebuild صريح لأن النظام لم يدخل Production، لكن يجب أن يكون كاملًا داخل Migration الحديثة نفسها.
- أو Migrations Incremental تحوّل الـSchema القديمة إلى الجديدة دون حذف غير ضروري.

وثّق سبب اختيارك.

---

## 3. حدود جداول المزادات

كوّن قائمة صريحة بكل جداول المزادات التي تدخل في الـSchema، مثل ما يوجد فعليًا في المشروع:

```text
auctions
auction_bids
auction_participants
auction_deposits
auction_payment_submissions
auction_payment_transactions
auction_refund_transactions
auction_settlements
auction_winner_reassignments
auction_terms
auction_terms_acceptances
auction_media
auction_activity_logs
auction_outbox_messages
auction_configuration_versions
```

لا تعتمد على هذه القائمة دون مراجعة الكود الفعلي.

يجب أن تتطابق:

- Models.
- Foreign keys.
- Repositories.
- Actions.
- Tests.
- Documentation.

---

## 4. ترتيب Foreign Keys

راجع ترتيب:

- إسقاط الجداول.
- إنشاء الجداول.
- Foreign Keys.
- Self-referencing foreign keys.
- Settlement previous/current references.
- Winner reassignment references.

لا تستخدم تعطيل Foreign Key checks كحل دائم يخفي ترتيبًا خاطئًا.

يمكن استخدامه بحذر أثناء Rebuild إذا كان ضروريًا، مع إعادة تفعيله دائمًا حتى عند الفشل، لكن الأفضل ترتيب الإسقاط والإنشاء بصورة صحيحة.

---

## 5. Constraints وIndexes

تحقق أن الـSchema النهائية تحتوي القيود المطلوبة فعليًا في الكود، خصوصًا:

- Public ULID uniqueness.
- Auction/bid idempotency uniqueness.
- Participant uniqueness.
- Payment submission idempotency.
- Payment transaction uniqueness.
- Provider transaction uniqueness.
- Refund provider reference uniqueness.
- Current settlement uniqueness.
- Sequence uniqueness داخل المزاد.
- Outbox uniqueness أو deduplication keys.
- Foreign key actions المناسبة.
- Indexes المستخدمة في Scheduler وPlace Bid وPayment review وRefund workers.

لا تضف Constraints بالتخمين. اربط كل Constraint باستعلام أو Business Rule فعلي.

---

## 6. Multiple Historical Settlements

تأكد أن Migration النهائية تدعم:

- أكثر من Settlement تاريخية لنفس المزاد.
- Settlement واحدة Current فقط.
- `sequence_number` فريد داخل المزاد.
- `previous_settlement_id`.
- `winner_reassignment_id`.
- عدم العودة إلى:
  ```text
  UNIQUE(auction_id)
  ```
  الذي يمنع Alternative Winner.

راجع توافق قاعدة البيانات مع الكود الحالي.

---

## 7. حماية البيانات الموجودة

النظام لم يدخل Production، لكن لا تفترض أن كل قواعد التطوير فارغة.

قبل إسقاط أي جدول:

- حدد هل توجد بيانات تحتاج Migration أم يمكن حذفها وفق قرار المشروع.
- إن كان الحذف مقصودًا، اكتب Warning واضحًا في التقرير.
- لا تحذف جداول غير المزادات.
- لا تستخدم SQL واسعًا غير Scoped.

إذا قررت أن البيانات القديمة غير متوافقة ويجب حذفها، اجعل ذلك صريحًا ومحدودًا في Auction tables فقط.

---

# الاختبارات المطلوبة

أنشئ اختبارات Migrations فعلية أو Scripts موثوقة تغطي السيناريوهات التالية:

## السيناريو A — Fresh Database

1. قاعدة اختبار فارغة.
2. تشغيل:
   ```bash
   php artisan migrate
   ```
3. التحقق من وجود جميع Auction tables.
4. التحقق من الأعمدة الأساسية.
5. التحقق من Foreign Keys وIndexes الحرجة.

## السيناريو B — Legacy Database

1. قاعدة اختبار منفصلة.
2. تشغيل Migrations حتى النسخة القديمة التي أنشأت Schema المزادات القديمة.
3. التأكد أن Migration القديمة مسجلة في جدول `migrations`.
4. تشغيل Migrations الحديثة.
5. التحقق أن Auction tables موجودة بعد الترقية.
6. التحقق أن الـSchema النهائية تطابق Fresh Database.

## السيناريو C — Current Schema

1. قاعدة لديها الـSchema الحالية بالفعل.
2. تشغيل Migrations مرة أخرى.
3. لا يتم حذف الجداول أو تكرار الأعمدة.
4. لا توجد أخطاء.
5. العملية Idempotent من منظور Laravel migration history.

لا تستخدم قاعدة التطوير الأساسية.

استخدم قاعدة تنتهي بـ:

```text
_testing
```

وأضف Guard يمنع تشغيل اختبار Migration على قاعدة غير مخصصة للاختبار.

---

# فحوصات مطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=AuctionMigration
```

وشغّل اختبارات السيناريوهات السابقة على MySQL، وليس SQLite فقط.

يمكنك استخدام:

```bash
php artisan schema:dump
```

للمقارنة إن كان مناسبًا، لكن لا تعتبره بديلًا عن اختبار Migration من Legacy إلى Current.

شغّل Syntax check على الملفات المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# ممنوعات هذه المهمة

لا تعدّل الآن:

- Payment flow.
- Refund flow.
- Cancellation business logic.
- Winner default business logic.
- Resources.
- Policies.
- DTO architecture.
- Outbox consumers.

إلا إذا كان تعديل صغير ضروريًا فقط لتوافق اسم عمود أو Constraint مع الـSchema المصححة، ويجب توثيقه.

لا تقم بتنظيفات جانبية.

---

# التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_01_MIGRATION_REPORT.md
```

ويحتوي على:

1. السبب الجذري للمشكلة.
2. وصف الـSchema القديمة.
3. وصف الـSchema النهائية.
4. الاستراتيجية التي تم اختيارها ولماذا.
5. Migrations المنشأة أو المعدلة.
6. جداول المزادات النهائية.
7. أهم Foreign Keys.
8. أهم Unique Constraints.
9. أهم Indexes.
10. كيفية التعامل مع البيانات القديمة.
11. نتائج Fresh database test.
12. نتائج Legacy upgrade test.
13. نتائج Current schema test.
14. الأوامر التي شُغلت ونتائجها.
15. أي فحص تعذر تشغيله وسبب ذلك.
16. أي مخاطر متبقية.

---

# شروط القبول

لا تعتبر المهمة مكتملة إلا إذا:

- لا توجد Migration حديثة تحذف Auction tables دون إعادة إنشاء أو تحويل كامل.
- لا تعتمد الترقية على إعادة تشغيل Migration قديمة.
- Fresh وLegacy ينتجان نفس Schema النهائية.
- Multiple historical settlements مدعومة.
- لا يوجد `UNIQUE(auction_id)` يمنع Alternative Winner.
- لا يتم لمس جداول خارج المزادات.
- لا يستخدم `migrate:fresh`.
- الاختبارات تعمل على قاعدة MySQL مخصصة.
- التقرير يطابق ما تم تشغيله فعليًا.

ابدأ بفحص الكود الحالي، ثم نفّذ الإصلاح الخاص بهذه المشكلة فقط.

لا تتوقف عند الخطة.
