# تسليم مركز الدعم الفني للفرونت والموبايل

## ما تم تنفيذه

تم إنشاء نطاق مستقل باسم **مركز الدعم الفني**. لم يُبنَ فوق محادثات المستخدمين الحالية لأن محادثة الدعم لها رقم مرجعي ودورة حياة وأولوية ومسؤول وSLA وملاحظات داخلية وسجل تدقيق، بينما شات المستخدمين هو تواصل مباشر فقط.

التصور المقصود للفرونت هو مساحة دعم كاملة تشبه أنظمة الشركات الكبيرة، مع حرية كاملة للمصمم في الشكل النهائي:

- العميل يفتح طلبًا من صفحة الدعم أو حسابه، يختار نوع المشكلة، يكتب عنوانًا واضحًا وتفاصيلها.
- يحصل فورًا على رقم مثل `SUP-260913-A1B2C3` ويشاهد حالة الطلب وأولويته وموظف المتابعة.
- المحادثة تستمر داخل نفس التذكرة، ويمكن للعميل اعتبارها محلولة أو إعادة فتحها خلال النافذة المسموحة.
- موظف الدعم يعمل من طوابير: جديد، مفتوح، بانتظار العميل، غير مسند، عاجل، ومتأخر عن SLA.
- الموظف يستطيع إرسال رد عام أو كتابة ملاحظة داخلية. النوعان يجب أن يظلا مختلفين بصريًا ووظيفيًا؛ الملاحظة الداخلية لا تظهر للعميل مطلقًا.
- البيانات الآتية من API هي المصدر الوحيد للحالة والأولوية وSLA. لا يعيد الفرونت اشتقاقها.

## الثوابت

### حالات التذكرة

| القيمة | المعنى |
|---|---|
| `new` | جديدة ولم يبدأ التعامل معها |
| `open` | قيد المعالجة |
| `waiting_customer` | فريق الدعم رد وينتظر العميل |
| `on_hold` | معلقة تشغيليًا |
| `resolved` | محلولة ويمكن إعادة فتحها في المدة المسموحة |
| `closed` | مغلقة ولا تقبل رسائل |

### الأولوية

`low`, `normal`, `high`, `urgent`.

### الرسائل

- `author_type`: `customer`, `agent`, `system`.
- `visibility`: `public`, `internal`.
- API العميل لا يعيد `internal` أصلًا. يفضل أن يرفض parser في تطبيق العميل هذه القيمة أيضًا كحماية إضافية.

## غلاف الاستجابة

النجاح المفرد:

```json
{
  "success": true,
  "message": "تمت العملية بنجاح.",
  "data": {}
}
```

القوائم المقسمة إلى صفحات تحمل `data` و`current_page` و`last_page` و`per_page` و`total` و`next_page_url` و`prev_page_url` في المستوى الأعلى.

الأخطاء الموحدة تحمل `success: false` و`message` و`code`، وأخطاء التحقق `422` تحمل `errors`.

## API العميل والموبايل

كل المسارات تتطلب Bearer token لحساب `user`.

### التصنيفات

`GET /api/soom/support/categories`

يستخدم قبل نموذج الإنشاء. المعرّف المطلوب في الطلب هو `id` العام من نوع ULID، وليس الرقم الداخلي.

### قائمة تذاكر المستخدم

`GET /api/soom/support/tickets?status=open&page=1&per_page=20`

`status` اختياري. النتائج مرتبة حسب آخر نشاط.

### إنشاء تذكرة

`POST /api/soom/support/tickets`

```json
{
  "category_id": "01K5Z7J39X5D3M8YF8Z8M2P7Q1",
  "subject": "تعذر إتمام عملية الدفع",
  "message": "تم خصم المبلغ ولم تتغير حالة العملية.",
  "client_message_id": "6d244491-439a-4b83-b0c6-0ea142f04bd0",
  "context_type": "payment",
  "context_id": "PAY-123"
}
```

`context_type` و`context_id` اختياريان. الأنواع المقبولة: `auction`, `ad`, `payment`, `account`. لا يرسل الفرونت سياقًا لم يختَره المستخدم أو لم يأتِ من الصفحة الحالية.

`subject` بحد أقصى 160 حرفًا و`message` بحد أقصى 5000 حرف.

### تفاصيل التذكرة

`GET /api/soom/support/tickets/{ticket_ulid}`

أهم حقول الاستجابة:

```json
{
  "id": "01K5Z...",
  "reference_number": "SUP-260913-A1B2C3",
  "subject": "تعذر إتمام عملية الدفع",
  "status": "open",
  "priority": "high",
  "category": {},
  "assignee": null,
  "context": { "type": "payment", "id": "PAY-123" },
  "version": 2,
  "sla": {
    "first_response_due_at": "2026-09-13T22:00:00+03:00",
    "resolution_due_at": "2026-09-14T09:00:00+03:00",
    "first_response_breached": false,
    "resolution_breached": false
  }
}
```

### الرسائل

`GET /api/soom/support/tickets/{ticket_ulid}/messages?per_page=50&before_id=123`

الاستجابة من الأحدث إلى الأقدم. يعكسها الفرونت للعرض الزمني. `before_id` اختياري لتحميل الرسائل الأقدم.

`POST /api/soom/support/tickets/{ticket_ulid}/messages`

```json
{
  "message": "ما زالت المشكلة قائمة.",
  "client_message_id": "f84621bf-b937-4f91-94ba-d17a22d059bc"
}
```

يُنشأ UUID واحد لكل محاولة منطقية ويعاد استخدامه فقط عند إعادة نفس الطلب بعد انقطاع غير مؤكد. لا يُنشأ UUID جديد عند retry لنفس الرسالة.

### القراءة ودورة الحياة

- `POST /api/soom/support/tickets/{ticket}/read`
- `POST /api/soom/support/tickets/{ticket}/resolve`
- `POST /api/soom/support/tickets/{ticket}/reopen`

الإرسال إلى تذكرة `waiting_customer` أو `resolved` يعيدها إلى `open`. التذكرة `closed` لا تقبل رسائل. إعادة الفتح الصريحة متاحة للتذكرة `resolved` خلال 14 يومًا افتراضيًا.

## API لوحة التحكم

كل المسارات تتطلب Bearer token لحساب `admin`.

- `GET /api/admin/support/summary`
- `GET /api/admin/support/agents`
- `GET /api/admin/support/tickets`
- `GET /api/admin/support/tickets/{ticket}`
- `PATCH /api/admin/support/tickets/{ticket}`
- `GET /api/admin/support/tickets/{ticket}/messages`
- `POST /api/admin/support/tickets/{ticket}/messages`
- `POST /api/admin/support/tickets/{ticket}/internal-notes`
- `POST /api/admin/support/tickets/{ticket}/read`

فلاتر الطابور: `status`, `priority`, `assigned_to`, `unassigned=true`, `sla=risk|breached`, `search`, `page`, `per_page`.

تحديث التذكرة يستخدم optimistic concurrency:

```json
{
  "expected_version": 3,
  "status": "open",
  "priority": "urgent",
  "assigned_to": 7
}
```

إذا غيّر موظف آخر التذكرة أولًا ترجع `422` على `expected_version`. يجب إعادة جلب التفاصيل ثم عرض التغيير الجديد، لا إعادة الطلب تلقائيًا فوقه.

الرد العام ومسار الملاحظة الداخلية يقبلان نفس جسم الرسالة، لكن لا يجوز للواجهة أن تختار المسار بناءً على لون فقط. اجعل وضع المحرر واضحًا ومكتوبًا، ويفضل خلفية مختلفة وتحذيرًا ثابتًا في وضع الملاحظة الداخلية.

## الوقت الحقيقي

مصادقة القنوات تتم من `/api/broadcasting/auth` بالتوكن نفسه.

- `private-support.ticket.{ticket_ulid}`: العميل صاحب التذكرة وكل مدير مصرح له.
- `private-support.queue`: للمديرين فقط.
- `.support.message.created`
- `.support.ticket.updated`

رسائل الوقت الحقيقي لا تحمل نص الرسالة؛ تحمل المعرّف والحالة اللازمة لإعادة الجلب، لتقليل تسرب المحتوى. بعد reconnect يعاد جلب التذكرة والرسائل لأن API هو المصدر النهائي.

الملاحظة الداخلية لا تُبث إلى قناة تذكرة العميل. تصل إشارة عامة لقناة طابور المديرين فقط.

## حالات HTTP المهمة

| الحالة | تصرف الواجهة |
|---|---|
| `401` | انتهاء الجلسة والانتقال لتسجيل الدخول |
| `403` | منع الوصول دون كشف وجود تذكرة مستخدم آخر |
| `404` | التذكرة أو التصنيف غير موجود |
| `422` | عرض أخطاء الحقول أو تعارض النسخة أو منع انتقال الحالة |
| `429` | تهدئة الإرسال وعرض وقت إعادة المحاولة إن توفر |

لا يُعاد تلقائيًا أي `POST` أو `PATCH`. الطلبات الآمنة `GET` فقط يمكن إعادتها بعد تجديد التوكن.

## تصور الواجهة مع مساحة للإبداع

### العميل

- `/support`: وسائل الاتصال مع CTA واضح لفتح تذكرة.
- `/account/support`: قائمة الطلبات وحالاتها.
- `/account/support/new`: نموذج موجّه قصير.
- `/account/support/{ticket}`: بطاقة ملخص ثم المحادثة والإجراءات.

لا يُفرض شكل بصري محدد؛ المطلوب أن يشعر العميل أن طلبه محفوظ وله مالك وحالة وخطوة تالية واضحة، وأن تظل المحادثة مريحة على الهاتف.

### الداشبورد

أفضل تصور للشاشات الكبيرة هو ثلاث مناطق: الطوابير، قائمة التذاكر، تفاصيل ومحادثة التذكرة. على الهاتف أو التابلت تتحول إلى تنقل متدرج دون ضغط الأعمدة. يجب أن تظل SLA والأولوية والمسؤول ووضع الرد/الملاحظة واضحة دائمًا.

## ما لا يجب فعله

- عدم استخدام `/soom/messages` لمحادثات الدعم.
- عدم استخدام الرقم المرجعي كمعرّف في المسار؛ يستخدم ULID.
- عدم عرض أو تخزين نص ملاحظة داخلية في كود العميل.
- عدم حساب SLA أو افتراض الحالة محليًا.
- عدم إرسال `requester_id` أو `author_id`; الهوية تأتي من التوكن.
- عدم إعادة إرسال mutation تلقائيًا بعد `401`.
- عدم وضع محتوى المحادثة في analytics أو logs أو push notification.

## التشغيل

1. تشغيل migration: `php artisan migrate`.
2. ضبط Pusher والـ queue worker في بيئة التشغيل.
3. فتح وثائق Scramble ومراجعة مجموعتي `مركز الدعم` و`إدارة الدعم الفني`.
4. تنفيذ smoke test: إنشاء تذكرة، استلامها من المدير، رد عام، ملاحظة داخلية، تحقق من عدم ظهورها للعميل، حل، ثم إعادة فتح.

## الملفات الرئيسية في الـ Backend

- `routes/api/support.php`
- `app/Http/Controllers/Support`
- `app/Http/Controllers/Admin/Support`
- `app/Services/Support/SupportTicketService.php`
- `app/Models/Support`
- `app/Domain/Support/Enums`
- `database/migrations/2026_09_13_120000_create_support_center_tables.php`
- `tests/Feature/Support/SupportCenterApiTest.php`
