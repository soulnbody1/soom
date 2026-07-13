# TASK 14 LITE — Secure Critical Auction Jobs and Scheduler

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف

راجع الـJobs والـCommands المجدولة الخاصة بالمزادات، وامنع تنفيذ العملية المالية أو تغيير الحالة أكثر من مرة عند تشغيل نفس Job بالتزامن أو إعادة المحاولة.

هذه مهمة محددة وصغيرة. لا تنفذ Refactor معماري واسع.

---

## راجع فقط العمليات الحرجة

```text
بدء المزاد Scheduled → Live
إنهاء المزاد Live → Ended
Finalize واختيار الفائز
Winner Default التلقائي
Refund processing
Outbox processing
```

راجع الملفات الموجودة في:

```text
app/Jobs/Auction
app/Console/Commands/Auction
app/Services/Auction/Actions
routes/console.php
app/Console/Kernel.php
```

---

## المطلوب

لكل Job أو Command حرجة:

1. أعد تحميل المزاد أو السجل داخل Transaction.
2. استخدم `lockForUpdate()` أو Repository lock الموجودة.
3. تحقق من الحالة الحالية بعد الحصول على Lock.
4. إذا نُفذت العملية بالفعل، اخرج بأمان دون تكرارها.
5. لا تنشئ:

   * Winner ثانية.
   * Settlement ثانية.
   * Refund ثانية.
   * Forfeiture ثانية.
   * Outbox message مكررة.
6. استخدم Database Constraints الموجودة كحماية إضافية.
7. لا تعتمد على `withoutOverlapping()` وحدها.

---

## معالجة السجلات

ممنوع استخدام:

```php
Model::query()->get();
```

على كل المزادات أو الـRefunds.

استخدم حسب الموجود:

```php
chunkById()
lazyById()
cursor()
```

مع Batch صغيرة.

كل Record تُعالج داخل Use Case الحالية، ولا تضع Business Logic جديدة داخل Job.

---

## Scheduler

تأكد أن كل عملية مجدولة مرة واحدة فقط.

راجع:

```bash
php artisan schedule:list
```

احذف أي Schedule مكرر لنفس العملية.

استخدم `withoutOverlapping()` كحماية إضافية فقط، وليس بديلًا عن Database Locks وIdempotency.

---

## قواعد مهمة

* عدّل الملفات الحالية قدر الإمكان.
* لا تنشئ أكثر من 5 ملفات جديدة إلا لضرورة حقيقية.
* لا تنشئ Job منفصلة لكل Status.
* لا تنشئ Lock Service أو Framework جديد.
* لا تنشئ DTOs أو Interfaces جديدة إلا إذا كانت ضرورية فعلًا.
* لا تغيّر Financial Logic أو State Machine.
* لا تعد تنفيذ TASK 13 أو Refund Lifecycle.
* لا تعمل Refactor عام للـRepositories.
* استخدم Actions وRepositories الموجودة بالفعل.

---

## التحقق المطلوب

اختبر بصورة مركزة:

1. تشغيل Start Job مرتين لا يبدأ المزاد مرتين.
2. تشغيل End/Finalize مرتين لا ينشئ Winner أو Settlement إضافية.
3. تشغيل Winner Default مرتين لا ينشئ Alternative Settlement إضافية.
4. تشغيل Refund Job مرتين لا ينفذ Refund مرتين.
5. تشغيل Outbox Job مرتين لا يرسل الإشعار مرتين.
6. عمليتان متزامنتان لنفس المزاد تنتج عنهما عملية واحدة فقط.
7. شغّل:

```bash
php artisan schedule:list
```

8. شغّل الاختبارات الموجودة المرتبطة بالـJobs فقط.
9. شغّل Syntax check للملفات المعدلة.

لا تنشئ Test Suite ضخمة جديدة. أضف فقط الاختبارات الضرورية لإثبات عدم التكرار.

---

## التقرير

أنشئ تقريرًا مختصرًا:

```text
AUCTION_TASK_14_LITE_REPORT.md
```

ويحتوي على:

```text
الـJobs والـCommands التي تمت مراجعتها
أماكن التكرار أو الخطر التي تم إصلاحها
الـLocks وIdempotency المستخدمة
أي Schedule مكرر تم حذفه
الملفات المعدلة والجديدة
نتيجة schedule:list
نتائج التحقق والاختبارات
```

نفّذ المطلوب فقط، ولا تضف Architecture أو تحسينات خارج النطاق.

لا تتوقف عند الخطة.
