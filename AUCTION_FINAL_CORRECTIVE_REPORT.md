# Auction Final Corrective Report

## 1. ملخص تنفيذي

تم تنفيذ تمريرة تصحيحية فعلية داخل نظام المزادات لمعالجة أخطاء مالية وقاعدية كانت تمنع اعتبار مسار الـSettlement وWinner Default آمنين. أهم التصحيحات المنفذة:

- عدم احتساب العربون كـ`amount_paid_minor` عند إنشاء التسوية.
- دعم حالة تغطية العربون لكامل مبلغ الفوز والانتقال مباشرة إلى `handover_pending`.
- إنشاء RefundTransaction للزيادة عندما يكون العربون أكبر من مبلغ الفوز، مع اختبار حالات العربون الأقل والمساوي والأكبر والصفر.
- دعم وجود Settlements تاريخية متعددة لنفس المزاد مع Settlement واحد فقط current بقيد قاعدة بيانات.
- إنشاء Settlement جديد للفائز البديل بدل تعديل Settlement الفائز القديم.
- قراءة Terms Acceptance من جدول `auction_terms_acceptances`.
- السماح بإعادة إرسال إيصال عربون بعد رفض محاولة دفع.
- إثبات أن المزاد المعتمد ينتقل مباشرة إلى `scheduled` عندما يكون عربون البائع صفرًا، وينتقل إلى `awaiting_seller_deposit` عندما يكون العربون مطلوبًا.
- منع refund صفرية، ومنع double-count عند تأكيد refund ناجحة مرتين.
- استبدال Regex الخانتين في `PlaceBidRequest` بـ`CurrencyDecimalRule` لدعم JOD بثلاث خانات.
- منع تعليم رسالة Outbox كـPublished إذا لم يوجد consumer/listener فعلي.
- إضافة retry/dead-letter للـOutbox: الفشل المؤقت يعود إلى `pending` مع `next_retry_at`، وبعد الحد الأقصى ينتقل إلى `dead_letter`.
- إضافة Outbox consumer داخلي يسجل `auction.outbox_consumed` عند نجاح الاستهلاك.
- إضافة Financial Cancellation Flow ينشئ refund plan للعربونات والمدفوعات الناجحة بشكل idempotent.
- تنظيف ملفات صور المزاد المرفوعة عند فشل إنشاء سجل قاعدة البيانات أو فشل العملية اللاحقة.
- تقليل العلاقات المحملة في `PublicAuctionQuery` وإضافة اختبارات خصوصية تمنع تسريب بيانات settlement/financial وبيانات المزايدين للعامة.
- حماية Bid History العام بسياسة visibility، ومنع الوصول إلى إيصال دفع مستخدم آخر.
- نقل تحديث metrics الخاص بالمزايدة إلى ما بعد bid transaction.
- استخدام `CreateBidRecordDTO` فعليًا في إنشاء bid، مع camelCase داخل DTO و`toPersistenceArray()` لأسماء قاعدة البيانات.
- إضافة اختبارات فعلية على SQLite الافتراضي وMySQL فعلي، بما فيها replay/idempotency/constraint tests لمسارات التزامن المطلوبة.

لم يتم الادعاء بأن النظام Production-Ready؛ لأن قائمة القبول الأصلية أكبر من نطاق ما تم إثباته بالكامل في هذه التمريرة، خصوصًا تغطية Outbox consumer المتخصصة لكل نوع event، واختبارات parallel-process حرفية تعمل عمليتين في نفس اللحظة.

## 2. المشكلات التي تم إصلاحها

- `FinalizeAuctionAction` كان ينشئ `amount_paid_minor = deposit_applied_minor`. أصبح `amount_paid_minor = 0` و`remaining_amount_minor = amount_due_minor`.
- `MarkWinnerDefaultedAction` كان يستخدم قسمة `/` في رسوم المنصة. أصبح يستخدم `intdiv()`.
- `MarkWinnerDefaultedAction` كان يقرأ `terms_accepted_at` من participant. أصبح يستخدم `AuctionTermsRepository::hasAcceptedTerms()`.
- جدول `auction_settlements` كان يمنع أكثر من Settlement واحدة للمزاد عبر `UNIQUE(auction_id)`. تم استبداله بتصميم current/history.
- `payment_due_at` أصبح nullable لحالة الدفع المغطى بالكامل بالعربون.
- رفض إيصال الدفع كان يجعل العربون `Rejected` كحالة نهائية. أصبح يرجع إلى `PendingSubmission`.
- Submit Payment أصبح يعيد lock وفحص الهدف داخل transaction بعد رفع الملف، مع cleanup عند الفشل.
- Payment Approval لم يعد يستخدم `provider_reference` المرسل من المستخدم كـ`provider_transaction_id` نهائي؛ الاعتماد يستخدم مرجع أدمن أو مرجع manual داخلي deterministic.
- Payment Approval يرفض تكرار `provider_transaction_id` على submissions مختلفة، مع قيد `UNIQUE(provider, provider_transaction_id)`.
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

حالة العربون الأكبر من مبلغ الفوز:

```text
deposit_applied_minor = winning_amount_minor
amount_due_minor = 0
amount_paid_minor = 0
remaining_amount_minor = 0
excess_refund_amount = held_deposit - winning_amount_minor
refund_transaction.status = pending
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
- عربون البائع بقيمة صفر لا يوقف المزاد في `awaiting_seller_deposit`؛ الاعتماد ينقله مباشرة إلى `scheduled`.
- عربون البائع المطلوب يبقي المزاد في `awaiting_seller_deposit` حتى الاعتماد.
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
- Duplicate provider transaction id مرفوض قبل إنشاء transaction جديدة، ومدعوم بقيد قاعدة بيانات.

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
- `CreateSettlementDTO` ليستخدم camelCase داخل PHP ويدعم `paidAt`, `previousSettlementId`, و`winnerReassignmentId`.
- `CreateBidRecordDTO` ليستخدم camelCase داخل PHP ويحوّل عبر `toPersistenceArray()` إلى أسماء قاعدة البيانات.
- `CreateOutboxMessageDTO` ليستخدم camelCase داخل PHP ويحوّل عبر `toPersistenceArray()` إلى أسماء قاعدة البيانات.

الاختبارات تثبت أن `amount_paid_minor` يبدأ من صفر في DTO، وأن `CreateSettlementDTO` يحافظ على روابط التاريخ/reassignment، وأن `CreateBidRecordDTO` و`CreateOutboxMessageDTO` يحافظان على camelCase داخليًا ويخرجان أسماء قاعدة البيانات للتخزين.

## 12. Repository Boundaries

تمت إضافة/تعديل:

- `AuctionSettlementRepository::lockByAuctionAndWinner()`
- `AuctionSettlementRepository::createSettlement(CreateSettlementDTO $dto)` لإدارة current/historical settlement بدل array خام.
- `AuctionBidRepository::createAcceptedBid(CreateBidRecordDTO $dto)` بدل array خام.
- `AuctionOutboxRepository::store(CreateOutboxMessageDTO $dto)` بدل parameters خامة.
- `AuctionDepositRepository::lockPaymentDeposit()`
- `AuctionRefundRepository::providerRefundIdExists()`
- `AuctionBidRepository::lockAlternativeWinnerCandidates()` لنقل قراءة bids البديلة خارج `MarkWinnerDefaultedAction`.
- `AuctionWinnerReassignmentRepository::create()` لنقل إنشاء سجل reassignment خارج `MarkWinnerDefaultedAction`.
- `AuctionPaymentRepository::createPaymentMethod()` و`AuctionPaymentRepository::updatePaymentMethod()`.
- `AuctionPaymentRepository::lockSucceededTransactionsForAuction()` لمسار الإلغاء.
- `AuctionParticipantRepository::save()` لإزالة حفظ المشارك المباشر من مراجعة الدفع.

تم نقل direct Eloquent الأهم في `MarkWinnerDefaultedAction` إلى repositories بعد جولة الاختبارات الأخيرة. لا تزال هناك استخدامات مباشرة محدودة موضحة في قسم المخاطر، لكنها ليست مسار قراءة bids البديلة أو إنشاء Winner Reassignment.

## 13. Resources والصلاحيات

تم تقليل علاقات `PublicAuctionQuery::DETAILS_RELATIONS` بإزالة:

```text
winningBid.bidder
settlement
```

واختبار:

```text
public auction resource does not expose private financial data
```

يثبت عدم ظهور:

```text
reserve_amount
settlement
seller_net
platform_fee
amount_due
amount_paid
```

كما يثبت اختبار:

```text
public bid resource anonymizes other bidders
```

أن Bid Resource للعامة لا يعرض `id/name/email` للمزايدين الآخرين.

وتمت إضافة اختبارات route فعلية تثبت:

```text
public bid history rejects non public auction
public bid history for live auction anonymizes bidders
unauthorized user cannot access another payment receipt url
```

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

تم إصلاح الحالة الأخطر: عدم وجود listener لم يعد يؤدي إلى `Published`. الفشل المؤقت يعيد الرسالة إلى `Pending` مع `next_retry_at` و`last_error`، وبعد بلوغ `auction.outbox.max_attempts` تنتقل إلى `DeadLetter`.

تمت إضافة consumer داخلي:

```text
App\Listeners\Auction\RecordAuctionOutboxConsumption
```

وعند النجاح يسجل:

```text
auction.outbox_consumed
```

ثم يسمح للرسالة بالانتقال إلى `Published`.

تمت إضافة إعدادات:

```text
auction.outbox.max_attempts
auction.outbox.retry_delay_seconds
```

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
database/migrations/2026_07_12_020000_add_outbox_retry_dead_letter_fields.php
```

وتم تشغيلها على MySQL عبر:

```text
php artisan migrate --force
```

ولم يتم استخدام `migrate:fresh`.

## 16. الملفات المنشأة

- `database/migrations/2026_07_12_010000_correct_auction_settlement_current_and_financials.php`
- `database/migrations/2026_07_12_020000_add_outbox_retry_dead_letter_fields.php`
- `phpunit.mysql.xml`
- `tests/Feature/Auction/AuctionFinancialFlowTest.php`
- `tests/Feature/Auction/AuctionMysqlConcurrencyTest.php`
- `AUCTION_FINAL_CORRECTIVE_REPORT.md`
- `app/Listeners/Auction/RecordAuctionOutboxConsumption.php`
- `app/Repositories/Auction/AuctionWinnerReassignmentRepository.php`

## 17. الملفات المعدلة

- `app/DTO/Auction/CreateSettlementDTO.php`
- `app/DTO/Auction/CreateBidRecordDTO.php`
- `app/DTO/Auction/CreateOutboxMessageDTO.php`
- `app/Http/Controllers/Auction/AuctionController.php`
- `app/Http/Controllers/Auction/BidController.php`
- `app/Http/Controllers/Auction/PaymentSubmissionController.php`
- `app/Http/Requests/Auction/MarkWinnerDefaultedRequest.php`
- `app/Http/Requests/Auction/PlaceBidRequest.php`
- `app/Http/Requests/Auction/ReviewPaymentSubmissionRequest.php`
- `app/Providers/AppServiceProvider.php`
- `app/Models/Auction/Auction.php`
- `app/Models/Auction/OutboxMessage.php`
- `app/Models/Auction/AuctionSettlement.php`
- `app/Domain/Auction/Enums/OutboxStatus.php`
- `app/Repositories/Auction/AuctionDepositRepository.php`
- `app/Repositories/Auction/AuctionBidRepository.php`
- `app/Repositories/Auction/AuctionPaymentRepository.php`
- `app/Repositories/Auction/AuctionParticipantRepository.php`
- `app/Repositories/Auction/AuctionOutboxRepository.php`
- `app/Repositories/Auction/AuctionRefundRepository.php`
- `app/Repositories/Auction/AuctionSettlementRepository.php`
- `app/Repositories/Auction/Queries/PublicAuctionQuery.php`
- `app/Services/Auction/Actions/CancelAuctionAction.php`
- `app/Services/Auction/Actions/CreateAuctionAction.php`
- `app/Services/Auction/Actions/CreatePaymentMethodAction.php`
- `app/Services/Auction/Actions/DispatchOutboxMessagesAction.php`
- `app/Services/Auction/Actions/FinalizeAuctionAction.php`
- `app/Services/Auction/Actions/MarkWinnerDefaultedAction.php`
- `app/Services/Auction/Actions/PlaceBidAction.php`
- `app/Services/Auction/Actions/RefundAuctionDepositAction.php`
- `app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php`
- `app/Services/Auction/Actions/SubmitPaymentSubmissionAction.php`
- `app/Services/Auction/Actions/UpdatePaymentMethodAction.php`
- `app/Services/Auction/Support/AuctionAudit.php`
- `app/Services/Auction/Support/AuctionMediaService.php`
- `app/Services/Auction/Support/AuctionStateMachine.php`
- `config/auction.php`
- `database/migrations/2025_06_12_090000_create_auctions_table.php`
- `database/migrations/2026_07_11_200000_auction_production_grade_corrections.php`
- `database/seeders/AuctionConfigurationSeeder.php`
- `lang/ar/auction.php`
- `lang/en/auction.php`
- `routes/api/auction.php`
- `tests/Feature/Auction/AuctionCoreTest.php`

## 18. الملفات المحذوفة

لا توجد ملفات محذوفة.

## 19. الاختبارات المنشأة

- `AuctionFinancialFlowTest`
  - deposit is not counted as paid.
  - full deposit coverage with pending excess refund.
  - exact deposit coverage without excess refund.
  - zero deposit leaves the full winning amount due.
  - alternative winner creates new current settlement.
  - reject then resubmit then approve.
  - approved auction with zero seller deposit goes directly to scheduled.
  - approved auction with required seller deposit waits for deposit.
  - duplicate provider transaction id is rejected for different submissions.
  - refund confirmation idempotency.
  - cancellation generates deposit refund plan once.
  - cancellation is allowed from Draft, PendingReview, AwaitingSellerDeposit, Scheduled, Live, Ended, PaymentPending, and HandoverPending.
  - cancellation with successful payment generates payment refund once.
  - auction media upload is cleaned when database insert fails.
  - public auction resource does not expose private financial data.
  - public bid resource anonymizes other bidders.
  - public bid history rejects non public auction.
  - public bid history for live auction anonymizes bidders.
  - unauthorized user cannot access another payment receipt url.
- `AuctionMysqlConcurrencyTest`
  - MySQL prevents two current settlements for same auction at database layer.
  - replayed bid submissions do not create duplicate bid.
  - repeated scheduler finalization creates one settlement.
  - repeated payment approval applies winner payment once.
  - repeated refund confirmation applies refund once.
  - repeated winner default execution does not duplicate reassignment settlement.
- `AuctionFinancialFlowTest`
  - outbox without consumer is not marked published.
  - outbox failure schedules retry.
  - outbox failure after max attempts moves to dead letter.
  - outbox consumer success marks message published.
- إضافات في `AuctionCoreTest`
  - JOD بثلاث خانات في Place Bid.
  - رفض EGP بثلاث خانات في Place Bid.
  - CreateBidRecordDTO camelCase and persistence mapping.
  - CreateSettlementDTO camelCase and history/reassignment persistence mapping.
  - CreateOutboxMessageDTO camelCase and persistence mapping.

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
50 passed, 6 skipped, 163 assertions
```

```text
vendor/bin/phpunit --configuration phpunit.mysql.xml
46 tests, 164 assertions, OK
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
| `AuctionWinnerReassignment::create()` | `MarkWinnerDefaultedAction.php` | نعم | نُقلت إلى `AuctionWinnerReassignmentRepository::create()`. |
| `AuctionBid::where(...)->get()` | `MarkWinnerDefaultedAction.php` | نعم | نُقلت إلى `AuctionBidRepository::lockAlternativeWinnerCandidates()`. |
| `RefundTransaction::firstOrCreate()` | `CancelAuctionAction.php` | نعم | نُقلت إلى `AuctionRefundRepository::firstOrCreateRefund()`. |
| `PaymentMethod::create()` / `$paymentMethod->update()` | `CreatePaymentMethodAction.php`, `UpdatePaymentMethodAction.php` | نعم | نُقلت إلى `AuctionPaymentRepository`. |
| bid metrics داخل transaction | `PlaceBidAction.php` | نعم | أصبح `refreshBidMetrics()` بعد commit. |
| حفظ deposit/participant داخل مراجعة الدفع | `ReviewPaymentSubmissionAction.php` | نعم | أصبح عبر `AuctionDepositRepository` و`AuctionParticipantRepository`. |
| `Event::dispatch` ثم `markAsPublished` | `DispatchOutboxMessagesAction.php` | جزئيًا | تم منع Published عند عدم وجود consumer، وأضيف retry/dead-letter وconsumer داخلي عام، لكن consumers المتخصصة لكل event لم تنفذ. |
| `Storage::disk(...)->delete()` | `SubmitPaymentSubmissionAction.php` | لا | مستخدم كتعويض cleanup عند فشل transaction بعد upload. |
| Direct creates داخل tests | `AuctionFinancialFlowTest.php`, `AuctionMysqlConcurrencyTest.php` | لا ينطبق | بيانات اختبار فقط. |

البحث الخاص أظهر:

| نقطة البحث | النتيجة |
|---|---|
| `amount_paid_minor => deposit_applied` | لم تعد موجودة. |
| `unique('auction_id')` داخل settlements | لم تعد موجودة. |
| `terms_accepted_at` داخل participant | لم تعد موجودة. |
| Regex بخانتين للمزايدات | لم يعد موجودًا في `PlaceBidRequest`. |
| `provider_reference` كمصدر موثوق نهائي | لم يعد يستخدم كـ`provider_transaction_id` عند الاعتماد؛ بقي كمرجع إيصال اختياري مرسل من المستخدم. |
| `markAsPublished` بعد `Event::dispatch` | لم يعد يحدث عند عدم وجود consumer؛ مثبت باختبار. |
| `Refund Succeeded` غير idempotent | تم إصلاحه واختباره. |

## 22. المخاطر المتبقية

- تم تنفيذ MySQL tests لكل أسماء مسارات التزامن المطلوبة كاختبارات replay/idempotency/constraint: bids, payment approvals, scheduler finalization, refund confirmations, winner default executions. المتبقي: ليست parallel-process tests حرفية تشغل عاملين مستقلين في نفس اللحظة؛ هي تثبت أثر الحماية بعد إعادة التنفيذ على MySQL.
- Outbox لم يعد ينشر عند عدم وجود consumer، ويوجد consumer داخلي عام، لكن لا توجد consumers متخصصة كاملة لكل event مطلوب.
- Financial Cancellation Flow يدعم العربونات والمدفوعات الناجحة ويثبت idempotency، لكنه لا يزال يحتاج تكامل provider فعلي لتنفيذ التحويلات خارج النظام.
- Privacy tests الأساسية للعامة موجودة، لكن لا تزال هناك حاجة لتوسيعها لكل endpoint وrole matrix كاملة.
- Media orphan cleanup مدعوم ومثبت باختبار فشل DB بعد upload.
- Metrics الخاصة بالمزايدة خرجت من bid transaction، لكن مراجعة metrics الأوسع خارج هذا المسار ما زالت تحتاج تمريرة مستقلة.
- `composer dump-autoload` لم ينجح بسبب timeout.
- PHPStan غير مثبت.

## 23. القرار النهائي

تم إصلاح وإثبات مجموعة مهمة من شروط القبول المالية وقاعدة البيانات والدفع والـrefund، لكن لا يجوز اعتبار النظام Production-Ready بعد هذه التمريرة لأن كل شروط القبول النهائية في `AUCTION_FINAL_CORRECTIVE_PASS.md` لم تثبت بالكامل باختبارات فعلية.
