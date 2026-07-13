# TASK — Complete Manual Auction Refund Flow

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف

إكمال تشغيل الـManual Refund الموجودة حاليًا، بحيث يستطيع الأدمن:

1. عرض عمليات الاسترداد.
2. تأكيد أن المبلغ تم تحويله للمستخدم فعليًا.
3. إلغاء عملية استرداد لم يتم تنفيذها.
4. منع بقاء العربون في حالة خاطئة بعد إلغاء Refund.

المطلوب استخدام الكود الحالي فقط، دون بناء نظام جديد.

---

## راجع الموجود أولًا

راجع:

```text
ConfirmAuctionRefundManuallyAction
CancelAuctionRefundAction
RefundTransaction
AuctionDeposit
AuctionRefundRepository
RefundTransactionPolicy
routes/api/auction.php
Admin Auction Controllers
```

استخدم الـActions الموجودة بالفعل، ولا تنسخ منطقها داخل Controller.

---

## المطلوب تنفيذه

### 1. عرض عمليات Refund

أضف Admin endpoint بسيطًا لعرض Refunds مع Pagination، ويمكن التصفية حسب:

```text
status
auction_id
user_id
```

اعرض فقط البيانات اللازمة:

```text
public_id
auction reference
owner
amount
currency
status
reason
attempt_count
last_error
created_at
succeeded_at
```

لا تعرض:

```text
provider_response
storage paths
بيانات حساسة غير لازمة
```

---

### 2. تأكيد Refund يدويًا

أضف Endpoint مثل:

```text
POST /admin/auctions/refunds/{refund}/confirm
```

الـRequest يحتوي فقط على:

```text
confirmation_reference
reason
```

كلاهما مطلوب.

استخدم:

```text
ConfirmAuctionRefundManuallyAction
```

ولا تكتب الحسابات المالية داخل Controller.

بعد النجاح يجب:

```text
RefundTransaction → Succeeded
Deposit buckets تتحدث مرة واحدة فقط
PaymentTransaction تتحدث حسب الكود الحالي
```

إعادة نفس الطلب لا يجب أن تخصم أو تسجل المبلغ مرتين.

---

### 3. إلغاء Refund

أضف Endpoint مثل:

```text
POST /admin/auctions/refunds/{refund}/cancel
```

الـRequest يحتوي:

```text
reason
```

استخدم:

```text
CancelAuctionRefundAction
```

---

## إصلاح مهم عند الإلغاء

حاليًا عند إنشاء Refund تصبح الـDeposit:

```text
RefundPending
```

وعند إلغاء Refund قد تظل في نفس الحالة.

أصلح ذلك بشكل مباشر.

عند إلغاء Refund:

* فك حجز مبلغ الـRefund.
* أعد Deposit إلى الحالة المالية الصحيحة حسب Buckets الحالية.
* إذا بقي مبلغ في:

  ```text
  held_amount_minor
  ```

  تكون الحالة `Held`.
* إذا بقي مبلغ في:

  ```text
  applied_amount_minor
  ```

  تكون الحالة المناسبة للعربون المطبق.
* إذا أصبح المبلغ بالكامل مصادرًا تكون `Forfeited`.
* إذا أصبح بالكامل مردودًا تكون `Refunded`.

استخدم الـEnums والحالات الموجودة فعليًا، ولا تضف حالات جديدة دون ضرورة.

لا تسمح بأن تصبح النتيجة:

```text
Refund = Cancelled
Deposit = RefundPending
```

---

## إعادة إنشاء Refund بعد الإلغاء

راجع الـIdempotency key الحالية.

إذا كانت Refund أُلغيت، يجب ألا تمنع إنشاء Refund جديدة لنفس المبلغ لاحقًا عند وجود سبب صحيح.

لا تعِد استخدام Refund الملغاة وتضع Deposit في `RefundPending` مرة أخرى.

نفّذ أبسط حل صحيح باستخدام الكود الحالي وDatabase state الموجودة.

---

## الصلاحيات

استخدم Permission موجودة أو أضف اسم Permission واحدًا واضحًا فقط:

```text
auction.refunds.manage
```

الأدمن المخول فقط يستطيع:

```text
عرض Refunds
تأكيد Refund
إلغاء Refund
```

لا تنشئ نظام صلاحيات جديد.

---

## قواعد صارمة

* لا تنشئ Refund architecture جديدة.
* لا تنشئ Processor جديدًا.
* لا تنشئ Service أو Repository جديدًا إذا الموجود يكفي.
* لا تنشئ DTO إلا إذا الـAction الحالية تتطلبه.
* لا تنشئ أكثر من 4 ملفات جديدة.
* عدّل Controller وRoutes وRequests الحالية قدر الإمكان.
* لا تغيّر Refund Lifecycle العامة.
* لا تغيّر Cancellation أو Winner Default.
* لا تعمل Refactor خارج المطلوب.
* لا تضف Upload system جديدًا.
* لا تضف واجهة Frontend.

---

## التحقق المطلوب

تحقق فقط من الحالات التالية:

1. الأدمن المخول يستطيع عرض Refunds.
2. غير المخول يُرفض.
3. تأكيد Refund يدويًا يحدث الحسابات مرة واحدة.
4. إعادة التأكيد لا تكرر الحسابات.
5. إلغاء Refund يغيرها إلى `Cancelled`.
6. Deposit لا تظل `RefundPending` بعد الإلغاء.
7. يمكن إنشاء Refund جديدة لاحقًا بعد إلغاء القديمة.
8. لا يمكن إلغاء Refund ناجحة.
9. شغّل Syntax check والاختبارات الحالية المتعلقة بالـRefund فقط.

لا تنشئ Test Suite كبيرة.

---

## النتيجة المطلوبة

في نهاية التنفيذ اكتب ملخصًا داخل ردك فقط، دون إنشاء تقرير جديد، يحتوي على:

```text
الملفات المعدلة
الملفات الجديدة
Routes المضافة
كيفية إصلاح Deposit بعد إلغاء Refund
نتائج التحقق والاختبارات
```

نفّذ المطلوب فقط، ولا تتوقف عند الخطة.
