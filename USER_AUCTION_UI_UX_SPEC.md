<div dir="rtl" align="right">

# مواصفات تجربة المستخدم وواجهات نظام المزادات

**نطاق الوثيقة:** تطبيق المستخدم — رحلتا المزايد والبائع
**المصدر:** التنفيذ الفعلي في الـBackend داخل `C:\Users\pc\Desktop\SB\soom`
**تاريخ الإصدار:** 2026-08-01

---

## كيف تُقرأ هذه الوثيقة

كل معلومة داخل الوثيقة موسومة بواحد من تصنيفين، وكلاهما يصف سلوكًا منفذًا فعليًا في الكود:

| الوسم | المعنى |
|---|---|
| **[منفذ]** | سلوك مقروء مباشرة من الكود، مع ذكر الملف أو الـClass أو الـMethod، ومغطى باختبار مرجعي. |
| **[استنتاج]** | سلوك مؤكد من تتبع مسار تنفيذ يمر بعدة ملفات، لكنه غير مكتوب صراحة في مكان واحد. |

كل ما ورد في هذه الوثيقة **منفذ ومختبَر**. الميزات التي تقرر عدم تنفيذها عمدًا مجموعة في قسم [خارج نطاق الإصدار الحالي](#s32) ولا يجوز تصميم شاشات تعتمد عليها.

أسماء الحقول والحالات والـEndpoints والـClasses مكتوبة بالإنجليزية كما هي في الكود ولا تُترجم. الأسماء العربية المقترحة للواجهة اقتراحات عرض فقط.

---

## فهرس المحتويات

1. [ملخص تجربة المزاد للمستخدم](#s1)
2. [المصطلحات الأساسية](#s2)
3. [أنواع المستخدمين والأدوار](#s3)
4. [خريطة دورة حياة المزاد](#s4)
5. [حالات المستخدم داخل كل مزاد](#s5)
6. [خريطة الشاشات المطلوبة](#s6)
7. [مواصفات قائمة المزادات](#s7)
8. [مواصفات شاشة تفاصيل المزاد](#s8)
9. [التسجيل والأهلية للمشاركة](#s9)
10. [العربون](#s10)
11. [غرفة المزاد والمزايدة المباشرة](#s11)
12. [سجل المزايدات والخصوصية](#s12)
13. [انتهاء المزاد وتحديد النتيجة](#s13)
14. [رحلة الفائز](#s14)
15. [التعثر وعدم سداد الفائز](#s15)
16. [رحلة المستخدم الخاسر](#s16)
17. [الاسترداد Refund](#s17)
18. [الإلغاء والتعليق والحالات الاستثنائية](#s18)
19. [قاموس البيانات الكامل](#s19)
20. [العملات والمبالغ المالية](#s20)
21. [التواريخ والتوقيت والعداد التنازلي](#s21)
22. [الـAPIs الخاصة بتطبيق المستخدم](#s22)
23. [الأخطاء ورسائل الواجهة](#s23)
24. [الإشعارات](#s24)
25. [حالات العرض العامة](#s25)
26. [قواعد إظهار وتعطيل الأزرار](#s26)
27. [صلاحيات وخصوصية المستخدم](#s27)
28. [سيناريوهات End-to-End](#s28)
29. [توصيات UI/UX المبنية على النظام]
30. [قائمة التسليم لمهندسة الـUI](#s30)
31. [الملحق التقني](#s31)
32. [خارج نطاق الإصدار الحالي](#s32)

---

<a id="s1"></a>

## 1. ملخص تجربة المزاد للمستخدم

النظام يخدم دورين داخل نفس التطبيق ونفس الـtoken: **المزايد** و**البائع**. كلاهما يستخدم مجموعة الـroutes ذاتها تحت `role:admin,user` في `routes/api/auction.php`، والتمييز بينهما يتم بالبيانات لا بالصلاحيات.

### 1.1 رحلة المزايد

**الاكتشاف.** المستخدم يفتح قائمة المزادات عبر `GET /api/auctions`. القائمة تعرض المزادات المرئية للعامة فقط — ثماني حالات محددة في `AuctionStatus::isPubliclyVisible()`: `scheduled`, `live`, `ended`, `settlement_pending`, `payment_pending`, `handover_pending`, `completed`, `unsold`. المزادات في `draft`, `pending_review`, `rejected`, `awaiting_seller_deposit`, `cancelled`, `defaulted`, `disputed` لا تظهر إطلاقًا. **[منفذ]** — `Auction::scopePublic()`، `PublicAuctionQuery::paginate()`.

**فتح التفاصيل.** `GET /api/auctions/{auction}` حيث `{auction}` هو `public_id` (ULID من 26 حرفًا) وليس رقمًا. الـEndpoint يقبل الزائر غير المسجل عبر `OptionalSanctumAuthentication`، وكل فتح يسجَّل في `auction_views` و`auction_metrics` من خلال `AuctionMetricsRecorder::recordView()`. **[منفذ]**

**التسجيل.** `POST /api/soom/auctions/{auction}/register`. مسموح فقط عندما تكون حالة المزاد `scheduled` أو `live`، وممنوع على البائع نفسه. النتيجة سجل `AuctionParticipant` بحالة `registered`. لا توجد مراجعة إدارية ولا KYC ولا شروط أهلية أخرى. **[منفذ]** — `RegisterParticipantAction`, `AuctionPolicy::register()`.

**قبول الشروط.** `POST /api/soom/auctions/{auction}/accept-terms`. يتطلب أن يكون المستخدم مسجلًا مسبقًا. الشروط المقبولة ليست «الشروط النشطة الحالية» بل تحديدًا `terms_version_id` المخزّن داخل الـConfiguration Snapshot الخاص بالمزاد. **[منفذ]** — `AcceptAuctionTermsAction`.

**دفع العربون.** `POST /api/soom/auctions/{auction}/bidder-deposit` برفع صورة إيصال. الدفع يدوي بالكامل: المستخدم يحوّل المبلغ خارج التطبيق ثم يرفع إثباتًا، والإدارة تراجعه. لا توجد بوابة دفع إلكترونية ولا Webhook. **[منفذ]** — `SubmitPaymentSubmissionAction`, `PaymentMethod` وحيد في الـSeeder هو `manual_bank_transfer`.

**التأهيل.** عند اعتماد الإدارة للعربون يتحول `AuctionDeposit` إلى `held` ويتحول `AuctionParticipant` إلى `qualified`. هذه هي **اللحظة الوحيدة** في النظام كله التي يصبح فيها المستخدم مؤهلًا للمزايدة. **[منفذ]** — `ReviewPaymentSubmissionAction::approve()` سطر 177.

**المزايدة.** `POST /api/soom/auctions/{auction}/bids`. تتطلب حالة `live` + مشارك `qualified` + قبول شروط النسخة المطابقة للـsnapshot + عربون `held` بمبلغ كافٍ. **[منفذ]** — `PlaceBidAction`.

**متابعة أعلى سعر.** بث لحظي على قناة عامة `auction.{public_id}` بحدث `auction.bid_accepted`. **[منفذ]** — `AuctionRealtimeEvent`, `AuctionRealtimeBroadcaster`.

**الانتهاء.** مهمة مجدولة كل دقيقة تنهي المزادات المنتهية زمنيًا وتحدد الفائز وتنشئ `AuctionSettlement`. **[منفذ]** — `FinalizeExpiredAuctionsJob` → `FinalizeAuctionAction`.

**الفوز.** الفائز يدفع المتبقي عبر `POST /api/soom/auctions/{auction}/winner-payment` (نفس آلية رفع الإيصال)، ثم بعد اعتماد الإدارة ينتقل المزاد إلى `handover_pending`.

**التسليم.** البائع يؤكد التسليم أولًا (`confirm-handover`)، ثم الفائز يؤكد الاستلام (`confirm-receipt`). الترتيب إلزامي. عند تأكيد الفائز يصبح المزاد `completed` وتُنشأ مستحقات البائع. **[منفذ]**

**الخسارة والاسترداد.** عرابين غير الفائزين تُخطَّط للاسترداد حسب سياسة `non_winner_deposit_hold_policy` داخل الـsnapshot: إما فورًا، أو بحجز أعلى N مزايدين، أو بحجز كل المؤهلين حتى يدفع الفائز.

### 1.2 رحلة البائع

`POST /api/soom/auctions` لإنشاء مزاد بحالة `draft` مع رفع حتى 12 صورة. ثم `POST /{auction}/submit-review` لإرساله للمراجعة (`pending_review`). الإدارة توافق أو ترفض. عند الموافقة يُنشأ **Configuration Snapshot ثابت** للمزاد، وإذا كان عربون البائع أكبر من صفر ينتقل المزاد إلى `awaiting_seller_deposit`، وإلا مباشرة إلى `scheduled`. البائع يدفع عربونه عبر `POST /{auction}/seller-deposit`، وعند اعتماده يصبح المزاد `scheduled` ويُعلَن للعامة.

بعد اكتمال الصفقة تُنشأ `AuctionSellerPayout` بمبلغ `seller_net_amount_minor`، ويتابعها البائع عبر `GET /api/soom/my/payouts`. يجب أن يكون لديه `PayoutDestination` مسجلة، وإلا يصل الإشعار `seller_payout.awaiting_destination` ويتعطل الصرف.

### 1.3 ما هو غير موجود في هذه الرحلة

هذه ليست نواقص تصميمية بل غياب تنفيذي مؤكد، ويجب ألا تُصمَّم شاشات لها:

- لا يوجد Auto Bid ولا Maximum Bid ولا مزايدة بالوكالة.
- لا يوجد سحب/إلغاء مزايدة بعد تقديمها.
- لا يوجد انسحاب من التسجيل بعد إتمامه.
- لا يوجد استرداد جزئي بطلب المستخدم.
- لا يوجد نظام متابعة/مفضلة (`watchlist`) للمزادات.
- لا يوجد تعثر تلقائي عند انتهاء مهلة دفع الفائز — يتطلب إجراءً إداريًا يدويًا.
- لا توجد بوابة دفع إلكترونية؛ كل المدفوعات إيصالات يدوية تراجعها الإدارة.

---

<a id="s2"></a>

## 2. المصطلحات الأساسية

| المصطلح | الكيان في الكود | المعنى من منظور المستخدم |
|---|---|---|
| Auction | `App\Models\Auction\Auction` | المزاد. يُعرَّف خارجيًا بـ`public_id` (ULID) وليس بالرقم التسلسلي. |
| Seller | `auctions.seller_id` | مالك المزاد. ممنوع من التسجيل أو المزايدة على مزاده. |
| Participant | `App\Models\Auction\AuctionParticipant` | مستخدم سجّل في مزاد معيّن. له ثلاث حالات: `registered`, `qualified`, `blocked`. |
| Bidder | `auction_bids.bidder_id` | مشارك مؤهل قدّم مزايدة فعلية. |
| Bid | `App\Models\Auction\AuctionBid` | مزايدة مقبولة. لا توجد مزايدات «مرفوضة» مخزّنة — المرفوضة تُرجَع كخطأ 422 ولا تُكتب في قاعدة البيانات. |
| Current Leading Bid | `auctions.current_leading_bid_id` | أعلى مزايدة **أثناء** المزاد. تتغير مع كل مزايدة أعلى. |
| Winning Bid | `auctions.winning_bid_id` | المزايدة الفائزة **بعد** الإنهاء. تُضبط مرة واحدة في `FinalizeAuctionAction`. |
| Deposit | `App\Models\Auction\AuctionDeposit` | العربون. نوعان عبر العمود `type`: `seller` و`bidder`. له ثمانِ حالات وأربعة أرصدة منفصلة. |
| Payment Submission | `App\Models\Auction\PaymentSubmission` | طلب دفع يدوي مرفق بإيصال، ينتظر مراجعة الإدارة. ثلاث حالات فقط. |
| Payment Transaction | `App\Models\Auction\PaymentTransaction` | القيد المالي الفعلي الذي يُنشأ **عند الاعتماد فقط**، وليس عند الإرسال. |
| Refund | `App\Models\Auction\RefundTransaction` | عملية استرداد. ست حالات مع نظام محاولات وlease وtoken. |
| Settlement | `App\Models\Auction\AuctionSettlement` | تسوية الصفقة بعد تحديد الفائز: المبلغ الفائز، العربون المطبَّق، الرسوم، صافي البائع، المستحق والمدفوع والمتبقي. |
| Current Settlement | `is_current = true` و`current_marker = 1` | التسوية السارية. قيد فريد في قاعدة البيانات يمنع وجود اثنتين. تسويات سابقة تبقى للتاريخ. |
| Winner | `auction_settlements.winner_id` | الفائز الحالي. قد يتغيّر إذا تعثر الفائز الأول وأُعيد التعيين. |
| Handover | `seller_handover_confirmed_at` / `buyer_receipt_confirmed_at` | تأكيد التسليم ثم الاستلام. خطوتان منفصلتان بترتيب ملزم. |
| Seller Payout | `App\Models\Auction\AuctionSellerPayout` | مستحقات البائع بعد اكتمال الصفقة. ست حالات. |
| Payout Destination | `App\Models\Auction\PayoutDestination` | وسيلة استلام المستحقات التي يسجلها البائع. |
| Default | `SettlementStatus::Defaulted` | تعثر الفائز عن السداد. لا يحدث تلقائيًا. |
| Dispute | `App\Models\Auction\AuctionDispute` | نزاع يفتحه البائع أو الفائز. حالتان: `open`, `resolved`. |
| Configuration Snapshot | `App\Models\Auction\AuctionConfigurationSnapshot` | نسخة إعدادات مجمّدة تُنشأ لحظة موافقة الإدارة، وتحكم المزاد حتى نهايته حتى لو تغيّرت إعدادات المنصة. |
| Terms Version | `App\Models\Auction\AuctionTermsVersion` | نسخة مرقّمة من الشروط. المزاد مرتبط بنسخة محددة عبر الـsnapshot. |
| Reserve Price | `auctions.reserve_amount_minor` | السعر الاحتياطي. اختياري (`null` يعني لا يوجد). |
| Minimum Increment | `snapshot.minimum_bid_increment_minor` | الحد الأدنى للزيادة فوق أعلى مزايدة. |
| Extension Window | `snapshot.auto_extend_window_seconds` | النافذة الزمنية قبل النهاية التي تُفعّل التمديد التلقائي (Anti-Sniping). |
| Payment Deadline | `auction_settlements.payment_due_at` | مهلة سداد الفائز. |
| Handover Deadline | `auction_settlements.handover_due_at` | مهلة التسليم. تُحتسب بعد اكتمال السداد. |
| Outbox | `App\Models\Auction\OutboxMessage` | طابور أحداث موثوق يغذي الإشعارات والبث اللحظي. |

### مصطلحات مطلوبة في تجربة المستخدم وغير موجودة كمفهوم في النظام

`Registration Deadline` — لا يوجد موعد نهائي للتسجيل كحقل مستقل؛ التسجيل يُغلق ضمنيًا بانتهاء المزاد لأن الحالة تخرج من `scheduled`/`live`. الواجهة تقرأه من `timeline.registration_deadline` المشتق.

`Deposit Deadline` **[استنتاج]** — لا يوجد حقل. المهلة الفعلية لعربون المزايد هي `auctions.ends_at` نفسه، مثبتة في `PaymentEligibilityRule::assertBidderDeadline()`. لا يوجد أي حقل صريح في الـAPI يخبر الواجهة بذلك.

`Grace Period` — فترة سماح بعد `payment_due_at` قبل التعثر الآلي. مجمّدة في اللقطة (`winner_payment_grace_period_minutes`) ومكشوفة كـ`timeline.winner_payment_grace_ends_at`.

</div>
<div dir="rtl" align="right">

---

<a id="s3"></a>

## 3. أنواع المستخدمين والأدوار

الأدوار في هذا النظام ليست أدوارًا في جدول صلاحيات، بل **علاقات بيانات** يُشتق منها ما يراه المستخدم. `users.role` يحمل قيمتين فقط تهمّان تطبيق المستخدم: `user` و`admin`. **[منفذ]** — `RoleMiddleware`, `AuctionPolicy`.

### 3.1 الزائر غير المسجل

يصل عبر `OptionalSanctumAuthentication` التي لا ترفض الطلب عند غياب الـtoken. الاختبار `PublicAuctionOptionalAuthTest` يؤكد أن **الـtoken غير الصالح أو المنتهي يُعامل كزائر تمامًا** ولا يعيد 401 على مسارات القراءة العامة.

- **يرى:** قائمة المزادات العامة، تفاصيل أي مزاد عام، سجل المزايدات مع هويات مجهولة، طرق الدفع، قائمة نسخ الشروط.
- **لا يستطيع:** التسجيل، قبول الشروط، الدفع، المزايدة، أو رؤية أي مزاد خارج الحالات الثماني العامة (يحصل على **404** لا 403 — `AuctionController::show()` يحوّل رفض الـGate إلى `auction_not_found`).
- **الانتقال:** تسجيل الدخول.

### 3.2 المستخدم المسجل غير المشارك

مصادَق لكن لا يملك `AuctionParticipant` في هذا المزاد.

- **يرى:** نفس محتوى الزائر. `AuctionController::show()` يستدعي `hasMyAuctionData()` التي ترجع `false` لغياب bids وdeposits وsettlement، فيحصل على `PublicAuctionResource` لا `MyAuctionResource`. **[منفذ]**
- **يستطيع:** `POST /{auction}/register` إذا كانت الحالة `scheduled` أو `live` وإذا لم يكن هو البائع.

### 3.3 المشارك المسجل غير المؤهل — `registered`

سجّل ولم يُعتمد عربونه بعد.

- **قيود:** المزايدة مرفوضة بـ`bidder_not_qualified`.
- **الرؤية:** مورد المزاد الموحّد `UserAuctionResource` يُرجع كتلة `my_participation` لكل مستخدم مصادَق، حتى لو كان مسجلًا فقط ولم يدفع بعد. الحقول `is_registered` و`participant_status` و`registered_at` و`terms_accepted_at` و`qualified_at` كلها حاضرة دائمًا، فالواجهة تميّز «سجّلت» عن «لم أسجّل» بعد إعادة تحميل الشاشة دون أي اشتقاق محلي.

### 3.4 المشارك المؤهل — `qualified`

عربونه `held` والمشارك `qualified`. هذا هو الدور الوحيد المسموح له بالمزايدة. الانتقال إليه يتم **حصريًا** عبر اعتماد الإدارة لعربون المزايد.

### 3.5 المشارك المحظور — `blocked`

الحالة موجودة في `AuctionParticipantStatus` ويعتمد عليها المنطق في ثلاثة مواضع: `PaymentEligibilityRule` يرفض دفعه بـ`participant_not_eligible`، و`PlanNonWinnerDepositRefundsAction` يعيد عربونه فورًا دون انتظار، و`AuctionParticipantResource` يعرض `blocked_at`/`block_reason` للإدارة فقط.

**[منفذ]** الحظر متاح إداريًا عبر `POST /admin/auctions/{auction}/participants/{participant}/block` و`.../unblock` بصلاحية `auction.participants.block` وبسبب موثق. لا يمكن حظر الفائز بعد إنشاء التسوية (`participant_block_winner_not_allowed`).

### 3.6 أعلى مزايد حالي

ليس دورًا مخزّنًا بل علاقة محسوبة: `auctions.current_leading_bid_id → auction_bids.bidder_id`. تتغير مع كل مزايدة أعلى.

**[منفذ]** `my_participation.is_highest_bidder` محسوب في الخادم من `current_leading_bid_id`. لا تقارني المبالغ يدويًا في الواجهة.

### 3.7 الفائز

`auction_settlements.winner_id` في التسوية الحالية. يرى كتلة `winner_settlement` داخل `MyAuctionResource`، ويستطيع `POST /{auction}/winner-payment` و`POST /{auction}/confirm-receipt` و`POST /{auction}/disputes`.

### 3.8 المستخدم الخاسر

شارك ولم يفز. لا يوجد كيان يمثله. **[استنتاج]** يُعرَف بأن لديه `my_bids` غير فارغة وأن `winner_settlement` غير موجودة في الاستجابة والمزاد في حالة نهائية.

### 3.9 المستخدم المتعثر

كان فائزًا ولم يسدد وأصدرت الإدارة قرار تعثر. تسويته تصبح `defaulted` و`is_current = false` و`current_marker = null`.

**[منفذ]** الأثر الوحيد المؤكد على المستخدم هو مصادرة العربون حسب `winner_default_deposit_policy` واستبعاده من ترشيح الفائز البديل في نفس المزاد (`MarkWinnerDefaultedAction::findEligibleAlternativeBid()`).
لا يوجد حظر تلقائي من مزادات مستقبلية ولا غرامة تتجاوز العربون ولا آلية استئناف — الحظر قرار إداري يدوي لكل مزاد على حدة.

### 3.10 البائع

`auctions.seller_id`. يستخدم نفس التطبيق. يرى `MyAuctionResource` بكتل إضافية: `seller_deposit`, `seller_payment_submissions`, `seller_financial_summary`, `handover_status`.

**قيود مؤكدة:** ممنوع من التسجيل في مزاده (`seller_cannot_register`) وممنوع من المزايدة عليه (`seller_cannot_bid`).

### 3.11 الإدارة — الأثر على تجربة المستخدم فقط

الإدارة ليست جمهورًا لهذه الوثيقة، لكن أربعة من إجراءاتها تُوقف رحلة المستخدم انتظارًا:

| الإجراء الإداري | ما يوقفه على المستخدم |
|---|---|
| مراجعة المزاد | البائع عالق في `pending_review` بلا مهلة معلنة. |
| اعتماد/رفض العربون | المزايد لا يستطيع المزايدة حتى الاعتماد. **هذا هو أخطر انتظار في الرحلة** لأنه يقع أثناء مزاد مباشر بعداد يعمل. |
| اعتماد دفعة الفائز | المزاد لا ينتقل إلى `handover_pending`. |
| تأكيد الاسترداد يدويًا | الخاسر ينتظر أمواله. |

**[منفذ]** مدة المراجعة المتوقعة مجمّدة في اللقطة (`review_sla_minutes`) ومكشوفة للواجهة. لا يوجد ترتيب في الطابور — وهو مقصود.

---

<a id="s4"></a>

## 4. خريطة دورة حياة المزاد

خمس عشرة حالة معرّفة في `App\Domain\Auction\Enums\AuctionStatus`، ومصفوفة الانتقالات المسموحة كاملة في `AuctionStateMachine::ALLOWED`. أي انتقال خارجها يرفع `AuctionException::invalidTransition()` برسالة `«لا يمكن نقل المزاد من:from إلى:to»` وحالة **422**. **[منفذ]**

### 4.1 جدول الحالات — التعريف والظهور

| الحالة | العربية المقترحة | الوصف | يظهر للمستخدم؟ |
|---|---|---|---|
| `draft` | مسودة | أُنشئ ولم يُرسل للمراجعة. | لا — للبائع فقط |
| `pending_review` | بانتظار المراجعة | بانتظار قرار الإدارة. | لا — للبائع فقط |
| `rejected` | مرفوض | رفضته الإدارة بسبب موثق. | لا — للبائع فقط |
| `awaiting_seller_deposit` | بانتظار تأمين البائع | مقبول وينتظر عربون البائع. | لا — للبائع فقط |
| `scheduled` | مجدول | منشور وينتظر موعد البداية. | نعم |
| `live` | مباشر | المزايدة مفتوحة. | نعم |
| `ended` | انتهى | انتهى الوقت ولم تكتمل المعالجة. | نعم |
| `settlement_pending` | بانتظار التسوية | حالة عبور تقنية أثناء إنشاء التسوية. | نعم |
| `payment_pending` | بانتظار دفع الفائز | الفائز محدد وعليه سداد المتبقي. | نعم |
| `handover_pending` | بانتظار التسليم | السداد اكتمل، دور التسليم والاستلام. | نعم |
| `completed` | مكتمل | الصفقة تمت. | نعم |
| `unsold` | لم يُبع | لا مزايدات أو لم يتحقق السعر الاحتياطي أو تعثر بلا بديل. | نعم |
| `cancelled` | ملغي | أُلغي مع معالجة مالية كاملة. | لا |
| `defaulted` | متعثر | حالة عبور فورية بعد قرار التعثر. | لا |
| `disputed` | متنازع عليه | حالة معرّفة في `ALLOWED` ولا يوجد كود ينقل إليها. | لا |

**ملاحظتان تؤثران على التصميم:**

`settlement_pending` و`defaulted` **حالتا عبور داخل نفس الـtransaction** — `FinalizeAuctionAction` ينتقل `ended → settlement_pending → payment_pending` في نفس القفل، و`MarkWinnerDefaultedAction` ينتقل `payment_pending → defaulted → unsold`. **[استنتاج]** الواجهة قد لا تلتقطهما أبدًا، لكن يجب أن تتعامل معهما بأمان إذا ظهرتا في استجابة (مثلًا انقطاع بين خطوتين). لا تصمَّم لهما شاشات مستقلة.

`disputed` **حالة ميتة عمليًا** — موجودة في مصفوفة الانتقالات لكن `OpenAuctionDisputeAction` لا يغيّر حالة المزاد إطلاقًا؛ النزاع يُخزَّن في جدول منفصل والمزاد يبقى في حالته. النتيجة: **لا يمكن للواجهة أن تعرف من `status` أن هناك نزاعًا مفتوحًا**، ولا يوجد حقل بديل يكشف ذلك للمستخدم.

### 4.2 جدول الحالات — الإجراءات المتاحة

| الحالة | تسجيل | عربون مزايد | مزايدة | دفع الفائز | الرسالة الأساسية |
|---|:--:|:--:|:--:|:--:|---|
| `scheduled` | نعم | نعم | لا | لا | «يبدأ خلال …» + دعوة للتسجيل وتجهيز العربون |
| `live` | نعم | نعم | نعم* | لا | «مباشر الآن» + العداد التنازلي |
| `ended` | لا | لا | لا | لا | «انتهى المزاد، جارٍ تحديد النتيجة» |
| `settlement_pending` | لا | لا | لا | لا | نفس رسالة `ended` |
| `payment_pending` | لا | لا | لا | الفائز فقط | للفائز: المبلغ والمهلة. لغيره: «تم البيع» |
| `handover_pending` | لا | لا | لا | لا | خطوات التسليم للطرفين |
| `completed` | لا | لا | لا | لا | «اكتملت الصفقة» |
| `unsold` | لا | لا | لا | لا | «انتهى دون بيع» + حالة استرداد العربون |

\* المزايدة في `live` مشروطة بأربعة شروط إضافية تُفصَّل في [القسم 11](#s11).

**نقطة تصميم مهمة:** التسجيل ودفع عربون المزايد مسموحان في `scheduled` **و**`live` معًا (`AuctionPolicy::register()` و`PaymentEligibilityRule::assertBidderDepositAuctionState()`). أي أن المستخدم قد يبدأ رحلة العربون كاملة والمزاد يعمل والعداد ينفد، وقد لا تعتمده الإدارة قبل النهاية. **[استنتاج]** هذا سيناريو واقعي جدًا ويجب أن تحذّر منه الواجهة صراحة.

### 4.3 مخطط الانتقالات

```text
draft ──► pending_review ──► rejected ──► draft
 │ │
 │ ├──► awaiting_seller_deposit ──► scheduled
 │ └──► scheduled
 │ (عربون بائع = 0)
 ▼
cancelled ◄── (من أي حالة تقريبًا)

scheduled ──► live ──► ended ──┬──► unsold
 └──► settlement_pending ──┬──► payment_pending
 └──► handover_pending
 (العربون يغطي المبلغ كاملًا)

payment_pending ──┬──► handover_pending ──► completed
 └──► defaulted ──┬──► payment_pending (فائز بديل)
 ├──► handover_pending (بديل مغطى بالعربون)
 └──► unsold (لا بديل)

disputed ──► payment_pending | handover_pending | completed | cancelled
 (حالة غير قابلة للوصول: لا كود ينقل إليها)
```

**انتقالات مؤكدة من `AuctionStateMachine::ALLOWED` تستحق الانتباه:**

- `rejected → draft` مسموح: البائع يعدّل ويعيد الإرسال. `AuctionPolicy::submitForReview()` يسمح من `draft` و`rejected`.
- `awaiting_seller_deposit → rejected` **غير مسموح**. الاختبار `test_auction_holding_a_seller_deposit_can_never_be_rejected` يثبت أن مزادًا يحتجز عربون بائع لا يمكن رفضه أبدًا — لهذا لا توجد سياسة `auction_rejected` في `seller_deposit_policy`.
- `completed` حالة نهائية مطلقة. `test_rejects_completed_auction` يثبت أن إلغاءها مرفوض بـ`auction_cancellation_not_allowed`.
- `unsold` نهائية أيضًا: لا انتقالات خارجة منها في `ALLOWED`.

### 4.4 الطوابع الزمنية المرتبطة بالانتقال

`AuctionStateMachine::transition()` يضبط طابعًا واحدًا حسب الحالة الهدف، ولا يعيد الكتابة إن كان موجودًا (`?? $now`):

| الحالة الهدف | الطابع |
|---|---|
| `scheduled` | `published_at` |
| `live` | `started_at` |
| `ended` | `ended_at` |
| `settlement_pending`, `payment_pending`, `unsold` | `finalized_at` |
| `completed` | `completed_at` |
| `cancelled` | `cancelled_at` |

**[منفذ]** الطوابع الستة مكشوفة في `UserAuctionResource.timeline` مع المواعيد المشتقة، فبناء Timeline كامل في تطبيق المستخدم متاح.

---

<a id="s5"></a>

## 5. حالات المستخدم داخل كل مزاد

هذا القسم يعرّف الحالات التي تحتاجها الواجهة، ويحدد لكل حالة ما إذا كانت **قابلة للاستنتاج من الـAPI الحالي** أم لا. العمود الأخير هو الأهم عمليًا.

### 5.1 مصدر الحقيقة المتاح للواجهة اليوم

كل ما تملكه الواجهة لتحديد حالة المستخدم هو استجابة `GET /api/auctions/{auction}` عندما تُرجَع كـ`MyAuctionResource`، وتحتوي على:

`my_bids[]` — مزايدات المستخدم في هذا المزاد.
`my_deposits[]` — عرابين المستخدم، كل عنصر فيه `type`, `status`, `required_amount`, `held_amount`, `applied_amount`, `refunded_amount`.
`my_payment_submissions[]` — طلبات الدفع، كل عنصر فيه `purpose`, `status`, `amount`, `submitted_at`, `reviewed_at`, `review_note`.
`my_refunds[]` — `id`, `status`, `amount`, `processed_at`, `succeeded_at`, `failed_at`, `cancelled_at`.
`winner_settlement` — تظهر فقط إذا كان المستخدم هو الفائز.

**لا يحتوي على:** حالة المشارك، ولا قبول الشروط، ولا علم بكون المستخدم أعلى مزايد.

### 5.2 جدول حالات المزايد

| # | الحالة | كيف تُستنتج اليوم | CTA الرئيسي |
|---|---|---|---|
| 1 | لم يسجل | الاستجابة `PublicAuctionResource` (لا مفاتيح `my_*`) | «سجّل في المزاد» |
| 2 | مسجل ولم يقبل الشروط | **غير قابلة للاستنتاج** | «اقرأ ووافق على الشروط» |
| 3 | قَبِل الشروط ولم يدفع | **غير قابلة للاستنتاج** | «ادفع العربون» |
| 4 | العربون قيد المراجعة | `my_deposits[bidder].status = pending_review` | لا CTA — انتظار |
| 5 | العربون مرفوض | `my_payment_submissions[bidder_deposit].status = rejected` | «أعد رفع الإيصال» |
| 6 | مؤهل للمزايدة | `my_deposits[bidder].status = held` | «قدّم مزايدة» |
| 7 | أعلى مزايد | `my_participation.is_highest_bidder` | «زد مزايدتك» (ثانوي) |
| 8 | تم تجاوزه | `my_participation.is_highest_bidder = false` | «زد مزايدتك» |
| 9 | فائز غير مسدد | `winner_settlement.status = payment_pending` | «ادفع المتبقي» |
| 10 | دفعة الفائز قيد المراجعة | `my_payment_submissions[winner_settlement].status = pending_review` | لا CTA — انتظار |
| 11 | فائز مسدد ينتظر التسليم | `winner_settlement.status = paid` والمزاد `handover_pending` | «أكّد الاستلام» (بعد تأكيد البائع) |
| 12 | خاسر | `my_bids` غير فارغة + المزاد نهائي + لا `winner_settlement` | «تابع استرداد عربونك» |
| 13 | عربونه محجوز كمرشح بديل | **غير قابلة للاستنتاج** — `hold_reason` غير مكشوف | لا CTA |
| 14 | استرداد مخطط | `my_deposits[bidder].status = refund_pending` | لا CTA — انتظار |
| 15 | استرداد قيد التنفيذ | `my_refunds[].status = processing` | لا CTA |
| 16 | تم الاسترداد | `my_deposits[bidder].status = refunded` | لا CTA |
| 17 | استرداد متعثر | `my_refunds[].status ∈ {failed, manual_review}` | «تواصل مع الدعم» |
| 18 | متعثر | `my_deposits[bidder].status = forfeited` | لا CTA |
| 19 | محظور | **غير قابلة للاستنتاج** | — |

### 5.3 تفصيل الحالات الحرجة

**الحالة 4 — العربون قيد المراجعة.** يصلها المستخدم فور رفع الإيصال. البيانات المعروضة: `amount`, `submitted_at`, `payment_method.name`. الإجراءات الممنوعة: إرسال طلب آخر لنفس العربون — الخادم يرفض بـ`active_payment_submission_exists`. الرسالة الواجبة: أن المراجعة يدوية وأن المزايدة مقفلة حتى الاعتماد. الحالة التالية: 5 أو 6.

**الحالة 5 — العربون مرفوض.** عند الرفض `ReviewPaymentSubmissionAction::reject()` يعيد الـdeposit إلى **`pending_submission`** لا إلى `rejected`. **[منفذ]** هذا يعني أن `my_deposits[].status` **لا يكشف الرفض إطلاقًا**؛ المصدر الوحيد هو `my_payment_submissions[].status = rejected` مع `review_note` الذي يحمل سبب الرفض ويكون مرئيًا لصاحب الطلب (`PaymentSubmissionResource` يعرضه عندما `$user->id === $this->user_id`). إعادة المحاولة مسموحة ومثبتة بالاختبار `test_rejected_deposit_submission_can_be_resubmitted_and_approved`، بشرط `idempotency_key` جديد.

**الحالة 9 — فائز غير مسدد.** البيانات: `winner_settlement.amount_due`, `amount_paid`, `remaining_amount`, `payment_due_at`. الإجراء الممنوع: دفع مبلغ مختلف — النظام يقبل **المبلغ المتبقي بالضبط فقط**، وأي زيادة تُرفض بـ`payment_amount_exceeds_remaining` وأي نقصان بـ`payment_amount_mismatch`. لا يوجد دفع جزئي في الواجهة رغم أن بنية البيانات تدعمه نظريًا.

**الحالة 13 — عربون محجوز كمرشح بديل.** عند سياسة `hold_all_eligible_bidders_until_winner_payment` أو `hold_top_n_bidders_until_winner_payment` يبقى عربون الخاسر بحالة `held` مع `hold_reason = 'alternative_winner_candidate'` حتى يسدد الفائز. **[منفذ]** — `PlanNonWinnerDepositRefundsAction::HOLD_REASON_ALTERNATIVE`. من منظور المستخدم: خسر المزاد ومع ذلك أمواله محتجزة لأجل غير مسمى.
**[منفذ]** `AuctionDepositResource` يعرض `hold_reason` و`hold_reason_label` و`hold_metadata` و`candidate_rank` و`expected_release_condition` و`hold_expires_at`، فالواجهة تشرح سبب الحجز وشرط الإفراج بدل ترك المستخدم بلا تفسير.

</div>
<div dir="rtl" align="right">

---

<a id="s6"></a>

## 6. خريطة الشاشات المطلوبة

الشاشات أدناه مشتقة من الـEndpoints المتاحة فعليًا. كل شاشة موسومة: **مؤكدة** (كل بياناتها متوفرة)، **مشروطة** (تعمل بنقص واضح)، **محجوبة** (تحتاج قرارًا أو Backend جديد قبل التصميم).

### 6.0 جدول الشاشات

| # | الشاشة | الحالة | الـEndpoint الأساسي |
|---|---|---|---|
| 1 | قائمة المزادات | مشروطة | `GET /api/auctions` |
| 2 | البحث والفلترة | محجوبة | — |
| 3 | تفاصيل المزاد | مؤكدة | `GET /api/auctions/{auction}` |
| 4 | معرض الوسائط | مؤكدة | ضمن التفاصيل |
| 5 | شروط المزاد | مشروطة | `GET /api/soom/auction-terms` |
| 6 | التسجيل في المزاد | مؤكدة | `POST.../register` |
| 7 | قبول الشروط | مشروطة | `POST.../accept-terms` |
| 8 | دفع العربون | مؤكدة | `POST.../bidder-deposit` |
| 9 | حالة مراجعة العربون | مؤكدة | ضمن التفاصيل |
| 10 | غرفة المزاد المباشر | مشروطة | تفاصيل + WebSocket |
| 11 | إدخال وتأكيد المزايدة | مؤكدة | `POST.../bids` |
| 12 | سجل المزايدات | مؤكدة | `GET /api/auctions/{auction}/bids` |
| 13 | مزايداتي | مؤكدة | `GET /api/soom/my/bids` |
| 14 | مزاداتي كبائع | مؤكدة | `GET /api/soom/my/auctions` |
| 15 | المزادات التي شاركت بها | محجوبة | — |
| 16 | المزادات التي فزت بها | محجوبة | — |
| 17 | المزادات التي أتابعها | محجوبة | — |
| 18 | نتيجة المزاد | مؤكدة | ضمن التفاصيل |
| 19 | دفع مستحق الفائز | مؤكدة | `POST.../winner-payment` |
| 20 | حالة الدفع | مؤكدة | ضمن التفاصيل |
| 21 | حالة الاسترداد | مشروطة | ضمن التفاصيل |
| 22 | تفاصيل التسوية والتسليم | مؤكدة | ضمن التفاصيل |
| 23 | الإشعارات | مؤكدة | `GET /api/notifications` |
| 24 | فتح نزاع | مشروطة | `POST.../disputes` |
| 25 | إنشاء مزاد (بائع) | مؤكدة | `POST /api/soom/auctions` |
| 26 | عربون البائع | مؤكدة | `POST.../seller-deposit` |
| 27 | تأكيد التسليم (بائع) | مؤكدة | `POST.../confirm-handover` |
| 28 | تأكيد الاستلام (فائز) | مؤكدة | `POST.../confirm-receipt` |
| 29 | مستحقاتي (بائع) | مؤكدة | `GET /api/soom/my/payouts` |
| 30 | وسائل استلام المستحقات | مؤكدة | `GET/POST /api/soom/my/payout-destinations` |
| 31 | عرض إيصال دفع | مؤكدة | `GET.../receipt-url` |
| 32 | الدعم | محجوبة | — |

### 6.1 شاشة قائمة المزادات

**الهدف:** الاكتشاف. **من يفتحها:** الجميع بما فيهم الزائر.

**الأقسام والحقول:** بطاقة لكل مزاد من `PublicAuctionResource`: `images[0].url`, `title`, `status` + `status_label`, `starting_amount`, `current_amount`, `ends_at`, `starts_at`, `category`.

**Loading:** هيكل عظمي بعدد `per_page` الافتراضي 20.
**Empty:** الاستجابة `data: []` مع `total: 0` — لا يوجد تمييز بين «لا مزادات» و«لا نتائج بحث» لأن البحث غير موجود أصلًا.
**Error:** غلاف الخطأ `{"success": false, "message": "..."}`.
**Offline:** ذاكرة مؤقتة مقبولة للقائمة، **غير مقبولة** للأسعار والعدادات.

**التحديث اللحظي:** قناة `public.auctions` تبث `auction.announcement` عند نشر مزاد جديد أو بدئه أو إلغائه قبل البداية. **[منفذ]** — `AuctionAnnouncementBroadcaster`. لا يوجد بث لتغيّر الأسعار على مستوى القائمة؛ تحديث الأسعار في القائمة يحتاج إعادة جلب.

**قيود يجب أن يعرفها التصميم:**
- `category`, `country`, `state`, `city`, `seller` **غائبة تمامًا من القائمة** لأن `whenLoaded` يحذف المفتاح ولا يضع `null`. `PublicAuctionQuery::LIST_RELATIONS` يحمّل `media, category, metric, currentLeadingBid` فقط — أي أن `category` موجودة و`country/state/city/seller` غير موجودة. لا تصمّمي بطاقة تعتمد على الموقع الجغرافي.
- `metrics` مكشوفة في `UserAuctionResource` للقائمة والتفاصيل: `views_count`, `unique_views_count`, `participants_count`, `bids_count`, `unique_bidders_count`. **[منفذ]**

### 6.2 شاشة تفاصيل المزاد

**الهدف:** كل ما يحتاجه المستخدم لاتخاذ قرار المشاركة ومتابعة موقفه.
**من يفتحها:** الجميع. الزائر يحصل على `PublicAuctionResource`؛ المستخدم ذو العلاقة يحصل على `MyAuctionResource`؛ الإداري يحصل على `AdminAuctionResource`.

**تحذير تكامل جوهري [منفذ]:** ثلاثة أشكال JSON مختلفة على نفس المسار. الفرق ليس إضافة مفاتيح فقط — مفتاح الوسائط اسمه `images` في `PublicAuctionResource` و`MyAuctionResource` واسمه **`media`** في `AdminAuctionResource`. يجب على التطبيق التعامل مع الشكلين الأول والثاني على الأقل، والتمييز بينهما بوجود أي من `my_bids`/`my_deposits`/`winner_settlement`/`seller_deposit`.

**الحالات:** تُشتق من `status` × حالة المستخدم من [القسم 5](#s5).
**Loading:** هيكل عظمي. **Error 404:** المزاد غير عام أو محذوف — نفس الرسالة في الحالتين.
**التحديث اللحظي:** قناة `auction.{public_id}` عندما تكون الحالة `live`.

### 6.3 غرفة المزاد المباشر

تُفصَّل بالكامل في [القسم 11](#s11). المحدد هنا: **مشروطة** لأن الحقول اللازمة لتحديد «أنا أعلى مزايد» و«حالتي مؤهل» غير مكشوفة.

### 6.4 شاشة سجل المزايدات

`GET /api/auctions/{auction}/bids`. **مؤكدة.** الترتيب من الخادم `ORDER BY amount_minor DESC, sequence_number ASC` — أي ترتيب بالقيمة لا بالزمن. Pagination عبر `per_page` (1–100، افتراضي 20) مع حقول `current_page`, `last_page`, `total`, `next_page_url` على مستوى الاستجابة لا داخل `meta`.

### 6.5 شاشة مزايداتي

`GET /api/soom/my/bids`. **مؤكدة.** ترتيب `latest('id')` أي الأحدث أولًا. تحمّل `auction.media` و`bidder`، فكل عنصر يحمل `auction_id` وبيانات المزايدة. **قيد:** العنصر يحتوي على `auction_id` فقط ولا يحتوي عنوان المزاد ولا صورته ولا حالته رغم تحميل العلاقة — `AuctionBidResource` يعرض `public_id` للمزاد فقط. لبناء قائمة مزايدات مفيدة استخدمي `GET /api/soom/my/participations` التي تحمل بيانات المزاد وحالة المشاركة معًا. **[منفذ]**

### 6.6 شاشة إنشاء مزاد — البائع

`POST /api/soom/auctions`، **مؤكدة**، `multipart/form-data`.

**الحقول الإلزامية:** `category_id`, `country_id`, `title` (≤180), `description` (≤10000), `currency_code` ∈ `JOD|EGP|USD`, `starting_amount`, `starts_at` (لاحق للآن)، `ends_at` (لاحق لـ`starts_at`).
**الاختيارية:** `state_id`, `city_id`, `reserve_amount`, `latitude`, `longitude`, `media[]`.
**الوسائط:** حتى 12 ملفًا، `jpg|jpeg|png|webp`، 5 ميغابايت للملف.
**قاعدة تحقق مخصصة:** `reserve_amount` لا يقل عن `starting_amount` وإلا خطأ على الحقل برسالة `auction.validation.reserve_below_starting`.

**ما لا يدخله البائع إطلاقًا [منفذ]:** `minimum_bid_increment`, `seller_deposit`, `bidder_deposit`, `platform_fee_*`, `winner_payment_deadline_hours`, `handover_deadline_hours`, `extension_*`. كلها تُنسخ من الإعدادات النشطة داخل `CreateAuctionAction`. لا تصمَّم لها حقول إدخال؛ تُعرض كمعلومات ثابتة فقط.

**نتيجة الإجراء:** 201 + `MyAuctionResource` بحالة `draft`. **ملاحظة:** الاستجابة تحمّل `bids/deposits/sellerDeposit/settlement` ولا تحمّل `media/category/country`، فالصور المرفوعة **لا تعود في استجابة الإنشاء** رغم حفظها. **[منفذ]** الواجهة تحتاج `GET` بعدها لعرض الصور.

### 6.7 شاشة مستحقاتي — البائع

`GET /api/soom/my/payouts` (**مؤكدة**) و`GET /api/soom/my/payouts/{sellerPayout}`. الشكل هو `SellerPayoutResource::sellerPayload()`: `id`, `status`, `auction{id,title}`, `amount`, `currency`, `has_destination`, `destination` بمعرّف **مقنّع** (`****1234`), `payout_method`, `transfer_reference`, `paid_at`, `created_at`.

`GET /my/payouts/{sellerPayout}/proof-url` يعيد `{url, expires_at}` لرابط مؤقت لإثبات التحويل، و**404** إذا لم يكن الإثبات موجودًا.

**فرق أمني يستحق التوثيق [منفذ]:** `SellerPayoutResource::sellerPayload()` يقنّع `identifier_value`، بينما `PayoutDestinationController::payload()` على `GET /my/payout-destinations` يعيده **غير مقنّع**. الواجهة يجب أن تقنّع العرض في الحالتين للاتساق.

### 6.8 شاشة وسائل استلام المستحقات

`GET/POST /api/soom/my/payout-destinations`، `PUT /{payoutDestination}`. **مؤكدة.**
الحقول: `recipient_name` (مطلوب ≤120)، `identifier_type` (مطلوب، من `PaymentMethod::IDENTIFIER_TYPES`)، `identifier_value` (مطلوب ≤160)، `is_default` (اختياري).
**لا يوجد Endpoint للحذف.** قيد `uq_payout_destination_default` على `(user_id, default_marker)` يضمن وسيلة افتراضية واحدة.

### 6.9 شاشة الإشعارات

`GET /api/notifications` من `routes/api/user.php` — ليست ضمن ملف المزادات لكنها الوجهة الفعلية لكل إشعارات المزادات. تُكمَّل بـ`POST /api/notifications/mark-all-as-read` و`PUT /api/notifications/{id}/read`. التوجيه داخل التطبيق يتم عبر حقل `screen` في بيانات الإشعار. تفاصيل الكتالوج في [القسم 24](#s24).

### 6.10 الشاشات المحجوبة

| الشاشة | سبب الحجب |
|---|---|
| البحث والفلترة | لا يوجد أي بارامتر بحث أو فلترة أو ترتيب على `GET /api/auctions` عدا `category_id`. |
| مزاداتي كمزايد / فزت بها | `/my/auctions` يستعلم `where('seller_id',...)` فقط. لا يوجد Endpoint لمزادات المشاركة. |
| المزادات التي أتابعها | لا يوجد جدول ولا Endpoint للمتابعة. |
| الدعم / النزاع الحر | `POST /{auction}/disputes` متاح للبائع والفائز فقط وفي أربع حالات فقط، وليس قناة دعم عامة. |

---

<a id="s7"></a>

## 7. مواصفات قائمة المزادات

### 7.1 أنواع القوائم الموجودة فعليًا

ثلاث قوائم فقط في تطبيق المستخدم:

| القائمة | الـEndpoint | المحتوى |
|---|---|---|
| كل المزادات العامة | `GET /api/auctions` | الحالات الثماني العامة مجتمعة، `latest('id')` |
| مزاداتي كبائع | `GET /api/soom/my/auctions` | `where('seller_id', me)` بكل الحالات بما فيها `draft` |
| مزايداتي | `GET /api/soom/my/bids` | قائمة مزايدات لا قائمة مزادات |

**[منفذ]** الفلترة على الخادم: `phase` (`live` / `upcoming` / `finished`) و`status` و`category_id` و`currency` و`search` و`sort`. **لا تفلتري على العميل بعد الـPagination إطلاقًا** — النتيجة ستكون خاطئة.

### 7.2 الترقيم

`per_page` بين 1 و100، افتراضي 20 (`AuctionIndexRequest::perPage()`). شكل الاستجابة من `ApiResponseTrait`:

```json
{
 "success": true,
 "message": "تم جلب المزادات.",
 "data": [... ],
 "current_page": 1,
 "last_page": 5,
 "per_page": 20,
 "total": 93,
 "next_page_url": "...",
 "prev_page_url": null
}
```

الحقول أشقاء لـ`data` ولا توجد كائنات `meta` أو `links`.

### 7.3 البحث والفلترة والترتيب

| القدرة | الحالة |
|---|---|
| `category_id` | **[منفذ]** الفلتر الوحيد العامل |
| `per_page` | **[منفذ]** |
| `status` | **[منفذ]** مقروء ومطبَّق على الخادم عبر `AuctionIndexRequest::filters()` |
| بحث نصي | غير موجود |
| نطاق سعر / عملة / موقع | غير موجود |
| ترتيب | غير موجود — ثابت `latest('id')` |
| فلترة بأهلية المستخدم أو حالة عربونه | غير موجودة |

المرجع المقابل: `AdminAuctionIndexRequest` يدعم `status`, `q`, `seller_id`, `category_id`, نطاقات تواريخ، `sort` ∈ `created_at|starts_at|ends_at`, و`direction`. القدرة موجودة في الكود لكنها **مقصورة على مسار الإدارة**. **[منفذ]**

### 7.4 حقول Auction Card — المتاح مقابل المطلوب

| الحقل | متاح في `GET /api/auctions`؟ | الملاحظة |
|---|---|---|
| الصورة | نعم | `images[]`، اختاري `is_primary` وإلا الأولى |
| الاسم | نعم | `title` |
| الرقم المرجعي | نعم | `id` = `public_id` (ULID 26 حرفًا، غير مناسب للعرض) |
| التصنيف | نعم | `category.name` |
| السعر الابتدائي | نعم | `starting_amount` |
| أعلى مزايدة | نعم | `current_amount` — يساوي `starting_amount` عند غياب مزايدات |
| الحد الأدنى للمزايدة التالية | نعم | `minimum_next_bid` |
| العملة | نعم | `currency_code` + داخل كل كائن Money |
| وقت البداية والنهاية | نعم | `starts_at`, `ends_at` بصيغة ISO 8601 |
| حالة المزاد | نعم | `status` + `status_label` مترجم |
| التمديد | نعم | `extension.count`, `extension.last_extended_at` |
| عدد المزايدات | **نعم** | `metrics.bids_count` |
| عدد المشاركين | **نعم** | `metrics.participants_count` |
| قيمة العربون | **نعم** | `bidder_deposit_required` من اللقطة |
| حالة المستخدم | **نعم** | `my_participation` + `next_action` |
| شارة الفوز / أعلى مزايد | **نعم** | `my_participation.is_highest_bidder` / `is_winner` |
| اسم البائع | **لا** | مخفي عمدًا عن المزايدين — قرار خصوصية |
| السعر الاحتياطي | **لا** | مخفي عمدًا — سلوك صحيح، مثبت باختبار الخصوصية |

**أثر تصميمي:** بطاقة القائمة تحمل `my_participation` و`next_action` لكل مستخدم مصادَق، فزر الإجراء يمكن أن يكون ذكيًا: «أكمل التأهيل» أو «تابع مزايدتك» أو «ادفع المتبقي» تُشتق من `next_action.code` مباشرة. **القاعدة:** لا تشتقي الزر من الحالة يدويًا في الواجهة — `next_action` هو المصدر الوحيد.

**ملاحظة على `minimum_next_bid` [منفذ]:** عند غياب مزايدة قائدة يساوي `starting_amount` وليس `starting_amount + increment`. وهذا مطابق لمنطق `PlaceBidAction` الذي يقبل أول مزايدة عند سعر البداية تمامًا.

---

<a id="s8"></a>

## 8. مواصفات شاشة تفاصيل المزاد

### 8.1 البيانات العامة — يراها الجميع بلا تسجيل دخول

من `PublicAuctionResource` بعد `PublicAuctionQuery::loadDetails()`:

| المجموعة | الحقول |
|---|---|
| التعريف | `id`, `title`, `description`, `status`, `status_label` |
| المالية | `currency_code`, `starting_amount`, `current_amount`, `minimum_next_bid` |
| الزمن | `starts_at`, `ends_at`, `extension.count`, `extension.last_extended_at` |
| الوسائط | `images[]`: `id`, `url`, `mime_type`, `sort_order`, `is_primary` |
| التصنيف والموقع | `category{id,name}`, `country{id,name}`, `state`, `city` (الأخيران قد يكونان `null`) |

`seller` **غير محمّلة** في `DETAILS_RELATIONS` فالمفتاح غائب دائمًا على المسار العام. **[منفذ]**

كل مبلغ يأتي ككائن موحّد:

```json
{ "amount": "1250.500", "minor": 1250500, "currency": "JOD" }
```

### 8.2 بيانات المستخدم ذي العلاقة

تُضاف من `MyAuctionResource` عند وجود بيانات للمستخدم في المزاد: `my_bids[]`, `my_deposits[]`, `my_payment_submissions[]`, `my_refunds[]`، و`winner_settlement` للفائز، و`seller_deposit` + `seller_payment_submissions` + `seller_financial_summary` + `handover_status` للبائع.

`handover_status` يحتوي: `payment_due_at`, `handover_due_at`, `seller_handover_confirmed_at`, `buyer_receipt_confirmed_at`, `handover_completed_at`.
`seller_financial_summary` يحتوي: `status`, `winning_amount`, `seller_net_amount`, `platform_fee`, `completed_at`, `payout`.
`winner_settlement` يحتوي: `status`, `amount_due`, `amount_paid`, `remaining_amount`, `payment_due_at`.

### 8.3 ما هو مخفي عمدًا ويجب ألا يظهر

مثبت باختبارات الخصوصية في `AuctionFinancialFlowTest` و`AuctionNotificationDispatchTest`:

- `reserve_amount` و`reserve_amount_minor` — للإدارة فقط. الاختبار يتحقق أن قيمة السعر الاحتياطي لا تظهر حتى داخل حمولة البث العام.
- `seller_id` الرقمي.
- هويات المزايدين الآخرين — تُستبدل بـ`{"anonymous": true}`.
- `receipt_path` ومسارات الملفات الداخلية.
- `ip_hash` في سجل النشاط.
- `provider_reference` و`transaction` في `PaymentSubmissionResource` — للمراجع فقط.
- بيانات `PayoutDestination` لأي مستخدم آخر.

### 8.4 بيانات تحتاجها الشاشة وغير موجودة

هذه أهم قائمة في الوثيقة من منظور التصميم:

| المطلوب | الوضع |
|---|---|
| قيمة عربون المزايد | **[منفذ]** مكشوفة قبل التسجيل من لقطة المزاد، فالمستخدم يعرف المبلغ قبل إرسال الطلب. |
| الرسوم والعمولة | مخفية عن المزايد عمدًا (تُخصم من البائع). للبائع تظهر في `seller_financial_summary.platform_fee`. |
| ضريبة القيمة المضافة | **غير موجودة في النظام إطلاقًا** — لا عمود ولا حساب ولا حقل. |
| الحد الأدنى للزيادة كقيمة مستقلة | مكشوف كـ`minimum_bid_increment` بجانب `minimum_next_bid` الجاهز. |
| سياسة التمديد | كتلة `timeline.extension` تحمل `window_seconds` و`duration_seconds` و`maximum` و`count` و`last_extended_at`، فشرح Anti-Sniping ممكن. |
| مهلة دفع الفائز كسياسة | مكشوفة من اللقطة قبل المشاركة، وكتاريخ محدد (`winner_payment_due_at`) بعد الفوز. |
| سياسة الاسترداد والتعثر | مكشوفتان عبر `expected_release_condition` و`refund_eligibility` على العربون وفترة السماح في الـtimeline. |
| نص شروط المزاد المرتبط | **[منفذ]** `GET /api/auctions/{auction}/terms` يعيد النص الكامل ورقم الإصدار للمستخدم. |
| بيانات البائع | مخفية عن المزايدين عمدًا — لا اسم ولا تقييم ولا عدد مزادات سابقة. خارج نطاق الإصدار. |
| Timeline للأحداث | **[منفذ]** كتلة `timeline` كاملة مع المواعيد المشتقة. |
| عدد المزايدات والمشاهدات | **[منفذ]** كتلة `metrics`. |

</div>
<div dir="rtl" align="right">

---

<a id="s9"></a>

## 9. التسجيل والأهلية للمشاركة

### 9.1 الشروط الفعلية

| السؤال | الجواب من الكود |
|---|---|
| هل يحتاج تسجيل دخول؟ | نعم. `auth:sanctum` + `role:admin,user`. |
| هل توجد شروط أهلية؟ | لا شيء عدا **ألا يكون البائع** وأن تكون الحالة `scheduled` أو `live`. |
| هل يوجد KYC؟ | لا. لا يُقرأ أي حقل من ملف المستخدم. |
| هل توجد موافقة إدارية على التسجيل؟ | لا. التسجيل فوري ويعيد 201. |
| هل يجب قبول الشروط؟ | نعم للمزايدة، لا للتسجيل. خطوة منفصلة. |
| هل الشروط مرتبطة بإصدار محدد؟ | نعم — `snapshot.terms_version_id` حصرًا. |
| هل يوجد موعد نهائي للتسجيل؟ | لا كحقل. ضمنيًا بخروج المزاد من `scheduled`/`live`. |
| هل يمكن الانسحاب؟ | **لا.** لا يوجد Endpoint. |
| هل يمكن إعادة التقديم بعد الرفض؟ | لا يوجد رفض للتسجيل أصلًا. |
| هل التسجيل Idempotent؟ | نعم. `firstOrCreateParticipant` + قيد `uq_auction_participant_user`. |

### 9.2 التدفق الطبيعي

1. المستخدم يفتح التفاصيل ويضغط «سجّل في المزاد».
2. `POST /api/soom/auctions/{auction}/register` بلا body.
3. `AuctionPolicy::register()` تتحقق: ليس البائع + الحالة ∈ `{scheduled, live}`.
4. داخل transaction مع `lockForStateChange` يُعاد التحقق ثم `firstOrCreateParticipant`.
5. تحديث `auction_metrics.participants_count`، سجل نشاط، ورسالة outbox `auction.participant_registered` **فقط عند الإنشاء الحقيقي**.
6. **201** + `AuctionParticipantResource`: `id`, `status: "registered"`, `registered_at`, `qualified_at: null`.
7. إشعار شخصي «تم تسجيلك في المزاد» موجّه إلى شاشة `auction_payment`.

### 9.3 التدفقات البديلة

| السيناريو | الاستجابة | ما تعرضه الواجهة |
|---|---|---|
| البائع يحاول التسجيل | **403** `Forbidden.` من الـPolicy | إخفاء الزر أصلًا للبائع |
| المزاد `ended` أو أي حالة أخرى | **403** من الـPolicy | «انتهى وقت التسجيل» + إعادة تحميل |
| تغيّرت الحالة بين فتح الشاشة والضغط | **403** أو **422** `registration_closed` | نفس المعالجة |
| تكرار الطلب (ضغط مزدوج) | **201** ونفس `participant.id` | لا خطأ؛ نتيجة متطابقة |
| المزاد غير عام | **404** | «المزاد غير متاح» |

**تفصيل تقني يفسر ازدواج الأخطاء [استنتاج]:** الحارس مطبق مرتين — في `AuctionPolicy::register()` (يعطي **403**) وداخل `RegisterParticipantAction` بعد أخذ القفل (يعطي **422** `registration_closed`). في سباق نادر بين الفحصين ستتلقى الواجهة 422 بدل 403 لنفس السبب الجوهري. **يجب معالجة الرمزين بنفس الرسالة.**

### 9.4 قبول الشروط

`POST /api/soom/auctions/{auction}/accept-terms`، بلا body.

**متطلب مسبق:** أن يكون مسجلًا، وإلا **422** `terms_registration_required`.
**النسخة المقبولة:** `snapshot.terms_version_id` وليس النسخة النشطة الحالية. الاختبار `test_terms_version_required_by_snapshot_controls_bidding` يثبت أن قبول نسخة نشطة **مختلفة** لا يفتح المزايدة.
**Idempotent:** قيد `uq_auction_terms_acceptance` على `(auction_id, user_id, terms_version_id)`.
**يُخزَّن:** `ip_hash` (SHA-256 مع `app.key`) و`user_agent` مقتطعًا عند 500 حرف — كلاهما غير مكشوف في أي API.
**الاستجابة:** **201** + `{"id": "<ULID>"}` فقط.

**[منفذ]** يستطيع التطبيق الآن:
1. قراءة نص الشروط — `GET /api/soom/auction-terms` بلا `body`، والنص الكامل خلف `role:admin`.
2. معرفة أي نسخة يجب قبولها — `terms_version_id` غير مكشوف في أي مورد عام.
3. معرفة ما إذا كان المستخدم قد قبل بالفعل — لا حقل ولا Endpoint.

نص الشروط متاح عبر `GET /api/auctions/{id}/terms`، فشاشة الشروط قابلة للتصميم بالكامل: العنوان، النص، رقم الإصدار، وحالة القبول من `my_participation.terms_accepted_at`.

### 9.5 المزادات بعربون صفري

المزاد الذي إعداداته `bidder_deposit_minor = 0` يعمل بمسار تأهيل مستقل عن الدفع:

1. `ParticipantQualifier` يؤهّل المشارك تلقائيًا لحظة قبول الشروط عندما يكون `snapshot.bidder_deposit_required_minor = 0`، فلا حاجة إلى طلب دفع.
2. `PaymentEligibilityRule::assertCanSubmitBidderDeposit()` تظل ترمي `zero_deposit_not_required` إذا حاول المستخدم إرسال عربون لا يلزمه — وهذا سلوك صحيح ومقصود.
3. `PlaceBidAction` يقبل المشارك المؤهَّل مباشرة، ولا يشترط وجود عربون `held` حين لا يكون العربون مطلوبًا أصلًا.

**أثر تصميمي:** في هذه المزادات تختفي خطوة العربون من الرحلة تمامًا. `next_action` ينتقل من «اقبل الشروط» إلى «زايد الآن» دون خطوة دفع وسيطة، ولا يجوز أن تعرض الواجهة بطاقة عربون فارغة. لا تُنشئ أي سجل عربون أو دفع في هذه الحالة.

---

<a id="s10"></a>

## 10. العربون

### 10.1 القواعد الأساسية

| السؤال | الجواب |
|---|---|
| متى يكون مطلوبًا؟ | عربون البائع: عند الموافقة إذا كان `seller_deposit_required_minor > 0`. عربون المزايد: دائمًا كشرط للمزايدة. |
| كيف تُحدد قيمته؟ | **مبلغ ثابت** بالوحدة الصغرى. **لا توجد نِسَب** في أي مكان. |
| المصدر | `snapshot.seller_deposit_required_minor` و`snapshot.bidder_deposit_required_minor`، منسوخان من `auctions.seller_deposit_amount_minor` و`bidder_deposit_amount_minor`، المنسوخين بدورهما من الإعدادات النشطة لحظة الإنشاء. |
| حد أدنى/أقصى | لا يوجد. |
| هل يختلف بين المزادات؟ | نعم — كل مزاد يحمل قيمته المجمّدة. **لا تعتمد الواجهة على إعدادات عامة.** |
| العملة | عملة المزاد؛ عدم التطابق يُرفض بـ`payment_currency_mismatch`. |
| طرق الدفع | من `GET /api/soom/payment-methods`. الـSeeder يوفّر `manual_bank_transfer` وحده. |
| المبلغ المقبول | **المبلغ المطلوب بالضبط.** لا زيادة ولا نقصان ولا دفع جزئي. |

### 10.2 حالات العربون الثماني

| الحالة | المعنى | كيف يدخلها | ما يعرضه التطبيق |
|---|---|---|---|
| `pending_submission` | التزام قائم بلا إيصال | إنشاء السجل، أو **بعد رفض إيصال** | «مطلوب دفع عربون» + زر الرفع |
| `pending_review` | إيصال مرفوع | `SubmitPaymentSubmissionAction` | «قيد المراجعة» بلا CTA |
| `held` | معتمد ومحتجز | اعتماد الإدارة | «العربون معتمد» + فتح المزايدة |
| `rejected` | مغلق دون تحصيل | `PlanNonWinnerDepositRefundsAction` لعربون غير مدفوع في مزاد منتهٍ | «انتهى المزاد» |
| `applied_to_settlement` | خُصم من قيمة الصفقة | `FinalizeAuctionAction` للفائز | ضمن ملخص مبلغ الفائز |
| `refund_pending` | استرداد مخطط | تخطيط الاسترداد | «جارٍ رد العربون» |
| `refunded` | رُدّ فعليًا | نجاح الاسترداد | «تم رد العربون» |
| `forfeited` | مصادَر | تعثر الفائز أو مخالفة البائع | «تمت مصادرة العربون» + السبب |

**ملاحظة تصميمية حاسمة:** `rejected` **ليست حالة رفض الإيصال**. رفض الإيصال يعيد العربون إلى `pending_submission`. حالة `rejected` تعني «التزام أُغلق دون أن يُدفع». الخلط بينهما سينتج رسالة خاطئة تمامًا. مصدر الحقيقة لرفض الإيصال هو `my_payment_submissions[].status = rejected` مع `review_note`.

### 10.3 الأرصدة الأربعة

كل سجل عربون يحمل أربعة مبالغ منفصلة، وقيد قاعدة بيانات يضمن أن مجموعها لا يتجاوز المطلوب:

`chk_deposit_amounts CHECK (held + applied + refunded + forfeited <= required)`

| الرصيد | المعنى |
|---|---|
| `required_amount_minor` | المطلوب |
| `held_amount_minor` | المحتجز حاليًا |
| `applied_amount_minor` | المطبَّق على التسوية |
| `refunded_amount_minor` | المسترد فعليًا |
| `forfeited_amount_minor` | المصادَر |

`AuctionDepositResource` يعرض الخمسة بما فيها `forfeited_amount`، فعند المصادرة يرى المستخدم المبلغ المصادَر وسببه. **[منفذ]**

### 10.4 دورة الدفع

**الإرسال:** `POST /{auction}/bidder-deposit` أو `/seller-deposit`، `multipart/form-data`:

| الحقل | القاعدة |
|---|---|
| `payment_method_id` | مطلوب، `size:26`، موجود في `payment_methods.public_id` |
| `receipt` | مطلوب، `jpg,jpeg,png,pdf,webp`، ≤5MB |
| `idempotency_key` | **مطلوب**، ≤120 حرفًا |
| `provider_reference` | اختياري ≤160 |

الإيصال يُخزَّن على قرص خاص `spaces_private` تحت `auction-payments/{auction_public_id}`، ويُحذف تلقائيًا عند أي فشل أو عند اكتشاف تكرار.

**المراجعة:** يدوية إدارية عبر `POST /admin/auctions/payment-submissions/{id}/review`. لا مراجعة آلية ولا Webhook ولا بوابة.

**الاعتماد:** ينشئ `PaymentTransaction` بحالة `succeeded` مع `successful_obligation_key` فريد على مستوى قاعدة البيانات — هذا القيد هو ما يمنع تحصيل نفس الالتزام مرتين. الأثر:

- عربون البائع → `held` + انتقال المزاد إلى `scheduled`.
- عربون المزايد → `held` + المشارك `qualified`.
- دفعة الفائز → تحديث التسوية، وعند اكتمال السداد انتقال إلى `handover_pending`.

**الرفض:** يتطلب `note` (`required_if:action,reject`)، والعربون يعود إلى `pending_submission`، ويصل إشعار `payment_rejected.{purpose}` يحمل السبب.

### 10.5 Idempotency ومنع الخصم المكرر

| الطبقة | الآلية |
|---|---|
| بارامتر الطلب | `idempotency_key` إلزامي في body (**لا يوجد header**) |
| فحص مسبق | `findSubmissionByIdempotencyKey` قبل رفع الملف |
| قيد قاعدة بيانات | `uq_payment_submission_idempotency` على `(auction_id, user_id, purpose, idempotency_key)` |
| منع الطلب المتوازي | `active_payment_submission_exists` عند وجود طلب `pending_review` |
| منع التحصيل المكرر | `uq_payment_successful_obligation` على `payment_transactions.successful_obligation_key` |
| منع مرجع مزود مكرر | `uniq_provider_txn` → `duplicate_provider_transaction` |

**فخ تكامل [منفذ]:** عند تكرار المفتاح تُعاد **201** بنفس السجل، **لكن** الطلب المعاد يُرجَع بدون `->load(['paymentMethod',...])` في مسار الفحص المسبق، فمفتاح `payment_method` **يغيب من الاستجابة** بينما يوجد في الإرسال الأول. يجب ألا يفترض التطبيق وجوده.

### 10.6 المواعيد النهائية

**عربون المزايد:** المهلة هي `auctions.ends_at`. **[منفذ]** — `PaymentEligibilityRule::assertBidderDeadline()`، ومثبت بالاختبار `test_bidder_deposit_deadline_is_auction_end_and_override_only_bypasses_deadline`. بعده يُرفض بـ`payment_deadline_expired` ما لم يتجاوزها إداري يملك `auction.payment.override_deadline` مع سبب موثق.
**عربون البائع:** له مهلة صريحة `seller_deposit_due_at` تبدأ من لحظة اعتماد المزاد ومدتها مجمّدة في اللقطة. عند انتهائها دون دفع أو طلب قيد المراجعة يُلغى المزاد آليًا بسبب `seller_deposit_deadline_expired` ولا يُنشر. **[منفذ]**
**دفعة الفائز:** `settlement.payment_due_at`.

### 10.7 مصير العربون في كل نهاية

| السيناريو | عربون الفائز | عرابين الخاسرين | عربون البائع |
|---|---|---|---|
| بيع مكتمل | `applied_to_settlement`، والفائض يُسترد | حسب السياسة | `refund` (افتراضي `completed`) |
| لم يُبع | — | `refund_pending` للمدفوع، `rejected` لغير المدفوع | `refund` |
| تعثر الفائز | `forfeited` حسب `winner_default_deposit_policy` | حسب سياسة الحجز | `keep_held` (افتراضي) |
| إلغاء | استرداد | استرداد | حسب `seller_deposit_policy` والمسؤولية |

**سياسات حجز عرابين الخاسرين** — `NonWinnerDepositHoldPolicy` داخل الـsnapshot:

| السياسة | السلوك |
|---|---|
| `refund_all_non_winners_immediately` | استرداد فوري عند الإنهاء |
| `hold_top_n_bidders_until_winner_payment` | حجز أعلى N مرشحين مع `hold_metadata.candidate_rank` |
| `hold_all_eligible_bidders_until_winner_payment` | حجز كل مؤهل حتى يسدد الفائز (**الافتراضي في الـSeeder**) |

في السياستين الأخيرتين يبقى العربون `held` مع `hold_reason = 'alternative_winner_candidate'`. المشارك `blocked` يُستثنى ويُسترد فورًا. **[منفذ]** — مثبت بأربعة اختبارات في `NonWinnerDepositReleaseTest`.

### 10.8 فائض عربون الفائز

عندما يتجاوز العربون قيمة المزايدة الفائزة: `depositApplied = min(held, winningAmount)` والفائض يولّد `RefundTransaction` بمفتاح `auction:{id}:winner-deposit-excess:{depositId}`. عند التغطية التامة `test_exact_deposit_coverage_does_not_create_excess_refund` يثبت عدم إنشاء استرداد.

---

<a id="s11"></a>

## 11. غرفة المزاد والمزايدة المباشرة

### 11.1 الدخول وما قبل البداية

لا توجد «غرفة» ككيان منفصل — الغرفة هي شاشة التفاصيل عندما تكون الحالة `live`. لا يوجد Endpoint دخول ولا حضور ولا عدد متصلين.

الدخول قبل البداية مسموح: المزاد ظاهر في `scheduled`. يرى المستخدم `starts_at` والعداد وسعر البداية، ويمكنه التسجيل ودفع العربون. لا يوجد بث لحظي مفيد قبل البداية.

**البداية:** مهمة مجدولة `auction:run-operations` كل دقيقة → `StartDueAuctionsJob` → `StartDueAuctionsAction` التي تأخذ المزادات `scheduled` ذات `starts_at <= now` وتنقلها إلى `live`. **[منفذ]**

**نتيجة تصميمية مهمة:** المزاد لا يبدأ في اللحظة المعلنة بل **خلال دقيقة منها**. لا تعرض الواجهة «بدأ» بمجرد وصول العداد إلى الصفر؛ تنتظر إما حدث البث `auction.status_changed` أو إعادة جلب.

### 11.2 البث اللحظي

| الجانب | التنفيذ |
|---|---|
| التقنية | Laravel Broadcasting، `ShouldBroadcastNow` (بث متزامن بلا طابور) |
| قناة المزاد | `auction.{public_id}` — قناة **عامة** غير مصادَقة |
| قناة الإعلانات | `public.auctions` |
| المصادقة | `POST /api/broadcasting/auth` موجود لقنوات المستخدم الخاصة فقط؛ قنوات المزاد لا تحتاجه |
| التسجيل في `channels.php` | لا يوجد — عامة بالتصميم |

**الأحداث على `auction.{public_id}`** — خمسة فقط:

| الحدث | الحمولة |
|---|---|
| `auction.bid_accepted` | `auction_id, bid_id, amount, amount_minor, currency, sequence_number, accepted_at, bidder:{anonymous:true}, ends_at, extended, extension_count, bids_count, status` |
| `auction.status_changed` | `auction_id, status, starts_at, ends_at, current_amount, currency, bids_count` — **يُبث فقط عندما تكون الحالة الهدف `live`/`ended`/`unsold`/`completed`** |
| `auction.finalized` | `auction_id, status, sold:true, amount, currency, winner:{anonymous:true}` |
| `auction.alternative_winner_selected` | `auction_id, status, amount, currency, winner:{anonymous:true}` |
| `auction.cancelled` | `auction_id, status:'cancelled'` |

`bids_count` و`ends_at` و`extended` تصل مع كل مزايدة — هذه الحقول الثلاثة كافية لتحديث العداد وعدّاد المزايدات فورًا دون إعادة جلب.

**قيد جوهري:** الحمولة مجهولة الهوية دائمًا. الاختبار `test_outbid_notifies_previous_leader_only_and_realtime_stays_anonymous` يتحقق صراحة من غياب `bidder_id` و`name` و`receipt_path` من الحمولة. **النتيجة: البث وحده لا يخبر المستخدم أن مزايدته هي التي قُبلت.** الطريقة الوحيدة هي مطابقة `bid_id` مع الـ`id` المستلم في استجابة الـPOST.

لا يوجد Endpoint خفيف مخصص لحالة المزاد اللحظية؛ عند فشل البث أعيدي `GET` للتفاصيل كاملة. الاستجابة تحمل `server_time` فيبقى العداد دقيقًا.

### 11.3 شروط قبول المزايدة

`PlaceBidAction` ينفّذ ثمانية فحوص بالترتيب داخل transaction بعد `lockForBidding` (قفل صف):

| # | الفحص | الخطأ |
|---|---|---|
| 1 | تكرار `idempotency_key` | لا خطأ — يُعاد نفس السجل |
| 2 | ليس البائع | `seller_cannot_bid` |
| 3 | `status = live` وله `starts_at` و`ends_at` | `auction_not_live` |
| 4 | `now` داخل النافذة | `bidding_window_closed` |
| 5 | المشارك `qualified` | `bidder_not_qualified` |
| 6 | قبول `snapshot.terms_version_id` | `terms_required_before_bidding` |
| 7 | العربون `held` بمبلغ كافٍ | `bidder_deposit_required` |
| 8 | العملة والمبلغ ≥ الحد الأدنى | `bid_currency_mismatch` / `bid_below_minimum` |

كلها **422** مع رسالة عربية جاهزة. الترتيب مهم: أول فحص يفشل هو الرسالة التي يراها المستخدم.

### 11.4 حساب الحد الأدنى

```php
$minimum = $currentAmount === 0
 ? $auction->starting_amount_minor
: $currentAmount + $snapshot->minimum_bid_increment_minor;
```

أول مزايدة عند سعر البداية **بالضبط** مقبولة. المزايدة الحرة مسموحة بلا سقف. لا توجد Quick Bid Values من الخادم — أي أزرار سريعة تحسبها الواجهة من `minimum_next_bid`.

**لا يوجد:** Auto Bid، Maximum Bid، مزايدة بالوكالة، سحب مزايدة، حد أعلى للمزايدة، أو فحص رصيد.

### 11.5 التزامن والقفل

كل مزايدة داخل transaction تبدأ بـ`lockForBidding($auction->id)` وهو `SELECT... FOR UPDATE` على صف المزاد. **[منفذ]**

**عند وصول مزايدتين متزامنتين:** إحداهما تنتظر الأخرى. الثانية ترى `current_leading_bid_id` المحدَّث فتُقاس ضد الحد الأدنى الجديد، وغالبًا تُرفض بـ`bid_below_minimum`. **لا يوجد فقدان بيانات ولا مزايدتان بنفس القيمة تقودان معًا.**

**Idempotency:** فحص مسبق بالمفتاح + قيد `uq_auction_bid_idempotency` على `(auction_id, bidder_id, idempotency_key)` + التقاط `QueryException` على `23000`/`1062` وإعادة القراءة. الاختبار `test_two_replayed_bid_submissions_do_not_create_duplicate_bid` يثبت أن الإرسالين يعيدان **نفس** `bid->id` وأن العدد لا يتغير.

**مزايدة من جهازين:** إن كان `idempotency_key` مختلفًا فهما مزايدتان مستقلتان — الثانية تُرفض غالبًا بـ`bid_below_minimum` لأن الأولى صارت القائدة. **مسؤولية الواجهة توليد مفتاح لكل نية مزايدة وإعادة استخدامه عند إعادة المحاولة، لا توليد مفتاح جديد لكل ضغطة.**

**[منفذ]** `POST /{auction}/bids` محمي بـ`throttle:auction-bids`: 30 محاولة في الدقيقة لكل مستخدم **ولكل مزاد على حدة**، مع حد إضافي حسب الـIP. التجاوز يعيد 429 برمز `too_many_requests` و`retry_after` بالثواني. الحد لا يغني عن الأقفال ولا عن منع التكرار.

### 11.6 التمديد التلقائي — Anti-Sniping

يُنفَّذ **داخل نفس قفل المزايدة**:

```php
$secondsRemaining = $now->diffInSeconds($auction->ends_at, false);
if ($secondsRemaining > 0
 && $snapshot->auto_extend_enabled
 && $secondsRemaining <= $snapshot->auto_extend_window_seconds
 && $auction->extension_count < $snapshot->maximum_extensions) {
 $auction->ends_at = $auction->ends_at->addSeconds($snapshot->auto_extend_duration_seconds);
 $auction->extension_count++;
 $auction->last_extended_at = $now;
}
```

القيم الافتراضية من الـSeeder: نافذة 300 ثانية، مدة 600 ثانية، حد أقصى 6 مرات. `auto_extend_enabled` مشتق: مفعّل عندما تكون النافذة والمدة أكبر من صفر.

**التمديد يُبنى على `ends_at` الحالي لا على وقت المزايدة**، فمزايدات متتالية داخل النافذة تراكم التمديدات حتى الحد الأقصى.

**الوصول إلى الواجهة:** حقل `extended: true` وحقل `ends_at` الجديد داخل حمولة `auction.bid_accepted`. **[منفذ]** — هذا هو المصدر الوحيد اللحظي للتمديد.

### 11.7 حماية سباق الإنهاء

`FinalizeAuctionAction` يبدأ بـ: إذا كانت الحالة `live` و`now < ends_at` **يرجع دون تغيير**. هذا يمنع إنهاء مزاد مُدِّد للتو بواسطة Job كان قد بدأ قبل التمديد. **[منفذ]**

### 11.8 تدفقات تفصيلية

**1) مزايدة ناجحة**

| الطرف | التسلسل |
|---|---|
| الواجهة | توليد `idempotency_key` ← تعطيل الزر ← `POST /{auction}/bids` |
| الخادم | `lockForBidding` ← الفحوص الثمانية ← `createAcceptedBid` ← تمديد إن لزم ← حفظ ← `outbox(auction.bid_accepted)` ← **201** `AuctionBidResource` |
| البث | `auction.bid_accepted` على القناة `auction.{public_id}` |
| الإشعار | القائد السابق وحده يتلقى «تمت المزايدة عليك» |
| الواجهة | التحديث من الاستجابة فورًا، ثم مطابقة `bid_id` عند وصول البث |

**2) أقل من الحد الأدنى** → 422 `bid_below_minimum`. الواجهة تعيد الجلب لأن `minimum_next_bid` تغيّر غالبًا.

**3) بعد انتهاء الوقت** → 422 `bidding_window_closed` (الحالة ما زالت `live` لأن الـJob لم يعمل بعد) أو `auction_not_live` بعد الإنهاء. **الرسالتان تعنيان الشيء نفسه للمستخدم.**

**4) مزايدتان متزامنتان** → الأولى 201، الثانية تنتظر القفل ثم 422 `bid_below_minimum` غالبًا.

**5) مزايدة مكررة (نفس المفتاح)** → **201** بنفس السجل. لا يوجد رمز يميز التكرار. **يجب ألا تُعرض رسالة نجاح مرتين.**

**6) فقد الاتصال ثم العودة** → عند إعادة الاتصال: `GET /api/auctions/{auction}` كمصدر حقيقة، ثم `GET /{auction}/bids` للسجل. **لا يوجد آلية استرجاع للأحداث الفائتة**؛ إعادة الجلب إلزامية.

**7) تمديد تلقائي** → يصل ضمنيًا مع `auction.bid_accepted` بحقلي `extended` و`ends_at`. الواجهة تعيد ضبط العداد وتعرض تنبيهًا واضحًا بالتمديد.

**8) إلغاء أثناء البث** → `auction.cancelled` على القناة. المزاد يخرج من الحالات العامة فيصبح `GET` عليه **404**. الواجهة تخرج من الشاشة برسالة نهائية ولا تحاول إعادة الجلب.

**9) صيرورة المستخدم أعلى مزايد** → لا حدث خاص. يُستنتج من مطابقة `bid_id` المستلم في استجابة الـPOST مع القيمة القائدة.

**10) تجاوز مزايدة المستخدم** → إشعار شخصي `bid_outbid` بعنوان «تمت المزايدة عليك» يصل عبر database + broadcast + FCM. **[منفذ]** يصل للقائد السابق فقط، ولا يصل عند رفع المستخدم لمزايدته الخاصة (`test_raising_own_leading_bid_sends_no_outbid_notification`).

### 11.9 مصدر الحقيقة النهائي

بترتيب الثقة: **استجابة `POST /bids`** (تأكيد قاطع) ← **`GET /api/auctions/{auction}`** (الحالة الكاملة) ← **`GET /{auction}/bids`** (السجل) ← **البث** (سريع لكنه مجهول الهوية وقد يُفقد).

عند أي تعارض بين البث والاستجابة، الاستجابة هي الحقيقة.

</div>
<div dir="rtl" align="right">

---

<a id="s12"></a>

## 12. سجل المزايدات والخصوصية

`GET /api/auctions/{auction}/bids` — مفتوح للزائر عبر `OptionalSanctumAuthentication`، ومحكوم بـ`Gate::authorize('view', $auction)` فيُرفض لأي مزاد غير عام.

### 12.1 الحقول

كل عنصر من `AuctionBidResource`:

| الحقل | القيمة |
|---|---|
| `id` | `public_id` للمزايدة |
| `auction_id` | `public_id` للمزاد (عند تحميل العلاقة) |
| `amount` | كائن Money |
| `sequence_number` | ترتيب المزايدة داخل المزاد |
| `accepted_at` | ISO 8601 |
| `bidder` | `{id, name}` أو `{anonymous: true}` أو **مفتاح غائب** |

### 12.2 قاعدة إخفاء الهوية

```php
$canViewBidder = $user && ($user->id === $this->bidder_id
 || Gate::forUser($user)->allows('viewAny', Auction::class));
```

أي: يرى المستخدم اسمه فقط، والإداري يرى الجميع، وكل من عداهما `{"anonymous": true}`. **[منفذ]** — مثبت بـ`test_public_bid_resource_anonymizes_other_bidders` و`test_public_bid_history_for_live_auction_anonymizes_bidders`.

**فخ تكامل مهم:** `bidder` يكون **غائبًا تمامًا** لا `null` عندما يكون المشاهد مخوّلًا لكن علاقة `bidder` غير محمّلة. هذا يحدث في `MyAuctionResource.my_bids` لأن `loadMyAuctionRelations()` لا تحمّل `bidder`. يجب أن يتعامل التطبيق مع ثلاث حالات: كائن، `{anonymous:true}`، ومفتاح مفقود.

**تمييز مزايدة المستخدم:** `bidder` كائن يحمل `id` للمستخدم نفسه و`{anonymous:true}` لغيره، و`my_participation.is_highest_bidder` يحسم من هو القائد.

### 12.3 الترتيب والترقيم

الترتيب من الخادم: `ORDER BY amount_minor DESC, sequence_number ASC` — **بالقيمة لا بالزمن**. هذا مطابق تمامًا لمنطق اختيار الفائز، أي أن **الصف الأول في السجل هو الفائز المتوقع**. مفيد للعرض، لكنه يعني أن السجل ليس Timeline زمنيًا. الترتيب الزمني يُبنى على `sequence_number` تصاعديًا.

Pagination عادي 1–100، افتراضي 20.

### 12.4 ما لا يوجد في السجل

- **لا مزايدات ملغاة أو مرفوضة** — المرفوضة تُرد كخطأ 422 ولا تُكتب أبدًا في `auction_bids`. السجل نظيف بالكامل.
- **لا تحديث لحظي للسجل** — البث يحمل مزايدة واحدة، والسجل يحتاج إعادة جلب. الحل العملي: إدراج العنصر الوارد من البث في أعلى القائمة محليًا.
- **لا Audit Log للمستخدم** — `auction_activity_logs` و`auction_status_history` متاحان للإدارة فقط عبر `/admin/auctions/{auction}/activity` و`/status-history`. سجل النشاط يحتوي `ip_hash` وهو محجوب حتى عن الإدارة في الـResource (مثبت باختبار).

---

<a id="s13"></a>

## 13. انتهاء المزاد وتحديد النتيجة

### 13.1 آلية الاكتشاف

`auction:run-operations` كل دقيقة مع `withoutOverlapping()` → `FinalizeExpiredAuctionsJob` → `AuctionRepository::findExpiredLiveAuctions()` → `FinalizeAuctionAction` لكل مزاد. **[منفذ]** — `routes/console.php`.

**النتيجة العملية:** تأخير حتى دقيقة بين انتهاء العداد وتغيّر الحالة. الواجهة يجب أن تعرض حالة انتقالية «انتهى المزاد، جارٍ تحديد النتيجة» ولا تفترض نتيجة فورية.

### 13.2 اختيار الفائز

```php
AuctionBid::where('auction_id', $id)
 ->orderByDesc('amount_minor')
 ->orderBy('sequence_number')
 ->lockForUpdate()->first();
```

**أعلى مبلغ، وعند التعادل الأسبق زمنيًا يفوز** (أقل `sequence_number`). **[منفذ]** — `AuctionBidRepository::lockWinningBid()`.

عمليًا التعادل شبه مستحيل لأن كل مزايدة يجب أن تتجاوز السابقة بالحد الأدنى، لكن القاعدة محددة وحاسمة.

### 13.3 السعر الاحتياطي وغياب المزايدات

```php
if (! $winningBid || ($auction->reserve_amount_minor !== null
 && $winningBid->amount_minor < $auction->reserve_amount_minor)) {
 → transition(Unsold)
}
```

المقارنة **`<` صارمة**: مزايدة تساوي السعر الاحتياطي بالضبط **تبيع**. `reserve_amount_minor = null` يعني لا سعر احتياطي. **[منفذ]**

عند `unsold`: تخطيط استرداد عرابين المزايدين، ثم معالجة عربون البائع حسب `seller_deposit_policy.unsold` (الافتراضي `refund`).

### 13.4 حساب التسوية

| القيمة | المعادلة |
|---|---|
| `winning_amount_minor` | مبلغ المزايدة الفائزة |
| `depositApplied` | `min(winnerDeposit.held, winningAmount)` |
| `depositExcess` | `max(0, held - applied)` |
| `platform_fee_minor` | `snapshot->platformFeeFor(winningAmount)` |
| `seller_net_amount_minor` | `max(0, winningAmount - platformFee)` |
| `amount_due_minor` | `winningAmount - depositApplied` |
| `payment_due_at` | `now + snapshot.winner_payment_deadline_minutes`، و`null` إذا كان المستحق صفرًا |
| `handover_due_at` | يُضبط الآن فقط إذا كان المستحق صفرًا؛ وإلا بعد اكتمال السداد |

قيدان في قاعدة البيانات يحرسان الحساب:
`chk_settlement_amounts CHECK (amount_due + deposit_applied = winning_amount)`
`chk_settlement_remaining CHECK (remaining + amount_paid = amount_due)`

**مساران بعد الإنشاء [منفذ]:**
- `amount_due > 0` → التسوية `payment_pending` والمزاد `payment_pending`.
- `amount_due = 0` (العربون غطى المبلغ) → التسوية `paid` مع `paid_at = now`، والمزاد **يقفز مباشرة إلى `handover_pending`**. مثبت بـ`test_full_deposit_coverage_moves_directly_to_handover`.

الرسوم تُحسب بحساب صحيح فقط: `test_percentage_fee_is_integer_arithmetic` و`test_fee_on_small_amount_has_no_float_drift`.

### 13.5 الحماية من الازدواج

| الطبقة | الآلية |
|---|---|
| قفل | `lockForFinalization()` |
| حارس مبكر | العودة دون تغيير إذا `live` و`now < ends_at` |
| حارس التسوية | العودة فورًا إذا وُجدت تسوية |
| قاعدة البيانات | `uq_auction_settlement_current` على `(auction_id, current_marker)` |
| قاعدة البيانات | `uq_auction_settlement_bid` على `winning_bid_id` |

`test_two_scheduler_finalizations_create_one_settlement` و`test_parallel_scheduler_finalization_processes_create_one_settlement` يثبتان تسوية واحدة عند التشغيل المتوازي بعمليات PHP حقيقية.

**نقطة تحتاج انتباهًا [استنتاج]:** `lockWinningBid()` لا يفلتر على `accepted_at` ولا يعيد التحقق من أن الفائز ما زال `qualified`. في المقابل `MarkWinnerDefaultedAction::findEligibleAlternativeBid()` يفلتر على الاثنين. الفرق غير مؤثر عمليًا لأن `PlaceBidAction` يضبط `accepted_at` دائمًا ويشترط `qualified`، لكنه اختلاف حقيقي بين المسارين.

### 13.6 غياب المراجعة الإدارية للنتيجة

تحديد الفائز آلي بالكامل. لا يوجد Endpoint لإلغاء نتيجة. المسارات الوحيدة للتراجع: إلغاء المزاد كليًا، أو قرار تعثر الفائز، أو حل نزاع.

### 13.7 الأحداث الصادرة

| الحدث | الجمهور |
|---|---|
| `auction.status_changed` (`to = ended`) | البائع + بث لحظي + إعلان عام |
| `auction.status_changed` (`to = unsold`) | البائع |
| `auction.finalized` | الفائز (`finalized_winner`) + البائع (`finalized_seller`) + بث لحظي |

`finalized_winner_paid` بدل `finalized_winner` عندما يكون `payment_due_at` فارغًا — أي عندما غطى العربون المبلغ كاملًا. **[منفذ]**

### 13.8 مصفوفة ما بعد الانتهاء

| الجمهور | يرى | CTA | البيانات |
|---|---|---|---|
| الفائز | «مبروك، فزت بالمزاد» | «ادفع المتبقي» | `winner_settlement` كاملة + `payment_due_at` |
| فائز مغطى بالعربون | «فزت والمبلغ مسدد» | انتظار تأكيد البائع | التسوية `paid` |
| خاسر | «انتهى المزاد» | «تابع استرداد عربونك» | `my_bids`, `my_deposits` |
| خاسر محجوز عربونه | نفس الشيء | لا CTA | **لا تفسير متاح** |
| مسجل لم يزايد | «انتهى المزاد» | حسب حالة عربونه | `my_deposits` |
| مستخدم لم يشارك | «انتهى المزاد» | لا شيء | `PublicAuctionResource` فقط |
| البائع | «تم اختيار الفائز» | متابعة | `seller_financial_summary` |
| البائع بلا بيع | «انتهى دون بيع» | متابعة استرداد عربونه | `seller_deposit` |

**قيد على كل الصفوف:** الفائز غير معروف علنًا. `winning_bid_id` غير مكشوف في `PublicAuctionResource`، وحمولة البث تحمل `winner:{anonymous:true}`. المستخدم غير المشارك لا يرى من فاز ولا بكم — يرى فقط `current_amount`. **[منفذ]** — قرار خصوصية متعمد.

---

<a id="s14"></a>

## 14. رحلة الفائز

### 14.1 المراحل

| المرحلة | ما يراه | CTA | المصدر |
|---|---|---|---|
| إشعار الفوز | «مبروك، فزت بالمزاد» + المبلغ + المهلة | فتح المزاد | إشعار `finalized_winner` |
| ملخص المبلغ | تفصيل مالي كامل | «ادفع المتبقي» | `winner_settlement` |
| اختيار طريقة الدفع | قائمة الطرق + بيانات التحويل | «رفعت الإيصال» | `GET /api/soom/payment-methods` |
| رفع الإيصال | تأكيد الإرسال | — | `POST /{auction}/winner-payment` |
| قيد المراجعة | «قيد المراجعة» | لا CTA | `my_payment_submissions` |
| رُفض | سبب الرفض | «أعد المحاولة» | `review_note` |
| اعتُمد | «تم استلام دفعتك» | انتظار تأكيد البائع | `winner_settlement.status = paid` |
| البائع أكد التسليم | «البائع أكد التسليم» | **«أكّد الاستلام»** | `handover_status.seller_handover_confirmed_at` |
| أكد الاستلام | «اكتملت الصفقة» | — | المزاد `completed` |

### 14.2 التفصيل المالي المتاح للفائز

من `winner_settlement`:

| الحقل | المعنى |
|---|---|
| `status` | `payment_pending` / `paid` / … |
| `amount_due` | المستحق بعد خصم العربون |
| `amount_paid` | المدفوع فعليًا |
| `remaining_amount` | المتبقي — **هذا هو المبلغ الواجب دفعه بالضبط** |
| `payment_due_at` | المهلة |

**غير متاح للفائز:** `winning_amount` (سعر الفوز نفسه)، و`deposit_applied` (كم خُصم من عربونه). كلاهما موجود في `AuctionSettlementResource` لكن `MyAuctionResource` لا يستخدمه للفائز — يبني كتلة مختصرة بخمسة حقول فقط. الفائز يرى «عليك 90 دينارًا» دون أن يرى أنه فاز بـ100 وأن عربونه 10 خُصم.

**الرسوم والضرائب:** رسوم المنصة تُخصم من البائع لا من المشتري (`seller_net = winning - fee`, `amount_due = winning - depositApplied`). لا ضريبة قيمة مضافة في النظام إطلاقًا.

### 14.3 قواعد الدفع الصارمة

- المبلغ = `remaining_amount_minor` **بالضبط**. زيادة → `payment_amount_exceeds_remaining`. نقص → `payment_amount_mismatch`.
- الحالة يجب أن تكون `payment_pending`؛ من `handover_pending` يُرفض بـ`winner_payment_state_not_allowed`.
- التسوية يجب أن تكون الحالية (`is_current` و`current_marker = 1`)، وإلا `payment_target_not_current`.
- طلب واحد قيد المراجعة فقط؛ الثاني `active_payment_submission_exists`.
- بعد `payment_due_at` يُرفض بـ`payment_deadline_expired` — **الرفض عند الإرسال وعند المراجعة معًا**.

### 14.4 التسليم

خطوتان بترتيب ملزم:

1. **البائع** `POST /{auction}/confirm-handover`. يشترط `status = handover_pending` وتسوية `paid` أو `handover_pending`. يضبط `seller_handover_confirmed_at`، وترسل إشعارًا للفائز.
2. **الفائز** `POST /{auction}/confirm-receipt`. **يرفض بـ`seller_handover_required` إذا لم يؤكد البائع أولًا.** يضبط `buyer_receipt_confirmed_at` و`handover_completed_at` و`completed_at`، ينقل المزاد إلى `completed`، ثم يشغّل ثلاثة إجراءات مالية: تحرير عرابين غير الفائزين، معالجة عربون البائع، وإنشاء `AuctionSellerPayout`.

**[منفذ]** `handover_due_at` تُرسَل عليه تذكيرات (24 ساعة وساعة) للطرف المطلوب منه الإجراء، وإشعار تأخر واحد بعد فواته. **التسليم لا يُغلق آليًا ولا تُصادر أموال بسببه** — النزاع أو الإدارة هما مسار المعالجة.

### 14.5 المستندات والنزاع

لا فواتير ولا مستندات ضريبية — خارج نطاق الإصدار. إيصال الفائز متاح عبر `GET /api/soom/payment-submissions/{id}/receipt-url` باستخدام `my_payment_submissions[].id`.

**النزاع:** `POST /{auction}/disputes` بحقل `reason` (مطلوب، ≤1000). متاح للبائع أو الفائز فقط، وفي الحالات `live`, `ended`, `handover_pending`, `payment_pending` فقط. الاستجابة **201** `{id, status}`.
**[منفذ]** `GET /api/soom/auctions/{auction}/disputes` و`GET .../disputes/{dispute}` متاحان لأطراف النزاع فقط، فالمستخدم يتابع نزاعه بعد فتحه.

---

<a id="s15"></a>

## 15. التعثر وعدم سداد الفائز

### 15.1 القاعدة الأساسية

للتعثر مساران يستخدمان **نفس Domain Action** (`MarkWinnerDefaultedAction`) بلا أي تكرار للمنطق المالي:

| المسار | المشغّل | الشرط الزمني | الفاعل المسجَّل |
|---|---|---|---|
| **آلي** | `AutoDefaultOverdueWinnersJob` عبر `auction:run-deadlines` كل دقيقة | بعد `payment_due_at` **وبعد** نهاية فترة السماح `payment_grace_ends_at` | `system` مع `auto_defaulted = true` |
| **يدوي** | `POST /admin/auctions/{auction}/winner-default` بصلاحية `auction.winners.mark_defaulted` | بعد `payment_due_at`، أو قبلها بتجاوز مصرّح | `admin` مع `auto_defaulted = false` |

**فترة السماح تحمي الفائز.** بين `payment_due_at` و`payment_grace_ends_at` يبقى الفائز متأخرًا لا متعثرًا، ويصله إشعار تأخر واحد. المهلتان مجمّدتان في لقطة إعدادات المزاد.

**إثبات دفع قيد المراجعة يوقف التعثر الآلي.** إذا وُجد `PaymentSubmission` بحالة `pending_review` للتسوية، يرفض المسار الآلي بـ`winner_default_blocked_by_pending_payment` — ويُعاد الفحص **داخل القفل** لا في الاستعلام فقط، فلا يتعثر فائز أرسل إيصاله في اللحظة الأخيرة.

**الأثر على الواجهة:** لا تعرض «تعثر» بمجرد تجاوز `payment_due_at`. الحقيقة هي `settlement.status` وحدها؛ واستخدم `is_payment_overdue` للتأخر و`auto_defaulted` للتمييز بين التعثر الآلي واليدوي.

### 15.2 شروط قرار التعثر

| الشرط | الخطأ عند الإخفاق |
|---|---|
| `reason` غير فارغ | `default_reason_required` |
| المزاد `payment_pending` | `winner_default_state_not_allowed` |
| توجد تسوية حالية | `current_settlement_missing` |
| التسوية `is_current` و`current_marker = 1` | `payment_target_not_current` |
| التسوية `payment_pending` | `payment_obligation_already_paid` أو `winner_default_settlement_not_allowed` |
| `remaining > 0` | `payment_obligation_already_paid` |
| `payment_due_at` موجود | `payment_deadline_missing` |
| المهلة انتهت **أو** تجاوز مصرّح (يدوي) | `payment_deadline_not_expired` |
| فترة السماح انتهت (آلي) | `payment_grace_period_not_expired` |
| لا إثبات دفع قيد المراجعة (آلي) | `winner_default_blocked_by_pending_payment` |

**التجاوز قبل المهلة** يتطلب صلاحية **منفصلة** `auction.payment.override_deadline` بالإضافة إلى صلاحية التعثر، وسببًا موثقًا. مثبت بـ`test_override_requires_permission_and_reason_and_records_deadline_metadata`.

### 15.3 الآثار

| الأثر | التنفيذ |
|---|---|
| التسوية القديمة | `defaulted`, `is_current = false`, `current_marker = null`, `defaulted_at` |
| طلبات دفع معلقة | تُرفض تلقائيًا بـ`review_note = 'winner_default_superseded'` |
| عربون الفائز | حسب `winner_default_deposit_policy` — الافتراضي `full_forfeit` |
| عربون البائع | حسب `seller_deposit_policy.winner_default` — الافتراضي `keep_held` مع `hold_reason = 'seller_deposit_keep_held'` |
| سجل | `AuctionWinnerReassignment` بقيد فريد يمنع التكرار |
| مصدر القرار | `settlement.auto_defaulted` + `default_reason` (`winner_payment_deadline_expired` في المسار الآلي) |

سياسات مصادرة عربون الفائز — `WinnerDefaultDepositDisposition`: `full_forfeit` (افتراضي)، `partial_forfeit`، `refund`، `manual_review`، `no_action`.

لا توجد غرامة تتجاوز العربون، ولا حظر تلقائي من مزادات مستقبلية، ولا آلية استئناف — قرارات مقصودة.

### 15.4 الفائز البديل

يُفعَّل عندما يمرر الإداري `reassign_to_next = true` **و** `snapshot.alternative_winner_enabled = true`.

`findEligibleAlternativeBid()` يمر على المزايدات تنازليًا ويشترط في كل مرشح: ليس المتعثر، `accepted_at` موجود، المشارك `qualified`، وعربون `held` بمبلغ كافٍ. مثبت بـ`test_skips_candidates_without_terms_or_with_non_held_deposit`.

**هنا تظهر قيمة سياسة الحجز:** إذا كانت السياسة `refund_all_non_winners_immediately` تكون عرابين الجميع قد رُدّت، فلا مرشح مؤهل، والنتيجة `unsold` دائمًا. **[استنتاج]** — سياسة الحجز هي ما يجعل الفائز البديل ممكنًا.

- **وُجد بديل:** تسوية جديدة بـ`sequence_number` أعلى ومهلة دفع جديدة، ويصله إشعار `alternative_winner_selected` + بث لحظي.
- **لا بديل:** `payment_pending → defaulted → unsold`، و`winning_bid_id = null`، وتُردّ العرابين المتبقية.

### 15.5 ما يراه المتعثر

| اللحظة | ما يراه |
|---|---|
| قبل انتهاء المهلة | `winner_settlement` + عداد المهلة |
| بعد المهلة وقبل القرار | **نفس الشيء تمامًا — لا تغيير في البيانات** |
| بعد القرار | `winner_settlement` تختفي (التسوية لم تعد `is_current`)، والعربون `forfeited` |
| الإشعار | `winner_defaulted_winner` |

**سياق المصادرة مكشوف بالكامل:** بعد التعثر يحمل `my_deposits[]` الحقول `status = forfeited` و`forfeited_amount` و`hold_reason` و`hold_reason_label`، فالواجهة تعرض المبلغ المصادَر وسببه دون الاعتماد على الإشعار. الإشعار تذكير لا مصدر وحيد.

</div>
<div dir="rtl" align="right">

---

<a id="s16"></a>

## 16. رحلة المستخدم الخاسر

### 16.1 متى يعرف أنه خسر

**لا يوجد إشعار خسارة.** `AuctionNotificationCatalog` لا يحتوي أي حدث موجّه للخاسرين، والاختبار `test_finalization_notifies_winner_and_seller_but_not_other_bidders` يؤكد أن الخاسر يتلقى **صفر إشعارات** عند الإنهاء. **[منفذ]**

**[منفذ]** بعد `bid_outbid` يصل الخاسر إشعار `auction.bidder_lost` عند تحديد النتيجة — **دون كشف هوية الفائز** — يحمل حالة عربونه ورابطًا عميقًا إلى الاسترداد أو تفاصيل المزاد. وإذا انتهى المزاد دون بيع يصل `auction.unsold_bidders`.

### 16.2 ما يظهر له

| المعلومة | متاح؟ |
|---|---|
| حالة المزاد | نعم — `status` |
| السعر النهائي | نعم — `current_amount` (لأنه يعكس القائدة) |
| هوية الفائز | **لا** — قرار خصوصية |
| مزايداته | نعم — `my_bids` |
| سجل المزاد كاملًا | نعم — مجهول الهوية |
| حالة عربونه | نعم — `my_deposits[].status` |
| **سبب حجز عربونه** | **لا** — `hold_reason` غير مكشوف |

### 16.3 مصير العربون

عند الإنهاء يُنفَّذ `PlanNonWinnerDepositRefundsAction` حسب السياسة:

| السياسة | مصير عربون الخاسر |
|---|---|
| `refund_all_non_winners_immediately` | `refund_pending` فورًا |
| `hold_top_n_bidders_until_winner_payment` | أعلى N يبقون `held`؛ الباقون `refund_pending` |
| `hold_all_eligible_bidders_until_winner_payment` | كل مؤهل يبقى `held`؛ المحظور يُسترد فورًا |

في حالتي الحجز يبقى العربون `held` مع `hold_reason = 'alternative_winner_candidate'` حتى يقع أحد ثلاثة أحداث: يسدد الفائز، أو يتعثر ويُختار بديل (فيبقى المرشح التالي محجوزًا)، أو ينتهي المزاد `unsold`.

**الحجز مشروح صراحة:** الخاسر الذي يبقى عربونه محجوزًا يرى `hold_reason` و`hold_reason_label` و`expected_release_condition` و`hold_expires_at` و`candidate_rank`، فالواجهة تشرح سبب الحجز وشرط الإفراج ومتى يُتوقع — بدل ترك المستخدم بلا تفسير.

### 16.4 مسار الاسترداد

`refund_pending` → مهمة `RefundPendingAuctionDepositsJob` كل دقيقة تنشئ `RefundTransaction` → `ProcessPendingAuctionRefundsJob` تعالجه.

المعالج الافتراضي `AUCTION_REFUND_PROVIDER=manual` هو `ManualReviewRefundProcessor` الذي **لا ينفذ تحويلًا** بل يحوّل الاسترداد إلى `manual_review` لينفذه موظف يدويًا ثم يؤكده عبر `POST /admin/auctions/refunds/{refund}/confirm`. **[منفذ]**

**نتيجة تصميمية:** المسار الطبيعي للاسترداد يمر عبر `manual_review` وليس عبر `succeeded` مباشرة. الواجهة يجب ألا تعرض `manual_review` كخطأ — هي المسار الطبيعي المتوقع.

المتابعة عبر `my_refunds[]`: `id`, `status`, `amount`, `processed_at`, `succeeded_at`, `failed_at`, `cancelled_at`.

### 16.5 عند الفشل والدعم

`failed` مع `next_retry_at` → إعادة محاولة تلقائية بتراجع أسي (افتراضي `60,300,900,3600` ثانية، حتى 5 محاولات). بعد استنفاد المحاولات → `manual_review` نهائي.
`failure_reason` و`attempt_count` مكشوفان في `my_refunds`، فالمستخدم يرى سبب الفشل وعدد المحاولات. **[منفذ]**

لا يوجد Endpoint دعم أو تذكرة. النزاع محصور بالبائع والفائز.

### 16.6 المشاركة في مزادات أخرى

لا قيود إطلاقًا. لا يوجد أي كود يمنع المشاركة بسبب خسارة أو استرداد معلق. كل مزاد يتطلب عربونه المستقل. **[منفذ]**

---

<a id="s17"></a>

## 17. الاسترداد Refund

### 17.1 أسباب الاسترداد

| السبب | المصدر |
|---|---|
| خسارة المزاد | `PlanNonWinnerDepositRefundsAction` |
| المزاد `unsold` | نفس الإجراء |
| فائض عربون الفائز | `FinalizeAuctionAction` |
| إلغاء المزاد | `CancelAuctionFinanciallyAction` |
| معالجة عربون البائع | `ResolveSellerDepositDispositionAction` |
| حل نزاع بالإلغاء | `ResolveAuctionDisputeAction` |

**لا يوجد استرداد بطلب المستخدم.** كل الاستردادات مولّدة من النظام.

### 17.2 الحالات الست

| الحالة | العربية | الوصف | الرسالة | CTA | ينتظر أم يتدخل؟ |
|---|---|---|---|---|---|
| `pending` | بانتظار المعالجة | مُنشأ ولم يُلتقط | «جارٍ تجهيز استرداد عربونك» | لا | ينتظر |
| `processing` | قيد التنفيذ | مُلتقط بـlease وtoken | «جارٍ تنفيذ الاسترداد» | لا | ينتظر |
| `succeeded` | تم الاسترداد | نجح وطُبّق ماليًا | «تم رد العربون» + التاريخ | لا | انتهى |
| `failed` | تعذّر مؤقتًا | فشل قابل لإعادة المحاولة | «تعذّر التنفيذ، ستُعاد المحاولة» | لا | ينتظر |
| `manual_review` | مراجعة يدوية | ينتظر تدخل موظف | «قيد المعالجة اليدوية» | «تواصل مع الدعم» عند الطول | ينتظر |
| `cancelled` | ملغي | ألغته الإدارة وأعادت الرصيد | «أُلغيت العملية» | «تواصل مع الدعم» | يتدخل |

الحالات الست متاحة عبر مسارين: `GET /api/auctions/{auction}` → `my_refunds[]` للمزاد الواحد، و`GET /api/soom/my/refunds` كقائمة مستقلة مع الفلترة بالحالة. **[منفذ]**

### 17.3 المبالغ

| الحقل | المعنى | مكشوف للمستخدم؟ |
|---|---|---|
| `amount_minor` | إجمالي الاسترداد | نعم كـ`amount` |
| `held_refund_amount_minor` | الجزء من الرصيد المحتجز | لا |
| `applied_refund_amount_minor` | الجزء من الرصيد المطبَّق | لا |

الفصل بين الدلوين يسمح باسترداد مختلط بعد إلغاء تسوية: `test_refunds_mixed_held_and_applied_buckets_after_settlement_is_cancelled` يثبت تقسيم 4000 محتجز + 6000 مطبَّق.

**الاسترداد الجزئي موجود كقدرة نظام لا كخيار مستخدم**، ويظهر في المصادرة الجزئية لعربون البائع (`partial_forfeit` يصادر جزءًا ويرد الباقي).

### 17.4 آلية المعالجة

| الآلية | التفصيل |
|---|---|
| الالتقاط | `dueForProcessingQuery()`: `pending`، أو `failed` مع `next_retry_at` منتهٍ، أو `processing` بـlease منتهٍ |
| الحماية | `processing_token` + `lease_expires_at` (افتراضي 300 ثانية) |
| استرجاع lease | العامل الجديد يلتقط الاسترداد ويبطل token القديم → `refund_processing_token_mismatch` |
| المحاولات | `attempt_count`، حد أقصى 5، تراجع `60,300,900,3600` |
| Idempotency | `uq_refund_idempotency` على `(provider, idempotency_key)` بمفاتيح مشتقة `{base}` و`{base}:retry:N` |
| منع مرجع مكرر | `uq_refund_provider_refund_id` → `duplicate_provider_refund` |

الأثر المالي عند النجاح: `deposit.refunded_amount += amount`, `held -= amount`، و`PaymentTransaction` تصبح `reversed` **فقط عند الاسترداد الكامل** — الاسترداد الجزئي يبقيها `succeeded`. **[منفذ]** — `test_refunds_held_then_applied_from_same_payment_without_reversing_until_full_refund`.

### 17.5 التأكيد اليدوي والإلغاء

**التأكيد:** `POST /admin/auctions/refunds/{refund}/confirm` بـ`confirmation_reference` و`reason` إلزاميين، بصلاحية `auction.refunds.confirm_manual`. Idempotent.
**الإلغاء:** `POST /admin/auctions/refunds/{refund}/cancel` بـ`reason`. مسموح من `pending`/`failed`/`manual_review` فقط؛ الاسترداد `processing` النشط يُرفض بـ`refund_cancellation_not_allowed`، والناجح لا يُلغى. الإلغاء يعيد الرصيد للعربون ويسمح باسترداد جديد.

### 17.6 الإشعارات

مكشوفة للمستخدم: `refund_succeeded` و`refund_manual_review` و`non_winner_deposit_refund_planned` و`seller_deposit_refund_planned` — كلها إلى شاشة `auction_refunds`.
**غير مكشوفة:** `refund_processing`, `refund_failed`, `refund_cancelled` — مسجّلة في الـoutbox بلا مستلم. **[منفذ]** — أي أن **فشل الاسترداد لا يُبلَّغ للمستخدم إطلاقًا**.

**[منفذ]** شاشة `auction_refunds` يخدمها `GET /api/soom/my/refunds`.

---

<a id="s18"></a>

## 18. الإلغاء والتعليق والحالات الاستثنائية

### 18.1 الإلغاء

`DELETE /api/soom/auctions/{auction}` مع body: `reason`/`reason_text` (مطلوب فعليًا)، و`reason_code` و`liability` (مطلوبان للإداري فقط).

`reason_code` ∈ `platform_fault | seller_breach | fraud | compliance | buyer_fault | neutral`
`liability` ∈ `platform | seller | buyer | manual_review | neutral`

**من يستطيع:** الإداري بصلاحية، أو **البائع** من الحالات: `draft`, `pending_review`, `rejected`, `awaiting_seller_deposit`, `scheduled`, `live`, `ended`, `settlement_pending`, `payment_pending`. **[منفذ]** — `AuctionPolicy::cancel()`.

**البائع يستطيع إلغاء مزاده أثناء البث.** المزايدون يفقدون المزاد فورًا. لا يوجد قيد يمنع ذلك.
`handover_pending` و`completed` غير قابلين للإلغاء.

### 18.2 الأثر المالي — الكل أو لا شيء

`CancelAuctionFinanciallyAction` ينفذ داخل transaction واحدة: إغلاق التسوية الحالية (`cancelled`, `is_current = false`)، رفض طلبات الدفع المعلقة بـ`review_note = 'auction_cancelled'`، تخطيط استرداد لكل دفعة ناجحة، ومعالجة عربون البائع حسب السياسة.

`test_refund_failure_prevents_financial_cancellation_completion` يثبت أنه **إذا فشل أي استرداد، يُلغى كل شيء ويبقى المزاد في حالته الأصلية**. لا إلغاء جزئي.

**دفعة الفائز تبقى `succeeded` حتى ينجح الاسترداد فعليًا** — الحالة المالية أمينة ولا تُزوَّر.

### 18.3 سياسة عربون البائع عند الإلغاء

`seller_deposit_policy` يحوي 16 مفتاحًا. الافتراضات من الـSeeder:

| السيناريو | القرار |
|---|---|
| `seller_cancellation_before_start` | `refund` |
| `seller_cancellation_after_start` | `manual_review` |
| `admin_cancellation_platform_fault` | `refund` |
| `admin_cancellation_seller_fault` | `forfeit` |
| `admin_cancellation_neutral` | `refund` |
| `admin_cancellation_fraud_or_compliance` | `manual_review` |
| `system_cancellation_platform_fault` | `refund` |
| `system_cancellation_seller_fault` | `forfeit` |
| `winner_default` | `keep_held` |
| `seller_breach` | `forfeit` |
| `dispute_complete` | `refund` |
| `dispute_cancel` | `manual_review` |
| `dispute_resume_handover` | `keep_held` |
| `unsold` / `completed` | `refund` |

مثبت بـ`test_seller_cancellation_before_start_refunds_but_after_start_requires_manual_review` و`test_admin_cancellation_platform_fault_refunds_and_seller_fault_forfeits`.

سياسات مصير عربون البائع مجمّدة في `snapshot.seller_deposit_policy` بمفاتيحها الستة عشر. اعرضي أثر الإلغاء المتوقع قبل التأكيد.

### 18.4 الحالات الاستثنائية

| السيناريو | السلوك | ما تعرضه الواجهة |
|---|---|---|
| إلغاء قبل التسجيل | لا أثر مالي | يختفي من القائمة |
| إلغاء بعد تسجيل بلا دفع | العربون `rejected` بلا استرداد | «أُلغي المزاد» |
| إلغاء بعد دفع العربون | `refund_pending` + استرداد | «أُلغي، جارٍ رد عربونك» |
| إلغاء أثناء البث | بث `auction.cancelled` + إشعار للجميع | خروج فوري من الغرفة |
| إلغاء بعد الفوز | التسوية `cancelled` + استرداد الدفعة | «أُلغيت الصفقة» |
| **تعليق المزاد** | **غير موجود** — لا `suspended` ولا استئناف | — |
| تعديل موعد البداية أو النهاية | **غير موجود** — لا Endpoint تعديل بعد الإنشاء | — |
| تغيير الشروط أثناء المزاد | مستحيل — الـsnapshot ثابت وقيد `updating` يمنع تغيير `configuration_version_id` | — |
| صيانة النظام | `api_maintenance` → **503** `{"status":"maintenance","message":"..."}` | شاشة صيانة كاملة |
| فشل Job الإنهاء | 3 محاولات ثم إعادة الالتقاط في الدورة التالية | تبقى `ended` |
| فشل الـoutbox | 3 محاولات ثم `failed` + `dead_lettered_at` | **الإشعار يُفقد نهائيًا** |
| تعطل بوابة الدفع | لا يوجد — الدفع يدوي | — |
| تكرار Webhook | لا توجد Webhooks | — |
| اختلاف الوقت | الخادم هو المرجع | راجع [القسم 21](#s21) |
| حذف/تعطيل حساب | `restrictOnDelete` على `seller_id` و`bidder_id` — الحذف مستحيل ماليًا | — |
| حظر مشارك | `POST .../participants/{participant}/block` بسبب موثق **[منفذ]** | يُمنع بعد إنشاء التسوية |
| مزاد غير متاح أو محذوف | **404** بنفس الرسالة | «المزاد غير متاح» |
| إجراء على حالة قديمة | **422** بخطأ حالة محدد | إعادة جلب + رسالة |

### 18.5 التعامل مع تغيّر الحالة بين التحميل والضغط

هذا أكثر سيناريو تكرارًا في مزاد مباشر. القاعدة الموحّدة الموصى بها:

كل **422** من عائلة الحالة (`auction_not_live`, `bidding_window_closed`, `registration_closed`, `payment_target_not_current`, `winner_changed`, `payment_deadline_expired`, `bidder_deposit_state_not_allowed`, `winner_payment_state_not_allowed`) يعالَج بالتسلسل: عرض الرسالة العربية كما جاءت من الخادم ← إعادة جلب التفاصيل ← إعادة رسم الأزرار من الحالة الجديدة ← **عدم إعادة المحاولة تلقائيًا**.

</div>
<div dir="rtl" align="right">

---

<a id="s19"></a>

## 19. قاموس البيانات الكامل

كل الحقول أدناه موثقة كما تصل فعليًا إلى تطبيق المستخدم. الحقول التي لا تصل مذكورة صراحة كذلك.

**قاعدتان عامتان تنطبقان على كل الجداول:**

1. **كل التواريخ** ISO 8601 مع الإزاحة الزمنية (`toIso8601String()`)، ونوعها `string|null`، ولا يرسل التطبيق تواريخ إلا في إنشاء المزاد.
2. **كل المبالغ** كائن `{amount: string, minor: int, currency: string}` من `MoneyResource::make()`.

### 19.1 Auction — من `PublicAuctionResource`

| الحقل | النوع | null | الوصف | مثال | UI |
|---|---|:--:|---|---|---|
| `id` | string(26) | لا | ULID عام، مفتاح كل المسارات | `01JQ...` | لا يُعرض؛ للتنقل |
| `title` | string | لا | العنوان (≤180) | `سيارة تويوتا 2020` | عنوان البطاقة |
| `description` | string | لا | الوصف (≤10000) | — | قسم قابل للطي |
| `status` | enum | لا | إحدى 15 حالة | `live` | منطق فقط |
| `status_label` | string | لا | ترجمة جاهزة من `auction.statuses.*` | `مباشر` | الشارة |
| `currency_code` | string(3) | لا | `JOD`/`EGP`/`USD` | `JOD` | مع المبالغ |
| `starting_amount` | Money | لا | سعر البداية | `{"amount":"100.000",...}` | «يبدأ من» |
| `current_amount` | Money | لا | أعلى مزايدة، أو سعر البداية إن لم توجد | — | السعر البارز |
| `minimum_next_bid` | Money | لا | الحد الأدنى التالي | — | تلميح حقل المزايدة |
| `starts_at` | ISO | نعم | موعد البداية | `2026-08-05T10:00:00+03:00` | عداد `scheduled` |
| `ends_at` | ISO | نعم | موعد النهاية **الحالي** بعد التمديدات | — | عداد `live` |
| `extension.count` | int | لا | عدد التمديدات | `2` | شارة تمديد |
| `extension.last_extended_at` | ISO | نعم | آخر تمديد | — | تنبيه |
| `images[]` | array | لا | قد تكون فارغة | — | معرض |
| `category` | object | — | `{id, name}` — **غائب إن لم تُحمّل** | — | تصنيف |
| `country` `state` `city` | object | نعم | في التفاصيل فقط | — | الموقع |
| `seller` | object | — | **غائب دائمًا في المسار العام** | — | — |

**غير مكشوف في المسار العام:** `reserve_amount` (عمدًا)، `minimum_bid_increment`، `seller_deposit_amount`، `bidder_deposit_amount`، `platform_fee_*`، `winner_payment_deadline_hours`، `handover_deadline_hours`، `original_ends_at`، `published_at`، `started_at`، `ended_at`، `finalized_at`، `completed_at`، `cancelled_at`، `winning_bid_id`، `metrics`، `latitude`/`longitude`.

### 19.2 Auction Media

| الحقل | النوع | null | ملاحظة |
|---|---|:--:|---|
| `id` | string(26) | لا | — |
| `url` | string | لا | رابط عام كامل من `Storage::disk($disk)->url($path)` |
| `mime_type` | string | لا | `image/jpeg` … |
| `sort_order` | int | لا | ترتيب مضمون بقيد فريد لكل مزاد |
| `is_primary` | bool | لا | قد تكون **كل** القيم `false` — الاحتياط: الأولى |

### 19.3 Configuration Snapshot

**لا يصل أي حقل من الـsnapshot إلى تطبيق المستخدم.** الجدول للفهم فقط ولتحديد ما يجب كشفه لاحقًا:

| المفتاح | يحكم |
|---|---|
| `terms_version_id` | أي نسخة شروط يجب قبولها للمزايدة |
| `currency_code` | عملة كل العمليات |
| `minimum_bid_increment_minor` | الحد الأدنى للزيادة |
| `auto_extend_enabled` / `auto_extend_window_seconds` / `auto_extend_duration_seconds` / `maximum_extensions` | Anti-Sniping |
| `seller_deposit_required_minor` / `bidder_deposit_required_minor` | قيم العرابين |
| `non_winner_deposit_hold_policy` / `alternative_candidate_limit` | حجز عرابين الخاسرين |
| `winner_payment_deadline_minutes` / `handover_deadline_minutes` | المهل |
| `platform_fee_type` / `platform_fee_value` / `platform_fee_min_minor` / `platform_fee_max_minor` | الرسوم |
| `winner_default_deposit_policy` / `seller_deposit_policy` | المصادرة والاسترداد |
| `alternative_winner_enabled` | تفعيل الفائز البديل |
| `snapshot_hash` | بصمة قانونية للإعدادات |

الـsnapshot يُنشأ لحظة الموافقة ولا يتغير أبدًا: `AuctionConfigurationSnapshotImmutableException` تمنع تعديل `configuration_version_id`، و`AuctionConfigurationVersionInUseException` تمنع تعديل نسخة إعدادات مستخدَمة.

### 19.4 Participant

| الحقل | النوع | null | مرئي للمستخدم؟ |
|---|---|:--:|---|
| `id` | string(26) | لا | نعم — في استجابة التسجيل فقط |
| `auction_id` | string(26) | — | عند تحميل العلاقة |
| `status` | enum | لا | **في استجابة التسجيل فقط، لا في تفاصيل المزاد** |
| `registered_at` | ISO | لا | نفس القيد |
| `qualified_at` | ISO | نعم | نفس القيد |
| `user` `blocked_at` `block_reason` | — | — | إداري فقط |

### 19.5 Bid

| الحقل | النوع | null | ملاحظة |
|---|---|:--:|---|
| `id` | string(26) | لا | — |
| `auction_id` | string(26) | — | غائب إن لم تُحمّل العلاقة |
| `amount` | Money | لا | — |
| `sequence_number` | int | لا | الترتيب الزمني الحقيقي |
| `accepted_at` | ISO | لا | — |
| `bidder` | mixed | — | كائن، أو `{anonymous:true}`، أو **غائب** |

**يرسله التطبيق:** `amount` (نص عشري)، `currency_code`، `idempotency_key`، `client_request_id` (اختياري ومخزَّن ولا يُستخدم في منع التكرار).

### 19.6 Deposit

| الحقل | النوع | null | ملاحظة |
|---|---|:--:|---|
| `id` | string(26) | لا | — |
| `type` | string | لا | `seller` / `bidder` |
| `status` | enum | لا | ثماني حالات |
| `required_amount` | Money | لا | المطلوب |
| `held_amount` | Money | لا | المحتجز |
| `applied_amount` | Money | لا | المطبَّق |
| `refunded_amount` | Money | لا | المسترد |

**مكشوف:** `forfeited_amount`, `hold_reason`, `hold_reason_label`, `hold_metadata`, `candidate_rank`, `hold_expires_at`, `expected_release_condition`, `refund_eligibility`. **[منفذ]**

### 19.7 Payment Submission

| الحقل | النوع | null | ملاحظة |
|---|---|:--:|---|
| `id` | string(26) | لا | يُستخدم في `receipt-url` |
| `purpose` | enum | لا | `seller_deposit`/`bidder_deposit`/`winner_settlement` |
| `status` | enum | لا | `pending_review`/`approved`/`rejected` |
| `amount` | Money | لا | — |
| `payment_method` | object | — | `{id, name, code}` — **قد يغيب عند إعادة الإرسال بنفس المفتاح** |
| `submitted_at` | ISO | لا | — |
| `reviewed_at` | ISO | نعم | — |
| `review_note` | string | نعم | **مرئي لصاحب الطلب** — يحمل سبب الرفض |

**يرسله التطبيق:** `payment_method_id` (26 حرفًا)، `receipt` (ملف)، `idempotency_key`، `provider_reference`.
**إداري فقط:** `provider_reference` في الاستجابة، `transaction`, `user`.

### 19.8 Settlement — كتلة الفائز

| الحقل | النوع | null | ملاحظة |
|---|---|:--:|---|
| `status` | enum | لا | سبع حالات |
| `amount_due` | Money | لا | المستحق بعد العربون |
| `amount_paid` | Money | لا | المدفوع |
| `remaining_amount` | Money | لا | **المبلغ الواجب دفعه بالضبط** |
| `payment_due_at` | ISO | نعم | `null` إذا غطى العربون المبلغ |

**مكشوف للفائز:** `winning_amount`, `deposit_applied`, `amount_due`, `remaining_amount`, `payment_due_at`, `payment_grace_ends_at`, `handover_due_at`, `defaulted_at`, `completed_at`. عمولة المنصة تبقى مخفية عن المزايد عمدًا. **[منفذ]**

### 19.9 Settlement — كتل البائع

`seller_financial_summary`: `status`, `winning_amount`, `seller_net_amount`, `platform_fee`, `completed_at`, `payout`.
`handover_status`: `payment_due_at`, `handover_due_at`, `seller_handover_confirmed_at`, `buyer_receipt_confirmed_at`, `handover_completed_at`.

### 19.10 Refund — من `my_refunds[]`

`id`, `status`, `amount`, `processed_at`, `succeeded_at`, `failed_at`, `cancelled_at`.
**مكشوف:** `reason`, `failure_reason`, `attempt_count`, `currency`. **[منفذ]**

### 19.11 Seller Payout — من `sellerPayload()`

| الحقل | النوع | null | ملاحظة |
|---|---|:--:|---|
| `id` | string(26) | لا | — |
| `status` | enum | لا | ست حالات |
| `auction` | object | نعم | `{id, title}` |
| `amount` | Money | لا | صافي البائع |
| `currency` | string | لا | — |
| `has_destination` | bool | لا | **مفتاح الـCTA**: `false` ⇒ أضف وسيلة استلام |
| `destination` | object | نعم | معرّف **مقنّع** `****1234` |
| `payout_method` | string | نعم | — |
| `transfer_reference` | string | نعم | مرجع التحويل |
| `paid_at` `created_at` | ISO | نعم/لا | — |

**مكشوف للبائع:** `hold_reason`, `failure_reason`, `required_action`, `held_at`, `processing_started_at`, `failed_at`, `has_proof`. **[منفذ]**

### 19.12 Payout Destination

`id`, `recipient_name`, `identifier_type`, `identifier_value` (**غير مقنّع هنا**), `is_default`, `created_at`.
**يرسله التطبيق:** `recipient_name` (مطلوب ≤120)، `identifier_type` (مطلوب من قائمة مغلقة)، `identifier_value` (مطلوب ≤160)، `is_default` (اختياري).

### 19.13 Payment Method

`id`, `name`, `code`, `recipient_name`, `identifier_type`, `identifier_value`, `instructions`, `requires_manual_review`, `is_active`.
**[منفذ]** `PaymentMethodDisclosureRule` تحجب `recipient_name` و`identifier_value` و`instructions` عن المسار العام غير المصادَق، ولا تكشفها إلا لمن لديه التزام مالي فعلي على المزاد.

### 19.14 Terms Version

من `GET /api/soom/auction-terms`: `id`, `version_number`, `title`, `is_active`, `published_at`.
**`body` متاح** عبر `GET /api/auctions/{auction}/terms` للمستخدم. **[منفذ]**
القائمة تعيد **كل** النسخ بما فيها غير المنشورة، مرتبة `latest('version_number')`.

### 19.15 Notification

من `GET /api/notifications`، وحمولة `AuctionOutboxNotification::toArray()`:
`event_id`, `event_type`, `auction_id`, `auction_title`, `screen`, `title`, `message`، بالإضافة إلى حقول إضافية حسب الحدث (مثل `payout_id`).
حمولة البث تضيف `created_at` و`unread_count`.
`screen` ∈ `auction_details | seller_auction | auction_payment | auction_refunds | auction_dispute | seller_payouts`.

---

<a id="s20"></a>

## 20. العملات والمبالغ المالية

### 20.1 التمثيل

كل مبلغ في النظام **عدد صحيح بالوحدة النقدية الصغرى** في عمود `unsignedBigInteger`. لا يوجد `decimal` ولا `float` لأي مبلغ.

الاختبار `test_auction_financial_code_does_not_use_floating_point_types_or_casts` يفحص بتعبير نمطي كل ملفات `Domain/Auction` و`Services/Auction` و`Models/Auction` و`Http/{Controllers,Requests,Resources}/Auction` ويطالب بصفر مطابقات لـ`float`/`double`. **[منفذ]**

### 20.2 العملات المدعومة

| العملة | الأس | المقياس | مثال |
|---|:--:|---|---|
| `JOD` | 3 | 1000 | `1250500` ⇒ `1250.500` |
| `EGP` | 2 | 100 | `125050` ⇒ `1250.50` |
| `USD` | 2 | 100 | `125050` ⇒ `1250.50` |

أي رمز آخر يرفع `Unsupported auction currency.` من `Currency::fromCode()`.

### 20.3 شكل الإرسال والاستقبال

**الاستقبال:** كائن ثلاثي الحقول:
```json
{ "amount": "1250.500", "minor": 1250500, "currency": "JOD" }
```
`amount` **نص وليس رقمًا** — مقصود لتجنّب فقدان الدقة في JavaScript. القاعدة العملية: **احسبي دائمًا على `minor` واعرضي دائمًا `amount`.** لا تحوّلي `amount` إلى `float`.

**الإرسال:** نص عشري في `amount` مع `currency_code`. `CurrencyDecimalRule` يفرض `/^\d+(\.\d+)?$/` (لا سالب، لا فواصل آلاف، لا رموز) وعدد خانات لا يتجاوز أس العملة.
`100` و`100.5` و`100.500` كلها صالحة لـJOD. `100.5000` مرفوضة برسالة «عدد الخانات العشرية يتجاوز الحد المسموح لعملة JOD (الحد الأقصى 3)».

**توصية إدخال:** قيّدي حقل المزايدة بعدد الخانات حسب `currency_code` واعرضي الحد الأدنى من `minimum_next_bid.amount`.

### 20.4 المكوّنات المالية

| المكوّن | المصدر | مرئي للمزايد | مرئي للبائع |
|---|---|:--:|:--:|
| سعر البداية | `starting_amount` | نعم | نعم |
| السعر الاحتياطي | `reserve_amount_minor` | لا | **لا** |
| أعلى مزايدة | `current_amount` | نعم | نعم |
| عربون المزايد | `snapshot` | **لا قبل الإنشاء** | لا |
| عربون البائع | `snapshot` | لا | **لا قبل الإنشاء** |
| رسوم المنصة | `settlement.platform_fee_minor` | لا (تُخصم من البائع) | بعد الاكتمال فقط |
| صافي البائع | `seller_net_amount_minor` | لا | بعد الاكتمال فقط |
| المستحق على الفائز | `amount_due_minor` | نعم | لا |
| المتبقي | `remaining_amount_minor` | نعم | لا |
| ضريبة | **غير موجودة** | — | — |

### 20.5 الرسوم والتقريب

`platform_fee_type` ∈ `percentage` (مع `platform_fee_basis_points`، افتراضي 250 = 2.5%) أو `fixed`. الحساب صحيح بالكامل، ويوجد `platform_fee_min_minor` و`platform_fee_max_minor` في الـsnapshot.

لا تُجري الواجهة أي تقريب أو حساب مالي. كل مبلغ يجب أن يُعرض من `amount` النصي كما ورد.

### 20.6 أمثلة عرض

| السياق | القيمة | العرض المقترح |
|---|---|---|
| سعر بداية JOD | `{"amount":"100.000","minor":100000}` | `100.000 د.أ` |
| أعلى مزايدة | `{"amount":"1250.500"}` | `1,250.500 د.أ` |
| عربون EGP | `{"amount":"500.00","minor":50000}` | `500.00 ج.م` |
| متبقٍّ صفر | `{"amount":"0.000","minor":0}` | «مسدد بالكامل» لا `0.000` |

---

<a id="s21"></a>

## 21. التواريخ والتوقيت والعداد التنازلي

### 21.1 التمثيل

كل الأعمدة الزمنية `timestampTz` (مع منطقة زمنية)، وكل الحقول في الـAPI بصيغة ISO 8601 مع الإزاحة عبر `toIso8601String()`. كل الحقول تُصبّ في `immutable_datetime` في النماذج.

مثال: `2026-08-05T14:30:00+03:00`.

**استثناء واحد:** نصوص الإشعارات تُنسَّق داخل `NotificationValueFormatter` بصيغة `Y-m-d H:i` بتوقيت `config('app.timezone')` بلا إزاحة، ومحليًا بالعربية دائمًا لأن `LOCALE = 'ar'` مثبتة في الكود. **[منفذ]** — أي أن **الإشعارات عربية دائمًا بصرف النظر عن لغة التطبيق**، وملف `lang/en/auction.php` للإشعارات غير مستخدم عمليًا.

### 21.2 الحقول الزمنية المهمة

| الحقل | المصدر | متاح لمن |
|---|---|---|
| `starts_at` | موعد البداية المخطط | الجميع |
| `ends_at` | **النهاية الحالية بعد التمديدات** | الجميع |
| `original_ends_at` | النهاية الأصلية | إداري فقط |
| `extension.last_extended_at` | آخر تمديد | الجميع |
| `payment_due_at` | مهلة الفائز | الفائز والبائع |
| `handover_due_at` | مهلة التسليم | البائع فقط |
| `submitted_at` / `reviewed_at` | دورة الإيصال | صاحب الطلب |
| `succeeded_at` / `failed_at` / `cancelled_at` | دورة الاسترداد | صاحب الاسترداد |

**غير موجود:** `registration_deadline`، `deposit_deadline` (هي `ends_at` ضمنيًا)، أي `grace_period`.

### 21.3 قواعد العداد التنازلي

المصدر الوحيد للنهاية هو `ends_at` من الخادم. القواعد الملزمة:

1. **لا تعتمدي على ساعة الجهاز.** احسبي الإزاحة مرة واحدة عند أول استجابة: `offset = serverTime - deviceTime`، ثم شغّلي العداد على `deviceTime + offset`. كل استجابة تحمل `server_time` صراحة عبر `AttachServerTime`. استخدميه لا رأس `Date` ولا ساعة الجهاز. **[منفذ]**

2. **وصول العداد إلى الصفر لا يعني الانتهاء.** المزاد ينتهي عندما يعمل الـJob (حتى دقيقة تأخير). عند بلوغ الصفر اعرضي «انتهى المزاد، جارٍ تحديد النتيجة» وأوقفي المزايدة محليًا، وانتظري `auction.status_changed` أو أعيدي الجلب.

3. **التمديد يعيد ضبط العداد.** حمولة `auction.bid_accepted` تحمل `ends_at` الجديد و`extended: true`. أعيدي الضبط من الحمولة فورًا واعرضي تنبيهًا بالتمديد.

4. **عند العودة إلى التطبيق** (foreground أو إعادة فتح) أعيدي جلب التفاصيل — لا تستأنفي عدادًا محسوبًا مسبقًا.

5. **`starts_at` قد يكون `null`** نظريًا (العمود nullable). عالجي الحالة بلا انهيار.

6. **التوقيت الصيفي** غير مؤثر لأن كل التواريخ تحمل إزاحتها.

### 21.4 المهل الزمنية الفعلية

| المهلة | القيمة | التنفيذ |
|---|---|---|
| عربون المزايد | `ends_at` | يُرفض بعده |
| عربون البائع | **لا مهلة** | ينتظر بلا حد |
| دفع الفائز | `payment_due_at` (افتراضي 48 ساعة) | يُرفض بعده؛ التعثر إداري |
| التسليم | `handover_due_at` (افتراضي 72 ساعة) | **محسوب ولا يُنفَّذ** |
| فترة سماح | **غير موجودة** | — |

**توصية عرض:** فرّقي بصريًا بين مهلة **تحظر إجراءً** (عربون المزايد ودفع الفائز) وبين مهلة **إعلامية فقط** (التسليم). عرضهما بنفس الشكل سيولّد توقعًا خاطئًا.

</div>
<div dir="rtl" align="right">

---

<a id="s22"></a>

## 22. الـAPIs الخاصة بتطبيق المستخدم

### 22.1 قواعد عامة

**الأساس:** كل المسارات تحت `/api` وداخل `api_maintenance`. عند تفعيل الصيانة كل المسارات تعيد **503** `{"status":"maintenance","message":"الموقع تحت الصيانة الآن، برجاء المحاولة لاحقاً."}` — وهو **شكل مختلف** عن غلاف الأخطاء العادي.

**المصادقة:** `Bearer <token>` عبر Laravel Sanctum.

**غلاف النجاح:**
```json
{ "success": true, "message": "...", "data":... }
```
مع الترقيم تُضاف `current_page`, `last_page`, `per_page`, `total`, `next_page_url`, `prev_page_url` **كأشقاء لـ`data`**.

**ثلاثة أشكال مختلفة للأخطاء [منفذ]** — يجب على طبقة الشبكة التعامل مع الثلاثة:

| المصدر | الشكل | الرمز |
|---|---|---|
| منطق المزاد | `{"success": false, "message": "رسالة عربية"}` | 422 / 403 / 404 |
| التحقق من المدخلات | `{"message": "...", "errors": {"field": ["..."]}}` | 422 |
| غياب المصادقة | `{"message": "Unauthenticated."}` | 401 |
| `RoleMiddleware` | `{"message": "يرجى تسجيل الدخول."}` **بلا `success`** | 403 |

**المعرّفات:** كل `{auction}` و`{sellerPayout}` و`{paymentSubmission}` و`{payoutDestination}` و`{paymentMethod}` هي **ULID من 26 حرفًا** (`public_id`) وليست أرقامًا. `HasPublicId::getRouteKeyName()`.

**Idempotency:** لا يوجد رأس `Idempotency-Key`. المفتاح **حقل إلزامي في body** لمسارين فقط: المزايدة وطلبات الدفع.

### 22.2 المسارات العامة — بلا مصادقة إلزامية

| Method | Path | الغرض | الشاشة |
|---|---|---|---|
| GET | `/api/auctions` | قائمة المزادات العامة | 1 |
| GET | `/api/auctions/{auction}` | تفاصيل المزاد | 3 |
| GET | `/api/auctions/{auction}/bids` | سجل المزايدات | 12 |
| GET | `/api/soom/payment-methods` | طرق الدفع | 8، 19 |
| GET | `/api/soom/payment-methods/{paymentMethod}` | تفاصيل طريقة دفع | 8 |
| GET | `/api/soom/auction-terms` | قائمة نسخ الشروط (**بلا نص**) | 5 |

الثلاثة الأولى عبر `OptionalSanctumAuthentication`: الـtoken الصالح يغيّر شكل الاستجابة، وغير الصالح يُعامل كزائر بلا 401.

**تفاصيل `GET /api/auctions`:** Query: `per_page` (1–100، افتراضي 20)، `category_id` (موجود في `categories`)، `status` (**يُقبل ويُتجاهل**). النجاح 200. أخطاء: 422 تحقق، 503 صيانة.

**تفاصيل `GET /api/auctions/{auction}`:** أثر جانبي — تسجيل مشاهدة في `auction_views` و`auction_metrics`. أخطاء: **404** لأي مزاد غير عام (وليس 403). الاستجابة أحد ثلاثة موارد.

**تفاصيل `GET /api/auctions/{auction}/bids`:** Query `per_page`. ترتيب `amount_minor DESC, sequence_number ASC`. 404 لغير العام.

### 22.3 مسارات المزايد

#### `POST /api/soom/auctions/{auction}/register`
**المصادقة:** إلزامية. **التخويل:** `AuctionPolicy::register()`. **Body:** لا شيء.
**النجاح:** 201 + `AuctionParticipantResource`.
**الأخطاء:** 403 (بائع أو حالة غير مسموحة)، 422 `registration_closed` / `seller_cannot_register`، 404.
**الأثر:** إنشاء `AuctionParticipant`، تحديث المقاييس، outbox `auction.participant_registered`.
**Idempotency:** بالبنية. **CTA:** «سجّل في المزاد».

#### `POST /api/soom/auctions/{auction}/accept-terms`
**التخويل:** لا Gate — الحماية داخل الـAction. **Body:** لا شيء.
**النجاح:** 201 + `{"id": "<ULID>"}`.
**الأخطاء:** 422 `terms_registration_required` / `terms_missing`.
**Idempotency:** بالبنية. **CTA:** «أوافق على الشروط».

#### `POST /api/soom/auctions/{auction}/bidder-deposit`
**Body (`multipart/form-data`):** `payment_method_id` (مطلوب، 26 حرفًا)، `receipt` (مطلوب، `jpg,jpeg,png,pdf,webp`، ≤5MB)، `idempotency_key` (مطلوب ≤120)، `provider_reference` (اختياري ≤160).
**النجاح:** 201 + `PaymentSubmissionResource`.
**الأخطاء:** 422 `registration_required` / `participant_not_eligible` / `bidder_deposit_state_not_allowed` / `payment_deadline_expired` / `payment_obligation_already_paid` / `active_payment_submission_exists` / `zero_deposit_not_required`؛ 422 تحقق.
**الأثر:** إنشاء العربون إن لم يوجد، تخزين الإيصال على `spaces_private`، العربون `pending_review`، outbox `auction.payment_submitted`.
**Idempotency:** بالمفتاح + قيد فريد. **تنبيه:** عند الإعادة قد يغيب `payment_method`.

#### `POST /api/soom/auctions/{auction}/bids`
**التخويل:** `AuctionPolicy::bid()` — ليس البائع والحالة `live`.
**Body:** `amount` (نص عشري بقاعدة `CurrencyDecimalRule`)، `currency_code` (`size:3`, `in:JOD,EGP,USD`)، `idempotency_key` (مطلوب ≤120)، `client_request_id` (اختياري).
**النجاح:** 201 + `AuctionBidResource`.
**الأخطاء:** 403؛ 422 من ثمانية أنواع (راجع [11.3](#s11))؛ 422 تحقق.
**الأثر:** إنشاء المزايدة، تحديث `current_leading_bid_id`، تمديد محتمل، outbox + بث + إشعار القائد السابق.
**التزامن:** `SELECT... FOR UPDATE` على المزاد.
**Idempotency:** كاملة — الإعادة تعيد 201 بنفس السجل.
**[منفذ]** `throttle:auction-bids` — 30/دقيقة لكل مستخدم ومزاد.

#### `POST /api/soom/auctions/{auction}/winner-payment`
نفس بنية `bidder-deposit`.
**الأخطاء الإضافية:** 422 `settlement_payment_unavailable` / `winner_payment_state_not_allowed` / `payment_target_not_current` / `winner_changed` / `payment_amount_exceeds_remaining` / `payment_amount_mismatch` / `payment_deadline_expired`.
**CTA:** «ادفع المتبقي».

#### `POST /api/soom/auctions/{auction}/confirm-receipt`
**التخويل:** `confirmWinnerReceipt` — الحالة `handover_pending` والمستخدم هو الفائز.
**النجاح:** 200 + المزاد المحدَّث.
**الأخطاء:** 403؛ 422 `seller_handover_required`؛ 404.
**الأثر:** إكمال التسوية، المزاد `completed`، تحرير العرابين، معالجة عربون البائع، **إنشاء مستحقات البائع**.
**CTA:** «أكّد الاستلام» — يظهر فقط بعد `seller_handover_confirmed_at`.

#### `POST /api/soom/auctions/{auction}/disputes`
**التخويل:** `openDispute` — بائع أو فائز، في `live`/`ended`/`handover_pending`/`payment_pending`.
**Body:** `reason` (مطلوب ≤1000). **النجاح:** 201 + `{id, status}`.
**[منفذ]** `GET /api/soom/auctions/{auction}/disputes` للأطراف.

#### `GET /api/soom/my/bids`
Query `per_page`. ترتيب `latest('id')`. 200 + مجموعة مرقّمة.

#### `GET /api/soom/payment-submissions/{paymentSubmission}/receipt-url`
**التخويل:** المالك أو مراجع. **النجاح:** 200 + `{url, expires_at}` — رابط مؤقت 10 دقائق.
**الأخطاء:** 403؛ 404 «رابط الإيصال غير متاح».

### 22.4 مسارات البائع

#### `POST /api/soom/auctions`
`multipart/form-data`. الحقول في [6.6](#s6). **النجاح:** 201 + `MyAuctionResource` بحالة `draft`.
**الأخطاء:** 422 تحقق (شاملة `reserve_below_starting`)؛ 403 إذا لم يكن الدور `user` أو `admin`.
**الأثر:** إنشاء المزاد ونسخ كل الإعدادات المالية والزمنية من النسخة النشطة، رفع الوسائط (تُحذف تلقائيًا عند فشل الإدراج).
**تنبيه:** الاستجابة لا تحوي `images`.

#### `POST /api/soom/auctions/{auction}/submit-review`
**التخويل:** `submitForReview` — البائع والحالة `draft` أو `rejected`.
**الأخطاء:** 422 `seller_only_submit_review` / `invalid_auction_times` / `active_terms_required`.
**الأثر:** الحالة → `pending_review` + إشعار.

#### `POST /api/soom/auctions/{auction}/seller-deposit`
نفس بنية `bidder-deposit`. **الأخطاء الخاصة:** 422 `seller_deposit_state_not_allowed` (خارج `awaiting_seller_deposit`) / `payment_target_owner_mismatch` / `zero_deposit_not_required`.
**بعد الاعتماد:** المزاد → `scheduled` وإعلان عام + بث FCM.

#### `POST /api/soom/auctions/{auction}/confirm-handover`
**التخويل:** البائع والحالة `handover_pending`.
**الأخطاء:** 403؛ 422 `settlement_must_be_paid`.
**الأثر:** `seller_handover_confirmed_at` + إشعار للفائز.

#### `DELETE /api/soom/auctions/{auction}`
**Body:** `reason_text`/`reason` (مطلوب فعليًا ≤2000)؛ `reason_code` و`liability` مطلوبان للإداري.
**الأخطاء:** 403؛ 422 `cancellation_reason_required` / `auction_cancellation_not_allowed` / أخطاء الاسترداد.
**الأثر:** إلغاء ذري كامل — راجع [18.2](#s18).

#### `GET /api/soom/my/auctions`
Query `per_page`. `where('seller_id', me)` بكل الحالات. العلاقات المحمّلة لا تشمل `category`/`country`/`bids`/`deposits`، فمفاتيح `my_bids`, `my_deposits`, `category` **غائبة** من القائمة وموجودة في `show`.

#### مستحقات البائع

| Method | Path | ملاحظة |
|---|---|---|
| GET | `/api/soom/my/payouts` | Query `status`, `per_page`. بلا Gate — مقيّد بـ`seller_id` |
| GET | `/api/soom/my/payouts/{sellerPayout}` | **404** إن لم يكن المالك (لا 403) |
| GET | `/api/soom/my/payouts/{sellerPayout}/proof-url` | 200 `{url, expires_at}` أو 404 |
| GET | `/api/soom/my/payout-destinations` | معرّف **غير مقنّع** |
| POST | `/api/soom/my/payout-destinations` | 201 |
| PUT | `/api/soom/my/payout-destinations/{payoutDestination}` | 200؛ 404 لغير المالك |

**لا يوجد DELETE لوسيلة الاستلام.**

### 22.5 الإشعارات

| Method | Path |
|---|---|
| GET | `/api/notifications` |
| POST | `/api/notifications/mark-all-as-read` |
| PUT | `/api/notifications/{id}/read` |

### 22.6 البث اللحظي

| القناة | النوع | الأحداث |
|---|---|---|
| `auction.{public_id}` | عامة | `auction.bid_accepted`, `auction.status_changed`, `auction.finalized`, `auction.alternative_winner_selected`, `auction.cancelled` |
| `public.auctions` | عامة | `auction.announcement` |
| `App.Models.User.{id}` | خاصة | إشعارات المستخدم — تحتاج `POST /api/broadcasting/auth` |

### 22.7 جاهزية التكامل

| المسار | جاهز؟ | النقص |
|---|:--:|---|
| `GET /api/auctions` | جزئيًا | لا فلترة ولا بحث ولا حالة مستخدم ولا `metrics` |
| `GET /api/auctions/{auction}` | نعم | ثلاثة أشكال؛ ينقصه حالة المشارك والشروط والعربون المطلوب |
| `GET /{auction}/bids` | نعم | — |
| `POST /{auction}/register` | نعم | — |
| `POST /{auction}/accept-terms` | **لا** | لا نص شروط ولا معرفة بالنسخة ولا حالة قبول |
| `POST /{auction}/bidder-deposit` | جزئيًا | المبلغ المطلوب غير معروف مسبقًا |
| `POST /{auction}/bids` | نعم | بلا throttle |
| `POST /{auction}/winner-payment` | نعم | — |
| `confirm-handover` / `confirm-receipt` | نعم | — |
| `POST /{auction}/disputes` | جزئيًا | لا قراءة بعد الإنشاء |
| `GET /my/bids` | جزئيًا | بلا بيانات المزاد |
| `GET /my/auctions` | نعم | للبائع فقط |
| مستحقات ووسائل الاستلام | نعم | مع أسباب الحجز والفشل والإجراء المطلوب والأرشفة الآمنة |
| `GET /my/participations` | نعم | مشاركات المزايد بحالتها الكاملة |
| `GET /my/refunds` | نعم | مع الفلترة بالحالة |
| `GET /auctions/{id}/terms` | نعم | نص الشروط ورقم الإصدار |
| `GET /auctions/{id}/disputes` | نعم | قراءة النزاع للأطراف فقط |
| `GET /soom/support-contact` | نعم | قنوات الدعم المفعّلة فقط |

---

<a id="s23"></a>

## 23. الأخطاء ورسائل الواجهة

الرسائل العربية أدناه **موجودة فعليًا** في `lang/ar/auction.php` وتصل جاهزة في `message`. القاعدة: **اعرضي الرسالة كما جاءت** ولا تُعيدي بناءها، وأضيفي فقط الـCTA المناسب.

**كل استجابة خطأ تحمل حقل `code` ثابتًا** بجانب `message`. القاعدة الملزمة: **اشتقّي منطق الواجهة من `code` لا من نص الرسالة إطلاقًا** — النص قابل للتغيير والترجمة، والرمز عقد مستقر.

```json
{ "success": false, "message": "المزايد غير مؤهل لهذا المزاد.", "code": "bidder_not_qualified" }
```

الأخطاء المشتركة ورموزها: `unauthenticated` (401) · `forbidden` (403) · `auction_not_found` / `not_found` (404) · `validation_failed` (422 مع `errors`) · `too_many_requests` (429 مع `retry_after` بالثواني على جذر الاستجابة لا داخل `data`).

### 23.1 أخطاء المزايدة — كلها 422

| الرسالة العربية | السبب | Retry | Refresh | CTA |
|---|---|:--:|:--:|---|
| لا يمكن للبائع المزايدة على مزاده | البائع يزايد | لا | لا | إخفاء الزر |
| المزاد غير مباشر الآن | الحالة ليست `live` | لا | **نعم** | تحديث |
| المزاد خارج نافذة المزايدة | خارج `starts_at`–`ends_at` | لا | **نعم** | تحديث |
| المزايد غير مؤهل لهذا المزاد | ليس `qualified` | لا | نعم | «ادفع العربون» |
| يجب قبول شروط المزاد قبل المزايدة | لم يقبل نسخة الـsnapshot | لا | لا | «اقرأ الشروط» |
| يجب وجود تأمين مزايد معتمد قبل المزايدة | العربون ليس `held` | لا | نعم | «ادفع العربون» |
| عملة المزايدة لا تطابق عملة المزاد | خطأ عميل | لا | لا | تصحيح برمجي |
| قيمة المزايدة أقل من الحد الأدنى | تجاوزه مزايد آخر | لا | **نعم** | «زد المبلغ» |

### 23.2 أخطاء التسجيل والشروط — 422

| الرسالة | السبب | CTA |
|---|---|---|
| لا يمكن للبائع التسجيل كمزايد | البائع | إخفاء |
| المزاد غير مفتوح لتسجيل المشاركين | حالة غير مسموحة | تحديث |
| يجب تسجيل المشارك قبل إرسال التأمين | لم يسجل | «سجّل أولًا» |
| يجب تسجيل المشارك قبل قبول الشروط | لم يسجل | «سجّل أولًا» |
| لا توجد نسخة شروط لهذا المزاد | خلل بيانات | «تواصل مع الدعم» |

### 23.3 أخطاء الدفع — 422

| الرسالة | السبب | Retry | CTA |
|---|---|:--:|---|
| تمت معالجة هذا الدفع مسبقًا | الطلب لم يعد `pending_review` | لا | تحديث |
| تم سداد هذا الالتزام المالي مسبقًا | الالتزام مدفوع | لا | تحديث |
| يوجد طلب دفع لهذا الالتزام المالي قيد المراجعة | طلب معلّق | لا | «تابع حالة الطلب» |
| لا يمكن دفع عربون البائع في الحالة الحالية للمزاد | خارج `awaiting_seller_deposit` | لا | تحديث |
| لا يمكن دفع عربون المزايد في الحالة الحالية للمزاد | خارج `scheduled`/`live` | لا | تحديث |
| لا يمكن دفع تسوية الفائز في الحالة الحالية للمزاد | ليس `payment_pending` | لا | تحديث |
| انتهت مهلة الدفع | تجاوز المهلة | لا | «تواصل مع الدعم» |
| لم يعد هدف الدفع هو الهدف الحالي | تغيّر الفائز أو التسوية | لا | تحديث |
| لا يخصك هذا الالتزام المالي | خطأ ملكية | لا | تحديث |
| مبلغ الدفع لا يطابق المبلغ المطلوب | أقل من المطلوب | نعم | تصحيح |
| مبلغ الدفع يتجاوز رصيد التسوية المتبقي | أكثر من المتبقي | نعم | تصحيح |
| عملة الدفع لا تطابق عملة المزاد | خطأ عميل | لا | تصحيح |
| التأمين غير مطلوب لهذا المزاد | عربون صفري | لا | — |
| المشارك غير مؤهل لهذا الدفع | `blocked` | لا | «تواصل مع الدعم» |
| تغير الفائز منذ بدء هذه العملية | إعادة تعيين | لا | تحديث |

### 23.4 أخطاء الحالة والتسليم — 422

| الرسالة | السياق |
|---|---|
| لا يمكن نقل المزاد من:from إلى:to | انتقال غير مسموح |
| يجب سداد التسوية قبل التسليم | البائع يؤكد قبل السداد |
| يجب تأكيد التسليم من البائع قبل تأكيد استلام الفائز | الفائز سبق البائع |
| لا يمكن إلغاء المزاد في حالته الحالية | `handover_pending` أو `completed` |
| سبب إلغاء المزاد مطلوب | `reason_text` فارغ |

### 23.5 أخطاء الاسترداد والمستحقات — 422

| الرسالة | ملاحظة |
|---|---|
| مبلغ الاسترداد يتجاوز الرصيد المتاح | — |
| يجب أن يكون مبلغ الاسترداد أكبر من صفر | يظهر أيضًا عند وجود استرداد معلق يحجز المبلغ |
| لا يمكن معالجة الاسترداد في حالته الحالية | — |
| لا توجد وسيلة استلام صالحة للبائع | **يظهر للبائع** — CTA «أضف وسيلة استلام» |
| لا يمكن صرف المستحقات مع وجود نزاع مفتوح على المزاد | يفسّر `on_hold` |

### 23.6 أخطاء عامة

| الرمز | الشكل | الرسالة | المعالجة |
|---|---|---|---|
| 401 | `{"message":"Unauthenticated."}` | — | إعادة تسجيل الدخول |
| 403 | `{"success":false,"message":"غير مصرح."}` | Policy | إخفاء الإجراء |
| 403 | `{"message":"يرجى تسجيل الدخول."}` | `RoleMiddleware` — **بلا `success`** | إعادة تسجيل الدخول |
| 404 | `{"success":false,"message":"المزاد غير موجود."}` | غير عام أو محذوف | العودة للقائمة |
| 422 | `{"message":"...","errors":{...}}` | تحقق المدخلات | إبراز الحقول |
| 503 | `{"status":"maintenance",...}` | صيانة | شاشة صيانة |

**غير موجود:** رمز 429 (لا rate limiting)، ورمز 409 (التعارضات كلها 422).

### 23.7 قاعدة العرض

لا تُعرَض أبدًا: أسماء Classes، رسائل SQL، مسارات ملفات، `event_id`، `snapshot_hash`، أي معرّف داخلي رقمي.
كل رسائل `auction.errors.*` **آمنة للعرض المباشر** — مُراجَعة ومكتوبة بالعربية للمستخدم النهائي.

</div>
<div dir="rtl" align="right">

---

<a id="s24"></a>

## 24. الإشعارات

### 24.1 البنية

`AuctionAudit::outbox()` يكتب رسالة في `outbox_messages` داخل نفس transaction العملية المالية. `DispatchAuctionOutboxJob` كل دقيقة يلتقطها بـlease ويوزعها على ثلاثة جماهير حسب `AuctionNotificationCatalog`:

| الجمهور | الوسيلة |
|---|---|
| **شخصي** | Laravel Notification: `database` + `broadcast` + FCM إذا كان `fcm_token` موجودًا |
| **لحظي** | بث على `auction.{public_id}` |
| **عام** | بث على `public.auctions` + بث FCM جماعي |

**لا بريد إلكتروني ولا SMS في مسار المزادات إطلاقًا.** `AuctionOutboxNotification::via()` تعيد `['database', 'broadcast']` فقط.

**منع التكرار:** قبل الإرسال يُفحص `notifications.data->event_id` لكل مستخدم — نفس الحدث لا يصل مرتين حتى لو أُعيدت معالجة الرسالة.
**الفشل:** 3 محاولات (`auction.outbox.max_attempts`) بفاصل 300 ثانية، ثم `failed` + `dead_lettered_at`، ولا يُحذف صامتًا — الرسالة تبقى في الجدول للمعالجة اليدوية.

**اللغة:** عربية دائمًا — `NotificationValueFormatter::LOCALE = 'ar'` ثابتة في الكود.
**التوجيه:** لا روابط URL؛ التوجيه بحقلي `screen` و`auction_id`.

**سياسة التذكيرات عند تعطل المجدول:** مواعيد التذكير مجمّدة داخل لقطة إعدادات المزاد، فلا تتأثر بتغيير الإعدادات العامة لاحقًا. إذا توقف المجدول ومرّت عدة مواعيد دفعة واحدة، يُرسَل **تذكير واحد فقط هو الأكثر إلحاحًا** وتُسجَّل البقية كمُرسَلة حتى لا تنهال على المستخدم. وإذا مرّ الموعد النهائي نفسه والالتزام ما زال قائمًا، يُرسَل **إشعار تأخر واحد** (`hours_before = 0`) بدل إسقاط التذكير صامتًا. لا يُرسَل أي تذكير بعد اكتمال الإجراء أو بعد انتهاء فترة السماح.

### 24.2 كتالوج إشعارات المزايد

| الحدث | المستلم | العنوان (عربي) | Deep Link |
|---|---|---|---|
| `auction.participant_registered` | المسجل | تم تسجيلك في المزاد | `auction_payment` |
| `auction.payment_submitted` | مرسل الطلب | تم استلام طلب الدفع | `auction_payment` |
| `auction.payment_approved` (`bidder_deposit`) | المزايد | تمت الموافقة على عربونك | `auction_payment` |
| `auction.payment_rejected` | مرسل الطلب | لم تتم الموافقة على الدفع (+ السبب) | `auction_payment` |
| `auction.bid_accepted` | **القائد السابق فقط** | تمت المزايدة عليك | `auction_details` |
| `auction.finalized` | الفائز | مبروك، فزت بالمزاد | `auction_payment` |
| `auction.finalized` (مغطى بالعربون) | الفائز | `finalized_winner_paid` | `auction_payment` |
| `auction.alternative_winner_selected` | الفائز البديل | تم اختيارك فائزًا | `auction_payment` |
| `auction.winner_payment_reminder` (`hours_before = 24/6`) | الفائز | تذكير بموعد سداد المزاد | `auction_payment` |
| `auction.winner_payment_reminder` (`hours_before = 1`) | الفائز | تذكير أخير بموعد السداد | `auction_payment` |
| `auction.winner_payment_reminder` (`hours_before = 0`) | الفائز | انتهت مهلة السداد — أنت ضمن فترة السماح | `auction_payment` |
| `auction.winner_defaulted` | المتعثر | إشعار التعثر | `auction_details` |
| `auction.bidder_lost` | كل مزايد لم يفز | لم تفز بالمزاد (4 صيغ حسب حالة العربون) | `auction_refunds` أو `auction_details` |
| `auction.unsold_bidders` | كل مزايد | انتهى المزاد دون بيع (4 صيغ حسب حالة العربون) | `auction_refunds` أو `auction_details` |
| `auction.handover_reminder` (`audience = winner`) | الفائز | تذكير بتأكيد الاستلام (3 صيغ: 24h / 1h / تأخر) | `auction_details` |
| `auction.winner_deposit_forfeited` | المتعثر | مصادرة العربون | `auction_details` |
| `auction.non_winner_deposit_refund_planned` | الخاسر | جارٍ رد عربونك | `auction_refunds` |
| `auction.refund_succeeded` | صاحب الاسترداد | تم رد العربون | `auction_refunds` |
| `auction.refund_manual_review` | صاحب الاسترداد | استرداد قيد المراجعة | `auction_refunds` |
| `auction.seller_handover_confirmed` | الفائز | البائع أكد التسليم | `auction_details` |
| `auction.dispute_opened` / `dispute_resolved` | البائع + الفائز | نزاع | `auction_dispute` |
| `auction.cancelled` | البائع + الفائز + **كل المشاركين** | تم إلغاء المزاد | `auction_details` |
| `auction.status_changed` (`completed`) | البائع + الفائز | اكتملت الصفقة | `auction_details` |

### 24.3 كتالوج إشعارات البائع

| الحدث | العنوان | Deep Link |
|---|---|---|
| `status_changed` → `pending_review` | تم استلام طلب مزادك | `seller_auction` |
| → `rejected` | لم تتم الموافقة على المزاد (+ السبب) | `seller_auction` |
| → `awaiting_seller_deposit` | مطلوب تأمين البائع | **`auction_payment`** |
| → `scheduled` | تم نشر مزادك (+ موعد البداية) | `seller_auction` |
| → `live` / `ended` / `unsold` | حالة المزاد | `seller_auction` |
| → `handover_pending` | مطلوب تأكيد التسليم | `seller_auction` |
| `auction.finalized` | تم اختيار الفائز بمزادك | `seller_auction` |
| `payment_approved` (`seller_deposit`) | اعتماد تأمين البائع | `auction_payment` |
| `seller_deposit_refund_planned` / `forfeited` / `partially_forfeited` / `manual_review` | مصير عربون البائع | `auction_refunds` أو `auction_details` |
| `seller_payout_created` (3 صيغ: عادي / `on_hold` / `awaiting_destination`) | مستحقاتك | `seller_payouts` |
| `seller_payout_processing` / `paid` / `failed` / `manual_review` / `on_hold` | حالة الصرف | `seller_payouts` |
| `auction.handover_reminder` (`audience = seller`) | تذكير بموعد التسليم (3 صيغ: 24h / 1h / تأخر) | `seller_auction` |
| `auction.seller_deposit_expired` | تم إلغاء المزاد لانتهاء مهلة التأمين | `seller_auction` |

**تحسين مؤكد في الكود:** `status_changed → scheduled` يُرسل **فقط** إذا كانت الحالة السابقة `pending_review`، لتجنّب تكرار إشعار `payment_approved`. و`handover_pending` لا يُرسل إذا جاء من `settlement_pending` (يغطيه `auction.finalized`) ولا من `payment_pending` (يغطيه `payment_approved`). **[منفذ]**

### 24.4 الإعلانات العامة

`public.auctions` بحدث `auction.announcement` بأنواع: `published` (عند `scheduled`)، `started` (عند `live`)، `cancelled` (فقط إذا نُشر ولم يبدأ).

**`published` وحده يطلق بث FCM جماعي** إلى `User::where('allow_ad_notifications', true)->whereNotNull('fcm_token')` مع استثناء البائع. **[منفذ]** — `SendAuctionAnnouncementJob`.

الحمولة: `type`, `auction_id`, `title`, `category`, `image_url`, `starting_amount`, `currency`, `starts_at`, `ends_at`, `screen`, `announcement_title`, `announcement_message`.
الاختبار `test_scheduled_auction_broadcasts_public_announcement_without_private_data` يتحقق من غياب `reserve_amount` و`seller_id` وقيمة السعر الاحتياطي من الحمولة.

### 24.5 إشعارات مطلوبة وغير موجودة

| الحدث | الأثر على التجربة |
|---|---|
| **الخسارة** | الخاسر لا يعلم بالنتيجة إطلاقًا. **الأعلى أولوية.** |
| **قرب بداية المزاد** | المسجل لا يُذكَّر رغم أنه دفع عربونًا. |
| **قرب نهاية المزاد** | لا تنبيه في الدقائق الأخيرة. |
| **التمديد** | يصل عبر البث فقط — من أغلق التطبيق لا يعلم. |
| **قرب انتهاء مهلة الدفع** | الفائز قد يتعثر دون تذكير. |
| **فشل الاسترداد** | `refund_failed` موجود في الـoutbox بلا مستلم. |
| **بدء الاسترداد** | `refund_processing` بلا مستلم. |
| **قبول/رفض المشاركة** | لا يوجد رفض تسجيل أصلًا. |

### 24.6 ملاحظات تكامل

- `screen = auction_refunds` يخدمه `GET /api/soom/my/refunds`. **[منفذ]**
- حمولة البث تحمل `unread_count` — يمكن تحديث الشارة دون نداء إضافي.
- حدث outbox غير معروف في الكتالوج **يفشل ولا يُحوَّل بصمت** إلى إشعار عام (`test_unknown_outbox_event_is_failed_with_diagnostics_not_silently_converted`).

---

<a id="s25"></a>

## 25. حالات العرض العامة

| الحالة | متى تحدث فعليًا | ما تعرضه |
|---|---|---|
| Initial Loading | كل نداء أول | هيكل عظمي مطابق للتخطيط |
| Pull to Refresh | يدويًا، وإلزاميًا بعد كل خطأ حالة | مؤشر علوي مع إبقاء المحتوى |
| Pagination Loading | `next_page_url` غير فارغ | مؤشر في أسفل القائمة |
| Empty State | `data: []` و`total: 0` | «لا توجد مزادات حاليًا» |
| No Search Results | **لا يحدث** — لا بحث | — |
| Validation Error | 422 بـ`errors` | إبراز الحقل + رسالته |
| API Error | 422 بـ`message` | الرسالة العربية + CTA |
| Unauthorized (401) | token منتهٍ | تسجيل دخول مع حفظ الوجهة |
| Forbidden (403) | Policy | إخفاء الإجراء لا عرض خطأ |
| Not Found (404) | مزاد غير عام/محذوف/ليس ملكك | «غير متاح» + عودة |
| Conflict | **لا يوجد 409** — كلها 422 | كمعالجة أخطاء الحالة |
| Rate Limited | **لا يوجد 429** | — |
| Offline | الجهاز | القائمة من الذاكرة مع شارة، وتعطيل كل إجراءات الكتابة |
| Timeout | الشبكة | **لا تُعاد المزايدة تلقائيًا** — راجع أدناه |
| Stale Data | البيانات أقدم من ثوانٍ في مزاد مباشر | شارة «آخر تحديث …» |
| Maintenance | 503 | شاشة صيانة كاملة |
| Auction State Changed | 422 من عائلة الحالة | رسالة + إعادة جلب + إعادة رسم |
| Account Restricted | `blocked` — **غير قابل للاكتشاف** | — |
| Payment Pending | `pending_review` | حالة انتظار بلا CTA |
| Operation Already Completed | إعادة إرسال بنفس `idempotency_key` | نجاح صامت بلا رسالة مكررة |

### 25.1 قاعدة Timeout للمزايدة

Timeout **لا يعني الفشل** — قد تكون المزايدة قُبلت. القاعدة الملزمة:

**احتفظي بنفس `idempotency_key` وأعيدي الإرسال.** إن كانت الأولى قد نجحت سيعيد الخادم 201 بنفس السجل ولن تُنشأ مزايدة ثانية. **توليد مفتاح جديد عند إعادة المحاولة يخلق مزايدة مضاعفة حقيقية.**

نفس القاعدة تنطبق على طلبات الدفع.

---

<a id="s26"></a>

## 26. قواعد إظهار وتعطيل الأزرار

### 26.1 أزرار المزايد

| الزر | الشرط الأساسي | يظهر عندما | يُعطَّل عندما | النص | الخطأ المتوقع |
|---|---|---|---|---|---|
| سجّل في المزاد | `status ∈ {scheduled, live}` وليس البائع وغير مسجل | نعم | أثناء الإرسال | «سجّل في المزاد» | 403 / 422 `registration_closed` |
| اقرأ ووافق على الشروط | مسجل ولم يقبل | `my_participation.terms_accepted_at = null` | — | «أوافق على الشروط» | 422 `terms_registration_required` |
| ادفع العربون | مسجل ولا يوجد عربون `held` أو `pending_review` | نعم | لا طرق دفع، أو انتهى الوقت | «ادفع العربون» | 422 حالة/مهلة |
| أعد رفع الإيصال | آخر طلب `rejected` | نعم | — | «أعد رفع الإيصال» | 422 |
| قدّم مزايدة | `status = live` وعربون `held` | نعم | مبلغ < `minimum_next_bid`، أو أثناء الإرسال، أو انتهاء العداد | «زايد الآن» | ثمانية أخطاء محتملة |
| زد المبلغ | نفس شرط المزايدة | نعم | نفس الشروط | «زد مزايدتك» | نفسها |
| ادفع المتبقي | `winner_settlement.status = payment_pending` | نعم | تجاوز `payment_due_at` | «ادفع المتبقي» | 422 مهلة/حالة |
| أكّد الاستلام | `handover_pending` + فائز + `seller_handover_confirmed_at ≠ null` | نعم | البائع لم يؤكد | «أكّد الاستلام» | 422 `seller_handover_required` |
| فتح نزاع | بائع أو فائز + `status ∈ {live, ended, payment_pending, handover_pending}` | نعم | — | «فتح نزاع» | 403 |
| متابعة الاسترداد | `my_refunds` غير فارغة | نعم | — | عرض للقراءة | — |
| التواصل مع الدعم | `manual_review` أو `failed` أو `forfeited` | نعم | — | «تواصل مع الدعم» | **لا Endpoint** |

### 26.2 أزرار البائع

| الزر | يظهر عندما | النص | الخطأ |
|---|---|---|---|
| أنشئ مزادًا | دائمًا للمستخدم المسجل | «أنشئ مزادًا» | 422 تحقق |
| أرسل للمراجعة | مالك + `status ∈ {draft, rejected}` | «أرسل للمراجعة» | 422 `invalid_auction_times` |
| ادفع تأمين البائع | مالك + `awaiting_seller_deposit` | «ادفع التأمين» | 422 حالة |
| أكّد التسليم | مالك + `handover_pending` + التسوية مسددة | «أكّد التسليم» | 422 `settlement_must_be_paid` |
| ألغِ المزاد | مالك + إحدى تسع حالات | «إلغاء المزاد» | 422 `auction_cancellation_not_allowed` |
| أضف وسيلة استلام | `has_destination = false` في أي مستحق | «أضف وسيلة استلام» | 422 تحقق |
| اعرض إثبات التحويل | `status = paid` | «إثبات التحويل» | 404 |

### 26.3 قواعد ملزمة على كل الأزرار

1. **زر واحد نشط لكل إجراء مالي.** تعطيل فوري عند الضغط حتى تصل الاستجابة.
2. **مفتاح Idempotency لكل نية.** يُولَّد مرة عند فتح النموذج ويُعاد استخدامه في كل إعادة محاولة لنفس النية.
3. **403 ⇒ إخفاء لا رسالة خطأ.** الرفض من الـPolicy يعني أن الزر ما كان يجب أن يظهر.
4. **404 على مزاد ⇒ عودة للقائمة**، لا رسالة داخل الشاشة.
5. **بعد أي 422 من عائلة الحالة ⇒ إعادة جلب ثم إعادة رسم الأزرار.**
6. **لا يُظهر «تعثرت»** بمجرد تجاوز `payment_due_at` — التعثر قرار إداري لاحق.

---

<a id="s27"></a>

## 27. صلاحيات وخصوصية المستخدم

### 27.1 مستويات الرؤية

| المستوى | المصدر | البيانات |
|---|---|---|
| عام بلا تسجيل دخول | `PublicAuctionResource` | التعريف والمالية العامة والزمن والوسائط والتصنيف والموقع |
| مصادَق بلا علاقة | نفسه | لا فرق |
| ذو علاقة | `MyAuctionResource` | + `my_bids`, `my_deposits`, `my_payment_submissions`, `my_refunds` |
| فائز | نفسه | + `winner_settlement` |
| بائع | نفسه | + `seller_deposit`, `seller_payment_submissions`, `seller_financial_summary`, `handover_status` |
| إداري | `AdminAuctionResource` | كل شيء |

### 27.2 ما لا يراه المستخدم عن غيره

| البيان | الحماية |
|---|---|
| هوية المزايدين | `AuctionBidResource` → `{anonymous: true}` |
| هوية الفائز | غير مكشوفة حتى في حمولة البث |
| عرابين الآخرين | `deposits` مفلترة بـ`where('user_id', me)` |
| طلبات دفع الآخرين | نفس الفلترة |
| استردادات الآخرين | فلترة إضافية داخل العلاقة |
| إيصال دفع آخر | `PaymentSubmissionPolicy::viewReceipt` — مثبت بـ`test_unauthorized_user_cannot_access_another_payment_receipt_url` |
| وسيلة استلام آخر | **404** لا 403 |
| مستحقات بائع آخر | **404** |

### 27.3 ما لا يراه أحد خارج الإدارة

`reserve_amount`، `seller_id` الرقمي، `platform_fee_*` قبل التسوية، `configuration_snapshot`، `auction_activity_logs`، `auction_status_history`، `ip_hash`، `user_agent`، `provider_reference`، `provider_transaction_id`، `receipt_path`، `internal_id`، `metrics`، أسباب الرفض الإدارية لغير صاحب الطلب.

### 27.4 ما يراه صاحبه فقط

`review_note` على طلب دفعه (يحمل سبب الرفض)، إيصاله عبر رابط مؤقت 10 دقائق، وسائل استلامه.

### 27.5 نقاط تحتاج قرارًا أمنيًا

**[منفذ]** بيانات الحساب البنكي محجوبة على المسار العام. `PaymentMethodDisclosureRule` تكشف `recipient_name` و`identifier_value` و`instructions` فقط لمستخدم مصادَق عليه التزام مالي فعلي.

`GET /api/soom/my/payout-destinations` يعيد `identifier_value` كاملًا لصاحبه وحده — وهو سلوك مقصود لأن المالك يحتاج تحرير وسيلته. `sellerPayload()` يقنّعه في سياق المستحقات.

**[منفذ — مقبول]** قنوات البث عامة بلا مصادقة، لكن حمولتها مجهولة الهوية بالتصميم ومثبتة بالاختبارات.

</div>
<div dir="rtl" align="right">

---

<a id="s28"></a>

## 28. سيناريوهات End-to-End

كل سيناريو موثق بمصدر تأكيده من الكود أو الاختبارات.

### 1) زائر يفتح مزادًا قادمًا
**البداية:** `scheduled`، بلا token.
**الخطوات:** `GET /api/auctions` ← فتح بطاقة ← `GET /api/auctions/{id}`.
**النظام:** `PublicAuctionResource`؛ تسجيل مشاهدة في `auction_views`.
**الواجهة:** كل البيانات العامة + عداد إلى `starts_at` + زر «سجّل الدخول للمشاركة».
**التأكيد:** `PublicAuctionOptionalAuthTest`.

### 2) تسجيل الدخول ومشاهدة الشروط
**النظام:** `GET /api/soom/auction-terms` يعيد `id`, `version_number`, `title`, `is_active`, `published_at` — **بلا `body`**.
**النتيجة:** نص الشروط متاح عبر `GET /api/auctions/{auction}/terms`. **[منفذ]**
**التأكيد:** `AuctionTermsQuery::list()`, `PublicCatalogEndpointsTest`.

### 3) التسجيل في مزاد بلا عربون
**غير مدعوم.** المزاد بعربون صفري يمنع المزايدة نهائيًا — راجع [9.5](#s9). التسجيل ينجح لكن المستخدم يبقى `registered` بلا مسار للتأهيل.
**التأكيد:** `ReviewPaymentSubmissionAction:177` + `PaymentEligibilityRule::assertCanSubmitBidderDeposit()` + `PlaceBidAction:71`.

### 4) التسجيل في مزاد بعربون
**البداية:** `scheduled`, `bidder_deposit = 50.000 JOD`.
**الخطوات:** `POST /register` (201) ← `POST /accept-terms` (201) ← `GET /payment-methods` ← `POST /bidder-deposit` بإيصال ومفتاح (201).
**النظام:** مشارك `registered`؛ قبول مرتبط بـ`snapshot.terms_version_id`؛ عربون `pending_review`؛ إيصال على `spaces_private`.
**الإشعارات:** «تم تسجيلك» ← `auction_payment`؛ «تم استلام طلب الدفع».
**النهاية:** انتظار المراجعة. **لا مزايدة بعد.**

### 5) اعتماد العربون
**النظام:** `PaymentTransaction` بحالة `succeeded` + `successful_obligation_key`؛ العربون `held`؛ **المشارك `qualified`**.
**الإشعار:** «تمت الموافقة على عربونك».
**الواجهة:** فتح واجهة المزايدة.
**التأكيد:** `test_bidder_deposit_approval_notifies_qualified_bidder`.

### 6) رفض العربون ثم إعادة المحاولة
**النظام:** الطلب `rejected` مع `review_note`؛ **العربون يعود إلى `pending_submission` لا `rejected`**.
**الواجهة:** تقرأ الرفض من `my_payment_submissions[].status` لا من `my_deposits[].status`.
**الإعادة:** بمفتاح **جديد**؛ نفس المفتاح يعيد الطلب المرفوض.
**التأكيد:** `test_rejected_deposit_submission_can_be_resubmitted_and_approved`.

### 7) الدخول قبل البداية
`scheduled` ← عداد إلى `starts_at`. لا مزايدة. عند بلوغ الصفر: انتظار حتى دقيقة حتى يعمل `StartDueAuctionsJob`.
**التأكيد:** `routes/console.php`, `StartDueAuctionsAction`.

### 8) أول مزايدة
**النظام:** ثمانية فحوص ← `sequence_number = 1` ← `current_leading_bid_id` ← outbox + بث.
**الحد الأدنى:** `starting_amount` بالضبط.
**الإشعار:** **لا أحد** — لا قائد سابق.
**التأكيد:** `PlaceBidAction:91`.

### 9) صيرورة أعلى مزايد
`my_participation.is_highest_bidder` يحسم القيادة، و`bid_id` من استجابة الـPOST يطابق المزايدة. **[منفذ]**

### 10) تجاوز المستخدم
**النظام:** يحدد `previous_leader_id` قبل الإنشاء؛ الإشعار للقائد السابق **فقط**، ولا يُرسل عند رفع المستخدم لمزايدته.
**التأكيد:** `test_outbid_notifies_previous_leader_only_and_realtime_stays_anonymous`, `test_raising_own_leading_bid_sends_no_outbid_notification`.

### 11) مزايدتان في نفس اللحظة
**النظام:** `lockForBidding` يسلسل التنفيذ. الأولى 201؛ الثانية تُقاس ضد الحد الجديد وتُرفض غالبًا بـ`bid_below_minimum`.
**التأكيد:** `AuctionMysqlConcurrencyTest` بعمليات PHP متوازية حقيقية.

### 12) فقد الاتصال ثم العودة
**النظام:** لا آلية استرجاع أحداث فائتة.
**الواجهة:** `GET` للتفاصيل ثم `GET` للسجل؛ إعادة حساب العداد؛ **عدم إعادة إرسال أي مزايدة معلقة إلا بنفس المفتاح**.

### 13) تمديد تلقائي
**البداية:** بقي 120 ثانية، النافذة 300، المدة 600، التمديدات 0/6.
**النظام:** داخل نفس قفل المزايدة: `ends_at += 600`, `extension_count = 1`.
**الوصول:** `extended: true` و`ends_at` الجديد في حمولة `auction.bid_accepted`.
**الواجهة:** إعادة ضبط العداد + تنبيه «تم تمديد المزاد».

### 14) الفوز
**النظام:** `FinalizeAuctionAction`: أعلى مبلغ ثم أقدم؛ فحص الاحتياطي؛ تطبيق العربون؛ إنشاء التسوية؛ `ended → settlement_pending → payment_pending`.
**مثال:** فوز 1000، عربون 50 ⇒ `deposit_applied = 50`, `amount_due = 950`, `remaining = 950`, `payment_due_at = now + 48h`.
**الإشعار:** «مبروك، فزت بالمزاد».
**التأكيد:** `test_finalize_does_not_count_deposit_as_paid`.

### 15) الخسارة
**النظام:** حسب سياسة الحجز. الافتراضي `hold_all_eligible` ⇒ العربون يبقى `held` مع `hold_reason` مخفي.
**الإشعار:** **لا شيء**.
**الواجهة:** لا تفسير متاح لاحتجاز العربون.
**التأكيد:** `test_hold_all_policy_keeps_eligible_until_winner_payment_then_releases`.

### 16) الفائز يدفع في الموعد
**النظام:** `POST /winner-payment` بالمبلغ **المتبقي بالضبط** ← اعتماد ← `amount_paid = amount_due`, `remaining = 0`, التسوية `paid`, `handover_due_at = now + 72h` ← المزاد `handover_pending` ← تحرير عرابين المرشحين المحجوزين.
**التأكيد:** `AuctionPaymentUniquenessTest`, `NonWinnerDepositReleaseTest`.

### 17) فشل الدفع ثم إعادة المحاولة
رفض بسبب مبلغ خاطئ أو إيصال غير واضح ← `review_note` ← إعادة بمفتاح جديد. الدفع الزائد مرفوض بـ`payment_amount_exceeds_remaining` والناقص بـ`payment_amount_mismatch`.
**التأكيد:** `test_winner_settlement_overpayment_is_rejected_on_approval`.

### 18) الفائز لا يدفع ويتعثر
**النظام:** بعد `payment_due_at` **لا يحدث شيء تلقائيًا**. الإداري يستدعي `winner-default` ← التسوية `defaulted` وتفقد `is_current` ← العربون `forfeited` ← إما فائز بديل أو `unsold`.
**الواجهة:** `my_deposits[]` يحمل `status = forfeited` مع `forfeited_amount` و`hold_reason_label`، ويصل إشعار التعثر موضحًا السبب. **[منفذ]**
**التأكيد:** `WinnerDefaultTest` بثمانية اختبارات.

### 19) الخاسر يسترد عربونه
**النظام:** `refund_pending` ← إنشاء `RefundTransaction` ← معالجة. مع المعالج اليدوي الافتراضي: `manual_review` ← تأكيد إداري ← `succeeded` ← `deposit.refunded += amount`, `held = 0`, `PaymentTransaction → reversed`.
**الإشعارات:** «جارٍ رد عربونك» ثم «تم رد العربون».
**التأكيد:** `AuctionRefundLifecycleTest`.

### 20) فشل الاسترداد
**النظام:** `failed` + `next_retry_at` بتراجع `60,300,900,3600`؛ بعد 5 محاولات `manual_review`.
**الإشعار:** `refund_failed` مسجَّل بلا مستلم شخصي عمدًا (حالة تشغيلية للإدارة)، والمستخدم يرى `failure_reason` و`attempt_count` في `my_refunds`.
**الواجهة:** `my_refunds[].status = failed` بلا سبب.

### 21) إلغاء المزاد قبل البداية
`scheduled` ← البائع يلغي ← لا تسوية ← استرداد عرابين المدفوعين ← بث `auction.cancelled` + إعلان عام + إشعار لكل المشاركين ← المزاد يخرج من الحالات العامة فيصبح **404**.

### 22) إلغاء أثناء التشغيل
مسموح للبائع في `live`. نفس المسار مع بث فوري. الواجهة تخرج من غرفة المزاد ولا تعيد الجلب.

### 23) انتهاء دون مزايدات
`lockWinningBid()` تعيد `null` ← `unsold` ← لا تسوية ← استرداد عربون البائع حسب `seller_deposit_policy.unsold` ← إشعار للبائع فقط.
**التأكيد:** `test_unsold_release_refunds_paid_bidder_deposits`.

### 24) انتهاء دون بلوغ السعر الاحتياطي
مدعوم. مزايدة 100 مقابل احتياطي 150 ⇒ `unsold` وكل العرابين `refund_pending`. مزايدة **تساوي** الاحتياطي تبيع (`<` صارمة).
**التأكيد:** `test_unsold_release_refunds_paid_bidder_deposits` (يرفع الاحتياطي إلى 150000 مع أعلى مزايدة 100000).

### 25) تكرار الطلب أو الضغط مرتين
| المسار | النتيجة |
|---|---|
| مزايدة بنفس المفتاح | 201 بنفس السجل — لا مزايدة مكررة |
| مزايدة بمفتاح جديد | مزايدة ثانية حقيقية أو رفض بالحد الأدنى |
| دفع بنفس المفتاح | 201 بنفس الطلب (قد يغيب `payment_method`) |
| تسجيل مكرر | 201 بنفس المشارك |
| قبول شروط مكرر | 201 بنفس القبول |
**التأكيد:** `test_two_replayed_bid_submissions_do_not_create_duplicate_bid`, `AuctionCriticalJobsIdempotencyTest`.

### 26) تغيّر الحالة بين التحميل والضغط
| التغيّر | الاستجابة | المعالجة |
|---|---|---|
| `live → ended` | 422 `auction_not_live` أو `bidding_window_closed` | رسالة + إعادة جلب |
| `scheduled → cancelled` | 404 | عودة للقائمة |
| تغيّر الفائز | 422 `payment_target_not_current` أو `winner_changed` | إعادة جلب |
| انتهت المهلة | 422 `payment_deadline_expired` | «تواصل مع الدعم» |
| اعتُمد العربون بينما الشاشة مفتوحة | لا خطأ — نجاح مفاجئ | إعادة جلب دورية أثناء الانتظار |

<a id="s29"></a>

## 29. توصيات UI/UX المبنية على النظام

كل توصية أدناه ناتجة عن قاعدة محددة في هذا النظام، لا عن ممارسة عامة.

**ثبّتي أربع معلومات أثناء المزاد المباشر.** لأن `PlaceBidAction` يقيس ضد `minimum_next_bid` المتغيّر لحظيًا، ولأن العداد قد يُمدَّد فجأة: أعلى سعر حالي، الحد الأدنى التالي، الوقت المتبقي، وحالة الأهلية. أي منها خارج الشاشة يعني مزايدة مرفوضة.

**اعرضي الحد الأدنى التالي كقيمة لا كقاعدة.** الواجهة لا تملك `minimum_bid_increment` كحقل مستقل، لكنها تملك `minimum_next_bid` جاهزًا. اجعليه القيمة الابتدائية لحقل الإدخال بدل تركه فارغًا.

**رتّبي البيانات المالية للفائز بترتيب السببية:** سعر الفوز ← العربون المخصوم ← المتبقي. المشكلة أن أول قيمتين غير متاحتين حاليًا، فالتصميم يجب أن يحجز مكانهما ويعرض المتبقي بارزًا الآن.

**أبرزي أن المبلغ المطلوب دقيق.** النظام يرفض الزيادة والنقصان معًا. اعرضي المبلغ قابلًا للنسخ وكرريه في شاشة رفع الإيصال.

**فرّقي بصريًا بين «قيد المراجعة» و«معتمد».** الفرق بينهما هو الفرق بين القدرة على المزايدة وعدمها. `pending_review` ليست حالة نجاح ويجب ألا تحمل لون النجاح.

**لا تستخدمي حالة العربون لعرض الرفض.** الرفض يعيد العربون إلى `pending_submission`. المصدر الصحيح `my_payment_submissions[].status` مع `review_note`. الخلط ينتج رسالة معاكسة تمامًا.

**ميّزي بين «أعلى مزايد» و«الفائز».** الأول حالة مؤقتة أثناء `live` مبنية على `current_leading_bid_id`، والثاني حقيقة نهائية بعد الإنهاء مبنية على `winning_bid_id` والتسوية. لا تستخدمي نفس الشارة.

**ميّزي بين «انتهى المزاد» و«اكتملت الصفقة».** بينهما `payment_pending` و`handover_pending` وقد تمتد أيامًا، وقد تنتهي بتعثر. `completed` وحدها تعني اكتمال الصفقة.

**اعرضي حالة انتقالية بعد صفر العداد.** الإنهاء عبر Job كل دقيقة. «انتهى المزاد، جارٍ تحديد النتيجة» أدق من عرض نتيجة غير موجودة بعد.

**اجعلي التمديد حدثًا مرئيًا لا تغييرًا صامتًا.** `extended: true` تصل مع المزايدة. تنبيه واضح + إعادة ضبط العداد + إظهار `extension.count`، وإلا سيبدو أن العداد «تعطل».

**عطّلي زر المزايدة فور الضغط.** لا يوجد throttle في الخادم. التعطيل مسؤولية الواجهة وحدها.

**ولّدي `idempotency_key` عند فتح النموذج لا عند الضغط.** كل إعادة محاولة لنفس النية تستخدم نفس المفتاح — وهذا ما يحوّل انقطاع الشبكة من خطر مزايدة مضاعفة إلى عملية آمنة.

**لا تعرضي رسالة نجاح مرتين عند التكرار.** الاستجابة المكررة تبدو 201 ناجحة تمامًا. طابقي `id` مع ما لديك قبل عرض تأكيد جديد.

**تعاملي مع كل 422 من عائلة الحالة بنفس النمط:** رسالة الخادم كما هي ← إعادة جلب ← إعادة رسم ← بلا إعادة محاولة تلقائية.

**اعتبري البيانات القديمة خطرًا في `live` فقط.** في `scheduled` أو `completed` البيانات المخزنة مقبولة. في `live` اعرضي طابع «آخر تحديث» وأعيدي الجلب عند العودة إلى التطبيق.

**اشرحي مصدر الوقت.** العداد يعمل على وقت الخادم لا الجهاز. عند اختلاف كبير أظهري تنبيهًا بدل السماح بمزايدة سترفض بـ`bidding_window_closed`.

**استخدمي Timeline للمدفوعات والاستردادات لا شارة حالة.** كلاهما عملية متعددة الخطوات بطوابع زمنية متاحة (`submitted_at`, `reviewed_at`, `processed_at`, `succeeded_at`). الشارة وحدها تخفي التقدم.

**عاملي `manual_review` كمسار طبيعي لا كخطأ.** المعالج الافتراضي يحوّل كل استرداد إلى `manual_review` بالتصميم. لونه يجب أن يكون محايدًا/انتظارًا لا خطأ.

**اجعلي `has_destination` مشغّل الـCTA في شاشة المستحقات.** `false` يعني أن الصرف متوقف على البائع، وهو الحقل الوحيد الذي يكشف ذلك بوضوح.

**قنّعي `identifier_value` دائمًا.** الخادم يقنّعه في المستحقات ولا يقنّعه في وسائل الاستلام. وحّدي السلوك في الواجهة.

**اشتقّي زر بطاقة القائمة من `next_action` لا من الحالة.** القائمة تحمل `my_participation` و`next_action` لكل مستخدم مصادَق، فالزر الذكي متاح — لكن اشتقاقه يدويًا من `status` يعيد إنتاج منطق الخادم ويتعارض معه عند أول تغيير.

**احجزي مكانًا مرئيًا لتفسير احتجاز العربون.** الحالة واقعية ومتكررة، وسبب الحجز غير متاح اليوم. تصميم يفترض وجود التفسير سيكون جاهزًا فور سد []؛ تصميم يتجاهلها سيحتاج إعادة عمل.

**لا تُنشئي Design System جديدًا لهذه الوثيقة.** الألوان والأحجام والشارات تتبع النظام القائم في التطبيق؛ ما تحدده هذه الوثيقة هو **الحالات التي تحتاج تمييزًا** لا شكل التمييز.

---

<a id="s30"></a>

## 30. قائمة التسليم لمهندسة الـUI

### 30.1 جاهز للتصميم والتنفيذ

كل شاشات الإصدار الحالي مدعومة بعقد Backend مستقر ومختبَر:

قائمة المزادات (بالبحث والفلاتر والترتيب وزر مشتق من `next_action`) · تفاصيل المزاد (`my_participation` + `timeline` + المواعيد المشتقة) · غرفة المزاد والمزايدة المباشرة · شروط المزاد · سجل المزايدات · شاشة رفع الإيصال (مشتركة بين العرابين الثلاثة) · حالة مراجعة الدفع · دفع مستحق الفائز · تذكيرات المهل · التعثر والفائز البديل · تأكيد التسليم والاستلام · مشاركاتي · استرداداتي · النزاعات قراءةً وفتحًا · إنشاء مزاد · عربون البائع · مستحقاتي · وسائل استلام المستحقات · الدعم · الإشعارات · شاشات الأخطاء والحالات العامة.

### 30.2 الحالات المؤكدة الجاهزة للتصميم

| المجال | العدد | القيم |
|---|:--:|---|
| Auction | 15 | `draft`, `pending_review`, `rejected`, `awaiting_seller_deposit`, `scheduled`, `live`, `ended`, `settlement_pending`, `payment_pending`, `handover_pending`, `completed`, `unsold`, `cancelled`, `defaulted`, `disputed` |
| Participant | 3 | `registered`, `qualified`, `blocked` |
| Deposit | 8 | `pending_submission`, `pending_review`, `held`, `rejected`, `refund_pending`, `refunded`, `forfeited`, `applied_to_settlement` |
| Payment Submission | 3 | `pending_review`, `approved`, `rejected` |
| Payment Transaction | 4 | `pending`, `succeeded`, `failed`, `reversed` |
| Refund | 6 | `pending`, `processing`, `succeeded`, `failed`, `manual_review`, `cancelled` |
| Settlement | 7 | `payment_pending`, `paid`, `handover_pending`, `completed`, `defaulted`, `disputed`, `cancelled` |
| Seller Payout | 6 | `pending`, `on_hold`, `processing`, `paid`, `failed`, `manual_review` |
| Dispute | 2 | `open`, `resolved` |
| حالات المستخدم | 19 | راجع [القسم 5](#s5) — جميعها قابلة للاشتقاق من `my_participation` و`next_action` |

**المجموع: 73 حالة نظام موثقة.**

### 30.3 الحقول المتاحة

كل حقول المزاد العامة · الوسائط · المبالغ بصيغة Money · التواريخ ISO مع `server_time` · `status_label` مترجم · `my_participation` (حالة المشارك، قبول الشروط، التأهيل، «أنا أعلى مزايد») · `next_action` · `timeline` والمواعيد المشتقة · `my_bids` · `my_deposits` بحقول `hold_reason` و`hold_reason_label` و`forfeited_amount` و`expected_release_condition` و`candidate_rank` · `my_payment_submissions` · `my_refunds` · `winner_settlement` بحقول `winning_amount` و`deposit_applied` والمتبقي والمهل وفترة السماح · `metrics` · كتل البائع مع أسباب الإيقاف والفشل والإجراء المطلوب · `SellerPayoutResource::sellerPayload()` · `PaymentMethodResource` مع قاعدة الكشف · Error Codes ثابتة على كل خطأ.

### 30.4 الـEndpoints المتاحة لتطبيق المستخدم

`GET /auctions` (بحث وفلاتر وترتيب) · `GET /auctions/{id}` · `GET /auctions/{id}/bids` · `GET /auctions/{id}/terms` · `GET /soom/auction-terms` · `GET /soom/payment-methods` · `GET /soom/payment-methods/{id}` · `GET /soom/support-contact` · `POST /{id}/register` · `POST /{id}/accept-terms` · `POST /{id}/bidder-deposit` · `POST /{id}/bids` (بحد معدل) · `POST /{id}/winner-payment` · `POST /{id}/confirm-handover` · `POST /{id}/confirm-receipt` · `POST /{id}/disputes` · `GET /{id}/disputes` · `GET /{id}/disputes/{dispute}` · `GET /{id}/payment-methods` · `GET /my/auctions` · `GET /my/participations` · `GET /my/refunds` · `GET /my/bids` · `POST /auctions` · `POST /{id}/submit-review` · `POST /{id}/seller-deposit` · `DELETE /{id}` — بالإضافة إلى مستحقات البائع ووسائل الاستلام وروابط الإيصالات والإشعارات.

<a id="s32"></a>

### 30.5 خارج نطاق الإصدار الحالي

هذه ميزات **تقرر عدم تنفيذها عمدًا** في هذا الإصدار. لا يوجد لها عقد Backend ولا يجوز تصميم شاشات تعتمد عليها:

| الميزة | الحالة |
|---|---|
| المزايدة التلقائية Auto Bid / Maximum Bid | خارج النطاق — لا يوجد نموذج بيانات ولا منطق تنفيذ |
| قائمة المتابعة Watchlist / تنبيهات المزادات المفضلة | خارج النطاق — لا يوجد نموذج بيانات |
| بوابة دفع إلكترونية Online Payment Gateway | خارج النطاق — الدفع يدوي بإيصال ومراجعة إدارية فقط |
| التحقق من الهوية KYC | خارج النطاق — لا يوجد مسار توثيق هوية |
| الدفع الجزئي أو التقسيط | خارج النطاق — النظام يرفض الزيادة والنقصان معًا ويطلب المبلغ بالضبط |
| سحب مزايدة أو الانسحاب من التسجيل | خارج النطاق — المزايدة نهائية بعد قبولها |
| الضرائب والرسوم على المزايد | خارج النطاق — عمولة المنصة تُخصم من مستحق البائع فقط |

---

<a id="s31"></a>

## 31. الملحق التقني

### 31.1 الملفات التي تمت مراجعتها

**التوجيه والإعداد (6):** `routes/api/auction.php`, `routes/api.php`, `routes/api/user.php`, `routes/console.php`, `routes/channels.php`, `bootstrap/app.php`, `config/auction.php`.

**Enums (15):** كل ملفات `app/Domain/Auction/Enums/`.

**Domain (8):** `Exceptions/` (5 ملفات)، `Rules/CurrencyDecimalRule.php`, `ValueObjects/Money.php`, `ValueObjects/Currency.php`.

**Models (24):** كل ملفات `app/Models/Auction/` بما فيها `Concerns/HasPublicId`.

**Actions (43):** كل ملفات `app/Services/Auction/Actions/` — قُرئت بالكامل: `PlaceBidAction`, `FinalizeAuctionAction`, `RegisterParticipantAction`, `AcceptAuctionTermsAction`, `SubmitPaymentSubmissionAction`, `ReviewPaymentSubmissionAction`, `MarkWinnerDefaultedAction`, `CancelAuctionAction`, `CancelAuctionFinanciallyAction`, `ResolveAuctionDisputeAction`, `ConfirmAuctionHandoverBySellerAction`, `ConfirmAuctionReceiptByWinnerAction`, `CreateAuctionAction`, `ReviewAuctionAction`, `SubmitAuctionForReviewAction`, `CreateSellerPayoutAction`, `PlanNonWinnerDepositRefundsAction`, `ProcessAuctionRefundAction`, `StartDueAuctionsAction`, `ReconcileAuctionsAction`, وبقية الملفات.

**Support (18):** `AuctionStateMachine`, `AuctionConfigurationSnapshotFactory/Reader/Validator/Hasher`, `PaymentEligibilityRule`, `AuctionNotificationCatalog`, `AuctionAudit`, `SellerPayoutRules`, `FinancialObligationKey`, `AuctionTransaction`, `AuctionMetricsRecorder`, `AuctionRefundCompletion`, `DepositRefundAllocation`, `SellerDepositDispositionResolver`, `WinnerDefaultDepositDispositionResolver`, `AuctionMediaService`, `DashboardPeriod`.

**Notifications (7):** `AuctionOutboxNotifier`, `PersonalNotificationSender`, `PersonalDeliveryResolver`, `AuctionRealtimeBroadcaster`, `AuctionAnnouncementBroadcaster`, `NotificationValueFormatter`, `OutboxPayloadResolver`.

**Repositories & Queries (24):** كل ملفات `app/Repositories/Auction/` بما فيها `Queries/` (7) و`Dashboard/` (5).

**HTTP (33):** Controllers (11)، Form Requests (14)، Resources (11).

**Policies (8):** `AuctionPolicy`, `PaymentSubmissionPolicy`, `AuctionDepositPolicy`, `AuctionSettlementPolicy`, `AuctionRefundPolicy`, `AuctionDisputePolicy`, `SellerPayoutPolicy`, `AuctionDashboardPolicy`, `Concerns/ChecksAuctionPermissions`.

**Jobs & Events (15):** 10 Jobs، 3 Commands، `AuctionRealtimeEvent`, `AuctionPublicAnnouncementEvent`, `AuctionOutboxNotification`.

**Migrations (5)** · **Seeders (3)** · **Lang (2):** `lang/ar/auction.php`, `lang/en/auction.php` · **Tests (52):** كل ملفات `tests/Feature/Auction/` و`tests/Unit/Auction/`.

**الإجمالي: 263 ملفًا.**

### 31.2 قائمة الـEndpoints

**عامة (6):** `GET /api/auctions` · `GET /api/auctions/{auction}` · `GET /api/auctions/{auction}/bids` · `GET /api/soom/payment-methods` · `GET /api/soom/payment-methods/{paymentMethod}` · `GET /api/soom/auction-terms`

**مستخدم مصادَق (19):** `GET /api/soom/my/auctions` · `GET /api/soom/my/bids` · `GET /api/soom/my/payouts` · `GET /api/soom/my/payouts/{id}` · `GET /api/soom/my/payouts/{id}/proof-url` · `GET|POST /api/soom/my/payout-destinations` · `PUT /api/soom/my/payout-destinations/{id}` · `GET /api/soom/payment-submissions/{id}/receipt-url` · `POST /api/soom/auctions` · `POST /api/soom/auctions/{auction}/submit-review` · `.../seller-deposit` · `.../register` · `.../accept-terms` · `.../bidder-deposit` · `.../bids` · `.../winner-payment` · `.../confirm-handover` · `.../confirm-receipt` · `.../disputes` · `DELETE /api/soom/auctions/{auction}`

**إدارية (29):** تحت `/api/admin/auctions` — خارج نطاق تطبيق المستخدم.

**الإشعارات (3):** `GET /api/notifications` · `POST /api/notifications/mark-all-as-read` · `PUT /api/notifications/{id}/read`

### 31.3 الـEnums والحالات

راجع [31.3](#s31) — 15 Enum بمجموع 73 حالة، بالإضافة إلى `OutboxStatus` (4) و`RefundProcessingOutcome` (4) و`AuctionCancellationTrigger` (10) و`SellerDepositDisposition` (7) و`WinnerDefaultDepositDisposition` (5) و`NonWinnerDepositHoldPolicy` (3) — وهي داخلية ولا تصل إلى تطبيق المستخدم.

### 31.4 الـEvents والـJobs

**Jobs (10):** `StartDueAuctionsJob` · `FinalizeExpiredAuctionsJob` · `RefundPendingAuctionDepositsJob` · `ProcessPendingAuctionRefundsJob` (`tries = 1` بالتصميم) · `DispatchAuctionOutboxJob` · `SendAuctionAnnouncementJob` · `SendWinnerPaymentRemindersJob` · `SendHandoverRemindersJob` · `AutoDefaultOverdueWinnersJob` · `ExpireSellerDepositDeadlinesJob`

**Commands (3):** `auction:run-operations` (كل دقيقة) · `auction:run-deadlines` (كل دقيقة) · `auction:reconcile` (كل 15 دقيقة) — جميعها `withoutOverlapping()`

**Broadcast Events (2):** `AuctionRealtimeEvent` على `auction.{public_id}` · `AuctionPublicAnnouncementEvent` على `public.auctions`

**Outbox Event Types (40):** 32 منها لها مستلمون — ومنها أحداث المهل الخمسة: `bidder_lost` · `unsold_bidders` · `winner_payment_reminder` · `handover_reminder` · `seller_deposit_expired`. و8 مسجلة بلا مستلم: `no_alternative_winner`, `alternative_settlement_created`, `seller_deposit_held`, `non_winner_deposit_released`, `refund_processing`, `refund_failed`, `refund_cancelled`, `cancellation_started`, `cancellation_financial_plan_created`.

### 31.5 مفاتيح الإعدادات المؤثرة على المستخدم

**من `config/auction.php`:** `refunds.max_attempts` (5) · `refunds.backoff_seconds` (`60,300,900,3600`) · `refunds.lease_seconds` (300) · `refunds.provider` (`manual`) · `outbox.max_attempts` (3) · `outbox.retry_delay_seconds` (300) · `deadlines.winner_payment_grace_period_hours` (24) · `deadlines.winner_payment_reminder_hours_before` (`24,6,1`) · `deadlines.handover_reminder_hours_before` (`24,1`) · `deadlines.seller_deposit_deadline_hours` (48) · `deadlines.seller_deposit_expiry_lease_minutes` (15) · `deadlines.review_sla_hours` (24) · `bidding.rate_limit_per_minute` (30) · `bidding.rate_limit_per_minute_per_ip` (120). جميعها قابلة للضبط عبر متغيرات البيئة الموثقة في `.env.example`.

**من نسخة الإعدادات (`AuctionConfigurationSeeder`):** `seller_deposit_minor` (10000) · `bidder_deposit_minor` (5000) · `platform_fee_type` (`percentage`) · `platform_fee_basis_points` (250) · `minimum_bid_increment_minor` (1000) · `extension_window_seconds` (300) · `extension_duration_seconds` (600) · `maximum_extension_count` (6) · `winner_payment_deadline_hours` (48) · `handover_deadline_hours` (72) · `non_winner_deposit_policy` (`hold_all_eligible_bidders_until_winner_payment`) · `alternative_winner_enabled` (`true`) · `winner_default_deposit_policy.disposition` (`full_forfeit`) · `seller_deposit_policy` (16 مفتاحًا).

**تنبيه ملزم:** هذه قيم **الإعدادات النشطة** لا قيم مزاد بعينه. كل مزاد يحمل نسخته المجمّدة، وقد تكون قيمه مختلفة تمامًا. الواجهة يجب ألا تفترض أي قيمة افتراضية.

### 31.6 الاختبارات المرجعية

| السلوك | الاختبار |
|---|---|
| منع تكرار المزايدة | `test_two_replayed_bid_submissions_do_not_create_duplicate_bid` |
| تسوية واحدة عند التوازي | `test_parallel_scheduler_finalization_processes_create_one_settlement` |
| دفعة واحدة عند التوازي | `test_two_payment_approvals_apply_winner_payment_once` |
| Idempotency لكل الـJobs | `AuctionCriticalJobsIdempotencyTest` (5 اختبارات) |
| إشعار التجاوز للقائد السابق فقط | `test_outbid_notifies_previous_leader_only_and_realtime_stays_anonymous` |
| إخفاء هوية المزايدين | `test_public_bid_resource_anonymizes_other_bidders` |
| منع كشف السعر الاحتياطي في البث | `test_scheduled_auction_broadcasts_public_announcement_without_private_data` |
| العربون لا يُحسب مدفوعًا | `test_finalize_does_not_count_deposit_as_paid` |
| التغطية الكاملة تقفز للتسليم | `test_full_deposit_coverage_moves_directly_to_handover` |
| رفض الدفع الزائد | `test_winner_settlement_overpayment_is_rejected_on_approval` |
| إعادة إرسال إيصال مرفوض | `test_rejected_deposit_submission_can_be_resubmitted_and_approved` |
| مهلة عربون المزايد = `ends_at` | `test_bidder_deposit_deadline_is_auction_end_and_override_only_bypasses_deadline` |
| ثبات الـsnapshot | `test_existing_auction_keeps_snapshot_after_new_configuration_is_activated` |
| نسخة الشروط تتحكم بالمزايدة | `test_terms_version_required_by_snapshot_controls_bidding` |
| صلاحيتان منفصلتان للتعثر | `test_override_requires_permission_and_reason_and_records_deadline_metadata` |
| استبعاد المتعثر من الترشيح | `test_defaulted_bidder_is_excluded_even_with_multiple_bids` |
| سياسات حجز العرابين | `NonWinnerDepositReleaseTest` (11 اختبارًا) |
| الإلغاء ذري | `test_refund_failure_prevents_financial_cancellation_completion` |
| منع رفض مزاد يحتجز عربونًا | `test_auction_holding_a_seller_deposit_can_never_be_rejected` |
| العكس المالي عند الاسترداد الكامل فقط | `test_refunds_held_then_applied_from_same_payment_without_reversing_until_full_refund` |
| منع الحساب العشري | `test_auction_financial_code_does_not_use_floating_point_types_or_casts` |
| دقة العملات | `MoneyTest` (6 اختبارات) |
| token غير صالح يُعامل كزائر | `PublicAuctionOptionalAuthTest` |
| إشعار الخسارة دون كشف الفائز | `test_losing_bidders_are_notified_without_revealing_the_winner` |
| إشعار انتهاء المزاد دون بيع | `test_unsold_auction_notifies_every_bidder` |
| عدم تكرار إشعار النتيجة عند إعادة التشغيل | `test_result_notifications_are_not_duplicated_on_replay` |
| تذكيرات الدفع على المواعيد المجمدة | `test_payment_reminders_fire_at_each_frozen_offset_without_repeating` |
| التذكيرات لا تستخدم الإعدادات العامة الحالية | `test_payment_reminders_use_the_frozen_snapshot_not_current_config` |
| تجميع التذكيرات الفائتة في تذكير واحد | `test_missed_offsets_collapse_into_a_single_catch_up_reminder` |
| إشعار تأخر بدل الفقد الصامت | `test_deadline_passing_sends_one_overdue_notice_instead_of_silent_loss` |
| لا تذكير بعد انتهاء فترة السماح أو السداد | `test_no_reminder_is_sent_once_the_grace_period_has_expired` |
| التعثر الآلي ينتظر فترة السماح | `test_auto_default_waits_for_the_grace_period` |
| التعثر الآلي يرشّح الفائز البديل | `test_auto_default_promotes_the_alternative_winner_after_grace` |
| التعثر الآلي بلا بديل ينتهي `unsold` | `test_auto_default_without_alternative_ends_unsold` |
| التعثر الآلي لا يتكرر | `test_auto_default_is_idempotent_across_runs` |
| إثبات دفع قيد المراجعة يمنع التعثر الآلي | `test_auto_default_is_blocked_by_a_payment_submission_under_review` |
| سباق اعتماد الدفع والتعثر الآلي | `test_auto_default_job_racing_payment_approval_never_double_settles` |
| مهلة تأمين البائع تُلغي المزاد دون نشره | `test_seller_deposit_deadline_cancels_unfunded_auction` |
| إعادة المحاولة بعد تعطل المعالجة | `test_seller_deposit_expiry_retries_after_a_crashed_claim` |
| تذكيرات التسليم للطرف المطلوب منه الإجراء | `test_handover_reminders_target_the_acting_party_without_repeating` |
| تأخر التسليم لا يغلق الصفقة ولا يصادر | `test_overdue_handover_sends_one_notice_without_closing_the_deal` |
| حد معدل المزايدة لكل مستخدم ومزاد | `test_bidding_is_throttled_per_user_and_auction` |
| عدادات الحد منفصلة لكل مزاد | `test_throttle_counters_are_isolated_per_auction` |
| تأهيل تلقائي عند العربون الصفري | `ZeroBidderDepositTest` (7 اختبارات) |
| ثبات Error Codes | `AuctionErrorCodeTest` |
| استقلال النزاع عن حالة المزاد | `DisputeIndependentStateTest` |
| حظر وإلغاء حظر المشاركين | `ParticipantBlockingTest` (8 اختبارات) |
| وقت الخادم في كل استجابة | `ServerTimeMetaTest` |

### 31.7 ما لم يُمكن تأكيده

| البند | السبب |
|---|---|
| سلوك الاستردادات مع مزود حقيقي | `AUCTION_REFUND_PROVIDER=manual` هو الوحيد المنفذ؛ `AuctionRefundProcessorInterface` بلا تنفيذ آخر |
| القيم الفعلية في بيئة الإنتاج | الـSeeder يعكس قيم التطوير؛ نسخة الإعدادات النشطة في الإنتاج غير مقروءة |
| زمن المراجعة الإدارية الفعلي | `review_sla_minutes` مجمّد في اللقطة كوعد معروض، لكن لا بيانات تشغيلية تؤكد الالتزام به |
| توافق قوائم `PaymentMethod::IDENTIFIER_TYPES` | لم تُقرأ القيم الحرفية للثابت |
| سلوك `disputed` كحالة مزاد | النزاع أصبح كيانًا مستقلًا عن حالة المزاد؛ الحالة القديمة محفوظة للتوافق التاريخي فقط وتُطبَّع بأمر `auction:normalize-legacy-disputed` |
| إعدادات اتصال البث في العميل | خارج نطاق `app/`؛ لم تُراجع إعدادات Pusher/Reverb |

---

**نهاية الوثيقة.**

</div>
