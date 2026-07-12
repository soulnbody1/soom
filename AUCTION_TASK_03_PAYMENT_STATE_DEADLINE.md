# TASK 03 — Payment State, Ownership, Current-Target and Deadline Validation

## المشروع

```text
C:\Users\pc\Desktop\SB\soom
```

## الهدف المحدد لهذه المهمة

إصلاح التحقق الخاص بإرسال واعتماد المدفوعات داخل نظام المزادات، بحيث لا يمكن إرسال أو اعتماد أي Payment Submission إلا إذا كان:

1. نوع الدفع صحيحًا.
2. المزاد في الحالة التي تسمح بهذا النوع من الدفع.
3. الالتزام المالي المطلوب هو الالتزام الحالي الصحيح.
4. المستخدم هو صاحب الالتزام الفعلي.
5. المبلغ والعملة مطابقان للمطلوب.
6. المهلة لم تنتهِ.
7. لا توجد حالة لاحقة تجعل الدفع قديمًا أو غير صالح.
8. أي Admin Override يكون بصلاحية مستقلة وسبب إجباري وAudit واضح.

هذه المهمة تخص **التحقق من حالة الدفع والمهلة والملكية والهدف الحالي فقط**.

لا تنفذ الآن إصلاحات Refunds أو Cancellation أو Winner Default أو Seller Deposit final lifecycle أو Outbox العامة أو Resources العامة، إلا إذا كان تعديل صغيرًا ضروريًا مباشرة لتحقيق شروط هذه المهمة.

---

# 1. راجع الكود الفعلي أولًا

راجع على الأقل:

```text
app/Services/Auction/Actions/SubmitPaymentSubmissionAction.php
app/Services/Auction/Actions/ReviewPaymentSubmissionAction.php
app/Repositories/Auction/AuctionPaymentRepository.php
app/Repositories/Auction/AuctionDepositRepository.php
app/Repositories/Auction/AuctionSettlementRepository.php
app/Repositories/Auction/AuctionRepository.php
app/Models/Auction/PaymentSubmission.php
app/Models/Auction/PaymentTransaction.php
app/Models/Auction/AuctionDeposit.php
app/Models/Auction/AuctionSettlement.php
app/Models/Auction/Auction.php
app/Domain/Auction/Enums/
app/Policies/Auction/
app/Http/Requests/Auction/
app/Http/Controllers/Auction/
database/migrations/*auction*
tests/Feature/Auction/
tests/Integration/Auction/
```

لا تعتمد على التقارير السابقة.

ارسم Flow حقيقي لكل نوع دفع قبل التعديل.

---

# 2. أنواع الدفع المطلوب التعامل معها

التزم بالأنواع الفعلية الموجودة في المشروع، لكن يجب أن تغطي على الأقل:

```text
seller_deposit
bidder_deposit
winner_settlement
```

إذا كانت الأسماء الحالية مختلفة، لا تغيّرها عشوائيًا. وحّدها فقط إذا كان ذلك ضروريًا مع تحديث جميع الاستخدامات والاختبارات.

---

# 3. القواعد الدقيقة لكل نوع دفع

## A. Seller Deposit

يسمح بإرسال واعتماد Seller Deposit فقط إذا:

```text
auction.status = AwaitingSellerDeposit
```

ويجب أن يكون:

```text
deposit.owner_id = auction.seller_id
deposit.type = seller
deposit.auction_id = auction.id
```

ويجب أن يكون الالتزام:

```text
غير مدفوع
غير refunded
غير forfeited
غير cancelled
```

ويجب أن يساوي المبلغ المطلوب وفق Snapshot الخاصة بالمزاد.

إذا كانت قيمة Seller Deposit المطلوبة:

```text
0
```

فلا يسمح بإنشاء Payment Submission أصلًا، ويجب ألا يصل المزاد إلى `AwaitingSellerDeposit`.

في هذه المهمة لا تعدّل Approval flow الخاص بإنشاء المزاد إلا إذا كان تعديل صغيرًا ضروريًا لمنع Payment Submission صفرية.

---

## B. Bidder Deposit

يسمح بإرسال واعتماد Bidder Deposit فقط إذا:

```text
auction.status ∈ [Scheduled, Live]
```

أو الحالات الفعلية التي تسمح بها سياسة التسجيل في المشروع.

لكن يجب أن تكتب Rule مركزية وصريحة، ولا تكرر الحالات يدويًا في أكثر من Action.

شروط إضافية:

```text
participant.auction_id = auction.id
participant.bidder_id = authenticated user id
participant is eligible
deposit.owner_id = participant.bidder_id
deposit.type = bidder
deposit.auction_id = auction.id
```

ويمنع الدفع إذا كان المزاد:

```text
Draft
PendingReview
AwaitingSellerDeposit
Rejected
Ended
SettlementPending
PaymentPending
HandoverPending
Completed
Cancelled
Unsold
WinnerDefaulted
Disputed
```

إلا إذا كانت هناك حالة موثقة في التصميم تسمح بذلك، وعندها وثّق السبب وأضف اختبارًا.

لا تسمح بإرسال أو اعتماد Bidder Deposit بعد انتهاء المزاد.

---

## C. Winner Settlement Payment

يسمح بإرسال واعتماد Winner Payment فقط إذا:

```text
auction.status = PaymentPending
```

ويجب أن تكون Settlement:

```text
is_current = true
status = PaymentPending
auction_id = auction.id
winner_bidder_id = authenticated user id
```

ويجب أن تكون مرتبطة بـ:

```text
auction.winning_bid_id الحالي
```

أو بالمرجع الصحيح المستخدم في التصميم.

يمنع الدفع إذا:

- تغير الفائز.
- أصبحت Settlement غير Current.
- أصبحت Settlement Voided أو Defaulted أو Paid أو Cancelled.
- أصبح المزاد HandoverPending أو Completed أو Cancelled أو Unsold أو Disputed.
- لم يعد المستخدم هو الفائز الحالي.
- تم إنشاء Settlement جديدة بديلة.

لا تعتمد على `settlement_id` القادم من المستخدم وحده.

أعد تحميل Current Settlement من المزاد وقارنها بالهدف المطلوب.

---

# 4. التحقق من المهلة

## Seller Deposit Deadline

إذا كان هناك حقل أو Policy لمهلة عربون البائع، طبّقه.

إذا لم يوجد، لا تخترع حقلًا بلا داعٍ. وثّق أنه غير موجود واترك هذه المهلة خارج نطاق المهمة.

## Bidder Deposit Deadline

يجب عدم السماح بالدفع بعد:

```text
auction.ends_at
```

وعند الحاجة قبل بدء المزاد أو أثناءه وفق السياسة الحالية.

لا تسمح بالاعتماد بعد انتهاء المزاد حتى لو تم إرسال الإيصال قبل الانتهاء، إلا إذا كانت هناك سياسة واضحة تسمح بمراجعة دفعة وصلت قبل المهلة.

إذا اخترت السماح بذلك، يجب أن يكون:

```text
submission.submitted_at <= deadline
```

مع التحقق أن الالتزام كان صالحًا وقت الإرسال.

وثّق القرار.

## Winner Payment Deadline

يجب التحقق في مرحلتين:

### عند Submit

```text
now <= settlement.payment_due_at
```

### عند Approve

أعد التحقق مرة أخرى:

```text
now <= settlement.payment_due_at
```

لأن الإيصال قد يُرسل قبل المهلة ويُراجع بعدها.

اختر سياسة واضحة من واحدة فقط:

### Policy 1 — Deadline applies to submission time

إذا وصل الإيصال قبل المهلة، يمكن للأدمن اعتماده بعد المهلة.

عندها تحقق:

```text
submission.submitted_at <= payment_due_at
```

ولا تستخدم وقت الاعتماد كسبب للرفض.

### Policy 2 — Approval must happen before deadline

استخدمها فقط إذا كانت هذه هي سياسة المشروع فعلًا.

الأفضل غالبًا في الدفع اليدوي بالإيصال هو:

```text
submission time determines timeliness
```

لكن يجب مراجعة متطلبات المشروع واختيار السياسة بوضوح.

لا تترك السلوك ضمنيًا أو مختلفًا بين Submit وApprove.

---

# 5. Admin Override

إذا كان المشروع يدعم اعتماد دفع بعد انتهاء المهلة، يجب ألا يكون ذلك Boolean عاديًا متاحًا لأي Admin.

أنشئ صلاحية مستقلة مثل:

```text
auction.payments.override_deadline
```

أو الاسم المتوافق مع نظام Permissions الحالي.

ويجب أن يتطلب:

```text
override_deadline = true
override_reason = required string
```

مع حفظ:

```text
overridden_by
overridden_at
override_reason
original_deadline
```

إما داخل Payment Submission review metadata أو Audit Log موثوق.

لا تسمح بالـOverride إذا:

- المزاد Cancelled.
- الفائز تغير.
- Settlement ليست Current.
- الالتزام دفع بالفعل.
- Settlement Voided أو Defaulted.
- السبب فارغ.
- المستخدم لا يملك الصلاحية.

الـOverride يتجاوز المهلة فقط، ولا يتجاوز Ownership أو Current Target أو Paid State.

---

# 6. إعادة التحميل والقفل داخل Transaction

في كل من:

```text
SubmitPaymentSubmissionAction
ReviewPaymentSubmissionAction
```

لا تستخدم Models تم تحميلها قبل رفع الملف أو قبل بدء Transaction النهائية.

داخل Transaction النهائية يجب:

1. Lock Auction.
2. إعادة فحص Auction status.
3. Lock Payment Target:
   - Deposit.
   - Settlement.
4. إعادة فحص Target type.
5. إعادة فحص Ownership.
6. إعادة فحص Current target.
7. إعادة فحص Deadline.
8. إعادة فحص Amount.
9. إعادة فحص Currency.
10. إعادة فحص Already paid.
11. إعادة فحص Active submission عند الحاجة.
12. تنفيذ العملية.
13. Audit.
14. Outbox إذا كان موجودًا في الـFlow الحالي.
15. Commit.

استخدم Repository methods واضحة مثل:

```text
lockAuctionForPayment()
lockDepositForPayment()
lockCurrentSettlementForPayment()
```

ولا تجعل Repository تقرر Business Rules.

---

# 7. مبلغ الدفع

لكل نوع:

## Seller Deposit

```text
submission.amount_minor = required seller deposit amount
```

## Bidder Deposit

```text
submission.amount_minor = required bidder deposit amount
```

## Winner Settlement

إذا لا يوجد Partial Payment:

```text
submission.amount_minor = settlement.remaining_amount_minor
```

ولا تسمح بمبلغ أقل أو أكبر.

إذا يوجد Partial Payment فعلي في الكود، وثّق ذلك ولا تكمله ضمن هذه المهمة إلا إذا كان موجودًا بالفعل ومختبرًا.

يجب منع:

```text
amount <= 0
currency mismatch
amount mismatch
```

ولا تستخدم Float.

---

# 8. العملة

يجب أن تطابق:

```text
submission.currency_id / currency_code
```

عملة المزاد والالتزام.

لا تسمح للمستخدم بتحديد Currency مختلفة عن Auction Currency.

إذا Request تستقبل `currency_code`، تحقق أنها:

```text
= auction.currency.code
```

وأنها Currency مدعومة.

---

# 9. حالات Payment Submission

عرّف الحالات بوضوح:

```text
PendingReview
Approved
Rejected
Cancelled
Expired
Superseded
```

استخدم الموجود فعليًا في المشروع ولا تضف حالات بلا حاجة.

الحالات المطلوبة:

- Submission قديمة لفائز سابق:
  ```text
  Superseded أو Cancelled
  ```
- Submission انتهت مهلة التزامها ولم تعد صالحة:
  ```text
  Expired
  ```
- Submission رفضها الأدمن:
  ```text
  Rejected
  ```

لا تسمح باعتماد:

```text
Rejected
Cancelled
Expired
Superseded
Approved
```

---

# 10. Rule/Policy المركزية

لا تكرر الحالات داخل:

```text
Submit action
Review action
Controller
Policy
Request
```

أنشئ Rule أو Domain Service صغيرة وواضحة، مثل:

```text
PaymentEligibilityRule
AuctionPaymentPolicyResolver
```

تجيب عن:

```text
هل يمكن إرسال هذا النوع من الدفع الآن؟
هل يمكن اعتماد هذه Submission الآن؟
ما سبب الرفض؟
```

لكن لا تنشئ God Service.

يجب أن تظل:

```text
Action orchestrates
Rule decides
Repository loads/persists
```

---

# 11. الرسائل والأخطاء

استخدم Exceptions محددة، مثل:

```text
AuctionStateDoesNotAllowPaymentException
PaymentDeadlineExpiredException
PaymentTargetIsNotCurrentException
PaymentTargetOwnerMismatchException
PaymentAmountMismatchException
PaymentCurrencyMismatchException
PaymentSubmissionExpiredException
PaymentOverrideNotAuthorizedException
PaymentOverrideReasonRequiredException
```

اربطها بالـCentral Exception Handler.

كل الرسائل التي تصل للمستخدم تكون عربية.

أمثلة رسائل:

```text
لا يمكن دفع عربون البائع في الحالة الحالية للمزاد.
انتهت مهلة دفع المبلغ المتبقي.
لم تعد هذه التسوية هي التسوية الحالية للمزاد.
لا يخصك هذا الالتزام المالي.
مبلغ الدفع لا يطابق المبلغ المطلوب.
عملة الدفع لا تطابق عملة المزاد.
يتطلب تجاوز المهلة صلاحية خاصة وسببًا موثقًا.
```

---

# 12. Policies والصلاحيات

راجع:

```text
submit payment
review payment
override deadline
view receipt
```

لا تعتمد فقط على `role:admin`.

المطلوب:

- المستخدم يرسل دفعًا لالتزام يخصه فقط.
- الأدمن المخول فقط يراجع.
- Override يحتاج Permission منفصلة.
- Temporary receipt URL لا تصدر إلا لمخول.

في هذه المهمة ركّز على Policies المرتبطة بالدفع فقط.

---

# 13. الاختبارات الإلزامية

أنشئ Feature وMySQL Integration Tests حقيقية.

## Seller Deposit

1. Submit في `AwaitingSellerDeposit` → نجاح.
2. Submit في `Draft` → رفض.
3. Submit في `Scheduled` → رفض.
4. مستخدم غير البائع → رفض.
5. مبلغ غير مطابق → رفض.
6. Currency غير مطابقة → رفض.
7. Deposit المطلوبة صفر → لا يسمح Submission.

## Bidder Deposit

1. Submit في `Scheduled` → نجاح.
2. Submit في `Live` → نجاح إذا السياسة تسمح.
3. Submit بعد `Ended` → رفض.
4. Submit في `Cancelled` → رفض.
5. مستخدم غير Participant → رفض.
6. Participant غير Eligible → رفض.
7. Deposit تخص مستخدمًا آخر → رفض.
8. Amount mismatch → رفض.
9. Currency mismatch → رفض.

## Winner Settlement

1. Current winner + PaymentPending + قبل المهلة → نجاح.
2. مستخدم ليس الفائز → رفض.
3. Settlement ليست Current → رفض.
4. Winner تغير بعد Submit وقبل Approve → رفض.
5. Settlement أصبحت Paid → رفض.
6. Auction أصبحت HandoverPending → رفض.
7. Auction أصبحت Cancelled → رفض.
8. Amount أقل من remaining → رفض.
9. Amount أكبر من remaining → رفض.
10. Currency مختلفة → رفض.

## Deadline

1. Submit قبل deadline → نجاح.
2. Submit بعد deadline → رفض.
3. Submission أُرسلت قبل deadline واعتمدت بعدها:
   - اختبر السياسة المختارة.
4. Override بدون Permission → رفض.
5. Override بدون Reason → رفض.
6. Override بصلاحية وسبب → نجاح فقط إذا باقي الشروط صحيحة.
7. Override لا يسمح بدفع Settlement غير Current.
8. Override لا يسمح بدفع Auction Cancelled.

## Concurrency / stale target

1. Submit على Current Settlement.
2. تغيير Winner وإنشاء Settlement جديدة.
3. محاولة Approve القديمة.
4. يجب الرفض دون إنشاء PaymentTransaction.

نفّذ هذا الاختبار على MySQL.

---

# 14. بيئة الاختبار

استخدم قاعدة MySQL مخصصة تنتهي بـ:

```text
_testing
```

لا تستخدم قاعدة التطوير.

أضف Guard يمنع تشغيل Integration Tests إذا اسم القاعدة لا ينتهي بـ:

```text
_testing
```

لا تعتمد على SQLite لإثبات Locks أو Stale target race.

---

# 15. الفحوصات المطلوبة

شغّل ما يمكن فعليًا:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan migrate:status
php artisan test --filter=PaymentState
php artisan test --filter=PaymentDeadline
php artisan test --configuration=phpunit.mysql.xml --filter=Payment
```

وشغّل:

```bash
vendor/bin/pint --test
```

إذا كان موجودًا.

شغّل Syntax check لكل ملفات PHP المعدلة.

لا تدّعِ نجاح أمر لم يتم تشغيله.

---

# 16. ممنوعات هذه المهمة

لا تعدّل الآن:

- Refund lifecycle.
- Cancellation financial flow.
- Seller deposit final refund/forfeit.
- Winner default financial flow.
- Non-winner deposit release.
- General Outbox consumers.
- General Resource privacy.
- Scheduler concurrency.
- Configuration Snapshot الكاملة.

إلا إذا كان تعديل صغيرًا وضروريًا مباشرة لصحة Payment validation.

لا تنفذ Refactor واسع.

---

# 17. التقرير المطلوب

أنشئ:

```text
C:\Users\pc\Desktop\SB\soom\AUCTION_TASK_03_PAYMENT_STATE_DEADLINE_REPORT.md
```

ويحتوي على:

1. Flow كل Payment Type قبل الإصلاح.
2. السبب الجذري للمشكلات.
3. الحالات المسموحة لكل نوع دفع.
4. الحالات المرفوضة لكل نوع دفع.
5. سياسة Deadline المختارة.
6. تصميم Admin Override.
7. Rule/Policy المركزية التي أُنشئت.
8. Repository locks المستخدمة.
9. الملفات المنشأة.
10. الملفات المعدلة.
11. Migrations إن وجدت.
12. Permissions الجديدة.
13. الاختبارات الجديدة.
14. نتائج Feature Tests.
15. نتائج MySQL Tests.
16. الأوامر التي شُغلت.
17. أي فحص تعذر تشغيله.
18. أي مخاطر متبقية.

---

# 18. شروط القبول النهائية

لا تعتبر المهمة مكتملة إلا إذا:

- Seller Deposit لا تُرسل أو تعتمد خارج حالتها الصحيحة.
- Bidder Deposit لا تُرسل أو تعتمد بعد انتهاء المزاد.
- Winner Payment لا تُرسل أو تعتمد إلا للـCurrent Winner والـCurrent Settlement.
- Submit وApprove يعيدان فحص الحالة والملكية والهدف والمهلة.
- Settlement قديمة لا يمكن اعتمادها بعد تغيير الفائز.
- Amount وCurrency يطابقان الالتزام.
- Deadline policy موحدة وواضحة.
- Override بصلاحية مستقلة وسبب إجباري.
- Override لا يتجاوز Ownership أو Current target أو Paid state.
- الاختبارات تغطي الحالات المسموحة والمرفوضة.
- MySQL test يثبت رفض stale settlement.
- التقرير يطابق التنفيذ الفعلي.

ابدأ بفحص الكود الحالي، ثم نفّذ هذه المشكلة فقط.

لا تتوقف عند الخطة.
