# تشغيل وتجربة سوم بسوقين (الأردن ومصر)

هذا الملف يشرح إزاي تشغّل المشروع محليًا بسوقين، إزاي تجرّبه، وإزاي ترحّل قاعدة إنتاج.

---

## 1. المتغيرات المطلوبة

### الباك إند — `soom/.env`

```dotenv
SOOM_ROOT_DOMAIN=soom.test
SOOM_ADMIN_API_HOST=api-admin.soom.test
SOOM_TRUSTED_PROXY_IPS=

SOOM_LEGACY_API_HOST=
SOOM_LEGACY_MARKET_CODE=JO
SOOM_LEGACY_SUNSET=

SOOM_DEFAULT_MARKET_CODE=JO
SOOM_DEV_MARKET_CODE=JO
```

- `SOOM_ROOT_DOMAIN` هو الأساس اللي الـ migration بتشتق منه هوستات الأسواق وبتخزّنها في جدول `markets`. **غيّره قبل الترحيل، مش بعده** — تغييره بعدين مابيعدّلش الصفوف المخزّنة.
- `SOOM_LEGACY_API_HOST` سيبه فاضي لو مفيش عملاء موبايل قدام. لو فيه، حطّ فيه الهوست القديم بالظبط وحطّ تاريخ في `SOOM_LEGACY_SUNSET`.
- `SOOM_DEFAULT_MARKET_CODE` هو السوق اللي بتروح له العمليات اللي مالهاش سوق صريح — أهمها الـ seeders، لأن أمر artisan مفيهوش طلب ولا جوب يفتح سياق سوق.
- `SOOM_DEV_MARKET_CODE` بيحدد السوق اللي يتفتح لما تضرب على `localhost` مباشرة في التطوير. خلّيه `EG` لو عايز تجرّب مصر من غير ما تظبط هوستات. لو سيبته فاضي بياخد قيمة `SOOM_DEFAULT_MARKET_CODE`.

### الويب — `soom-web/.env`

**للتطوير المحلي سيب `SOOM_ROOT_DOMAIN` فاضي:**

```dotenv
SOOM_ROOT_DOMAIN=
SOOM_API_BASE_URL=http://localhost:8000/api/
SOOM_API_TIMEOUT_MS=8000
SESSION_COOKIE_SECURE=false
NEXT_PUBLIC_SITE_URL=http://localhost:3001
NEXT_PUBLIC_ENVIRONMENT=development
```

`SOOM_ROOT_DOMAIN` بيعمل تلات حاجات مع بعض: بيحدد السوق من هوست الطلب، **وبيخلي الويب ينادي على `https://api-<code>.<root>/api/`**، وبيبقى دومين كوكي الجلسة علشان الدخول يعدّي بين الأردن ومصر.

التلاتة دول مترابطين، فلو ضبطته محليًا الويب هيحاول ينادي على `https://api-jo.soom.test/api/` — وده مفيش حاجة بترد عليه على جهازك (مفيش TLS ولا listener على 443). عشان كده **سيبه فاضي محليًا**؛ ساعتها الويب بينادي على `SOOM_API_BASE_URL` مباشرة، والباك إند هو اللي بيقرر السوق (شوف تحت).

اضبطه في الاستيجنج والإنتاج بس، لما يكون فيه DNS و TLS للسَب-دومينات فعلًا.

### الداشبورد — `soom-dashboard/.env`

```dotenv
NEXT_PUBLIC_ADMIN_API_URL=http://localhost:8000/api/
```

محليًا `ResolveMarketContext` بيسمح لـ `localhost` و`127.0.0.1` بالوصول لمسارات `api/admin/*`، فالداشبورد بيشتغل على طول من غير هوست أدمن منفصل. في الإنتاج خلّيه `https://api-admin.<root>/api/`.

---

## 2. التشغيل المحلي

```bash
cd soom            && php artisan serve --port=8000
cd soom-web        && npm run dev -- --port 3001
cd soom-dashboard  && npm run dev -- --port 3000
```

| | العنوان |
|---|---|
| الويب | `http://localhost:3001` |
| الداشبورد | `http://localhost:3000` |
| الـ API | `http://localhost:8000/api` |

**مش `jo.soom.test:3001`.** السَب-دومينات دي مالهاش معنى محليًا لأن الويب ساعتها هيحاول ينادي على `https://api-jo.soom.test` اللي مش موجود على جهازك.

### التبديل بين السوقين محليًا

محليًا الباك إند هو اللي بيقرر السوق، مش هوست الويب. غيّر السطر ده في `soom/.env`:

```dotenv
SOOM_DEV_MARKET_CODE=EG
```

وأعد تشغيل `php artisan serve`. الموقع كله — الفئات، العملة، المحافظات، التوقيت، مفتاح التليفون — هيتحوّل لمصر، لأن الويب بيسأل الـ API عن السوق الحالي في كل طلب. رجّعه `JO` للأردن.

منتقي الدولة في الهيدر هيبان وفيه السوقين، لكن روابطه بتروح على `https://jo.soom.test` و`https://eg.soom.test` — دي عناوين الأسواق الحقيقية ومش هتشتغل محليًا. التبديل المحلي بالمتغير اللي فوق.

### السَب-دومينات الحقيقية (استيجنج/إنتاج)

محتاجة DNS و TLS لـ `jo.<root>` و`eg.<root>` و`api-jo.<root>` و`api-eg.<root>` و`api-admin.<root>`، وبعدها تضبط `SOOM_ROOT_DOMAIN` في الويب والباك إند. ساعتها الهوست هو اللي بيختار السوق، وكوكي الجلسة بتتشارك على الدومين الأب فالدخول بيعدّي بين السوقين.

---

## 3. تجهيز مصر

### على قاعدة فيها بياناتك بالفعل (الحالة الطبيعية)

```bash
cd soom
php artisan migrate
php artisan market:validate
php artisan market:provision EG
```

**مش محتاج `db:seed` هنا.** الـ backfill جوه الترحيل خلاص أسند كل بياناتك القديمة للأردن، وفيها الفئات ونسخة الشروط ونسخة الإعدادات وطرق الدفع. `db:seed` مخصص لقاعدة فاضية، وتشغيله على قاعدة فيها بيانات مالوش لازمة.

`market:provision EG` بيحمّل محافظات ومدن مصر، وينسخ الفئات المرئية من الأردن، وينشئ نسخة الشروط ونسخة الإعدادات وطريقة الدفع المصرية، وبعدين **يتحقق إن كل ده موجود قبل ما يفعّل السوق**. لو حاجة ناقصة بيسمّيها ومابيفعّلش.

### على قاعدة فاضية تمامًا

```bash
php artisan migrate:fresh
php artisan db:seed
```

`db:seed` بينشئ خط الأساس (نسخة إعدادات، نسخة شروط، طريقة دفع، سياسات مراجعة المحتوى) في السوق الافتراضي — `SOOM_DEFAULT_MARKET_CODE`، والأردن افتراضيًا.

لكنه **مابينشئش فئات**. ومصر بتاخد فئاتها بالنسخ من الأردن، يعني `market:provision EG` هيرفض التفعيل على قاعدة فاضية ويقول:

> `no visible category is mapped to this market, and the source market JO has none to mirror; create the catalogue first`

فعلى قاعدة فاضية: اعمل شجرة الفئات الأول (من داشبورد الأدمن أو استوردها)، وبعدين شغّل `market:provision EG`.

للتأكد:

```bash
php artisan market:validate
php artisan tinker --execute="App\Models\Market::all(['code','is_active','api_host'])->each(fn(\$m) => print(\$m->code.' active='.\$m->is_active.' '.\$m->api_host.PHP_EOL));"
```

المفروض تشوف `JO active=1` و`EG active=1` و`AE active=0`.

---

## 4. تشغيل الاختبارات

### الباك إند

```bash
cd soom
php artisan test --filter=Market
php artisan test tests/Unit
php vendor/bin/phpunit -c phpunit.market-mysql.xml
php vendor/bin/pint --test
```

مجموعة MySQL محتاجة قاعدة `soom_market_testing`. لو مش موجودة:

```bash
php artisan tinker --execute="DB::statement('CREATE DATABASE IF NOT EXISTS soom_market_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"
```

دي المجموعة الوحيدة اللي بتثبت قيود المفاتيح المركّبة ونافذة الترحيل قبل التعاقد؛ الاختبارات دي بتتخطى على SQLite.

**السويت القديم مش أخضر** ومكانش ضمن نطاق الشغل ده: فيه فيكستشرز قديمة بتخترع دول بعملة JOD، وفيه اختبارات بتبعت IDs رقمية لإندبوينتس بقت ULID بس.

### الويب

```bash
cd soom-web
npm run typecheck
npm run lint
npm run test
npm run docs:check
```

### الداشبورد

```bash
cd soom-dashboard
npx tsc --noEmit
npm run lint
npm run test
```

---

## 5. ترحيل قاعدة فيها بيانات

> `2026_09_17_130000_contract_market_ownership` عندها `down()` فاضية عمدًا. **مفيش rollback** — الرجوع بالنسخة الاحتياطية بس.

1. **خُد نسخة احتياطية.**
2. اظبط `SOOM_ROOT_DOMAIN` و`SOOM_ADMIN_API_HOST` و`SOOM_TRUSTED_PROXY_IPS` وكمان `SOOM_LEGACY_API_HOST` لو فيه عملاء قدام.
3. **جرّب على نسخة من البيانات الحقيقية، مش على قاعدة فاضية.** ده اللي بيكشف المشاكل الحقيقية:

```bash
mysqldump --single-transaction --routines --triggers soom_pr > soom_pr.sql
mysql -e "CREATE DATABASE soom_pr_clone_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql soom_pr_clone_testing < soom_pr.sql
DB_DATABASE=soom_pr_clone_testing php artisan migrate
DB_DATABASE=soom_pr_clone_testing php artisan market:validate
DB_DATABASE=soom_pr_clone_testing php artisan market:provision EG
```

4. `php artisan migrate --pretend` بيطبع الـ DDL وبيتخطى خطوات البيانات، فهو آمن لكنه ناقص — الدراي رن على نسخة هو الوحيد اللي بيشغّل الـ backfill فعلًا.
5. لما النسخة تعدّي نضيف، كرّر نفس الخطوات على الإنتاج بعد الـ backup.

### اللي ممكن يوقف الترحيل

| الرسالة | المعنى | التصرف |
|---|---|---|
| `... is shared by multiple markets and must be split explicitly` | نسخة شروط أو طريقة دفع واحدة بيستخدمها سوقين | اعمل نسخة منفصلة لكل سوق قبل الترحيل |
| `Ad ... currency does not match its market` | إعلان عملته مش عملة سوق دولته | صحّح الصف |
| `... references ... without a market` | صف تابع لكيان موجود بس الكيان نفسه مالوش سوق | تناقض حقيقي، لازم يتصحح يدوي |
| `Market ... is not ready to activate` | `market:provision` لقى متطلب ناقص | الرسالة بتسمّي الناقص |

الصفوف اليتيمة (اللي كيانها اتمسح) بتتسند للسوق القديم وبتتعدّ في اللوج بدل ما توقف الترحيل.

---

## 6. سيناريو تجربة يدوي

### الأردن (`SOOM_DEV_MARKET_CODE=JO`)
1. افتح `http://localhost:3001` — لازم تشوف منتقي الدولة في الهيدر، والأردن عليه علامة.
2. اعمل إعلان: الفئة والمحافظة والمدينة أردنية، والسعر بالدينار (3 خانات عشرية مقبولة).
3. اعمل مزاد: تأمين البائع 10.000 د.أ، أقل زيادة 1.000 د.أ.

### مصر (`SOOM_DEV_MARKET_CODE=EG` وأعد تشغيل السيرفر)
4. افتح `http://localhost:3001` تاني — الفئات والعملة والتوقيت ومفتاح التليفون كلها مصرية دلوقتي.
5. اعمل إعلان مصري: المحافظات المصرية ظاهرة، والسعر بالجنيه (خانتين عشريتين، والتالتة مرفوضة).
6. اعمل مزاد مصري: تأمين البائع **700.00 ج.م**، تأمين المزايد **350.00**، أقل زيادة **70.00**، الرسوم 2.5%.
7. قدّم تأمين بتحويل بنكي يدوي، وراجعه من الداشبورد.
8. افتح رسالة قديمة وقارن التوقيت — في الشتاء لازم يفرق ساعة عن الأردن.

### الداشبورد
9. اختر «كل الأسواق» من الهيدر: ملخص أي مستخدم لازم يعرض صف لكل سوق بعملته، **من غير مجموع واحد مختلط**.
10. اختر «مصر»: كل الأرقام تتفلتر، وبيانات الحساب (وجهات التحويل، المحادثات) تفضل عالمية.
11. روح لإعدادات المزادات من غير ما تختار سوق: لازم تشوف رسالة «اختر سوقًا» مش خطأ عام.
12. جرّب تعمل بانر أو إعلان أو جمعية خيرية بعد اختيار سوق — لازم يتحفظ (ده كان بيرجّع 422 قبل الإصلاح).

### حواف (بوابة الهوست — تتجرب بـ curl محليًا)
```bash
curl -s -o /dev/null -w "%{http_code}\n" -H "Host: api-ae.soom.test"      http://localhost:8000/api/market-config   # 404، الإمارات غير مفعّلة
curl -s -o /dev/null -w "%{http_code}\n" -H "Host: api-unknown.soom.test" http://localhost:8000/api/market-config   # 404
curl -s -o /dev/null -w "%{http_code}\n" -H "Accept: application/json"    http://localhost:8000/api/admin/markets   # 401 قبل الدخول
```

### مشاركة الجلسة بين السوقين
دي محتاجة السَب-دومينات الحقيقية، فتتجرب على الاستيجنج مش محليًا: سجّل دخول على `jo.<root>`، اضغط «مصر»، لازم توصل `eg.<root>` وإنت لسه داخل. وبعدين اعمل خروج من مصر وارجع للأردن — لازم تكون خرجت من الاتنين.
