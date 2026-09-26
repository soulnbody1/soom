# دليل نشر Soom Backend على Production

هذا الدليل مخصص لنشر مشروع Laravel الموجود في هذا المستودع على استضافة cPanel/GoDaddy، مع تشغيل تعدد الأسواق، حماية توثيق الـ API، والمهام المجدولة والـ queues.

> المسار المستخدم في الأمثلة:
> `/home/xsx1xjs32uxx/public_html/soom`

## 1. خريطة الدومينات

| الاستخدام | الدومين المقترح | الوجهة |
|---|---|---|
| الرابط الأساسي للـ Backend | `soomnow.com` | `public_html/soom/public` |
| API الأردن | `api-jo.soomnow.com` | `public_html/soom/public` |
| API مصر | `api-eg.soomnow.com` | `public_html/soom/public` |
| Admin API | `api-admin.soomnow.com` | `public_html/soom/public` |
| واجهة الأردن | `jo.soomnow.com` | مشروع الواجهة الأمامية |
| واجهة مصر | `eg.soomnow.com` | مشروع الواجهة الأمامية |

- أنشئ DNS وSSL لكل دومين قبل فتحه للمستخدمين.
- لا تستخدم Redirect بين دومينات الـ API؛ كل Host يجب أن يصل مباشرة إلى Laravel.
- يجب أن يكون Document Root الخاص بدومينات الـ Backend هو مجلد `public`، وليس جذر المشروع.

## 2. فحوصات ما قبل النشر

نفّذ فحص الاعتماديات الأمنية محليًا قبل كل إصدار، ولا تنشر مع وجود ثغرات عالية الخطورة غير معالجة:

```bash
composer validate --no-check-publish
composer audit --locked --no-dev
```

نفّذ على السيرفر:

```bash
php -v
composer --version
git --version
```

المشروع يدعم PHP `8.2` أو أحدث، لكن ملف `composer.lock` مضبوط ومختبر حاليًا على PHP `8.3.32`. اختر PHP `8.3` في cPanel لكل دومينات هذا النشر وواجهة الأوامر.

قبل ترحيل قاعدة Production:

1. خذ نسخة احتياطية كاملة من قاعدة البيانات عبر cPanel أو phpMyAdmin.
2. تأكد أن آخر كود معتمد موجود في `main`.
3. سجّل رقم آخر commit للرجوع إليه عند الحاجة:

```bash
git rev-parse --short HEAD
```

لا تستخدم `migrate:fresh` أو `db:seed` على قاعدة Production القائمة.

## 3. أول نشر فقط

```bash
cd /home/xsx1xjs32uxx/public_html/soom
git clone --branch main https://github.com/soulnbody1/soom.git .
cp .env.example .env
```

أنشئ المجلدات التي لا تُحفظ في Git:

```bash
mkdir -p storage/app/public storage/framework/cache storage/framework/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

ثبّت مكتبات Production:

```bash
composer install --no-dev --optimize-autoloader
```

أنشئ `APP_KEY` في أول نشر فقط:

```bash
php artisan key:generate --force
```

لا تعِد توليد `APP_KEY` بعد بدء استخدام الموقع؛ تغييره يبطل الجلسات والبيانات المشفرة.

## 4. إعداد `.env`

اضبط القيم الفعلية بدون أقواس أو Backslashes داخل أسماء الدومينات:

```dotenv
APP_NAME=Soom
APP_ENV=production
APP_DEBUG=false
APP_URL=https://soomnow.com

SOOM_ROOT_DOMAIN=soomnow.com
SOOM_ADMIN_API_HOST=api-admin.soomnow.com
SOOM_DOCS_MARKET_CODES=JO,EG
SOOM_LEGACY_API_HOST=
SOOM_LEGACY_MARKET_CODE=JO
SOOM_DEFAULT_MARKET_CODE=JO
SOOM_DEV_MARKET_CODE=JO
SOOM_TRUSTED_PROXY_IPS=

API_DOCS_USERNAME=soomdocs
API_DOCS_PASSWORD=ضع-هنا-كلمة-سر-طويلة-وعشوائية

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=اسم_قاعدة_البيانات
DB_USERNAME=اسم_المستخدم
DB_PASSWORD=كلمة_السر

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

ملاحظات مهمة:

- اضبط `SOOM_ROOT_DOMAIN` قبل تشغيل migrations؛ ترحيل الأسواق يكتب الـ hosts داخل جدول `markets` ولا يعيد كتابتها عند تغيير `.env` لاحقًا.
- اضبط بيانات Pusher الحقيقية إذا كان البث الفوري مستخدمًا. عند عدم استخدامه اجعل `BROADCAST_CONNECTION=log`.
- اترك `SOOM_TRUSTED_PROXY_IPS` فارغًا ما لم توجد عناوين Reverse Proxy/LB معروفة ومحددة.
- لا تحفظ كلمات السر أو `.env` في Git.

## 5. قاعدة البيانات والأسواق

امسح أي كاش قديم أولًا:

```bash
php artisan optimize:clear
```

راجع حالة الترحيلات ثم نفّذها:

```bash
php artisan migrate:status
php artisan migrate --force
```

الإصدار الحالي يتعامل مع وجود جدول `banners` القديم ومع اختلاف أسماء indexes في `messages`، فلا تحتاج إلى حذف جداول أو تسجيل migrations يدويًا.

تحقق من ملكية بيانات الأسواق:

```bash
php artisan market:validate
```

راجع خطة تهيئة كل سوق دون تعديل قاعدة البيانات:

```bash
php artisan market:bootstrap JO --dry-run
php artisan market:bootstrap EG --dry-run
```

بعد مراجعة الخطة، طبّق ملفات السوق. يتطلب Production إضافة `--force` عمدًا:

```bash
php artisan market:bootstrap JO --apply --force
php artisan market:bootstrap EG --apply --force
php artisan market:validate
```

الأمر `market:bootstrap` مصمم ليكون idempotent: لا يكرر الإصدارات المطابقة، ولا يحذف طرق الدفع القديمة، بل يعطّل الطرق التي لا تنتمي إلى الملف المعتمد للسوق. ينشر إصدارًا جديدًا فقط عند تغير إعدادات المزاد أو الشروط أو سياسة مراجعة المحتوى.

ملف الأردن ينشئ تحويلًا يدويًا تجريبيًا وN-Genius وeFAWATEERcom. تظل الطرق الإلكترونية غير فعالة حتى تكتمل بيانات اعتمادها:

```dotenv
NGENIUS_API_KEY=
NGENIUS_OUTLET_REFERENCE=
NGENIUS_BASE_URL=https://api-gateway.sandbox.ngenius-payments.com
NGENIUS_WEBHOOK_SECRET=

AUCTION_PAYMENTS_EFAWATEERCOM_BILLER_CODE=
AUCTION_PAYMENTS_EFAWATEERCOM_USERNAME=
AUCTION_PAYMENTS_EFAWATEERCOM_PASSWORD=
AUCTION_PAYMENTS_EFAWATEERCOM_BIDDER_DEPOSIT_CODE=SOOMBID
AUCTION_PAYMENTS_EFAWATEERCOM_SELLER_DEPOSIT_CODE=SOOMSELL
AUCTION_PAYMENTS_EFAWATEERCOM_WINNER_SETTLEMENT_CODE=SOOMWIN
```

ملف مصر ينشئ طريقة تحويل يدوي واحدة. بيانات المستفيد وأرقام الحسابات وقنوات الدعم في الملفين بيانات اختبار واضحة، ويجب استبدالها من لوحة الإدارة قبل استقبال أي أموال.

تُنشأ مراجعة المحتوى في السوقين باستخدام Gemini بوضع `shadow` وميزانية 5 دولارات يوميًا و100 دولار شهريًا. لتشغيلها فعليًا:

```dotenv
CONTENT_REVIEW_ENABLED=true
CONTENT_REVIEW_PROVIDER=gemini
GEMINI_API_KEY=ضع-المفتاح-هنا
GEMINI_CONTENT_REVIEW_MODEL=gemini-3.6-flash
```

ثم شغّل عامل الطابور المخصص:

```bash
php artisan queue:work --queue=content-review --tries=3 --timeout=120
```

الشروط المنشورة بواسطة ملفات السوق مسودات تشغيلية تجريبية وليست بديلًا عن اعتماد مستشار قانوني محلي. استبدل بيانات الكيان والدعم وراجع النص قبل الإطلاق التجاري.

أنشئ رابط الملفات العامة:

```bash
php artisan storage:link
```

## 6. حماية توثيق الـ API

المسارات التالية محمية بـ HTTP Basic Auth:

- `/docs/api`
- `/docs/api.json`

الحماية تستخدم:

```dotenv
API_DOCS_USERNAME=soomdocs
API_DOCS_PASSWORD=كلمة-سر-قوية-وفريدة
```

وتسمح بالتوثيق على Host الموجود في `APP_URL` فقط. عند غياب أي من القيمتين ترجع المسارات `404`.

تعرض واجهة التوثيق قائمة Servers للأسواق الموجودة في `SOOM_DOCS_MARKET_CODES` بالإضافة إلى Admin API. اختر Server السوق للـ public/user endpoints، واختر Admin API لمسارات `/admin/*`. لا تُرسل الواجهة Cookies أو بيانات Basic Auth الخاصة بصفحة التوثيق إلى دومينات الـ API.

بعد تغيير بيانات الدخول:

```bash
php artisan optimize:clear
php artisan scramble:clear
php artisan scramble:cache
php artisan optimize
```

اختبار الحماية:

```bash
curl -I https://soomnow.com/docs/api
curl -I -u soomdocs https://soomnow.com/docs/api
```

الأمر الأول يجب أن يرجع `401`، والثاني يطلب كلمة السر ثم يرجع `200` عند صحتها.

## 7. Scheduler والـ Queue

المشروع يحتوي مهام كل دقيقة للمزادات، المواعيد النهائية، التنبيهات ومراجعة المحتوى. أضف Cron Job من cPanel:

```cron
* * * * * cd /home/xsx1xjs32uxx/public_html/soom && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

إذا لم توفر الاستضافة Process دائمًا للـ queue، أضف Cron منفصلًا يشغّل الوظائف المنتظرة ويخرج عند فراغ الطابور:

```cron
* * * * * cd /home/xsx1xjs32uxx/public_html/soom && /opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --tries=3 --timeout=120 >> /dev/null 2>&1
```

إذا توفر Supervisor أو خدمة Process دائمة، استخدم `queue:work` دائم بدل Cron، ونفّذ بعد كل نشر:

```bash
php artisan queue:restart
```

## 8. إنهاء النشر وبناء الكاش

```bash
php artisan scramble:clear
php artisan scramble:cache
php artisan optimize
php artisan queue:restart
```

تحقق من حالة التطبيق:

```bash
php artisan about
php artisan schedule:list
```

يجب أن تكون البيئة `production` وDebug مغلقًا.

## 9. اختبارات Smoke Test

```bash
curl -i https://soomnow.com/
curl -i https://api-jo.soomnow.com/api/status
curl -i https://api-jo.soomnow.com/api/market-config
curl -i https://api-eg.soomnow.com/api/market-config
curl -i https://api-admin.soomnow.com/api/markets
curl -I https://soomnow.com/docs/api
```

المتوقع:

- الرابط الأساسي يرجع `200` و`{"status":"ok"}`.
- Status وMarket Config يرجعان `200`.
- Admin Markets يعرض الأسواق المفعّلة.
- التوثيق بدون بيانات دخول يرجع `401`.

## 10. خطوات كل تحديث لاحق

```bash
cd /home/xsx1xjs32uxx/public_html/soom
php artisan down
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan market:validate
php artisan scramble:cache
php artisan optimize
php artisan queue:restart
php artisan up
```

بعدها نفّذ Smoke Tests السابقة وراجع أحدث الأخطاء:

```bash
tail -n 100 storage/logs/laravel.log
tail -n 100 public/error_log
```

## 11. تشخيص سريع

| المشكلة | الفحص أو الإجراء |
|---|---|
| `400 Bad Request` | راجع الـ trusted hosts و`SOOM_ROOT_DOMAIN` وامسح config cache |
| `500` بدون Laravel log | راجع `public/error_log` لأنه يحتوي أخطاء PHP المبكرة |
| `Target class [config] does not exist` | لا تستدعِ `config()` مباشرة أثناء بناء middleware؛ استخدم callback مؤجلًا |
| Pusher key يساوي `null` أثناء Composer | اضبط مفاتيح Pusher أو استخدم `BROADCAST_CONNECTION=log` |
| `View path not found` | أنشئ `storage/framework/views` وبقية مجلدات `storage` |
| جدول موجود وmigration يحاول إنشاءه | تأكد أن السيرفر سحب آخر `main`؛ الترحيلات الحالية تعالج الحالات القديمة |
| API يعمل لكن الوظائف المؤجلة لا تعمل | راجع Cron الخاص بـ scheduler والـ queue |
| تغيير `.env` لا يظهر | نفّذ `php artisan optimize:clear` ثم `php artisan optimize` |

## 12. قواعد أمان وتشغيل

- اجعل `APP_DEBUG=false` دائمًا على Production.
- استخدم كلمات سر مختلفة لقاعدة البيانات، التوثيق، Pusher ومزودي الدفع.
- لا تعرض جذر مشروع Laravel للويب؛ اعرض `public` فقط.
- راقب `failed_jobs` و`storage/logs/laravel.log` بانتظام.
- اختبر migrations على نسخة حديثة من قاعدة Production قبل موعد الإطلاق.
- احتفظ بنسخة قاعدة البيانات السابقة حتى انتهاء فحوصات النشر.
