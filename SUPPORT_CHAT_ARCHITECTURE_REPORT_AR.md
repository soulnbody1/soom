# تقرير معماري لمركز الدعم الفني والمحادثات

## الملخص التنفيذي

الاختيار الموصى به هو بناء مركز دعم مستقل قائم على التذاكر، وليس إضافة حساب دعم داخل نظام المحادثات الحالي بين المستخدمين.

كل محادثة دعم تكون تذكرة لها رقم مرجعي وحالة وأولوية وتصنيف وموظف مسؤول ومواعيد SLA وسجل تدقيق. تحتوي التذكرة على رسائل عامة بين العميل والدعم، وملاحظات داخلية لا يراها العميل، ومرفقات خاصة، وحالة قراءة مستقلة لكل طرف.

هذا التصميم يحقق تجربة قريبة من Zendesk وIntercom وFreshdesk، ويخدم ثلاثة مستهلكين من عقد API واحد:

- تطبيق الويب في `C:\Users\pc\Desktop\SB\soom-web`.
- تطبيق الموبايل من خلال API موثق بالكامل.
- مساحة عمل موظفي الدعم في `C:\Users\pc\Desktop\SB\soom-dashboard`.

قنوات الهاتف والبريد وواتساب الموجودة حاليًا تظل وسائل بديلة، خصوصًا لمشكلات تسجيل الدخول، ولا تُلغى عند إضافة المحادثة الداخلية.

## ما هو موجود حاليًا

### Laravel API

المشروع يحتوي على أساس جيد يمكن إعادة استخدام بنيته التحتية دون إعادة استخدام نموذج بياناته:

- محادثات مباشرة بين مستخدمين في جدول `messages` تعتمد على `sender_id` و`receiver_id`.
- مرفقات ورسائل مقروءة وحذف مرئي لكل مستخدم.
- Pusher عبر قنوات presence خاصة بكل طرفين.
- أحداث لتحديث الرسائل وقائمة المحادثات وعدد غير المقروء.
- FCM وإشعارات داخلية ومحددات معدل للقراءة والكتابة.
- اختبارات أمان وأداء وتسليم واستعلامات لنظام الرسائل.
- إعدادات تواصل للدعم تشمل الهاتف والبريد وواتساب وساعات العمل.
- شاشة إدارية لعرض محادثات المستخدمين الحالية للقراءة فقط.

### Dashboard

الداشبورد مبني بـ Next.js 15 وReact 19، ويستخدم React Query وAxios وRedux ومكونات Radix ويدعم العربية والإنجليزية واتجاه RTL. توجد شاشة لإدارة بيانات التواصل مع الدعم، كما توجد شاشة قراءة لمحادثات مستخدم معين داخل ملفه الإداري.

لا يوجد حاليًا مركز دعم أو queue للموظفين، ولا يوجد استخدام فعلي لـ Pusher داخل كود الداشبورد رغم وجود `pusher-js` ضمن الحزم.

يخزن الداشبورد access token وrefresh token في cookies يستطيع JavaScript قراءتها. هذا غير مناسب لمحادثات دعم قد تحتوي على بيانات حساسة، ويجب تحويل المصادقة إلى BFF أو cookies من نوع HttpOnly قبل إطلاق مركز الدعم للإنتاج.

### Web

الويب يحتوي على:

- صفحة دعم عامة تعرض الهاتف والبريد وواتساب وساعات العمل.
- نظام رسائل مستخدمين داخل الحساب مع list وthread وcomposer ومرفقات وحذف وmark-as-read.
- طبقة BFF تحافظ على التوكن داخل HttpOnly cookies.
- parsing دفاعي لردود Laravel وفصل واضح بين DTOs وview models.
- تصميم محادثة responsive واختبارات مكونات.

الـ realtime لمحادثات المستخدمين ما زال غير مكتمل حسب `COMM-003`، لذلك يجب عدم نسخ افتراض أنه مكتمل إلى مركز الدعم.

## القرار المعماري

### عدم استخدام جدول الرسائل الحالي

جدول `messages` مناسب لمحادثة مباشرة بين مستخدمين، لكنه لا يستطيع تمثيل عناصر العمل المطلوبة للدعم المؤسسي:

- حالة التذكرة ودورة حياتها.
- الأولوية والتصنيف.
- التعيين إلى موظف أو فريق.
- SLA للرد الأول والحل.
- الملاحظات الداخلية.
- سجل كل تغيير إداري.
- رقم مرجعي مفهوم للعميل.
- سياق المشكلة المرتبط بإعلان أو مزاد أو دفعة.
- قياس الأداء وإعادة الفتح والتصعيد.

القرار هو إنشاء Domain مستقل باسم `Support` أو `SupportTicket`. يمكنه إعادة استخدام أفكار التخزين والإشعارات والبث الموجودة، لكن لا يعيد استخدام جدول `messages` أو endpoints الخاصة به.

## نموذج البيانات المقترح

### support_categories

- `id`
- `public_id`
- `code`
- `name_ar`
- `name_en`
- `is_active`
- `display_order`
- `default_priority`
- `first_response_minutes`
- `resolution_minutes`
- timestamps

التصنيفات الابتدائية المقترحة: الحساب وتسجيل الدخول، الإعلانات، المزادات، المدفوعات والاستردادات، الشحن أو التسليم، بلاغ أمني، مشكلة تقنية، وأخرى.

### support_tickets

- `id`
- `public_id` كـ ULID يستخدم في المسارات.
- `reference_number` مثل `SUP-260913-AB12` يظهر للمستخدم.
- `requester_id`
- `category_id`
- `subject`
- `status`
- `priority`
- `assigned_to`
- `context_type`
- `context_id`
- `last_message_id`
- `last_message_at`
- `first_response_due_at`
- `resolution_due_at`
- `first_responded_at`
- `resolved_at`
- `closed_at`
- `reopened_at`
- `version` لمنع تعارض تعديلات موظفين مختلفين.
- timestamps

السياق يكون اختياريًا ومقيدًا بقائمة واضحة مثل `ad` و`auction` و`payment` و`refund` و`payout`. الخادم يتحقق من ملكية المستخدم للكيان قبل ربطه بالتذكرة، ولا يقبل اسم model عشوائيًا من العميل.

### support_messages

- `id`
- `public_id`
- `ticket_id`
- `author_id` ويقبل null للرسائل النظامية.
- `author_type`: `customer` أو `agent` أو `system`.
- `visibility`: `public` أو `internal`.
- `body`
- `client_message_id` كـ UUID لمنع تكرار الإرسال.
- timestamps

الرسائل لا تُحذف فعليًا من الواجهة. عند الحاجة إلى الإخفاء أو التنقيح يتم تسجيل عملية redaction مع سبب داخل سجل التدقيق.

### support_attachments

- `id`
- `public_id`
- `message_id`
- `disk`
- `path`
- `original_name`
- `mime_type`
- `size_bytes`
- `sha256`
- `scan_status`
- timestamps

المرفقات تحفظ في private storage، ولا يعاد `path` للعميل. التحميل يتم من endpoint مصرح يصدر رابطًا مؤقتًا قصير العمر بعد التحقق من ملكية التذكرة أو صلاحية موظف الدعم.

### support_ticket_reads

- `ticket_id`
- `user_id`
- `last_read_message_id`
- `read_at`
- unique على `ticket_id + user_id`

هذا يسمح بعدد غير مقروء صحيح لكل عميل ولكل موظف، ولا يفترض أن كل موظفي الدعم قرأوا ما قرأه موظف واحد.

### support_ticket_events

- `id`
- `ticket_id`
- `actor_id`
- `event_type`
- `from_value`
- `to_value`
- `metadata` محدودة وغير حساسة.
- `created_at`

يسجل إنشاء التذكرة والتعيين وتغيير الحالة والأولوية والتصعيد وكسر SLA وإعادة الفتح والإغلاق والتنقيح.

### المرحلة المتقدمة

- `support_saved_replies` للردود المحفوظة.
- `support_tags` و`support_ticket_tag` للتصنيف التشغيلي.
- `support_ticket_feedback` لتقييم تجربة الدعم بعد الحل.
- جداول business hours والإجازات إذا أُريد حساب SLA داخل ساعات العمل بدل 24/7.

## دورة حياة التذكرة

الحالات الموصى بها:

- `new`: أنشأها العميل ولم يرد عليها الدعم بعد.
- `open`: يعمل عليها موظف الدعم.
- `waiting_customer`: الدعم ينتظر معلومات أو إجراء من العميل.
- `on_hold`: التذكرة متوقفة على فريق داخلي أو مزود خارجي.
- `resolved`: تم تقديم الحل ويمكن للعميل إعادة الفتح خلال المدة المحددة.
- `closed`: حالة نهائية بعد انتهاء مهلة إعادة الفتح أو إغلاق إداري موثق.

القواعد الأساسية:

- إنشاء العميل ينتج `new`.
- أول رد عام من موظف يسجل `first_responded_at` مرة واحدة.
- رسالة العميل على `waiting_customer` تعيدها إلى `open` وتستأنف SLA.
- رسالة العميل على `resolved` تعيد فتحها إذا كانت داخل مهلة إعادة الفتح.
- لا يسمح بإرسال رسالة جديدة على `closed`؛ ينشئ العميل تذكرة جديدة مع رابط للتذكرة القديمة.
- `internal note` لا تغير حالة قراءة العميل ولا ترسل له إشعارًا.
- تغيير الحالة يكون صريحًا ولا يتحول كل رد دعم تلقائيًا إلى `waiting_customer`.
- job مجدول يحول `resolved` إلى `closed` بعد مدة قابلة للضبط، مثل سبعة أيام.

## الأولوية والـ SLA

الأولويات:

- `low`
- `normal`
- `high`
- `urgent`

الأولوية الافتراضية تأتي من التصنيف، ويستطيع موظف مخول تغييرها مع تسجيل السبب. يمكن رفع الأولوية تلقائيًا لحالات مثل مشكلة دفع مؤكدة أو بلاغ أمني، لكن لا يستنتج العميل أولوية لنفسه مباشرة.

يجب حفظ المواعيد الفعلية على التذكرة بدل حسابها فقط عند العرض:

- موعد الرد الأول.
- موعد الرد التالي عند الحاجة.
- موعد الحل.
- وقت التوقف المسموح في `waiting_customer` أو `on_hold`.
- تاريخ الكسر ونوعه.

تظهر في الداشبورد حالات `healthy` و`at_risk` و`breached` مع وقت متبقٍ. تستخدم jobs مجدولة لإصدار تنبيه قبل الكسر وعند الكسر، مع منع تكرار التنبيه.

## الصلاحيات

وجود دور `admin` وحده لا يكفي. الصلاحيات المقترحة:

- `support.tickets.view`
- `support.tickets.reply`
- `support.tickets.assign`
- `support.tickets.change_priority`
- `support.tickets.resolve`
- `support.internal_notes.write`
- `support.attachments.download`
- `support.saved_replies.manage`
- `support.settings.manage`
- `support.analytics.view`

يفضل إضافة صلاحيات دعم منفصلة بدل استخدام `auction_permissions` لأنها تسمية ونطاق مختلفان. جميع admin endpoints تمر على Policy ولا تعتمد على إخفاء أزرار الداشبورد.

موظف الدعم يرى تذاكر الدعم فقط، ولا يحتاج تلقائيًا إلى صلاحية قراءة كل محادثات المستخدمين العادية أو معاملاتهم المالية. أي بيانات سياقية تظهر له تكون أقل قدر لازم لمعالجة التذكرة.

## عقد API المقترح

### بيانات مشتركة

| Method | Endpoint | الغرض |
| --- | --- | --- |
| GET | `/api/soom/support/categories` | التصنيفات المتاحة وسياسات المرفقات العامة |

### المستخدم والموبايل

| Method | Endpoint | الغرض |
| --- | --- | --- |
| GET | `/api/soom/support/tickets` | تذاكر المستخدم مع status/category/search/page |
| POST | `/api/soom/support/tickets` | إنشاء تذكرة مع أول رسالة وسياق اختياري |
| GET | `/api/soom/support/tickets/{ticket}` | تفاصيل التذكرة وحالتها وSLA المسموح عرضه |
| GET | `/api/soom/support/tickets/{ticket}/messages` | رسائل عامة باستخدام cursor pagination |
| POST | `/api/soom/support/tickets/{ticket}/messages` | إرسال رسالة idempotent ومرفقات اختيارية |
| POST | `/api/soom/support/tickets/{ticket}/read` | تحديث آخر رسالة مقروءة |
| POST | `/api/soom/support/tickets/{ticket}/resolve` | تأكيد العميل أن المشكلة حُلّت إذا سمحت الحالة |
| POST | `/api/soom/support/tickets/{ticket}/reopen` | إعادة الفتح خلال المهلة |
| GET | `/api/soom/support/attachments/{attachment}/download-url` | رابط تنزيل مؤقت بعد authorization |

### الداشبورد

| Method | Endpoint | الغرض |
| --- | --- | --- |
| GET | `/api/admin/support/tickets` | queue بفلاتر الحالة والأولوية والتصنيف والتعيين وSLA |
| GET | `/api/admin/support/tickets/summary` | أعداد الـ queues والتنبيهات دون تحميل القوائم |
| GET | `/api/admin/support/tickets/{ticket}` | تفاصيل التذكرة وسياق العميل وسجل التغييرات |
| GET | `/api/admin/support/tickets/{ticket}/messages` | الرسائل العامة والملاحظات الداخلية |
| POST | `/api/admin/support/tickets/{ticket}/messages` | رد عام على العميل |
| POST | `/api/admin/support/tickets/{ticket}/internal-notes` | ملاحظة داخلية لا تصل للعميل |
| PATCH | `/api/admin/support/tickets/{ticket}` | الحالة والأولوية والتعيين مع `expected_version` |
| POST | `/api/admin/support/tickets/{ticket}/read` | حالة قراءة الموظف الحالي |
| GET | `/api/admin/support/analytics` | backlog وSLA والرد والحل وإعادة الفتح والتقييم |
| GET/POST/PATCH/DELETE | `/api/admin/support/saved-replies` | إدارة الردود المحفوظة حسب الصلاحية |

### قواعد العقد

- تستخدم التذاكر والرسائل والمرفقات ULID في المسارات ولا تكشف IDs الرقمية.
- كل mutation تقبل `client_request_id` أو `idempotency_key` مناسبًا.
- إرسال الرسالة يستخدم `client_message_id` فريدًا داخل التذكرة.
- pagination النشط للرسائل يكون cursor أو `before_id` و`after_id`، وليس page رقمية تتغير أثناء وصول رسائل جديدة.
- كل success response يلتزم `{ success, message, data, meta? }`.
- كل error response يلتزم `{ success: false, message, code, errors? }`.
- أكواد أخطاء مستقرة مثل `support_ticket_not_found` و`support_ticket_closed` و`support_invalid_transition` و`support_assignment_conflict` و`support_attachment_rejected`.
- endpoints القراءة الخاصة لا تستخدم shared cache.

## توثيق API والموبايل

كل controller وendpoint جديد يوثق بواسطة Scramble ويحتوي حسب احتياجه على:

- `Group`
- `Endpoint`
- `PathParameter`
- `QueryParameter`
- `BodyParameter`
- كل حالات `Response` الفعلية مثل 200 و201 و401 و403 و404 و409 و422 و429.

توثق Form Requests الحقول والأمثلة والحدود، وتستخدم Resources واضحة حتى يستطيع Scramble استنتاج schemas الفعلية. بعد التنفيذ يتم تشغيل `scramble:analyze` وتصدير OpenAPI والتحقق من جميع مسارات الدعم.

يُنشأ ملف `SUPPORT_CHAT_FRONTEND_HANDOFF_AR.md` بجوار توثيق تقييم البائع، ويشمل:

- تصور تجربة العميل والموظف.
- جميع endpoints وpayloads والاستجابات.
- الحالات والانتقالات والأولويات.
- أكواد الأخطاء.
- أحداث Pusher وقنواتها وpayloads.
- قواعد المرفقات والتحميل.
- أمثلة تكامل الويب والموبايل.
- سيناريوهات reconnect وdeduplication وoffline.

## تجربة المستخدم في الويب

### المسارات

- تبقى `/support` صفحة عامة لقنوات الاتصال والأسئلة الشائعة، وتضيف CTA واضحًا لبدء محادثة بعد تسجيل الدخول.
- `/account/support` لقائمة تذاكر المستخدم.
- `/account/support/new` لإنشاء تذكرة.
- `/account/support/[ticketId]` لعرض المحادثة.

### إنشاء التذكرة

النموذج يجمع أقل معلومات لازمة:

- التصنيف.
- عنوان مختصر.
- وصف المشكلة.
- سياق اختياري من الإعلان أو المزاد أو الدفعة التي جاء منها المستخدم.
- مرفقات اختيارية.

بعد الإنشاء يظهر رقم مرجعي واضح ووقت الاستجابة المتوقع. لا يطلب النموذج بيانات يعرفها الحساب بالفعل.

### شاشة التذكرة

- header ثابت نسبيًا يحتوي الرقم والحالة والتصنيف ووقت آخر تحديث.
- timeline يفرق بوضوح بين العميل والدعم ورسائل النظام.
- composer يحافظ على المسودة عند فشل الشبكة.
- حالة إرسال محلية `sending` ثم `sent` بعد acknowledgment فقط.
- المرفق يعرض الاسم والنوع والحجم قبل الإرسال.
- رسالة واضحة عندما ينتظر الدعم رد العميل.
- زر إعادة فتح يظهر فقط عندما يسمح الخادم.
- نموذج تقييم دعم مختصر بعد الحل.
- حالة offline/reconnecting هادئة دون سيل من التنبيهات.

التنفيذ يتبع معمارية الويب الحالية: Server Component للقراءة الأولية، BFF Route Handlers للمصادقة والـ mutations، وclient island صغير للمحادثة والـ realtime. لا يصل bearer token إلى JavaScript.

## مساحة عمل الدعم في الداشبورد

يضاف قسم مستقل في القائمة باسم مركز الدعم على المسار `/dashboard/support`، ولا يوضع داخل إعدادات المزادات لأن الدعم يخدم الحساب والإعلانات والمزادات والمدفوعات.

### التخطيط الكبير

واجهة ثلاثية الأعمدة:

1. queues المحفوظة والأعداد: جديدة، غير معينة، الخاصة بي، مفتوحة، تنتظر العميل، معرضة لكسر SLA، مكسورة SLA، عاجلة، ومحسومة.
2. قائمة تذاكر كثيفة تعرض الرقم والعميل والعنوان والحالة والأولوية والموظف ووقت آخر رسالة ومؤشر SLA وغير المقروء.
3. مساحة التذكرة: المحادثة وفي جانبها معلومات العميل والسياق وسجل الأحداث والإجراءات.

في الشاشات الصغيرة تتحول الأعمدة إلى routes أو panels متتابعة دون فقد filters أو التذكرة المفتوحة.

### أدوات الموظف

- claim ticket أو التعيين لموظف آخر.
- تغيير الحالة والأولوية مع optimistic concurrency.
- الرد العام والملاحظة الداخلية بلونين وعنوانين مختلفين بوضوح.
- ردود محفوظة قابلة للبحث، دون إرسال تلقائي بمجرد اختيارها.
- ربط آمن بالإعلان أو المزاد أو الدفعة ذات الصلة.
- عرض التذاكر السابقة للعميل دون كشف محادثاته الخاصة مع مستخدمين آخرين.
- مؤقتات SLA وتاريخ أول رد وآخر تحديث.
- shortcuts موثقة، focus واضح، ودعم keyboard كامل.
- منع الإرسال المزدوج وإبقاء النص عند الفشل.

React Query يملك server state والـ cache. الفلاتر والبحث والتذكرة المحددة توضع في URL. لا يضاف Redux لمحتوى التذاكر. Pusher يحدث العناصر الموجودة أو يطلب refetch عند عدم اليقين.

## Realtime والإشعارات

### القنوات

- `private-support.ticket.{public_id}` للعميل والموظفين المصرح لهم.
- `private-support.agent.{admin_id}` لتحديث badge والتعيينات الخاصة بالموظف.
- `private-support.queue` لتحديثات summaries التشغيلية، بدون نصوص الرسائل أو مرفقاتها.

### الأحداث

- `support.message.created`
- `support.ticket.updated`
- `support.ticket.assigned`
- `support.ticket.sla_warning`
- `support.ticket.sla_breached`
- `support.unread.updated`

كل event يحمل أقل payload لازم، وله schema وversion. الرسالة العامة تصل إلى قناة التذكرة، أما الملاحظة الداخلية فلا تصل إلى قناة العميل مطلقًا.

تُبث الأحداث بعد commit فقط. العميل يتحقق من payload باعتباره unknown، يزيل التكرار بواسطة message ID، ويحافظ على الترتيب. بعد reconnect أو العودة للتبويب يتم جلب tail والأعداد من الخادم قبل إعلان أن الشاشة محدثة.

إشعار الهاتف لا يحتوي نص الرسالة أو معلومات حساسة على شاشة القفل. يكفي مثلًا: «لديك رد جديد من فريق الدعم على التذكرة SUP-…» مع deep link آمن.

## الأمان والخصوصية

- Policy على كل read وwrite وdownload.
- requester لا يرى إلا تذاكره ورسائل `public`.
- internal notes لا تدخل user resources أو user events أو push payloads.
- المرفقات private وروابطها مؤقتة وغير مخزنة في cache.
- MIME حقيقي وحجم وامتداد وhash وفحص malware قبل إتاحة التحميل.
- الرسائل تعرض plain text ولا تقبل HTML من العميل.
- لا تسجل logs نصوص الرسائل أو أسماء الملفات أو التوكن أو الهاتف أو البريد.
- rate limits منفصلة للإنشاء والإرسال والقراءة والتحميل.
- حماية من spam: حد لعدد التذاكر المفتوحة وعدد المرفقات والحجم الإجمالي.
- لا يسمح بتعديل sender أو requester أو visibility من user payload.
- جميع تغييرات الموظف الحساسة مسجلة في audit غير قابل للتلاعب من الواجهة.
- سياسة retention معلنة، مع anonymization أو redaction عند طلب حذف الحساب وفق الالتزامات التشغيلية والقانونية.
- تنزيل المرفقات وتصدير البيانات يحتاجان صلاحية مستقلة ويمكن تدقيقهما.

## الملاحظة الأمنية الخاصة بالداشبورد

قبل تشغيل Pusher أو فتح محتوى دعم في الإنتاج، يجب نقل جلسة الداشبورد من cookies قابلة للقراءة بواسطة JavaScript إلى HttpOnly Secure cookies خلف Route Handlers أو BFF. الوضع الحالي يجعل أثر أي XSS أكبر لأنه يستطيع قراءة access token وrefresh token.

الترتيب الموصى به:

1. login server route يحفظ التوكنات في HttpOnly cookies.
2. browser يتصل فقط بـ dashboard Route Handlers.
3. Route Handlers تضيف bearer token عند الاتصال بـ Laravel.
4. endpoint ضيق لـ broadcasting auth يسمح فقط بأنماط قنوات الدعم المعروفة.
5. لا يستخدم `NEXT_PUBLIC` أو localStorage أو JavaScript cookies لأي secret.

## المراقبة والتحليلات

### مؤشرات تشغيلية

- backlog الحالي وعمر أقدم تذكرة.
- متوسط وP50 وP90 زمن الرد الأول.
- متوسط وP50 وP90 زمن الحل.
- نسبة كسر SLA حسب التصنيف والأولوية.
- التذاكر المفتوحة والمحلولة والمعاد فتحها.
- حمل كل موظف وعدد تذاكره النشطة.
- نسبة تقييمات الرضا ومتوسطها.

لا تدخل نصوص الرسائل أو معلومات العميل الشخصية في analytics. تستخدم IDs داخلية أو مجاميع فقط.

### تشغيل واعتمادية

- queue منفصلة لإشعارات وبث الدعم عند الحاجة.
- dead-letter أو failed-job visibility للأحداث والإشعارات.
- health metrics لزمن queue وفشل Pusher وفشل push.
- pruning للمرفقات المنتهية وفق retention policy.
- alert عند ارتفاع backlog أو كسر SLA، مع cooldown يمنع تكرار التنبيه.

## الاختبارات المطلوبة

### Backend

- إنشاء وقراءة وملكية التذاكر.
- منع المستخدم من قراءة أو إرسال رسالة في تذكرة غيره.
- عدم تسرب الملاحظات الداخلية في user API أو Pusher أو FCM.
- كل انتقالات الحالة المسموحة والمرفوضة.
- حساب SLA والإيقاف والاستئناف والكسر والإغلاق المجدول.
- idempotency للإرسال والإنشاء.
- تعارض موظفين على التعيين أو الحالة باستخدام version.
- مرفقات خاصة وروابط مؤقتة وفشل الفحص.
- query budgets للقوائم والرسائل والعدادات.
- MySQL concurrency للتعيين والإرسال المكرر والإغلاق التلقائي.
- events بعد commit وعدم إرسال phantom notification عند rollback.
- rate limits وحذف الحساب وretention.
- فحص Scramble وOpenAPI.

### Dashboard

- filters في URL وqueues والأعداد.
- claim/assign/status/priority conflicts.
- الفصل بين public reply وinternal note.
- optimistic message reconciliation وdeduplication.
- reconnect وmissed events.
- permission states وforbidden.
- keyboard وRTL و360/768/1280/1440 viewports.
- عدم فقد مسودة الرد عند الشبكة أو conflict.

### Web

- إنشاء تذكرة وإظهار الرقم المرجعي.
- قائمة وتفاصيل مع ownership.
- إرسال نص ومرفق وحالة failed/retry.
- unread وmark read.
- realtime duplicate/out-of-order/reconnect.
- resolved/reopen/closed flows.
- CSAT بعد الحل.
- deep links من الإشعارات.
- authenticated E2E وaccessibility وresponsive evidence.

## مراحل التنفيذ

### المرحلة صفر: العقد والأمان

- تثبيت الحالات والأولويات والتصنيفات والصلاحيات وSLA.
- تسجيل ADRs اللازمة في الويب.
- إضافة task IDs لمركز الدعم في `soom-web` قبل تعديل الكود.
- تحديد سياسة المرفقات والاحتفاظ.
- تأمين جلسة الداشبورد بخيار HttpOnly BFF.

### المرحلة الأولى: Backend Core

- migrations وmodels وenums وpolicies وresources وrequests.
- actions وrepositories ودورة الحالة وSLA.
- user/admin APIs كاملة.
- audit وidempotency والمرفقات الخاصة.
- Scramble واختبارات SQLite وMySQL.

### المرحلة الثانية: Dashboard Operations

- صفحة مركز الدعم والـ queues والفلاتر.
- تفاصيل التذكرة والرد والملاحظة الداخلية والتعيين.
- SLA indicators والتعارضات والعدادات.
- صلاحيات الموظفين والردود المحفوظة الأساسية.

### المرحلة الثالثة: Web Customer Experience

- CTA من صفحة الدعم الحالية.
- قائمة وإنشاء وتفاصيل التذاكر داخل الحساب.
- BFF routes وDTO parsing والـ composer والمرفقات.
- حالات loading وempty وoffline وfailed وresolved.

### المرحلة الرابعة: Realtime والإشعارات

- private channel auth الضيق.
- الأحداث والـ schemas والdeduplication والreconnect.
- FCM وdatabase notifications والdeep links.
- dashboard badge وqueue refresh.

### المرحلة الخامسة: Hardening وإطلاق تدريجي

- query budgets وload tests وconcurrency.
- E2E والـ accessibility والـ responsive QA.
- analytics وalerts وrunbook.
- feature flag وإطلاق داخلي ثم نسبة من المستخدمين ثم الإطلاق الكامل.

## معايير القبول النهائية

- المستخدم لا يرى إلا تذاكره ورسائله العامة.
- موظف الدعم يعمل من queue مستقلة بصلاحيات least privilege.
- كل تذكرة لها owner وحالة وأولوية وتصنيف وSLA وسجل تدقيق.
- لا تضيع الرسائل ولا تتكرر عند retry أو reconnect.
- لا تظهر رسالة ناجحة قبل acknowledgment من Laravel.
- لا تتسرب internal notes أو المرفقات أو نصوص الرسائل إلى push أو logs أو analytics.
- كل API موثق بالكامل ومتاح لتطبيق الموبايل دون افتراضات خاصة بالويب.
- الويب والداشبورد يدعمان RTL والkeyboard والشاشات الصغيرة والكبيرة.
- dashboards والعدادات تعتمد على الخادم، ولا تعيد بناء business truth في الواجهة.
- حالات failure وoffline وconflict وrate limit وforbidden وnot found لها UX واضح.
- OpenAPI والاختبارات والفحوصات التشغيلية تنجح قبل الإطلاق.

## التوصية النهائية

المسار الصحيح هو مركز دعم Ticketed مستقل مع queue للموظفين وSLA وتعيين وملاحظات داخلية وتدقيق ومرفقات خاصة وrealtime موثوق. يتم الاحتفاظ بنظام محادثات المستخدمين كما هو، والاستفادة منه فقط كمصدر لخبرة التنفيذ وبنية Pusher وFCM، لا كنموذج بيانات للدعم.

أهم شرطين قبل البدء هما اعتماد دورة الحالات والصلاحيات، وتأمين جلسة الداشبورد بواسطة HttpOnly BFF. بعدهما يمكن تنفيذ الـ backend أولًا، ثم الداشبورد، ثم الويب والموبايل على عقد API واحد موثق.
