# TASK — Final Three Auction Fixes

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

نفّذ الإصلاحات الثلاثة التالية فقط، بأبسط تعديل ممكن ودون إضافة Architecture أو Features جديدة.

## 1. إصلاح Outbox

راجع:

```text
DispatchOutboxMessagesAction
AuctionOutboxRepository
```

* لا تنشئ Outbox messages لأحداث لا يملك المعالج Handler فعليًا لها.
* احتفظ فقط بالأحداث المستخدمة فعليًا للإشعارات.
* لا تعتبر Audit events إشعارات.
* اسمح باسترجاع الرسائل التي ظلت:

  ```text
  Processing
  ```

  بعد انتهاء مدة الـLock بسبب توقف Worker.
* امنع إرسال نفس الرسالة مرتين.
* لا تنشئ Handler أو Class لكل Event.

## 2. توحيد صلاحية تجاوز مهلة الدفع

الكود يستخدم:

```text
auction.payment.override_deadline
```

تأكد أن نفس الاسم موجود ومتطابق في:

```text
config/auction.php
PaymentEligibilityRule
PaymentSubmissionPolicy
Requests/Controllers ذات الصلة
```

احذف أو استبدل الاسم المتعارض:

```text
auction.winners.override_payment_deadline
```

فقط إذا كان يؤدي نفس الغرض.

لا تنشئ نظام صلاحيات جديد.

## 3. عدم تجاهل فشل Refund أثناء الإلغاء

راجع:

```text
CancelAuctionFinanciallyAction
```

ممنوع تجاهل:

```php
catch (AuctionException) {
    // ignored
}
```

إذا فشل Refund ماليًا:

* لا تسجل أن الإلغاء المالي اكتمل بنجاح.
* إمّا تفشل العملية بوضوح، أو تسجل أنها تحتاج Manual Review باستخدام الحقول الحالية.
* لا تترك المزاد بحالة توحي أن كل الالتزامات المالية تمت وهي لم تتم.
* لا تغيّر منطق Cancellation العام خارج هذه النقطة.

## قيود صارمة

* عدّل الملفات الحالية فقط قدر الإمكان.
* لا تنشئ أكثر من ملف جديد واحد.
* لا تنشئ DTO أو Interface أو Service أو Migration جديدة.
* لا تعمل Refactor عام.
* لا تغيّر API Contracts.
* لا تعد تنفيذ Refund أو Outbox من البداية.
* لا تنشئ تقريرًا جديدًا.

## التحقق

تحقق من:

1. Event غير مدعومة لا تدخل Outbox.
2. رسالة `Processing` منتهية الـLock يمكن معالجتها مجددًا.
3. الرسالة الناجحة لا تُرسل مرتين.
4. Permission تجاوز المهلة تعمل بالاسم الموحد.
5. فشل Refund يمنع تسجيل اكتمال الإلغاء المالي.
6. شغّل الاختبارات الحالية المرتبطة فقط وSyntax check.

في النهاية اذكر الملفات المعدلة ونتائج التحقق داخل ردك فقط.

نفّذ التعديلات مباشرة، ولا تتوقف عند الخطة.
