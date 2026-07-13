# TASK 13 LITE — Make Auction Outbox Actually Work

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف

راجع نظام الـOutbox الحالي الخاص بالمزادات، واجعله ينفذ الأحداث المهمة فعليًا بدل أن تكون الرسائل مجرد Records محفوظة أو Placeholder لا ينتج عنه أي أثر.

هذه مهمة صغيرة ومحددة.

لا تنشئ Event Platform كبيرة، ولا تعد بناء نظام Notifications بالكامل، ولا تنشئ Processor منفصل لكل Event إلا عند ضرورة حقيقية.

---

## المطلوب مراجعته

راجع فقط:

```text
app/Models/Auction/OutboxMessage.php
app/Repositories/Auction/*Outbox*
app/Services/Auction/*Outbox*
app/Jobs/Auction/*Outbox*
app/Console/Commands/Auction/*Outbox*
app/Notifications/
app/Mail/
routes/console.php
app/Console/Kernel.php
config/auction.php
```

وراجع الأماكن التي تنشئ Outbox messages داخل Actions الخاصة بالمزادات.

---

## الأحداث الضرورية فقط

ادعم في الإصدار الحالي الأحداث الأساسية التالية إن كانت موجودة بالفعل:

```text
auction.approved
auction.started
auction.ended
auction.winner_selected
auction.payment_approved
auction.payment_rejected
auction.refund_succeeded
auction.cancelled
```

لا تضف عشرات الأحداث الجديدة.

إذا كان اسم الحدث الحالي مختلفًا، استخدم الاسم الموجود بدل تغييره بلا ضرورة.

---

## النتيجة المطلوبة لكل Event

يكفي في هذه المرحلة أن يؤدي الحدث إلى Notification حقيقية داخل النظام، ويمكن استخدام Email فقط إذا كانت البنية الحالية تدعمه بسهولة.

مثال:

```text
auction.approved
→ إشعار للبائع بأن المزاد تم اعتماده
```

```text
auction.winner_selected
→ إشعار للفائز
→ إشعار للبائع
```

```text
auction.payment_approved
→ إشعار لصاحب عملية الدفع
```

```text
auction.refund_succeeded
→ إشعار لصاحب المبلغ المرتجع
```

```text
auction.cancelled
→ إشعار للأطراف المتأثرة
```

لا تنشئ SMS أو WhatsApp أو Push Integration جديدة ضمن هذه المهمة.

---

## Flow المعالجة المطلوب

استخدم Flow بسيطًا:

```text
Pending
→ Processing
→ Processed
```

وفي حالة الفشل:

```text
Failed
```

يجب أن يدعم Outbox message الحقول الموجودة فعليًا، وعلى الأقل:

```text
status
attempt_count
last_error
available_at أو next_retry_at
processed_at
```

لا تضف الحقول إذا كان لها بديل موجود بالفعل.

---

## Job واحدة فقط

استخدم أو أنشئ Job واحدة مثل:

```text
ProcessAuctionOutboxJob
```

مسؤوليتها:

1. جلب عدد محدود من الرسائل الجاهزة.
2. منع معالجة الرسالة نفسها مرتين.
3. تحويل الرسالة إلى `Processing`.
4. تنفيذ الـNotification المناسبة.
5. تحويلها إلى `Processed` عند النجاح.
6. تسجيل الخطأ وزيادة `attempt_count` عند الفشل.
7. إعادة المحاولة لاحقًا بحد أقصى بسيط.

لا تنشئ Job مستقلة لكل Event.

---

## التزامن وIdempotency

يجب منع تشغيل نفس Outbox message مرتين.

استخدم الموجود في المشروع مثل:

```text
Database lock
lockForUpdate
processing_at
unique event key
```

لا تبنِ Lease System معقدة إذا لم تكن مطلوبة.

إذا كانت الرسالة `Processed` بالفعل:

```text
لا تُرسل Notification مرة ثانية
```

---

## عدد المحاولات

استخدم إعدادًا بسيطًا في Config مثل:

```text
max_attempts = 3
retry_delay_minutes = 5
```

بعد الحد الأقصى:

```text
status = Failed
```

مع حفظ `last_error`.

لا تنشئ Dead Letter System أو Dashboard جديدة.

---

## بيانات الرسالة

يجب أن تحتوي Payload على IDs ومعلومات ضرورية فقط، مثل:

```text
auction_id
user_id
settlement_id
payment_submission_id
refund_transaction_id
```

لا تحفظ داخل Payload:

```text
كائنات Models كاملة
إيصالات
مسارات ملفات
Provider responses
بيانات مالية حساسة غير لازمة
```

عند التنفيذ، أعد تحميل البيانات من Repository أو Model حسب الهيكلة الحالية.

---

## التعامل مع Event غير معروف

إذا وصلت رسالة بنوع غير مدعوم:

```text
لا تعتبرها Processed
```

سجل خطأ واضحًا، وحولها إلى `Failed` بعد المحاولات المناسبة.

---

## Scheduler

سجل Job واحدة لتعمل بشكل دوري، مثل:

```text
كل دقيقة
```

وتحقق أنها ظاهرة في:

```bash
php artisan schedule:list
```

لا تنشئ أكثر من Command وJob لنفس الغرض.

---

## قواعد مهمة

* استخدم البنية الحالية قدر الإمكان.
* عدّل الملفات الموجودة بدل إنشاء Layers جديدة.
* لا تنشئ أكثر من 5 ملفات جديدة إلا لضرورة حقيقية مع توضيح السبب.
* لا تنشئ Interface لكل Handler.
* لا تنشئ DTO لكل Event.
* لا تنشئ Event Bus أو Registry معقدة.
* يمكن استخدام `match` واضح داخل Handler واحد للأحداث القليلة.
* لا تنفذ Notifications داخل Transaction المالية.
* يجب إنشاء Outbox record داخل Transaction المالية، ومعالجتها بعد Commit.
* لا تغيّر Financial Logic أو State Machine.
* لا تنفذ TASK 14 أو TASK 15.
* لا تعمل Refactor عام.

---

## التحقق المطلوب

نفّذ تحققًا بسيطًا:

1. أنشئ Outbox message من Event موجود.
2. شغّل Job يدويًا.
3. تأكد أن Notification أُرسلت أو تم تسجيلها في القناة الحالية.
4. تأكد أن الرسالة أصبحت `Processed`.
5. شغّل Job مرة أخرى وتأكد أن Notification لم تتكرر.
6. جرّب فشلًا متعمدًا وتأكد من زيادة `attempt_count`.
7. تأكد من تحولها إلى `Failed` بعد الحد الأقصى.
8. شغّل:

```bash
php artisan schedule:list
```

9. شغّل الاختبارات الموجودة الخاصة بالـOutbox فقط.
10. شغّل Syntax check للملفات المعدلة.

لا تنشئ Test Suite كبيرة جديدة.

---

## التقرير

أنشئ تقريرًا مختصرًا:

```text
AUCTION_TASK_13_LITE_REPORT.md
```

يحتوي فقط على:

```text
كيف كانت Outbox تعمل قبل التعديل
الأحداث التي أصبحت مدعومة
الإشعارات التي يتم تنفيذها
الملفات المعدلة
الملفات الجديدة
عدد المحاولات وإعادة المحاولة
نتيجة schedule:list
نتائج التحقق والاختبارات
```

نفّذ المطلوب فقط.

لا تضف Events أو Notifications أو Architecture خارج النطاق.

لا تتوقف عند الخطة.
