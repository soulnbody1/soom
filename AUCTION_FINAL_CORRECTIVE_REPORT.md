# Auction Final Corrective Report

## 1. ملخص تنفيذي

تم تنفيذ تمريرة تصحيحية فعلية داخل نظام المزادات لمعالجة أخطاء مالية وقاعدية كانت تمنع اعتبار مسار الـSettlement وWinner Default آمنين. أهم التصحيحات المنفذة:

- عدم احتساب العربون كـ`amount_paid_minor` عند إنشاء التسوية.
- دعم حالة تغطية العربون لكامل مبلغ الفوز والانتقال مباشرة إلى `handover_pending`.
- دعم وجود Settlements تاريخية متعددة لنفس المزاد مع Settlement واحد فقط current بقيد قاعدة بيانات.
- إنشاء Settlement جديد للفائز البديل بدل تعديل Settlement الفائز القديم.
- قراءة Terms Acceptance من جدول `auction_terms_acceptances`.
- السماح بإعادة إرسال إيصال عربون بعد رفض محاولة دفع.
- منع refund صفرية، ومنع double-count عند تأكيد refund ناجحة مرتين.
- استبدال Regex الخانتين في `PlaceBidRequest` بـ`CurrencyDecimalRule` لدعم JOD بثلاث خانات.
- منع تعليم رسالة Outbox كـPublished إذا لم يوجد consumer/listener فعلي.
- إضافة Outbox consumer داخلي يسجل `auction.outbox_consumed` عند نجاح الاستهلاك.
- إضافة Financial Cancellation Flow ينشئ refund plan للعربونات والمدفوعات الناجحة بشكل idempotent.
- تنظيف ملفات صور المزاد المرفوعة عند فشل إنشاء سجل قاعدة البيانات أو فشل العملية اللاحقة.
- إضافة اختبارات فعلية على SQLite الافتراضي وMySQL فعلي، بما فيها replay/idempotency/constraint tests لمسارات التزامن المطلوبة.

لم يتم الادعاء بأن النظام Production-Ready؛ لأن قائمة القبول الأصلية أكبر من نطاق ما تم إثباته بالكامل في هذه التمريرة، خصوصًا تغطية Outbox consumer المتخصصة لكل نوع event، واختبارات parallel-process حرفية تعمل عمليتين في نفس اللحظة، وبعض اختبارات الخصوصية.

## 2. المشكلات التي تم إصلاحها

- `FinalizeAuctionAction` كان ينشئ `amount_paid_minor = deposit_applied_minor`. أصبح `amount_paid_minor = 0` و`remaining_amount_minor = amount_due_minor`.
- `MarkWinnerDefaultedAction` كان يستخدم قسمة `/` في رسوم المنصة. أصبح يستخدم `intdiv()`.
- `MarkWinnerDefaultedAction` كان يقرأ `terms_accepted_at` من participant. أصبح يستخدم `AuctionTermsRepository::hasAcceptedTerms()`.
- جدول `auction_settlements` كان يمنع أكثر من Settlement واحدة للمزاد عبر `UNIQUE(auction_id)`. تم استبداله بتصميم current/history.
- `payment_due_at` أصبح nullable لحالة الدفع المغطى بالكامل بالعربون.
- رفض إيصال الدفع كان يجعل العربون `Rejected` كحالة نهائية. أصبح يرجع إلى `PendingSubmission`.
- Submit Payment أصبح يعيد lock وفحص الهدف داخل transaction بعد رفع الملف، مع cleanup عند الفشل.
- Refund confirmation أصبحت idempotent ولا تضاعف `refunded_amount_minor`.
- `PlaceBidRequest` أصبح currency-aware.

## 3. الحسابات المالية النهائية

تعريف التسوية الحالي:

```text
amount_due_minor = winning_amount_minor - deposit_applied_minor
amount_paid_minor = payments approved after settlement creation
remaining_amount_minor = amount_due_minor - amount_paid_minor
```

عند إنشاء Settlement:

```text
amount_paid_minor = 0
remaining_amount_minor = amount_due_minor
```

حالة مثال:

```text
winning_amount_minor = 100000
deposit_applied_minor = 10000
amount_due_minor = 90000
amount_paid_minor = 0
remaining_amount_minor = 90000
```

حالة العربون الكامل:

```text
deposit_applied_minor = min(held_deposit, winning_amount)
amount_due_minor = 0
amount_paid_minor = 0
remaining_amount_minor = 0
status = paid
payment_due_at = null
handover_due_at = now + handover_deadline_hours
```

## 4. Schema الـSettlements الجديد

أضيفت/فعّلت الأعمدة التالية:

```text
sequence_number
is_current
current_marker
previous_settlement_id
winner_reassignment_id
superseded_at
remaining_amount_minor
```

قيد الـcurrent:

```text
UNIQUE(auction_id, current_marker)
```

القيمة `current_marker = 1` للـcurrent فقط، و`NULL` للـhistorical؛ وبذلك تسمح MySQL بعدة historical rows وتمنع current مكررًا.

## 5. Winner Default Flow

المنفذ الآن:

- Lock للمزاد.
- Lock للـcurrent settlement.
- منع default قبل `payment_due_at` إلا مع `override_deadline`.
- تحويل settlement القديم إلى `defaulted`.
- مصادرة عربون الفائز المتعثر عند وجود held amount.
- استبعاد كل bids الخاصة بالمستخدم المتعثر.
- التحقق من participant status.
- التحقق من held deposit.
- التحقق من Terms Acceptance من جدول `auction_terms_acceptances`.
- إنشاء Winner Reassignment.
- إنشاء Settlement جديد للفائز البديل.

## 6. Alternative Winner Flow

عند وجود بديل مؤهل:

- لا يتم تعديل Settlement القديم ليصبح للفائز الجديد.
- يتم إنشاء Settlement جديد بـ`sequence_number` جديد.
- يتم ربطه بـ`previous_settlement_id`.
- يتم تحديث `winning_bid_id` في المزاد.
- current القديم يصبح historical.

## 7. Deposit Lifecycle

التعديلات المنفذة:

- العربون الفائز يطبق على `deposit_applied_minor` فقط.
- العربون لا يصبح payment.
- reject لمحاولة الدفع لا ينهي أصل العربون.
- resubmit بعد reject مدعوم ومثبت باختبار.
- سياسة غير الفائزين صارت من configuration:

```text
hold_all_eligible_bidders_until_winner_payment
refund_all_non_winners_immediately
```

القيمة الافتراضية: `hold_all_eligible_bidders_until_winner_payment`.

## 8. Payment Lifecycle

التعديلات:

- Submit يعيد lock للـdeposit/settlement داخل transaction بعد رفع الملف.
- يمنع payment بمبلغ صفر.
- Approval يرفض stale settlement إذا لم تعد current.
- Approval يحدث `amount_paid_minor` و`remaining_amount_minor`.
- Duplicate approval يبقى idempotent بسبب حالة submission وtransaction idempotency.

## 9. Refund Lifecycle

التعديلات:

- منع refund بمبلغ صفر.
- منع refund أكبر من held amount.
- `confirmSucceeded()` أصبحت idempotent.
- منع تكرار `provider_refund_id` عبر unique index على:

```text
provider, provider_refund_id
```

## 10. Cancellation Lifecycle

تم تنفيذ Financial Cancellation Flow داخل `CancelAuctionAction`:

- يعمل داخل transaction بعد lock للمزاد.
- ينشئ refund plan للعربونات ذات الحالات `Held`, `AppliedToSettlement`, `RefundPending`.
- ينشئ refund plan للـ`PaymentTransaction` الناجحة.
- يستخدم مفاتيح idempotency من نوع:

```text
auction:{auction_id}:cancel:deposit:{deposit_id}
auction:{auction_id}:cancel:payment:{payment_transaction_id}
```

- يعيد تشغيل الإلغاء دون تكرار RefundTransaction.
- يحول المدفوعات الناجحة المخطط لاستردادها إلى `Reversed`.
- يسمح بالإلغاء من حالات التسوية والدفع والتسليم في State Machine.

تم إثبات:

- cancellation with held deposits generates one refund plan.
- cancellation with successful payment generates one payment refund plan.
- replay does not duplicate refunds.

## 11. DTOs المستخدمة فعليًا

تم تحديث:

- `CreateSettlementDTO` بإضافة `remaining_amount_minor`.

الاختبارات تثبت أن `amount_paid_minor` يبدأ من صفر في DTO.

## 12. Repository Boundaries

تمت إضافة/تعديل:

- `AuctionSettlementRepository::lockByAuctionAndWinner()`
- `AuctionSettlementRepository::createSettlement()` لإدارة current/historical settlement.
- `AuctionDepositRepository::lockPaymentDeposit()`
- `AuctionRefundRepository::providerRefundIdExists()`

بقيت بعض direct Eloquent usages في Actions، وأهمها `AuctionWinnerReassignment::create()` وقراءة bids في `MarkWinnerDefaultedAction`. السبب: لم يتم نقلها بالكامل في هذه التمريرة، وهي مدونة كمخاطرة معمارية متبقية.

## 13. Resources والصلاحيات

تم عدم توسيع Resources في هذه التمريرة إلا بما يتصل بالتسوية الحالية عبر علاقة `Auction::settlement()`. ما زالت مراجعة الخصوصية الكاملة لكل endpoint غير مثبتة باختبارات شاملة؛ لذلك تبقى مخاطرة متبقية.

## 14. Scheduler وOutbox

تم تشغيل:

```text
php artisan schedule:list
```

والنتيجة تضمنت:

```text
auction:run-operations
auction:reconcile
```

تم إصلاح الحالة الأخطر: عدم وجود listener لم يعد يؤدي إلى `Published`، بل تصبح الرسالة `Failed` مع `last_error`.

تمت إضافة consumer داخلي:

```text
App\Listeners\Auction\RecordAuctionOutboxConsumption
```

وعند النجاح يسجل:

```text
auction.outbox_consumed
```

ثم يسمح للرسالة بالانتقال إلى `Published`.

بقيت مخاطرة: هذا consumer داخلي عام، وليس consumers متخصصة لكل event من القائمة الطويلة مثل notifications/emails/reconciliation triggers.

## 14.1 Auction Media Cleanup

تم تعديل:

```text
AuctionMediaService
CreateAuctionAction
```

السلوك الحالي:

- `AuctionMediaService::storeAuctionMedia()` يعيد قائمة الملفات المخزنة.
- إذا فشل إنشاء سجل `AuctionMedia` بعد رفع الملف، يحذف كل الملفات التي رفعها في نفس العملية.
- `CreateAuctionAction` يحتفظ بقائمة الملفات المخزنة داخل transaction، وإذا فشل أي جزء لاحق من العملية يحذف الملفات المخزنة.

تم إثبات ذلك باختبار:

```text
auction media upload is cleaned when database insert fails
```

## 15. Migrations الجديدة

تم إنشاء:

```text
database/migrations/2026_07_12_010000_correct_auction_settlement_current_and_financials.php
```

وتم تشغيلها على MySQL عبر:

```text
php artisan migrate --force
```

ولم يتم استخدام `migrate:fresh`.

## 16. الملفات المنشأة

- `database/migrations/2026_07_12_010000_correct_auction_settlement_current_and_financials.php`
- `phpunit.mysql.xml`
- `tests/Feature/Auction/AuctionFinancialFlowTest.php`
- `tests/Feature/Auction/AuctionMysqlConcurrencyTest.php`
- `AUCTION_FINAL_CORRECTIVE_REPORT.md`
- `app/Listeners/Auction/RecordAuctionOutboxConsumption.php`

## 17. الملفات المعدلة

- `app/DTO/Auction/CreateSettlementDTO.php`
- `app/Http/Controllers/Auction/AuctionController.php`
- `app/Http/Requests/Auction/MarkWinnerDefaultedRequest.php`
- `app/Http/Requests/Auction/PlaceBidRequest.php`
- `app/Providers/AppServiceProvider.php`
- `app/Models/Auction/Auction.php`
- `app/Models/Auction/AuctionSettlement.php`
- `app/Repositories/Auction/AuctionDepositRepository.php`
- `app/Repositories/Auction/AuctionRefundRepository.php`
- `app/Repositories/Auction/AuctionSettlementRepository.php`
- `app/Services/Auction/Actions/CancelAuctionAction.php`
- `app/Services/Auction/Actions/CreateAuctionAction.php`
- `app/Services/Auction/Actions/DispatchOutboxMessagesAction.php`
- `app/Services/Auction/Actions/FinalizeAuctionAction.php`
- `app/Services/Auction/Actions/MarkWinnerDefaultedAction.php`
- `app/Services/Auction/Actions/RefundAuctionDepositAction.php`
- `app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php`
- `app/Services/Auction/Actions/SubmitPaymentSubmissionAction.php`
- `app/Services/Auction/Support/AuctionMediaService.php`
- `app/Services/Auction/Support/AuctionStateMachine.php`
- `config/auction.php`
- `database/migrations/2025_06_12_090000_create_auctions_table.php`
- `database/migrations/2026_07_11_200000_auction_production_grade_corrections.php`
- `database/seeders/AuctionConfigurationSeeder.php`
- `lang/ar/auction.php`
- `lang/en/auction.php`
- `tests/Feature/Auction/AuctionCoreTest.php`

## 18. الملفات المحذوفة

لا توجد ملفات محذوفة.

## 19. الاختبارات المنشأة

- `AuctionFinancialFlowTest`
  - deposit is not counted as paid.
  - full deposit coverage.
  - alternative winner creates new current settlement.
  - reject then resubmit then approve.
  - refund confirmation idempotency.
  - cancellation generates deposit refund plan once.
  - cancellation with successful payment generates payment refund once.
  - auction media upload is cleaned when database insert fails.
- `AuctionMysqlConcurrencyTest`
  - MySQL prevents two current settlements for same auction at database layer.
  - replayed bid submissions do not create duplicate bid.
  - repeated scheduler finalization creates one settlement.
  - repeated payment approval applies winner payment once.
  - repeated refund confirmation applies refund once.
  - repeated winner default execution does not duplicate reassignment settlement.
- `AuctionFinancialFlowTest`
  - outbox without consumer is not marked published.
  - outbox consumer success marks message published.
- إضافات في `AuctionCoreTest`
  - JOD بثلاث خانات في Place Bid.
  - رفض EGP بثلاث خانات في Place Bid.

## 20. نتائج الأوامر الفعلية

نجحت:

```text
php artisan optimize:clear
php artisan route:list
php artisan schedule:list
php artisan migrate:status
php artisan migrate --force
php artisan test
vendor/bin/phpunit --configuration phpunit.mysql.xml
vendor/bin/phpunit --configuration phpunit.mysql.xml --group mysql-concurrency
vendor/bin/pest
vendor/bin/pint --test <modified files only>
php -l <modified php files>
```

نتائج مهمة:

```text
php artisan test
36 passed, 6 skipped, 93 assertions
```

```text
vendor/bin/phpunit --configuration phpunit.mysql.xml
32 tests, 94 assertions, OK
```

```text
vendor/bin/phpunit --configuration phpunit.mysql.xml --group mysql-concurrency
6 tests, 14 assertions, OK
```

تعذر أو فشل:

```text
composer dump-autoload
```

تعذر مرتين بسبب timeout: مرة بعد 120 ثانية ومرة بعد 300 ثانية، وتوقف عند:

```text
Generating optimized autoload files
```

```text
vendor/bin/phpstan analyse
```

لم يعمل لأن `vendor/bin/phpstan` غير موجود.

```text
vendor/bin/pint --test
```

فشل على المشروع كاملًا: 358 files, 157 style issues. تم تشغيل Pint بنجاح على الملفات المعدلة فقط.

## 21. Direct Eloquent usage المتبقي

| المخالفة | الملف | هل تم نقلها؟ | سبب بقائها |
|---|---|---:|---|
| `AuctionWinnerReassignment::create()` | `MarkWinnerDefaultedAction.php` | لا | يحتاج Repository مخصص لاحقًا؛ بقي داخل transaction ومحصور في Action واحدة. |
| `AuctionBid::where(...)->get()` | `MarkWinnerDefaultedAction.php` | لا | اختيار البديل يحتاج query مركبة؛ تم إصلاح شروطها لكن لم تنقل Repository بالكامل. |
| `Event::dispatch` ثم `markAsPublished` | `DispatchOutboxMessagesAction.php` | جزئيًا | تم منع Published عند عدم وجود consumer، وأضيف consumer داخلي عام، لكن consumers المتخصصة لكل event لم تنفذ. |
| `Storage::disk(...)->delete()` | `SubmitPaymentSubmissionAction.php` | لا | مستخدم كتعويض cleanup عند فشل transaction بعد upload. |
| Direct creates داخل tests | `AuctionFinancialFlowTest.php`, `AuctionMysqlConcurrencyTest.php` | لا ينطبق | بيانات اختبار فقط. |

البحث الخاص أظهر:

| نقطة البحث | النتيجة |
|---|---|
| `amount_paid_minor => deposit_applied` | لم تعد موجودة. |
| `unique('auction_id')` داخل settlements | لم تعد موجودة. |
| `terms_accepted_at` داخل participant | لم تعد موجودة. |
| Regex بخانتين للمزايدات | لم يعد موجودًا في `PlaceBidRequest`. |
| `provider_reference` كمصدر موثوق نهائي | ما زال موجودًا كحقل اختياري ويربط بـmanual transaction؛ ليس مثبتًا كتكامل provider نهائي. |
| `markAsPublished` بعد `Event::dispatch` | لم يعد يحدث عند عدم وجود consumer؛ مثبت باختبار. |
| `Refund Succeeded` غير idempotent | تم إصلاحه واختباره. |

## 22. المخاطر المتبقية

- تم تنفيذ MySQL tests لكل أسماء مسارات التزامن المطلوبة كاختبارات replay/idempotency/constraint: bids, payment approvals, scheduler finalization, refund confirmations, winner default executions. المتبقي: ليست parallel-process tests حرفية تشغل عاملين مستقلين في نفس اللحظة؛ هي تثبت أثر الحماية بعد إعادة التنفيذ على MySQL.
- Outbox لم يعد ينشر عند عدم وجود consumer، ويوجد consumer داخلي عام، لكن لا توجد consumers متخصصة كاملة لكل event مطلوب.
- Financial Cancellation Flow يدعم العربونات والمدفوعات الناجحة ويثبت idempotency، لكنه لا يزال يحتاج تكامل provider فعلي لتنفيذ التحويلات خارج النظام.
- Privacy tests لكل Public/Participant/Admin resources غير مكتملة.
- Media orphan cleanup مدعوم ومثبت باختبار فشل DB بعد upload.
- Metrics الثقيلة ما زالت تحتاج مراجعة أعمق خارج هذه التمريرة.
- `composer dump-autoload` لم ينجح بسبب timeout.
- PHPStan غير مثبت.

## 23. القرار النهائي

تم إصلاح وإثبات مجموعة مهمة من شروط القبول المالية وقاعدة البيانات والدفع والـrefund، لكن لا يجوز اعتبار النظام Production-Ready بعد هذه التمريرة لأن كل شروط القبول النهائية في `AUCTION_FINAL_CORRECTIVE_PASS.md` لم تثبت بالكامل باختبارات فعلية.
