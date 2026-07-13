# TASK 09 — Winner Default Financial Correctness

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إعادة بناء Winner Default Flow داخل نظام المزادات بحيث يكون صحيحًا ماليًا ومعماريًا وآمنًا تحت التزامن.

المطلوب:

1. لا يمكن اعتبار الفائز متعثرًا إلا إذا كانت:
   - Settlement الحالية هي Current.
   - Settlement غير مدفوعة.
   - Auction في الحالة الصحيحة.
   - المهلة انتهت، أو يوجد Admin Override بصلاحية مستقلة وسبب موثق.

2. التعامل الصحيح مع عربون الفائز المتعثر:
   - Forfeit.
   - Refund.
   - Partial forfeiture.
   حسب Configuration Snapshot والسياسة الفعلية.

3. عدم ترك:
   ```text
   applied_amount_minor
   ```
   عالقًا داخل Settlement قديمة دون معالجة.

4. عدم إعادة استخدام Settlement القديمة للفائز البديل.

5. استبعاد الفائز المتعثر بالكامل، وليس Winning Bid واحدة فقط.

6. اختيار Alternative Winner مؤهل فعليًا فقط.

7. إنشاء Settlement جديدة كاملة للفائز البديل.

8. الحفاظ على التاريخ الكامل لكل:
   - Winner.
   - Settlement.
   - Deposit.
   - Payment.
   - Default.
   - Reassignment.

9. جعل العملية Idempotent وآمنة مع عمليتين متزامنتين.

هذه المهمة تخص فقط:

```text
Winner default eligibility
Deadline and admin override
Defaulted winner deposit disposition
Old settlement closure
Alternative winner selection
New settlement creation
Winner reassignment history
Idempotency and concurrency
```

لا تنفذ الآن:

- General Cancellation refactor.
- Seller Deposit lifecycle.
- General Configuration Snapshot refactor.
- General Outbox consumers.
- General Scheduler cleanup.
- Resource privacy.
- Full DTO/Repository cleanup.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لهذه المهمة.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/MarkWinnerDefaultedAction.php
app/Services/Auction/Actions/FinalizeAuctionAction.php
app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
app/Services/Auction/Actions/ResolveAuctionDisputeAction.php
app/Services/Auction/Actions/*
app/Repositories/Auction/AuctionRepository.php
app/Repositories/Auction/AuctionBidRepository.php
app/Repositories/Auction/AuctionParticipantRepository.php
app/Repositories/Auction/AuctionDepositRepository.php
app/Repositories/Auction/AuctionSettlementRepository.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Repositories/Auction/AuctionWinnerReassignmentRepository.php
app/Models/Auction/Auction.php
app/Models/Auction/AuctionBid.php
app/Models/Auction/AuctionParticipant.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/AuctionSettlement.php
app/Models/Auction/AuctionWinnerReassignment.php
app/Models/Auction/PaymentTransaction.php
app/Domain/Auction/Enums/
app/Domain/Auction/Rules/
app/Domain/Auction/Exceptions/
app/DTO/Auction/
app/Policies/Auction/
app/Http/Requests/Auction/
app/Http/Controllers/Auction/
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

راجع نتائج TASK 02 إلى TASK 08 إن كانت موجودة.

لا تكسر:

- One successful payment per obligation.
- Payment state/deadline validation.
- Refund source of truth.
- Applied deposit refund accounting.
- Refund lifecycle.
- Non-winner deposit release.
- Seller deposit lifecycle.

لا تعتمد على التقارير السابقة دون مراجعة الكود الفعلي.

---

# 2. المشكلة الحالية التي يجب التحقق منها

الاشتباه الحالي أن Winner Default Flow قد تعاني من واحد أو أكثر من الآتي:

- السماح بالـDefault بعد الدفع.
- السماح بالـDefault على Settlement غير Current.
- السماح بالـDefault مع `payment_due_at = null`.
- Admin override بدون Permission مستقلة أو سبب.
- استبعاد Winning Bid فقط بدل استبعاد الفائز المتعثر بالكامل.
- اختيار Bid أخرى لنفس المستخدم المتعثر.
- عدم التحقق من Eligibility للبديل.
- قراءة Terms Acceptance من مكان خاطئ.
- استخدام Deposit تم ردها أو مصادرتها.
- إعادة استخدام Settlement القديمة.
- عدم معالجة `applied_amount_minor`.
- إنشاء أكثر من Reassignment أو Settlement تحت التزامن.
- بقاء Auction وSettlement في حالات متعارضة.

تحقق من الكود الفعلي أولًا.

---

# 3. شروط السماح بـWinner Default

لا يسمح بتنفيذ Winner Default إلا إذا تحقق كل الآتي:

```text
auction.status = PaymentPending
```

و:

```text
current settlement exists
settlement.is_current = true
settlement.status = PaymentPending
settlement.amount_due_minor > 0
settlement.amount_paid_minor < settlement.amount_due_minor
```

ويجب أن يكون:

```text
auction.winning_bid_id
```

متوافقًا مع:

```text
settlement.winning_bid_id
settlement.winner_bidder_id
```

ويمنع التنفيذ إذا كانت Settlement:

```text
Paid
HandoverPending
Completed
Cancelled
Voided
Defaulted
Superseded
```

ويمنع التنفيذ إذا كان المزاد:

```text
Completed
Cancelled
Unsold
HandoverPending
Disputed
```

إلا إذا كان هناك مسار Dispute Resolution مستقل ومقصود.

لا تعتمد على Status واحدة فقط.

---

# 4. Deadline

## الحالة الطبيعية

يجب أن يكون:

```text
payment_due_at != null
now > payment_due_at
```

حتى يسمح بالـDefault.

إذا:

```text
payment_due_at = null
```

فهذا يعني غالبًا أن Settlement لا تحتاج Winner Payment، وبالتالي لا يجوز Winner Default بسبب عدم الدفع.

## Admin Override

إذا كان النظام يسمح بالـDefault قبل المهلة:

- Permission مستقلة مثل:
  ```text
  auction.winners.override_payment_deadline
  ```
- Request يحتوي:
  ```text
  override_deadline = true
  override_reason = required
  ```
- حفظ:
  ```text
  overridden_by
  overridden_at
  override_reason
  original_payment_due_at
  ```
- Audit واضح.

الـOverride يتجاوز المهلة فقط.

لا يتجاوز:

- Settlement paid.
- Settlement not current.
- Wrong winner.
- Auction wrong state.
- Missing current settlement.
- Missing reason.
- Missing permission.

---

# 5. Action مركزية

أعد بناء أو أنشئ Action واضحة مثل:

```text
MarkWinnerDefaultedAction
```

داخل Transaction واحدة:

1. Lock Auction.
2. Recheck Auction status.
3. Lock Current Settlement.
4. Recheck Settlement status/current flag.
5. Lock Winning Bid.
6. Lock Winner Participant.
7. Lock Winner Deposit.
8. Recheck Deadline/Override.
9. Recheck no successful payment exists.
10. Recheck no prior default already applied.
11. Resolve defaulted winner deposit disposition.
12. Close old Settlement correctly.
13. Create Winner Default / Reassignment history record.
14. Select eligible alternative winner.
15. If candidate exists:
    - create new winner result/reference.
    - create new Settlement.
    - update auction current winner/current settlement.
16. If no candidate:
    - transition Auction to Unsold أو الحالة المحددة.
17. Release/refund remaining non-winner deposits according to TASK 07.
18. Audit.
19. Outbox.
20. Commit.

لا تنفذ API calls أو Notifications داخل Transaction.

---

# 6. معالجة Settlement القديمة

Settlement القديمة يجب ألا تُعدّل لتصبح للفائز الجديد.

المطلوب:

```text
old settlement:
is_current = false
status = Defaulted أو Voided
defaulted_at
default_reason
superseded_at
```

مع الحفاظ على:

```text
winning_amount_minor
deposit_applied_minor
amount_due_minor
amount_paid_minor
platform_fee_minor
seller_net_minor
payment_due_at
winner identity
```

كبيانات تاريخية.

لا تمسح Payment Submissions أو Payment Transactions القديمة.

أي Submission قديمة يجب أن تصبح:

```text
Superseded
Cancelled
Expired
```

حسب الـEnum الحالي، ولا يمكن اعتمادها لاحقًا.

---

# 7. عربون الفائز المتعثر

يجب استخدام Seller/Buyer policy الفعلية الخاصة بالفائز المتعثر من Snapshot أو المصدر الحالي الموثوق.

القرارات الممكنة:

```text
FullForfeit
PartialForfeit
Refund
ManualReview
```

## A. Full Forfeit

إذا كان:

```text
applied_amount_minor > 0
held_amount_minor >= 0
```

يجب نقل المبلغ القابل للمصادرة من Bucket المناسبة إلى:

```text
forfeited_amount_minor
```

دون إبقائه Applied في Settlement قديمة بصورة نشطة.

مثال:

```text
applied = 100
forfeit = 100
```

النتيجة:

```text
applied = 0
forfeited = 100
```

مع Audit يوضح أن المبلغ كان Applied ثم صودر بعد Default.

## B. Partial Forfeit

مثال:

```text
captured = 100
forfeit = 30
refund = 70
```

المطلوب:

- نقل 30 إلى forfeited.
- إنشاء Refund Plan للـ70 عبر Refund source/lifecycle الحالية.
- لا يمكن رد الجزء المصادر.
- لا يمكن تجاوز captured amount.

## C. Refund

إذا السياسة تسمح:

- Void أثر العربون على Settlement القديمة.
- إنشاء Refund Plan.
- لا تغيّر Deposit مباشرة إلى Refunded.
- لا تكرر Refund.

## D. Manual Review

إذا السياسة غير حاسمة أو يوجد نزاع:

```text
deposit disposition = ManualReview
```

ولا تتخذ قرارًا ماليًا تلقائيًا.

---

# 8. اختيار Alternative Winner

لا تختَر ببساطة ثاني أعلى Bid.

يجب استبعاد:

```text
defaulted_bidder_id بالكامل
```

أي Bid أخرى لنفس المستخدم المتعثر لا يمكن أن تفوز.

ثم رتّب Accepted Bids حسب قواعد المزاد الفعلية.

لكل Candidate تحقق من:

1. Bid accepted.
2. Participant exists.
3. Participant eligible.
4. Participant not cancelled.
5. User not blocked.
6. User not previously defaulted in نفس المزاد.
7. Terms accepted من:
   ```text
   auction_terms_acceptances
   ```
8. Terms version مطابقة للمزاد.
9. Deposit موجودة ومدفوعة.
10. Deposit ليست:
    ```text
    Refunded
    RefundPending
    RefundProcessing
    Forfeited
    Cancelled
    ```
11. Deposit amount meets requirement.
12. Candidate still valid according to policy.

لا تقرأ `terms_accepted_at` من Participant إذا لم يكن هذا هو التصميم الفعلي.

---

# 9. إنشاء Settlement جديدة

عند وجود Alternative Winner:

أنشئ Settlement جديدة بالكامل:

```text
auction_id
sequence_number = previous + 1
is_current = true
previous_settlement_id
winner_reassignment_id
winning_bid_id
winner_bidder_id
winning_amount_minor
deposit_applied_minor
amount_due_minor
amount_paid_minor = 0
remaining_amount_minor
platform_fee_minor
seller_net_minor
payment_due_at
handover_due_at if fully covered
status
```

يجب:

- استخدام `intdiv()` فقط.
- عدم استخدام Float.
- استخدام Deposit البديل نفسه.
- عدم نسخ Payment Transactions القديمة.
- عدم نسخ Submission القديمة.
- عدم إعادة استخدام Deadline القديمة.
- أخذ Deadline جديدة وفق Snapshot.
- تطبيق Deposit مرة واحدة فقط.

إذا:

```text
remaining_amount_minor = 0
```

انتقل للحالة المالية الصحيحة مباشرة ولا تضعها في PaymentPending.

---

# 10. Winner Reassignment History

أنشئ أو أكمل سجلًا واضحًا يحتوي:

```text
auction_id
previous_winning_bid_id
previous_winner_id
new_winning_bid_id
new_winner_id
previous_settlement_id
new_settlement_id
reason
triggered_by
created_at
```

لا تكتب سجلًا ناقصًا أو تعدّل سجلًا تاريخيًا سابقًا.

يجب أن يكون هناك Unique/Idempotency protection يمنع إنشاء نفس Reassignment مرتين.

---

# 11. Auction State

## عند وجود Alternative Winner

إذا المبلغ المتبقي > 0:

```text
Auction → PaymentPending
```

إذا المبلغ المتبقي = 0:

```text
Auction → HandoverPending
```

حسب State Machine الحالية.

## عند عدم وجود Alternative Winner

```text
Auction → Unsold
```

أو الحالة المحددة في Snapshot.

ثم شغّل Release flow الخاصة بـNon-winner deposits.

لا تترك Auction في:

```text
WinnerDefaulted
```

كحالة نهائية غامضة إذا كان التصميم يحتاج نتيجة لاحقة.

إذا كانت `WinnerDefaulted` حالة انتقالية موثقة، اجعل الانتقال واضحًا ومختبرًا.

---

# 12. التعامل مع Payment Submissions القديمة

أي Submission مرتبطة بالـSettlement القديمة يجب ألا يمكن اعتمادها بعد Reassignment.

المطلوب:

```text
PendingReview → Superseded/Cancelled/Expired
```

مع Reason:

```text
winner_reassigned
```

لا تحذفها.

لا تعدّل Approved historical records.

---

# 13. Idempotency

إعادة تشغيل Winner Default على نفس Auction يجب أن:

- لا تنشئ Default ثانية.
- لا تنشئ Settlement جديدة ثانية.
- لا تنشئ Reassignment ثانية.
- لا تصادر Deposit مرتين.
- لا تنشئ Refund مرتين.
- لا تكرر Audit/Outbox الحساسة.
- تعيد النتيجة الحالية أو Conflict واضح.

استخدم:

- Locks.
- Current settlement check.
- Unique constraints.
- Reassignment key.
- Settlement sequence uniqueness.
- Existing default marker.

---

# 14. Concurrency

شغّل حالتين متزامنتين على نفس Auction.

المتوقع:

- واحدة فقط تطبق Winner Default.
- Settlement قديمة تغلق مرة واحدة.
- Settlement جديدة واحدة.
- Reassignment واحد.
- Deposit disposition مرة واحدة.
- لا negative buckets.
- لا duplicate refunds.
- لا current settlement مزدوجة.

استخدم MySQL Locks وConstraints.

---

# 15. Policies والصلاحيات

أنشئ Policy/Permission مستقلة مثل:

```text
auction.winners.mark_defaulted
auction.winners.override_payment_deadline
```

لا تستخدم Permission عامة مثل:

```text
resolveDispute
```

لـWinner Default.

الـOverride يحتاج Permission إضافية فوق Permission التنفيذ الأساسية.

Request يجب أن تفرض:

```text
reason = required
override_reason = required_if:override_deadline,true
```

---

# 16. DTOs وRepositories

استخدم DTOs Typed عند الحاجة، مثل:

```text
MarkWinnerDefaultedDTO
WinnerDefaultDispositionDTO
CreateWinnerReassignmentDTO
CreateAlternativeSettlementDTO
```

لا تنشئ Generic Winner DTO.

القاعدة:

```text
Rule/Resolver decides.
Action orchestrates.
Repository locks and persists.
```

لا تضع Business Logic داخل Repository.

---

# 17. الاستثناءات والرسائل

أضف Exceptions واضحة، مثل:

```text
WinnerDefaultNotAllowedException
WinnerPaymentDeadlineNotExpiredException
WinnerDefaultOverrideUnauthorizedException
WinnerDefaultOverrideReasonRequiredException
CurrentSettlementMissingException
SettlementAlreadyPaidException
SettlementIsNotCurrentException
DefaultedBidderCannotWinAgainException
NoEligibleAlternativeWinnerException
WinnerDepositDispositionConflictException
```

الرسائل للمستخدم أو الأدمن تكون عربية.

---

# 18. Audit

سجل أحداثًا مثل:

```text
winner_default_started
winner_marked_defaulted
winner_deposit_forfeited
winner_deposit_refund_planned
winner_deposit_manual_review
old_settlement_defaulted
alternative_winner_selected
alternative_settlement_created
auction_marked_unsold_after_default
```

Metadata المهمة:

```text
previous_winner
new_winner
previous_winning_bid
new_winning_bid
previous_settlement
new_settlement
deadline
override
override_reason
deposit disposition
forfeited amount
refund amount
actor
```

لا تكرر Audit عند Retry Idempotent.

---

# 19. Outbox

أنشئ أحداثًا على الأقل:

```text
auction.winner_defaulted
auction.alternative_winner_selected
auction.alternative_settlement_created
auction.no_alternative_winner
auction.winner_deposit_forfeited
auction.winner_deposit_refund_planned
```

تنفيذ Consumers العامة سيكون في TASK 13.

---

# 20. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Default before deadline

المتوقع:

```text
Rejected
```

## B. Default after deadline

المتوقع:

```text
Success
```

إذا كل الشروط صحيحة.

## C. `payment_due_at = null`

المتوقع:

```text
Default rejected
```

## D. Paid settlement

المتوقع:

```text
Default rejected
```

## E. Non-current settlement

المتوقع:

```text
Default rejected
```

## F. Wrong auction state

اختبر:

```text
HandoverPending
Completed
Cancelled
Unsold
```

المتوقع:

```text
Rejected
```

## G. Admin override without permission

المتوقع:

```text
Rejected
```

## H. Admin override without reason

المتوقع:

```text
Rejected
```

## I. Admin override valid

المتوقع:

- يسمح فقط بتجاوز Deadline.
- باقي الشروط ما زالت مطلوبة.

## J. Same bidder has multiple bids

- Bidder A لديه أعلى 3 Bids.
- Bidder B لديه Bid أقل.

بعد Default A:

```text
B wins
```

ولا تفوز Bid أخرى لـA.

## K. Candidate terms missing

المتوقع:

```text
Candidate skipped
```

## L. Candidate deposit refunded

المتوقع:

```text
Candidate skipped
```

## M. Candidate deposit active and eligible

المتوقع:

```text
Candidate selected
```

## N. Old settlement

بعد Default:

```text
is_current = false
status = Defaulted/Voided
```

وتظل بياناتها التاريخية كما هي.

## O. New settlement

المتوقع:

- sequence +1.
- new winner.
- new deposit applied.
- amount paid starts at 0.
- new deadline.
- no old payments copied.

## P. Fully covered alternative settlement

```text
remaining = 0
```

المتوقع:

```text
Auction → HandoverPending
```

## Q. No eligible alternative

المتوقع:

```text
Auction → Unsold
```

مع Release flow المطلوبة.

## R. Full forfeiture

المتوقع:

- applied/held moved correctly to forfeited.
- no refund.

## S. Partial forfeiture

إذا مدعومة:

- correct forfeit.
- correct refund plan.
- no over-refund.

## T. Refund disposition

- old applied deposit becomes refundable.
- refund plan uses correct source payment.
- no double refund.

## U. Idempotency

شغّل Action مرتين.

المتوقع:

- one default.
- one reassignment.
- one new settlement.
- one deposit disposition.

## V. Concurrent default

شغّل عمليتين متزامنتين على MySQL.

المتوقع:

- one winner default.
- one reassignment.
- one current settlement.
- no duplicate financial changes.

## W. Old payment submission approval

بعد Reassignment حاول اعتماد Submission قديمة.

المتوقع:

```text
Rejected
```

---

# 21. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع Integration Tests على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات:

- Concurrent default.
- Current settlement uniqueness.
- Reassignment uniqueness.
- Deposit bucket integrity.
- Stale payment rejection.

---

# 22. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan migrate:status
php artisan test --filter=WinnerDefault
php artisan test --configuration=phpunit.mysql.xml --filter=WinnerDefault
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

وشغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# 23. ممنوعات هذه المهمة

لا تنفذ الآن:

- General Cancellation refactor.
- Seller Deposit lifecycle.
- Configuration Snapshot الكاملة.
- General Outbox consumers.
- General Scheduler concurrency.
- Resource privacy.
- Full DTO/Repository cleanup خارج الملفات المطلوبة.

لا تعِد بناء Refund lifecycle؛ استخدم TASK 06.

لا تعِد بناء Non-winner release؛ استخدم TASK 07.

---

# 24. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_09_WINNER_DEFAULT_REPORT.md
```

ويحتوي على:

1. Flow القديم.
2. السبب الجذري للمشكلات.
3. شروط السماح بالـDefault.
4. Deadline وOverride design.
5. Old settlement closure.
6. Winner deposit disposition.
7. Alternative candidate eligibility.
8. Alternative winner selection.
9. New settlement creation.
10. No-alternative flow.
11. Payment submission superseding.
12. Idempotency design.
13. Concurrency protection.
14. Actions/Resolvers المنشأة.
15. DTOs المنشأة.
16. Repositories المعدلة.
17. Permissions الجديدة.
18. Audit events.
19. Outbox events.
20. الاختبارات الجديدة.
21. نتائج Feature Tests.
22. نتائج MySQL concurrency tests.
23. الأوامر التي شُغلت.
24. أي فحص تعذر تشغيله.
25. المخاطر المؤجلة إلى TASK 10 أو TASK 11.

---

# 25. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- لا يمكن Default لفائز دفع بالفعل.
- لا يمكن Default على Settlement غير Current.
- `payment_due_at = null` لا يسمح بالـDefault.
- Deadline أو Override الموثق مطلوب.
- Defaulted bidder يُستبعد بالكامل.
- Terms acceptance تُقرأ من المصدر الصحيح.
- Candidate refunded/forfeited deposit تُستبعد.
- Old settlement لا يعاد استخدامها.
- New settlement مستقلة بالكامل.
- Applied deposit للفائز المتعثر تُعالج ماليًا.
- No candidate يؤدي إلى Unsold.
- Old submissions لا يمكن اعتمادها.
- العملية Idempotent.
- Concurrent default آمنة على MySQL.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
