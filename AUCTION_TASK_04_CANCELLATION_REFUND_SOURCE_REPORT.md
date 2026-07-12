# AUCTION TASK 04 - Cancellation Refund Source Report

## 1. السبب الجذري للـDouble Refund

كان `CancelAuctionAction` يبني خطة refund من مصدرين مستقلين:

- `AuctionDeposit` حسب `held_amount_minor + applied_amount_minor - refunded_amount_minor`.
- `PaymentTransaction` حسب كل successful payment في المزاد.

هذا يسمح لنفس المال، مثل bidder deposit، أن ينتج:

- Refund من deposit.
- Refund ثانية من payment transaction التي دفعت نفس deposit.

كما كان الكود يحول `PaymentTransaction.status` إلى `reversed` فور إنشاء `RefundTransaction` بحالة `pending`، رغم أن المال لم يرد فعلياً بعد.

## 2. Source of Truth النهائي

مصدر الأموال النهائي للـrefund هو:

```text
PaymentTransaction
```

التصميم بعد الإصلاح:

```text
Deposit / Settlement = financial obligation
PaymentTransaction = captured money
RefundTransaction = reversal plan for captured money
```

لا ينشئ مسار cancellation أي refund قابل للتنفيذ إذا لم يجد `PaymentTransaction` ناجحة.

## 3. العلاقة النهائية بين Deposit وPaymentTransaction وRefundTransaction

- Deposit refund:
  - `refund_transactions.payment_transaction_id` يشير إلى payment الأصلية.
  - `refund_transactions.deposit_id` يبقى كمرجع محاسبي للـdeposit.
  - `obligation_type = deposit`
  - `obligation_id = deposit.id`

- Settlement refund:
  - `refund_transactions.payment_transaction_id` يشير إلى winner payment الأصلية.
  - `deposit_id = null`
  - `obligation_type = settlement`
  - `obligation_id = settlement.id`

## 4. حساب Refundable Amount

في هذه المهمة لم تتم إضافة partial refunds. السياسة المنفذة:

```text
one full refund per successful payment transaction
```

مسار cancellation:

1. يقفل المزاد.
2. يقفل deposits ذات الصلة لمنع سباق الحالة.
3. يقفل successful payment transactions.
4. لكل payment:
   - يقفل refund active/succeeded لنفس `payment_transaction_id`.
   - إذا وجد refund سابق، لا ينشئ شيئاً.
   - إذا لم يوجد، ينشئ refund pending بالمبلغ الكامل للـpayment.

## 5. Database Constraints الجديدة

أضيف migration:

```text
database/migrations/2026_07_13_010000_add_refund_source_constraints.php
```

ويضيف:

- `refund_transactions.obligation_type`
- `refund_transactions.obligation_id`
- unique index:
  ```text
  uq_refund_source_payment_transaction(payment_transaction_id)
  ```
- index:
  ```text
  idx_refund_obligation(obligation_type, obligation_id)
  ```

القيد يمنع أكثر من refund كامل لنفس `PaymentTransaction`.

## 6. منع تكرار Refund Plan

تم تغيير `CancelAuctionAction` ليبني الخطة من `PaymentTransaction` فقط.

إعادة الإلغاء:

- لا تنشئ refund ثانية.
- لا تضاعف المبلغ.
- لا تغير `PaymentTransaction` أثناء pending.

التزامن:

- `Auction` يقفل أولاً داخل transaction.
- successful payments تقفل بـ`lockForUpdate`.
- refunds القائمة لنفس payment تقفل قبل التخطيط.
- unique index على `payment_transaction_id` يحمي قاعدة البيانات.

## 7. حالة PaymentTransaction أثناء Pending Refund

عند إنشاء `RefundTransaction` بحالة:

```text
pending
```

تبقى:

```text
PaymentTransaction.status = succeeded
```

ولا تتحول إلى `reversed`.

## 8. متى تصبح Refunded/Reversed

الـenum الحالي يحتوي:

```text
reversed
```

ولا يحتوي `refunded`.

لذلك يتم تحويل `PaymentTransaction.status` إلى `reversed` فقط داخل:

```text
RefundAuctionDepositAction::confirmSucceeded()
```

أي بعد نجاح refund فعلياً.

## 9. الملفات المنشأة

- `database/migrations/2026_07_13_010000_add_refund_source_constraints.php`
- `tests/Feature/Auction/CancellationRefundTest.php`
- `tests/Feature/Auction/CancellationRefundMysqlTest.php`
- `AUCTION_TASK_04_CANCELLATION_REFUND_SOURCE_REPORT.md`

## 10. الملفات المعدلة

- `app/Services/Auction/Actions/CancelAuctionAction.php`
- `app/Services/Auction/Actions/RefundAuctionDepositAction.php`
- `app/Repositories/Auction/AuctionPaymentRepository.php`
- `app/Repositories/Auction/AuctionRefundRepository.php`
- `app/Models/Auction/PaymentTransaction.php`
- `app/Models/Auction/RefundTransaction.php`
- `tests/Feature/Auction/AuctionFinancialFlowTest.php`

## 11. Migrations الجديدة

- `2026_07_13_010000_add_refund_source_constraints`

`php artisan migrate:status` نجح وأظهر migration الجديدة Pending في قاعدة التطوير الحالية. لم يتم تشغيل migrate على قاعدة التطوير.

## 12. الاختبارات الجديدة

- `CancellationRefundTest`
  - paid deposit ينتج refund واحدة من payment الأصلية.
  - paid settlement ينتج refund واحدة من payment الأصلية.
  - deposit + settlement ينتجان refundين فقط بإجمالي صحيح.
  - deposit بدون successful payment لا ينتج refund حقيقي.
  - `PaymentTransaction` تتحول إلى `reversed` فقط بعد `confirmSucceeded`.

- `CancellationRefundMysqlTest`
  - عمليتا cancellation متزامنتان على MySQL تنتجان refund واحدة فقط.

## 13. نتائج Feature Tests

- `php artisan test --filter=CancellationRefund`
  - Passed: 5
  - Skipped: 1 MySQL-only
  - Assertions: 27

- `php artisan test --filter=Cancellation`
  - Passed: 8
  - Skipped: 1 MySQL-only
  - Assertions: 50

- `php artisan test --filter=Refund`
  - Passed: 9
  - Skipped: 3 MySQL-only
  - Assertions: 43

## 14. نتائج MySQL Concurrency Test

- `php artisan test --configuration=phpunit.mysql.xml --filter=CancellationRefund`
  - Passed: 6
  - Assertions: 38
  - شمل اختبار التزامن: `parallel cancellations create one refund for original payment`.
  - ظهر warning من Laravel: `Option --configuration cannot be used more than once`، لكن الاختبارات عملت على إعداد MySQL ونجحت.

## 15. الأوامر التي شغلت

- `composer dump-autoload`
  - تم تشغيله مرتين، لكنه انتهى بـtimeout بعد 120 ثانية ثم 240 ثانية عند `Generating optimized autoload files`.
- `php artisan optimize:clear`
  - Passed.
- `php artisan migrate:status`
  - Passed.
- `php artisan test --filter=CancellationRefund`
  - Passed.
- `php artisan test --configuration=phpunit.mysql.xml --filter=CancellationRefund`
  - Passed.
- `php artisan test --filter=Cancellation`
  - Passed.
- `php artisan test --filter=Refund`
  - Passed.
- `vendor/bin/pint --test`
  - Failed على كامل المشروع بسبب 147 style issue قديمة خارج نطاق المهمة.
- `vendor/bin/pint --test <task files>`
  - Passed على 10 ملفات.
- `php -l` للملفات المعدلة والمنشأة
  - Passed.

## 16. فحوص تعذر تشغيلها

- `composer dump-autoload` لم يكتمل بسبب timeout مرتين.
- `vendor/bin/pint --test` على كامل المشروع لم ينجح بسبب style issues موجودة مسبقاً في ملفات كثيرة خارج نطاق هذه المهمة.

## 17. نقاط مؤجلة إلى TASK 05 أو TASK 06

- Refund provider retries/backoff/lease الكامل.
- Partial refunds.
- Applied deposit refund accounting الكامل.
- Seller deposit final policy.
- Winner default.
- Non-winner release policy.
- General outbox consumers.

## 18. خلاصة القبول

- لا يوجد refund من deposit ومن payment لنفس المال في cancellation.
- كل refund في cancellation لديه `payment_transaction_id` أصلي.
- PaymentTransaction تبقى `succeeded` أثناء pending refund.
- PaymentTransaction تتحول إلى `reversed` فقط بعد نجاح refund.
- إعادة cancellation لا تكرر refund plan.
- MySQL concurrency test يثبت أن الإلغاء المتزامن ينتج refund واحدة فقط.
