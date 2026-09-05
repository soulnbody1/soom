# اختبارات أداء Soom باستخدام k6

هذه الحزمة تقيس أداء واجهة الصفحة الرئيسية ومسار المزايدة الساخنة بأحمال قابلة للتكرار، وتفصل أخطاء النظام عن رفض قواعد العمل. شغّل الأوامر من جذر المشروع، وليس من داخل `load-tests`.

> اختبارات `load` و`stress` مخصصة لبيئة أداء معزولة. لا تشغّلها على Production أو على مزاد يستخدمه عملاء حقيقيون.

## المتطلبات

- k6 إصدار 2.1 أو أحدث (`k6 version`).
- API يعمل ويمكن الوصول إليه من جهاز مولد الحمل.
- لاختبار المزاد: مزاد `live` مخصص للاختبار ومستخدمون مسجلون فيه، وافقوا على الشروط، وتأميناتهم مؤهلة، وتظهر لهم `my_participation.can_bid=true`.
- يجب أن تتحمل مدة المزاد كامل زمن الاختبار، مع هامش للـpreflight والـgraceful stop.

## Profiles الافتراضية

| Profile | Home | Auction browse | Auction bid flows | Tokens المطلوبة |
|---|---:|---:|---:|---:|
| `smoke` | طلب واحد | طلب واحد | محاولة واحدة | 1 |
| `load` | 25 طلب/ث لمدة 3 دقائق | 10 طلبات/ث | 20 محاولة/دقيقة لمدة 3 دقائق | 2 على الأقل |
| `stress` | 25 ← 50 ← 100 طلب/ث | 10 ← 25 ← 50 طلب/ث | 30 ← 60 ← 100 محاولة/دقيقة | 4 على الأقل |

كل bid flow يجلب تفاصيل المزاد أولًا ثم يرسل أقل مزايدة صالحة من `minimum_next_bid`. عند سبق طلب آخر لنفس القيمة، يصنف الرد `bid_below_minimum` كتعارض تنافسي طبيعي وليس عطلًا.

## الإعداد مرة واحدة ثم التشغيل بأمر واحد

عدّل الملف `load-tests/config/settings.js`. هذا هو المصدر المركزي للإعدادات المحلية: ضع فيه `profile` و`baseUrl` ومعدلات الحمل والمدد والـthresholds، ثم شغّل مباشرة:

```powershell
k6 run .\load-tests\soom-home.k6.js
```

بعد بدء التشغيل راجع سطرَي `Config source` و`Env overrides` في التقرير. التشغيل النظيف يعرض `Config source: settings.js` و`Env overrides: none`. إذا ظهر اسم متغير، فقيمته تتغلب على القيمة الموجودة في `settings.js`.

مثال لاختيار Stress محلي داخل الملف:

```javascript
export const settings = {
  profile: 'stress',
  baseUrl: 'http://127.0.0.1:8000',
  // بقية الإعدادات...
};
```

لا تضع Tokens الحقيقية في `settings.js`. لاختبار المزاد ضع `auction.id` في الملف، وضع الـTokens في `load-tests/data/bidders.local.json` لأنه مستبعد من Git.

متغيرات Environment اختيارية فقط لتجاوز الإعداد في تشغيل مؤقت أو CI. إذا كنت قد عرّفتها سابقًا في نافذة PowerShell الحالية، أغلق النافذة أو امسحها مرة واحدة حتى تُستخدم قيم `settings.js`:

```powershell
Remove-Item Env:TEST_PROFILE, Env:BASE_URL -ErrorAction SilentlyContinue
```

للفحص بدون عرض أي قيمة حساسة، استخدم:

```powershell
$k6Names = @('TEST_PROFILE','BASE_URL','ALLOW_REMOTE_TARGET','CONFIRM_AUCTION_MUTATION','REQUEST_TIMEOUT','MIN_CHECKS_RATE','TEST_DURATION','RAMP_UP_DURATION','STAGE_DURATION','RAMP_DOWN_DURATION','HOME_ENDPOINT','HOME_AUTH_TOKEN','AUTH_TOKEN','HOME_TARGET_RPS','HOME_PREALLOCATED_VUS','HOME_MAX_FAILURE_RATE','HOME_P90_MS','HOME_P95_MS','HOME_P99_MS','AUCTION_ID','BIDDER_TOKENS_FILE','AUCTION_BROWSE_TARGET_RPS','AUCTION_BID_TARGET_RPM','AUCTION_BROWSE_PREALLOCATED_VUS','AUCTION_BID_PREALLOCATED_VUS','AUCTION_MAX_FAILURE_RATE','AUCTION_P95_MS','AUCTION_P99_MS','NO_COLOR','DISABLE_SUMMARY_FILE','SUMMARY_PATH','RUN_ID')
$set = $k6Names | Where-Object { Test-Path "Env:$_" }
if ($set) { "Set: $($set -join ', ')" } else { 'No k6 override variables are set.' }
```

## اختبار Home

بعد ضبط `settings.js`:

```powershell
k6 run .\load-tests\soom-home.k6.js
```

وتظل الـEnvironment Variables متاحة كتجاوز اختياري، مثل تشغيل مؤقت على بيئة أداء بعيدة:

```powershell
$env:TEST_PROFILE = 'load'
$env:BASE_URL = 'https://performance.example.test'
$env:ALLOW_REMOTE_TARGET = 'true'
k6 run load-tests/soom-home.k6.js
```

ينفذ `setup()` طلب preflight قبل الحمل؛ هذا يتحقق من العقد ويدفئ Cache المفتاح `home_ads_data`. لذلك يقيس التشغيل المعتاد warm-cache. لقياس cold-cache امسح المفتاح من البيئة خارج k6 قبل التشغيل وسجل النتيجة كتجربة مستقلة.

## تجهيز وتشغيل اختبار المزاد

انسخ ملف المثال ثم استبدل القيم بـSanctum tokens حقيقية. الملف المحلي مستبعد من Git تلقائيًا:

```powershell
Copy-Item load-tests/data/bidders.example.json load-tests/data/bidders.local.json
```

الصيغة:

```json
[
  { "name": "bidder-1", "token": "1|real-token" },
  { "name": "bidder-2", "token": "2|real-token" }
]
```

بعد وضع `auction.id` في `settings.js` وتجهيز ملف المزايدين:

```powershell
k6 run .\load-tests\auction-bidding.k6.js
```

للتوافق مع الأمر القديم، يقبل `smoke` متغير `AUTH_TOKEN` بدل الملف. لا يقبله `load` أو `stress` لأن Token واحد لا يمثل تنافسًا حقيقيًا وسيصطدم بحد المستخدم.

Stress بعيد، بعد التأكد أن البيئة والمزاد مخصصان للاختبار:

```powershell
$env:TEST_PROFILE = 'stress'
$env:BASE_URL = 'https://performance.example.test'
$env:ALLOW_REMOTE_TARGET = 'true'
$env:CONFIRM_AUCTION_MUTATION = 'true'
$env:AUCTION_ID = '01J...'
$env:BIDDER_TOKENS_FILE = './data/bidders.local.json'
k6 run load-tests/auction-bidding.k6.js
```

قبل بدء الحمل يفحص k6 كل Token ويتوقف إذا كان المزاد غير مباشر أو أحد المستخدمين غير مؤهل. الـTokens لا تظهر في التقرير أو رسائل الخطأ.

## قراءة تقرير Terminal

ينتهي كل تشغيل بتقرير إنجليزي ثابت الاتجاه وواضح في PowerShell وWindows Terminal، لتجنب مشاكل انعكاس العربية عند خلط RTL مع الأرقام ومؤشرات مثل `p95` و`ms`. التقرير يوضح:

- `PASS` أو `FAIL`، والـProfile والـTarget والحمل المطلوب.
- عدد الطلبات والـiterations والمعدل الفعلي وأعلى VUs و`dropped iterations`.
- `avg/p90/p95/p99/max` لكل endpoint بشكل مستقل.
- Home: عدد `200` ونسبة العقد الصحيح.
- Auction: المحاولات المقبولة، تنافس `422`, ردود `429`، والأخطاء غير المتوقعة.
- كل Threshold فاشلة مع القيمة الفعلية والحد المطلوب.

دلالات الخلاصة:

- `PASS`: النظام اجتاز الحمل والحدود المحددة.
- `INVALID LOAD`: ظهرت `429`؛ النتيجة تقيس Rate Limiter، وليست سعة مسار المزايدة.
- `GENERATOR SATURATED`: مولد الحمل لم يستطع المحافظة على arrival rate؛ زِد preallocated VUs أو استخدم جهازًا أقوى.
- `FAIL`: فشل عقد API أو latency أو reliability threshold موضح بالاسم والرقم.

يحفظ التقرير البيانات الخام تلقائيًا في `load-tests/results/<run-id>.json`، والمجلد مستبعد من Git. الخيارات:

```powershell
$env:RUN_ID = 'release-2026-09-05-baseline'
$env:SUMMARY_PATH = 'load-tests/results/custom-result.json'
$env:NO_COLOR = 'true'                 # مناسب للـCI
$env:DISABLE_SUMMARY_FILE = 'true'     # Terminal فقط
```

> عند نسخ أوامر PowerShell استخدم أسماء المتغيرات كما هي، مثل `$env:TEST_PROFILE`، بدون `\` قبل `_`. واكتب عنوان `BASE_URL` كنص URL عادي بدون صيغة روابط Markdown.

## الإعدادات القابلة للتعديل

الأفضل للتشغيل المحلي تعديل القيم المناظرة في `config/settings.js`. الأسماء التالية Overrides اختيارية للاستخدام المؤقت أو داخل CI، ولها أولوية على الملف:

إعدادات مشتركة:

| المتغير | الافتراضي | الوظيفة |
|---|---|---|
| `TEST_PROFILE` | `smoke` | `smoke`, `load`, أو `stress` |
| `BASE_URL` | `http://127.0.0.1:8000` | أصل عنوان الـAPI |
| `REQUEST_TIMEOUT` | `30s` | مهلة HTTP |
| `MIN_CHECKS_RATE` | `0.98` | أقل نسبة checks ناجحة |
| `TEST_DURATION` | `3m` | مدة Profile `load` |
| `RAMP_UP_DURATION` | `30s` | أول مرحلة في `stress` |
| `STAGE_DURATION` | `1m` | مدة كل مستوى رئيسي في `stress` |
| `RAMP_DOWN_DURATION` | `30s` | مرحلة النزول |

Home:

| المتغير | الافتراضي |
|---|---:|
| `HOME_ENDPOINT` | `/api/soom/home` |
| `HOME_TARGET_RPS` | 25 للـload، 100 للـstress |
| `HOME_PREALLOCATED_VUS` | 75 للـload، 250 للـstress |
| `HOME_MAX_FAILURE_RATE` | 0.02 |
| `HOME_P90_MS` / `HOME_P95_MS` / `HOME_P99_MS` | 1000 / 2000 / 4000 |

Auction:

| المتغير | الافتراضي |
|---|---:|
| `AUCTION_BROWSE_TARGET_RPS` | 10 للـload، 50 للـstress |
| `AUCTION_BID_TARGET_RPM` | 20 للـload، 100 للـstress |
| `AUCTION_BROWSE_PREALLOCATED_VUS` | 30 للـload، 150 للـstress |
| `AUCTION_BID_PREALLOCATED_VUS` | 5 للـload، 10 للـstress |
| `AUCTION_MAX_FAILURE_RATE` | 0.02 |
| `AUCTION_P95_MS` / `AUCTION_P99_MS` | 500 / 1000 |

رفع `AUCTION_BID_TARGET_RPM` يرفع تلقائيًا أقل عدد Tokens إلى `ceil(rate/30)` وبحد أدنى 2 للـload و4 للـstress، بما يتوافق مع حد المستخدم الحالي. الحد الافتراضي لكل IP هو 120 مزايدة/دقيقة؛ تجاوزه من مولد واحد ينتج `429` مقصودة من الحماية ولا يمثل انهيار التطبيق.

## منهج التشغيل الصحيح

1. شغّل `smoke` وتأكد من صحة البيانات والعقد.
2. شغّل `load` مرتين متتاليتين بنفس البيانات والبنية وقارن النتائج.
3. استخدم `stress` تحت المراقبة فقط، وأوقفه إذا ظهرت أخطاء متسارعة.
4. لا تقارن تشغيلين اختلف فيهما Profile أو البيانات أو حجم البنية التحتية كأنهما Baseline واحدًا.
5. احتفظ بملفات JSON المطلوبة للتحليل التاريخي خارج Git أو أرسل المقاييس إلى منصة المراقبة المستخدمة لديكم.
