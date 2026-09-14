# تسليم معرّف الإعلان العام وتحسينات تجميع الـAPI

## التغيير الإلزامي

أصبح كل معرّف إعلان يظهر أو يُرسل عبر API العام عبارة عن ULID بطول 26 حرفًا. يظل `ads.id` الرقمي داخليًا فقط للعلاقات والـjoins والـjobs، ولا يقبله أي مسار عام بعد الإطلاق المنسق.

```text
01K8F3N7Y0QZ9W4C2X6MAB12DE
```

أسماء الحقول لم تتغير: `id` و`ad_id` ما زالا كما هما، لكن نوع معرّف الإعلان أصبح `string` بدل `integer`.

## قواعد العميل

- يقبل العميل ULID مطابقًا للنمط `^[0-9A-HJKMNP-TV-Z]{26}$` فقط.
- لا يحوّل معرّف الإعلان باستخدام `Number` ولا يحتفظ به في نوع رقمي.
- لا يوفّر fallback للرابط الرقمي القديم.
- لا يعتبر ULID صلاحية؛ الخادم يطبق الملكية والسياسات وحدود المعدل وحالة الإعلان.
- معرّفات المستخدمين والتصنيفات والرسائل والريلز لم تتغير ضمن هذا الإصدار.

## مسارات الإعلانات

```http
GET    /api/soom/ads/{ad_ulid}
POST   /api/soom/ads
PUT    /api/soom/ads/my/{ad_ulid}
DELETE /api/soom/ads/my/soft-delete/{ad_ulid}
POST   /api/soom/ads/my/restore/{ad_ulid}
DELETE /api/soom/ads/my/force-delete/{ad_ulid}
POST   /api/soom/favorites
DELETE /api/soom/favorites/{ad_ulid}
```

إضافة الإعلان إلى المفضلة:

```json
{
  "ad_id": "01K8F3N7Y0QZ9W4C2X6MAB12DE"
}
```

إرسال رسالة مرتبطة بإعلان:

```json
{
  "receiver_id": 25,
  "content": "هل الإعلان ما زال متاحًا؟",
  "ad_id": "01K8F3N7Y0QZ9W4C2X6MAB12DE"
}
```

إنشاء الإعلان يعيد بيانات الإعلان ويكون `data.id` هو ULID الذي يجب استخدامه في صفحة النجاح والرابط التالي.

## أماكن ظهور ULID

يظهر ULID بدل الرقم الداخلي في تفاصيل الإعلان والقوائم والصفحة الرئيسية وإعلانات المستخدم والمفضلة والرسائل وأحداث الشات والريلز والإشعارات وملف المستخدم الإداري وعمليات إدارة الإعلان وسياق تذاكر الدعم المرتبط بإعلان.

## مزامنة محادثة الدعم

```http
GET /api/soom/support/tickets/{ticket_ulid}/conversation
```

الفتح الأول:

```http
GET /api/soom/support/tickets/01K8F3N7Y0QZ9W4C2X6MAB12AA/conversation?limit=100
```

المزامنة التالية:

```http
GET /api/soom/support/tickets/01K8F3N7Y0QZ9W4C2X6MAB12AA/conversation?after_message_id=01K8F3N7Y0QZ9W4C2X6MAB12AB&known_version=7&limit=100
```

```json
{
  "success": true,
  "data": {
    "ticket": {},
    "messages": [],
    "sync": {
      "version": 7,
      "latest_message_id": "01K8F3N7Y0QZ9W4C2X6MAB12AB",
      "changed": false,
      "has_more_before": false,
      "has_more_after": false
    }
  }
}
```

القناة الخاصة `private-support.ticket.{ticket_ulid}` تنبّه العميل لبدء Delta Sync. عند تعذر الاتصال اللحظي يستخدم العميل polling احتياطيًا، يتوقف عند إخفاء التطبيق ويتدرج من 15 إلى 60 ثانية. مسار الرسائل القديم مستمر، لكن `before_id` فيه أصبح ULID الرسالة العام.

يلزم ضبط `NEXT_PUBLIC_PUSHER_KEY` و`NEXT_PUBLIC_PUSHER_CLUSTER` في الويب والداشبورد. عند غيابهما لا تفشل المحادثة، بل تعمل عبر الـpolling الاحتياطي فقط.

لوحة الإدارة تستخدم:

```http
GET /api/admin/support/tickets/{ticket_ulid}/conversation
```

وتعيد أيضًا الملاحظات الداخلية المصرح بها للموظف.

## ملخص لوحة الحساب

```http
GET /api/soom/account/dashboard-summary
```

```json
{
  "success": true,
  "data": {
    "active_ads_count": 8,
    "deleted_ads_count": 1,
    "total_ad_views": 420,
    "favorites_count": 6,
    "seller_auctions_count": 2,
    "recent_ads": [
      {
        "id": "01K8F3N7Y0QZ9W4C2X6MAB12DE",
        "title": "عنوان الإعلان",
        "price": "125.00",
        "image": null,
        "status": true,
        "views_count": 50
      }
    ]
  }
}
```

يبقى `/api/soom/account/shell` منفصلًا لبيانات المستخدم وعدادات الرسائل والإشعارات.

## محتوى الصفحة الرئيسية

```http
GET /api/soom/home/promotions
```

```json
{
  "success": true,
  "data": {
    "banners": [],
    "announcements": [],
    "charity": []
  }
}
```

هذه الاستجابة بديل الطلبات الثلاثة المنفصلة عند تحميل الصفحة الرئيسية، ولا تلغي المسارات القديمة.

## بيانات شريك المحادثة

`GET /api/soom/messages/chat/{userId}` يعيد `partner` بجوار بيانات pagination:

```json
{
  "partner": {
    "id": 25,
    "name": "اسم البائع",
    "logo": null,
    "location": "الأردن، عمان",
    "joined_at": "2026-09-01T10:00:00+03:00",
    "rating_summary": {
      "average": 4.75,
      "count": 20
    }
  }
}
```

## أخطاء التكامل

- `404`: ULID غير موجود، رابط رقمي قديم، أو إعلان غير مملوك في مسارات الإدارة الشخصية.
- `422`: صيغة ULID غير صحيحة في body أو query.
- `401`: لا توجد جلسة صالحة لمسار محمي.
- `403`: العملية غير مسموحة وفق السياسة في المسارات التي لا تستخدم إخفاء الوجود.
- `429`: تجاوز حد المعدل.

## ترتيب الإطلاق

1. تشغيل `2026_09_14_120000_add_public_id_to_ads.php` أولًا لإضافة الحقل nullable وتنفيذ الـBackfill والتحويلات القديمة.
2. بدء نافذة صيانة قصيرة لكتابات الإعلانات، ثم تفعيل إصدار Laravel الذي يولد `public_id` والتحقق من عدم وجود قيمة فارغة أو مكررة.
3. تشغيل `2026_09_14_121000_make_ads_public_id_required.php`؛ يعالج أي صف فارغ ظهر بين المرحلتين ثم يتوقف تلقائيًا إن بقيت قيمة فارغة.
4. تفعيل الويب والموبايل والداشبورد وإنهاء نافذة الصيانة كقطع عقد منسق.
5. التحقق من أن رابط إعلان رقمي يعيد `404` وأن الإنشاء والمفضلة والشات والإشعارات تستخدم ULID.
6. مراقبة أخطاء `404` و`422` بعد القطع لاكتشاف عميل قديم ما زال يرسل أرقامًا.

الرجوع يتطلب إعادة نسخة كل العملاء والعقد معًا. لا يُنصح بإعادة numeric fallback بعد بدء القطع لأنه يعيد تسريب المعرّف الداخلي ويخلق عقدين متوازيين.
