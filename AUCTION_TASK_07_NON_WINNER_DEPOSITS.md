# TASK 07 — Non-Winner Bidder Deposit Release

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إكمال دورة عربونات المزايدين غير الفائزين، بحيث لا يظل أي عربون:

```text
Held
Applied
RefundPending
```

بلا سبب أو نهاية واضحة.

المطلوب:

1. تحديد متى يُحتفظ بعربون غير الفائز كمرشح لفائز بديل.
2. تحديد متى تنتهي الحاجة إليه.
3. إنشاء Refund Plan واحدة فقط عندما يصبح العربون قابلًا للرد.
4. عدم رد عربون مرشح بديل قبل انتهاء فرصة إعادة التعيين.
5. عدم إبقاء عربونات غير الفائزين محتجزة بعد دفع الفائز أو انتهاء كل فرص البديل.
6. دعم حالات:
   - Winner paid.
   - Winner defaulted with alternative winner.
   - Winner defaulted without alternative winner.
   - Unsold.
   - Completed.
   - Cancelled.
7. استخدام Refund Lifecycle الموجودة بعد TASK 04 وTASK 05 وTASK 06، وعدم إنشاء دورة رد موازية جديدة.

هذه المهمة تخص فقط:

```text
Non-winner bidder deposit retention
Alternative-winner candidate retention
Release/refund triggers
Idempotent refund planning
Terminal cleanup of bidder deposits
```

لا تنفذ الآن:

- Seller deposit lifecycle.
- Winner default financial rules الكاملة.
- General cancellation refactor.
- General Configuration Snapshot refactor.
- General Outbox refactor.
- General Scheduler refactor.
- Resource privacy.
- General DTO/Repository cleanup.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لهذه المهمة.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/FinalizeAuctionAction.php
app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
app/Services/Auction/Actions/MarkWinnerDefaultedAction.php
app/Services/Auction/Actions/CancelAuctionAction.php
app/Services/Auction/Actions/RefundAuctionDepositAction.php
app/Services/Auction/Actions/*
app/Jobs/Auction/*
app/Repositories/Auction/AuctionDepositRepository.php
app/Repositories/Auction/AuctionBidRepository.php
app/Repositories/Auction/AuctionParticipantRepository.php
app/Repositories/Auction/AuctionSettlementRepository.php
app/Repositories/Auction/AuctionRefundRepository.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/AuctionBid.php
app/Models/Auction/AuctionSettlement.php
app/Models/Auction/Auction.php
app/Domain/Auction/Enums/
app/DTO/Auction/
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
config/auction.php
```

راجع نتائج TASK 04 وTASK 05 وTASK 06 إن كانت موجودة.

لا تكسر:

- Refund source of truth.
- source PaymentTransaction.
- Double-refund prevention.
- held/applied allocation.
- Refund lifecycle.
- Refund idempotency.

لا تعتمد على التقارير السابقة دون مراجعة الكود الفعلي.

---

# 2. المشكلة الحالية التي يجب التحقق منها

الاشتباه الحالي:

- Finalization تحتفظ بعربونات غير الفائزين عند سياسة مثل:
  ```text
  hold_all_eligible_bidders_until_winner_payment
  ```
- بعد دفع الفائز لا يوجد Flow موحد يحول عربونات غير الفائزين إلى Refund Plan.
- بعض العربونات قد تظل:
  ```text
  Held
  ```
  إلى أجل غير مسمى.
- عند Winner Default قد لا يتم تحديد أي المرشحين يبقون محتجزين وأيهم يُرد.
- عند عدم وجود Alternative Winner قد لا يتم تحرير باقي العربونات.
- عند Unsold أو Completed قد تظل أرصدة غير محسومة.

تحقق من السبب الجذري في الكود الفعلي قبل التعديل.

---

# 3. المصطلحات المطلوبة

## Current Winner Deposit

عربون المستخدم الذي يملك Winning Bid الحالية أو Current Settlement الحالية.

لا يُعامل كعربون Non-winner.

## Alternative Winner Candidate Deposit

عربون مستخدم ما زال مرشحًا فعليًا للفوز البديل وفق:

- ترتيب الـBids.
- Eligibility.
- Terms acceptance.
- Deposit validity.
- عدم الحظر أو الإلغاء.
- سياسة المزاد.

قد يبقى Held مؤقتًا.

## Non-Winner Deposit

عربون مستخدم لم يعد:

- Current Winner.
- Alternative Winner Candidate.
- مرتبطًا بفرصة فوز ما زالت مفتوحة.

يجب الانتقال به إلى Refund Plan أو Release مناسب.

## Release

لا تستخدم كلمة `Release` بصورة غامضة.

إذا كان المال تم تحصيله فعليًا عبر PaymentTransaction:

```text
Release = create Refund Plan
```

إذا لم يوجد مبلغ محصل فعليًا:

```text
Release = close/cancel obligation without creating monetary refund
```

لا تنشئ Refund لمال لم يتم تحصيله.

---

# 4. سياسة الاحتفاظ بالعربونات

راجع السياسة الفعلية في Configuration/Snapshot.

يجب دعم القيم الموجودة فعليًا، وقد تشمل:

```text
refund_all_non_winners_immediately
hold_top_n_bidders_until_winner_payment
hold_all_eligible_bidders_until_winner_payment
```

لا تضف كل القيم إذا لم تكن موجودة أو مطلوبة.

لكن يجب أن يكون السلوك النهائي واضحًا ومختبرًا.

## A. refund_all_non_winners_immediately

بعد Finalization:

- Current winner deposit تبقى Applied/Held حسب Settlement.
- جميع غير الفائزين تتحول إلى Refund Plan مباشرة.
- لا يوجد Alternative Winner مباشر يعتمد على Deposit محتجزة، إلا إذا كانت السياسة تسمح بإعادة طلب Deposit جديدة.

## B. hold_top_n_bidders_until_winner_payment

- احتفظ فقط بأعلى N مرشحين مؤهلين.
- N تأتي من Snapshot أو Config موثوقة.
- بقية غير الفائزين تتحول إلى Refund Plan.
- عند دفع الفائز:
  - أطلق Refund Plan لكل المرشحين المحتجزين غير الفائز.
- عند Winner Default:
  - اختر البديل المؤهل.
  - احتفظ بعربون البديل الجديد.
  - أعد تقييم المرشحين المتبقين.
  - حرر من خرج من Top N أو انتهت الحاجة إليه.

## C. hold_all_eligible_bidders_until_winner_payment

- احتفظ فقط بالمشاركين المؤهلين فعليًا.
- لا تحتفظ بعربون Participant غير مؤهل.
- بعد دفع الفائز:
  - أنشئ Refund Plan لجميع غير الفائزين.
- بعد انتهاء كل فرص البديل:
  - أنشئ Refund Plan لجميع المحتجزين المتبقين.

إذا كانت السياسة الحالية مخزنة في Configuration Version بدل Snapshot ثابتة، استخدم المصدر الحالي دون تنفيذ TASK 11 بالكامل، لكن وثّق ذلك كمخاطرة متبقية.

لا تغيّر السياسة لمزاد قائم أثناء هذه المهمة.

---

# 5. التوقيت الصحيح لإنشاء Refund Plan

## A. عند Finalization

بعد تحديد Winner:

1. Current winner deposit:
   - تطبق على Settlement حسب Flow الحالي.
2. Non-winners:
   - طبّق سياسة الاحتفاظ.
3. المرشح الذي يجب رده:
   - أنشئ Refund Plan واحدة فقط.
4. المرشح الذي يجب الاحتفاظ به:
   - يبقى Held مع سبب وMetadata واضحة.

لا تنشئ Refund لكل العربونات عشوائيًا.

---

## B. عند اعتماد Winner Payment

بعد نجاح دفع Current Settlement وانتقال المزاد إلى الحالة المالية التالية:

```text
HandoverPending
```

أو الحالة الفعلية المقابلة:

- تنتهي الحاجة عادةً إلى Alternative Winner.
- أنشئ Refund Plan لجميع Bidder Deposits المحتجزة التي لا تخص Current Winner.
- لا ترد Current Winner Deposit إذا كانت Applied أو لازمة للـSettlement.
- اجعل العملية جزءًا من نفس Use Case أو Post-commit Action موثوق.
- لا تسمح بفشل Release بصمت.

إذا كانت سياسة المشروع تحتفظ بالعربونات حتى Completion وليس Payment، لا تفترض ذلك. راجع السياسة ووثّقها واختبرها.

---

## C. عند Winner Default مع Alternative Winner

بعد اختيار Alternative Winner:

- Deposit البديل الجديد تبقى Held أو تصبح Applied وفق Settlement الجديدة.
- Deposit الفائز المتعثر تُعالج وفق TASK 09، لا تعالجها كـNon-winner عادية.
- أعد حساب قائمة المرشحين المتبقين.
- حرر أي Deposit لم تعد مرشحة.
- لا ترد Deposit المرشح الذي قد يصبح البديل التالي إذا كانت السياسة تحتفظ بها.

---

## D. عند Winner Default بلا Alternative Winner

عندما تنتهي النتيجة إلى:

```text
Unsold
```

أو حالة نهائية مكافئة:

- أنشئ Refund Plan لجميع Bidder Deposits المحتجزة القابلة للرد.
- استثنِ المبالغ المصادر وفق سياسة Default إن وجدت.
- لا تترك Deposits في Held.

---

## E. عند Unsold من البداية

الحالات مثل:

```text
No bids
Reserve not reached
No eligible winner
```

يجب أن تنتهي بـRefund Plan لكل Bidder Deposit محصلة وقابلة للرد.

لا تُنشئ Refund لالتزام لم يُدفع فعليًا.

---

## F. عند Completed

Completion يجب أن تتأكد أن:

- لا توجد Bidder Deposits غير فائزة ما زالت Held بلا سبب.
- لا توجد Refunds مطلوبة لم تُخطط.
- إذا كانت Refunds موجودة Pending/Processing، لا تمنع Completion بالضرورة إلا إذا كانت سياسة المشروع تتطلب ذلك، لكن يجب أن تكون مخططة وقابلة للتتبع.

أنشئ Reconciliation check أو Guard مناسبًا.

---

## G. عند Cancelled

لا تنشئ Flow رد ثانية.

استخدم Financial Cancellation Flow الموجودة من TASK 04.

هذه المهمة يجب فقط أن تتأكد أن Non-winner deposits لا تُترك Held بعد Cancellation.

لا تنشئ Refund duplicate إذا كانت Cancellation أنشأتها بالفعل.

---

# 6. سبب الاحتفاظ بالعربون

إذا بقيت Deposit في:

```text
Held
```

يجب أن يكون هناك سبب واضح وقابل للمراجعة، مثل:

```text
hold_reason = alternative_winner_candidate
hold_expires_at
candidate_rank
```

أو Metadata/جدول مكافئ.

لا تضف أعمدة كثيرة بلا حاجة.

المطلوب أن يستطيع النظام الإجابة:

```text
لماذا هذا العربون ما زال Held؟
حتى متى؟
ما الحدث الذي سيحرره؟
```

إذا لا يوجد `hold_expires_at` حقيقي في السياسة، استخدم Event trigger واضحًا بدل اختراع مهلة.

---

# 7. Action مركزية

أنشئ Action أو Service صغيرة وواضحة، مثل:

```text
ReleaseNonWinnerDepositsAction
```

أو:

```text
PlanNonWinnerDepositRefundsAction
```

مسؤوليتها:

1. Lock Auction عند الحاجة.
2. تحديد Current Winner.
3. تحديد Current Settlement.
4. تحديد المرشحين البدلاء المؤهلين.
5. قراءة سياسة الاحتفاظ.
6. تصنيف كل Bidder Deposit:
   - keep held
   - apply/current winner
   - refund plan
   - already refunded
   - forfeited
   - no captured money
7. إنشاء Refund Plans المطلوبة عبر Refund service/repository الحالية.
8. منع التكرار.
9. Audit.
10. Outbox عند الحاجة.
11. Commit.

لا تجعل Repository تقرر من هو المرشح البديل.

لا تجعل Action تنشئ RefundTransaction بطرق تتجاوز TASK 04 وTASK 06.

---

# 8. Eligibility للمرشح البديل

لا تعتبر كل Bidder لديه Bid مرشحًا.

استخدم نفس القواعد المعتمدة في Winner Default:

- Participant eligible.
- Deposit paid and valid.
- Deposit not refunded.
- Deposit not forfeited.
- Terms accepted.
- User not defaulted.
- User not blocked.
- Bid accepted.
- Auction-specific constraints.

يفضل مشاركة Rule واحدة مع `MarkWinnerDefaultedAction` بدل نسخ الشروط.

لا تنفذ Winner Default كاملًا هنا.

---

# 9. Refund Plan Idempotency

إعادة تشغيل Release Action يجب ألا:

- تنشئ Refund ثانية.
- تغيّر Deposit إلى RefundPending مرتين.
- تكرر Audit المالية.
- تكرر Outbox المالية.
- تحجز مبلغًا سبق حجزه داخل Refund Pending/Processing.

استخدم:

- Locks.
- Existing refund checks.
- Source PaymentTransaction.
- Database constraints من TASK 04.
- Active refund reservation من TASK 05.

---

# 10. حالة Deposit

استخدم الحالات الموجودة فعليًا، لكن يجب أن يكون الانتقال واضحًا.

مثال:

```text
Held
→ RefundPending
→ RefundProcessing
→ Refunded
```

أو الحالة الفعلية بعد TASK 06.

لـDeposit لم تُدفع:

```text
PendingSubmission / RejectedSubmission
→ Released / Cancelled
```

إذا لم توجد حالة `Released` ولا حاجة فعلية لها، لا تضفها عشوائيًا.

لا تجعل Deposit المدفوعة تتحول مباشرة إلى `Refunded` قبل نجاح Refund.

---

# 11. عدم المساس بـCurrent Winner Deposit

لا ترد Deposit تخص:

- Current Winning Bid.
- Current Settlement.
- Alternative Winner الجديد بعد إعادة التعيين.

إلا إذا دخلت Flow إلغاء أو Default أو Refund مستقلة.

أضف Guard صريح واختبارات.

---

# 12. Reconciliation

أضف Query أو Command فحص، وليس بالضرورة إصلاحًا تلقائيًا، يستطيع اكتشاف:

```text
Auction terminal state
+ bidder deposit still Held
+ not current winner
+ no active alternative-winner need
```

ويبلغ عنها.

يمكن إضافة هذه الحالة إلى `ReconcileAuctionsAction` إذا كان ذلك بسيطًا ومناسبًا.

لا تجعل Reconciliation تنشئ Refunds عشوائيًا دون Locks وUse Case الصحيحة.

---

# 13. DTOs وRepositories

استخدم DTOs Typed عند الحاجة، مثل:

```text
NonWinnerDepositDispositionDTO
PlanDepositRefundDTO
DepositHoldDecisionDTO
```

لكن لا تنشئ Generic Deposit DTO.

القاعدة:

```text
Action decides classification.
Repository loads, locks and persists.
Refund Action creates/processes refund.
```

لا تضع Business Logic داخل Repository.

---

# 14. الاستثناءات والرسائل

أضف Exceptions واضحة عند الحاجة، مثل:

```text
CurrentWinnerDepositCannotBeReleasedException
AlternativeWinnerCandidateDepositCannotBeReleasedException
NonWinnerDepositReleaseConflictException
DepositAlreadyHasActiveRefundException
DepositReleaseSourcePaymentMissingException
```

الرسائل للمستخدم أو الأدمن تكون عربية.

---

# 15. Audit

سجل أحداثًا مثل:

```text
non_winner_deposit_held
non_winner_deposit_refund_planned
non_winner_deposit_released_without_payment
alternative_candidate_hold_released
terminal_auction_deposit_cleanup
```

Metadata المهمة:

```text
policy
candidate_rank
reason
trigger
source_payment_transaction_id
refund_transaction_id
actor/system
```

لا تكرر Audit عند إعادة التشغيل Idempotently.

---

# 16. Outbox

أنشئ أحداثًا عند الحاجة، مثل:

```text
auction.non_winner_deposit_refund_planned
auction.non_winner_deposit_released
```

تنفيذ Consumers العامة سيكون في TASK 13.

لا تعتبر Log وحده Side Effect كافيًا.

---

# 17. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests فعلية.

## A. Immediate refund policy

- Winner selected.
- ثلاثة Non-winners.
- policy = refund_all_non_winners_immediately.

المتوقع:

- Current winner deposit لا تُرد.
- كل Non-winner المدفوع لها Refund Plan واحدة.
- غير المدفوع لا تُنشأ له Refund مالية.

## B. Hold all until winner payment

بعد Finalization:

- Non-winners eligible تبقى Held.
- Ineligible participant لا تبقى Held بلا سبب.

بعد Winner payment:

- جميع Non-winners المدفوعة تحصل على Refund Plan.
- Current winner deposit لا تُرد.

## C. Hold top N

- N = 2.
- ترتيب مرشحين واضح.

المتوقع:

- أعلى 2 eligible يحتفظ بهم.
- الباقي Refund Plan.
- بعد Winner payment يتحرر المحتجزون.

## D. Winner default with alternative

- Winner A defaults.
- B becomes alternative winner.
- C remains possible candidate حسب السياسة.
- D no longer candidate.

المتوقع:

- B deposit لا تُرد.
- C تبقى أو تُرد حسب السياسة.
- D تُنشأ لها Refund Plan.
- A لا تُعامل كـNon-winner عادية إذا كانت لها Default policy مستقلة.

## E. Winner default without alternative

المتوقع:

- كل Deposits القابلة للرد تحصل على Refund Plan.
- لا Deposit تبقى Held بلا سبب.

## F. Unsold — no bids / reserve not reached

المتوقع:

- كل Bidder Deposit مدفوعة وقابلة للرد تحصل على Refund Plan.
- لا Duplicate refunds.

## G. Completed auction

- Winner payment approved.
- Auction completed.

المتوقع:

- لا توجد Non-winner Deposit في Held بلا سبب.
- Current winner accounting لا تتأثر.

## H. Cancelled auction

- Cancellation سبق وأن أنشأت Refund Plan.

ثم شغّل Release action.

المتوقع:

- لا Refund duplicate.

## I. Idempotency

شغّل Release action مرتين.

المتوقع:

- نفس عدد Refunds.
- لا duplicate Audit/Outbox المالية.
- لا تغيير مزدوج للحالات.

## J. Concurrent release

شغّل عمليتين متزامنتين على MySQL.

المتوقع:

- Refund Plan واحدة لكل Deposit.
- لا Double reservation.
- لا negative amounts.
- لا current winner deposit refund.

## K. Unpaid deposit

Deposit required لكن بلا Successful PaymentTransaction.

المتوقع:

- لا Refund مالية.
- الالتزام يغلق/يتحرر حسب التصميم.

## L. Existing active refund

Deposit لديها Refund:

```text
Pending أو Processing
```

المتوقع:

- لا Refund ثانية.
- المبلغ يظل محجوزًا مرة واحدة.

## M. Reconciliation detection

Terminal auction + stale Held non-winner deposit.

المتوقع:

- Reconciliation تكتشفها.
- لا تنشئ إصلاحًا ماليًا خارج Use Case دون قرار صريح.

---

# 18. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع Integration Tests على قاعدة غير مخصصة.

لا تعتمد على SQLite لإثبات:

- Concurrent release.
- Locks.
- Refund uniqueness.
- Active refund reservation.

---

# 19. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=NonWinnerDeposit
php artisan test --configuration=phpunit.mysql.xml --filter=NonWinnerDeposit
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

وشغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أي أمر لم يتم تشغيله.

---

# 20. ممنوعات هذه المهمة

لا تنفذ الآن:

- Seller Deposit final lifecycle.
- Winner Default financial policy الكاملة.
- General Cancellation redesign.
- Configuration Snapshot الكاملة.
- General Outbox consumers.
- General Scheduler concurrency.
- Resource privacy.
- Full DTO/Repository cleanup خارج الملفات المطلوبة.

لا تنشئ Refund processor جديدًا؛ استخدم TASK 06.

لا تنشئ مصدر Refund جديدًا؛ استخدم TASK 04.

---

# 21. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_07_NON_WINNER_DEPOSITS_REPORT.md
```

ويحتوي على:

1. Flow القديم.
2. السبب الجذري لبقاء Deposits Held.
3. سياسة الاحتفاظ النهائية.
4. تعريف Current winner وAlternative candidates.
5. Triggers التي تُنشئ Refund Plans.
6. كيفية التعامل مع:
   - Finalization
   - Winner payment
   - Winner default
   - Unsold
   - Completed
   - Cancelled
7. Idempotency design.
8. Reconciliation rule.
9. Actions المنشأة أو المعدلة.
10. DTOs المنشأة.
11. Repositories المعدلة.
12. Audit events.
13. Outbox events.
14. الاختبارات الجديدة.
15. نتائج Feature Tests.
16. نتائج MySQL concurrency tests.
17. الأوامر التي شُغلت.
18. أي فحص تعذر تشغيله.
19. المخاطر المؤجلة إلى TASK 08 أو TASK 09 أو TASK 11.

---

# 22. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- لا توجد Non-winner Deposit تبقى Held بلا Trigger واضح.
- Current winner deposit لا تُرد بالخطأ.
- Alternative candidate deposit لا تُرد قبل انتهاء الحاجة إليها.
- Winner payment يحرر غير الفائزين حسب السياسة.
- Unsold يحرر كل القابل للرد.
- Winner default بلا بديل لا يترك Deposits محتجزة.
- Cancellation لا تنتج Refund duplicate.
- Unpaid deposit لا تنشئ Refund مالية.
- Active Refund تمنع Refund جديدة.
- Release action Idempotent.
- Concurrent release آمنة على MySQL.
- Reconciliation تكتشف أي Stale Held deposit.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بمراجعة الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
