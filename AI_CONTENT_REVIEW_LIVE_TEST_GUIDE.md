# دليل تشغيل واختبار المراجعة الآلية بمفتاح Anthropic حقيقي

> ملحق تشغيلي لـ `AI_CONTENT_REVIEW_RUNBOOK.md`. الرَنبوك يشرح تشغيل النظام في الإنتاج،
> وهذا الملف يشرح **كيف تجرّب مكالمة Anthropic حقيقية أول مرة** بأقل تكلفة وبدون أي خطر
> على قاعدة `soom_pr`.

## الوضع الحالي

نظام `content_review` مكتمل (Batches 1–9) لكنه **مُطفأ بالكامل**: ملف `.env` الحالي لا يحتوي
أي مفتاح `CONTENT_REVIEW_*` ولا `ANTHROPIC_API_KEY`، فتُطبَّق افتراضات
`config/content_review.php`:

```
enabled  = false
provider = fake
mode     = manual
```

النتيجة أن كل الاختبارات تمر على `FakeContentReviewProvider`، ومسار Anthropic الحقيقي
لم يُجرَّب ولا مرة. الهدف من هذا الدليل: مكالمة حقيقية واحدة على الأقل ضد
`api.anthropic.com` للتأكد أن شكل الطلب (‏`tool_choice` + `input_schema` + الصور base64)
وشكل الرد (‏`tool_use` block) صحيحان.

---

## 1. كيف تعمل المراجعة الآلية فعليًا

```
البائع يرسل مزاد للمراجعة
  └─ SubmitAuctionForReviewAction        app/Services/Auction/Actions/
       └─ RequestContentReviewAction     → ينشئ صف content_reviews + يرسل Job
            └─ ProcessContentReviewJob   → على طابور CONTENT_REVIEW_QUEUE = "content-review"
                 └─ ProcessContentReviewAction
                      ├─ ReviewModeResolver::resolve()   ← manual | shadow | ai_assisted | ai_automatic
                      ├─ حرّاس: ProviderCallGuard + circuit breaker + budget + concurrency
                      ├─ ContentReviewProviderFactory::make('anthropic')
                      │    └─ AnthropicContentReviewProvider::analyze()
                      │         POST {base_url}/v1/messages
                      │         headers : x-api-key, anthropic-version: 2023-06-01
                      │         body    : model, max_tokens, system = policyInstructions,
                      │                   tools = [record_content_review],
                      │                   tool_choice = {type: tool},
                      │                   messages = [ صور base64 + FIELD blocks ]
                      │         يقرأ الرد من أول block نوعه tool_use واسمه record_content_review
                      ├─ StructuredReviewResultValidator
                      └─ ContentReviewDecisionEngine → approve | reject | escalate
```

الجدولة الدورية في `routes/console.php`:

| الأمر | التكرار |
|---|---|
| `content-review:dispatch-pending` | كل دقيقة |
| `content-review:sweep-alerts` | كل ٥ دقائق |

### نقطتان حاسمتان قبل أي اختبار

**١. صف الإعدادات المنشور يتغلّب على `.env`.**
`ReviewModeResolver::effectiveSettings()` يدمج `content_review_settings.settings` فوق
`config('content_review.defaults')`، ودالة `providerName()` في
`ProcessContentReviewAction.php:558` و `TestContentReviewProviderAction.php:92`
تقرأ `$settings['provider']` أولًا. و `ContentReviewSeeder` ينشر صفًا فيه
`provider: fake, enabled: false, mode: manual`.

⇒ **وضع `CONTENT_REVIEW_PROVIDER=anthropic` في `.env` وحده لن يبدّل المزوّد.**
لا بد من نشر نسخة إعدادات جديدة عبر `POST /api/admin/content-review/settings`.
هذا أكثر موضع يقع فيه الناس.

**٢. المفتاح الرئيسي في `.env` شرط لازم.**
`ReviewModeResolver::resolve()` يرجع `Manual` فورًا إذا كان
`config('content_review.enabled') !== true`، مهما كان المنشور.
فلا بد من `CONTENT_REVIEW_ENABLED=true` ثم `php artisan config:clear`.

---

## 2. من أين تأتي بمفتاح الـ API

**اشتراك Claude (‏Pro / Max) على claude.ai لا يعطي وصولًا للـ API، ولا يمكن استخراج مفتاح
API منه.** هما منتجان منفصلان بفوترة منفصلة: الاشتراك يغطي واجهة الدردشة و Claude Code فقط،
بينما `POST /v1/messages` — وهو ما يستدعيه `AnthropicContentReviewProvider` — يحتاج حساب
Anthropic Console برصيد مستقل.

### الطريقة المدعومة الوحيدة لهذا الكود

1. افتح <https://console.anthropic.com> وسجّل الدخول.
2. **Billing** ← أضف رصيدًا. الحد الأدنى ٥ دولار يكفي مئات المراجعات.
3. **API keys** ← *Create Key* ← انسخ المفتاح `sk-ant-api03-...` (يظهر مرة واحدة فقط).
4. ضعه في ملف البيئة كـ `ANTHROPIC_API_KEY=` — الكود يقرأه من
   `config('services.anthropic.api_key')` في `config/services.php:57`.

### التكلفة المتوقعة

| البند | التقدير |
|---|---|
| سعر `claude-sonnet-5` | ‏$3 لكل مليون token إدخال، $15 إخراج (سعر تعريفي $2/$10 حتى 2026-08-31) |
| مراجعة واحدة بأربع صور | ‏4–6 آلاف token إدخال + ~500 إخراج ≈ **‎$0.01 – $0.02** |
| `POST /provider/test` | ‏256 token إخراج كحد أقصى ≈ **أقل من سنت** |
| السقف اليومي المطبَّق داخل الكود | `CONTENT_REVIEW_DAILY_BUDGET_MICROS=5000000` = ‏$5 |

### لماذا لا يصلح توكن الاشتراك

`ant auth login` و `claude setup-token` يُنتجان توكن OAuth يستعمله Claude Code و Agent SDK،
ويُرسل كـ `Authorization: Bearer <token>` مع `anthropic-beta: oauth-2025-04-20` — وليس
`x-api-key`. و `AnthropicContentReviewProvider` يرسل `x-api-key` فقط، فالتوكن سيفشل بـ 401
ويُترجم إلى `ProviderAuthFailed`. تشغيله يتطلب تعديل الـ provider، وهو خارج الاستعمال
المقصود لتوكن الاشتراك ولا يصلح لتشغيل منتج.

**التوصية: مفتاح Console برصيد ٥ دولار.**

---

## 3. خطوات الاختبار

> ⚠️ **تحذير قاعدة البيانات:** `.env` يشير إلى `DB_DATABASE=soom_pr`.
> **لا تشغّل أي migration عليها.** الاختبار الحي يتم على قاعدة رمل منفصلة.

### المرحلة أ — بدون مفتاح: تحقق من الأنابيب (مجانية) ✅ منفَّذة

```bash
composer test:content-review
php artisan test --filter=ProviderContractTest
```

`phpunit.xml` يفرض sqlite `:memory:` و `CONTENT_REVIEW_PROVIDER=fake`، فلا مكالمات شبكة
ولا مساس بـ `soom_pr`.

النتيجة عند آخر تشغيل: **425 اختبار ناجح، 1871 assertion، صفر فشل.**
هذا يثبت المنطق كله ما عدا شكل طلب/رد Anthropic الفعلي على الشبكة.

### المرحلة ب — أول مكالمة حقيقية: probe الاتصال

**١. جهّز قاعدة رمل ونسخة بيئة مستقلة:**

```bash
mysql -u root -e "CREATE DATABASE soom_ai_sandbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cp .env .env.sandbox
```

في `.env.sandbox` عدّل هذه المفاتيح فقط:

```dotenv
APP_ENV=sandbox
DB_DATABASE=soom_ai_sandbox

CACHE_STORE=database          # موجود أصلًا — لازم لأن الـ breaker/budget/concurrency كلها cache ذرّية
QUEUE_CONNECTION=database     # موجود أصلًا

CONTENT_REVIEW_ENABLED=true
CONTENT_REVIEW_PROVIDER=anthropic
CONTENT_REVIEW_MODEL=claude-sonnet-5
CONTENT_REVIEW_DAILY_BUDGET_MICROS=1000000     # سقف $1 أثناء التجربة

ANTHROPIC_API_KEY=sk-ant-api03-...
```

> `.env.sandbox` محجوب في `.gitignore` — لا يُرفع للريبو.

```bash
APP_ENV=sandbox php artisan migrate --seed
APP_ENV=sandbox php artisan config:clear
```

**٢. انشر نسخة إعدادات تُبدّل المزوّد فعليًا** — خطوة **إلزامية**، راجع §1:

```http
POST /api/admin/content-review/settings        (auth:sanctum + role:admin)
```

```json
{
  "scope": "auction",
  "settings": {
    "enabled": true,
    "mode": "shadow",
    "provider": "anthropic",
    "model": "claude-sonnet-5",
    "timeout_seconds": 45,
    "max_attempts": 3,
    "backoff_seconds": [60, 300, 900],
    "max_concurrent": 2,
    "max_output_tokens": 2000,
    "daily_budget_micros": 1000000,
    "monthly_budget_micros": 5000000,
    "analyze_images": true,
    "circuit_breaker": { "failure_threshold": 5, "window_seconds": 300, "open_seconds": 600 },
    "automation": { "allowed_category_ids": [], "max_starting_amount_minor": 0, "require_images": true }
  }
}
```

قواعد التحقق في `app/Http/Requests/ContentReview/PublishContentReviewSettingsRequest.php`:
‏`daily ≤ monthly`، و `provider` من `ContentReviewProviderFactory::available()`،
و `model` **من كتالوج المزوّد المختار نفسه** — أي
`config('content_review.providers.<provider>.models')` وليس قائمة عامة. لذلك
‏`provider: "openrouter"` مع `model: "claude-sonnet-5"` يُرفض بـ 422 ورسالة
`model_not_available_for_provider`.

`mode: "shadow"` يعني: يحلّل ويسجّل ولا يطبّق أي قرار على المزاد.

**٣. اضغط زر اختبار الاتصال:**

```http
POST /api/admin/content-review/provider/test
```

محدود بـ `throttle:content-review-provider-test` — ٣ في الدقيقة، ٢٠ في الساعة.

الرد المتوقع:

```json
{ "ok": true, "provider": "anthropic", "model": "claude-sonnet-5", "latency_ms": 1420, "error_code": null }
```

جدول تشخيص الفشل (`ContentReviewErrorCode`):

| `error_code` | السبب |
|---|---|
| `provider_auth_failed` | المفتاح فارغ أو 401/403 — تأكد من `config:clear` ومن الرصيد |
| `provider_timeout` | ‏408 أو انقطاع اتصال |
| `provider_rate_limited` | ‏429 |
| `invalid_structured_output` | الرد لا يحوي المخرج المتفق عليه: ‏`tool_use` باسم `record_content_review` عند Anthropic، أو `tool_calls`/`content` غير قابل للتحليل عند OpenRouter، أو `functionCall`/`parts[].text` عند Gemini. وعند Gemini أيضًا ‏`INVALID_ARGUMENT` الذي **تذكر رسالته المخطط أو صيغة الرد** وحده — أمّا `INVALID_ARGUMENT` لسبب آخر فيظهر كـ`provider_unavailable` |
| `provider_unavailable` | أي شيء آخر، أو اسم مزوّد غير معروف، أو رد 200 يحمل `error` من OpenRouter |

### بديل أرخص — التشغيل الأول عبر OpenRouter

النظام صار **provider-agnostic**: نفس المسار بالكامل، ويتغيّر السائق فقط. وبما أن
‏OpenRouter يوفّر نماذج مجانية، فهذه أرخص طريقة لأول مكالمة حقيقية — بلا رصيد في
‏Anthropic Console.

```dotenv
CONTENT_REVIEW_ENABLED=true
CONTENT_REVIEW_PROVIDER=openrouter

OPENROUTER_API_KEY=sk-or-v1-...
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_SITE_URL=https://soom.example        # اختياري — للتصنيف في لوحة OpenRouter
OPENROUTER_APP_NAME=Soom                        # اختياري
OPENROUTER_CONTENT_REVIEW_MODEL=google/gemini-2.5-flash
```

ثم انشر نسخة إعدادات بـ `"provider": "openrouter"` و`"model"` = نفس الslug —
تذكّر أن صف الإعدادات المنشور **يغلب** `.env` (‏§1).

> **لا تستخدم موزّعًا (‏router) مثل `openrouter/auto`.** جُرّب فعليًا، وكانت النتيجة أن
> كل نداء يقع على نموذج مختلف: مرة على مصنِّف سلامة لا يعرف سوى `User Safety: safe`،
> ومرة على نموذج reasoning يستهلك سقف الإخراج كله في التفكير فينقطع قبل إنهاء الـJSON.
> ثبّت slug واحدًا صفحته على <https://openrouter.ai/models> تذكر دعم *Structured Outputs*.
> ‏`google/gemini-2.5-flash` يكلّف أقل من نصف سنت للمراجعة الكاملة.

ثلاث ملاحظات تخصّ OpenRouter تحديدًا:

1. **شكل المخرج المهيكل يُحدَّد لكل نموذج** في `config/content_review.php` عبر المفتاح
   `structured`: `tool` (استدعاء أداة إلزامي، مثل Anthropic) أو `json_schema`
   (‏`response_format` صارم) أو `json_object` (المخطط داخل الـsystem prompt).
   إن رجع الرد خارج العقد فالنتيجة `invalid_structured_output` والمراجعة تُصعَّد لبشري —
   يفشل بأمان، لكن راقب `invalid_output_percent` في `/metrics`.
   وإن انقطع الرد لبلوغ سقف الإخراج فالكود `provider_output_truncated` بدلًا منه، وهو
   إشارة مختلفة تمامًا: ارفع `max_output_tokens`. انتبه أن السقف على OpenRouter يشمل
   **توكنات التفكير**، فنموذج reasoning قد يبتلع 2000 توكن قبل أن يكتب حرفًا.
2. **التكلفة تُقرأ من المزوّد نفسه**: نرسل `usage: {include: true}` ونحوّل
   ‏`usage.cost` إلى micros صحيحة؛ وإن غابت نرجع لجدول الكتالوج. النموذج المجاني
   يُسعّر بـ `0` وليس `null` — أي `totals_complete` يبقى `true`.
3. **الميزانية لا تحجب نموذجًا مجانيًا** لأن تقديره صفر. هذا مقصود، لكن انتبه له عند
   قراءة `/health`.

> تحقّق من الـslug مقابل <https://openrouter.ai/models> قبل النشر — الslug الخاطئ يظهر
> كـ `provider_unavailable` وليس كخطأ إعدادات.

### بديل مجاني — Google Gemini مباشرة

مزوّد مستقل تمامًا (`gemini`) يتكلّم مع Google Gemini API مباشرةً بلا وسيط، ويستفيد من الـFree
Tier في Google AI Studio. المفتاح من <https://aistudio.google.com/apikey>، وحدود الـFree Tier
(RPM / RPD) معروضة في <https://aistudio.google.com/rate-limit>.

```dotenv
CONTENT_REVIEW_ENABLED=true
CONTENT_REVIEW_PROVIDER=gemini

GEMINI_API_KEY=AIza...
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_CONTENT_REVIEW_MODEL=gemini-3.6-flash
GEMINI_THINKING_LEVEL=minimal
```

ثم انشر نسخة إعدادات بـ`"provider": "gemini"` و`"model": "gemini-3.6-flash"` — تذكّر أن صف
الإعدادات المنشور **يغلب** `.env` (‏§1).

الموديلات المكتلجة، وكلها `vision: true` و`structured: json_schema`:

| الموديل | input / output (micros لكل 1M) | ملاحظة |
|---|---|---|
| `gemini-3.6-flash` | 750_000 / 3_750_000 | **الافتراضي.** سعر تمهيدي حتى 2026-12-31، ثم 1_500_000 / 7_500_000 |
| `gemini-2.5-flash` | 300_000 / 2_500_000 | لم يعد يُتاح للحسابات الجديدة — مُبقى للحسابات القديمة |
| `gemini-2.5-flash-lite` | 100_000 / 400_000 | نفس التحفّظ |

> **‏`gemini-2.5-flash` لم يعد متاحًا للحسابات الجديدة.** الاختبار الحيّ أكّد ذلك: نفس المفتاح
> الذي يفشل مع 2.5 يعمل مع `gemini-3.6-flash`. الموديلان 2.5 باقيان في الكتالوج للحسابات التي
> ما تزال تملكهما، فإن كان حسابك جديدًا فسيظهر 2.5 كـ`provider_unavailable` (‏`NOT_FOUND`).

أربع ملاحظات تخصّ Gemini تحديدًا:

1. **ضبط التفكير يختلف باختلاف الجيل، والسائق يختار بنفسه.** كل موديلات Gemini تفكّر
   افتراضيًا، وتوكنات التفكير تُقتطع من `max_output_tokens` نفسه، فبلا ضبط قد يبتلع النموذج
   السقف كله ويعود `provider_output_truncated` قبل أن يكتب حرفًا. المفتاحان **متنافيان**:
   إرسالهما معًا يعيد 400. لذلك يقرأ السائق جيل الموديل من الـid:

   | الموديل | الحقل المُرسَل | الافتراضي هنا |
   |---|---|---|
   | ‏`gemini-3.x` فأعلى | `generationConfig.thinkingConfig.thinkingLevel` | `minimal` |
   | ‏`gemini-2.5-*` | `generationConfig.thinkingConfig.thinkingBudget` | `0` |

   ‏`minimal` وليس «إيقاف»: الجيل 3 لا يوفّر طريقة مدعومة لتعطيل التفكير، و`minimal` هو أقل
   قدر من الـreasoning مع بقاء الـStructured Output ثابتًا. القيم المقبولة:
   ‏`minimal` / `low` / `medium` / `high`؛ وأي قيمة أخرى تعني «لا ترسل `thinkingConfig`» فيقرّر
   النموذج بنفسه. و`GEMINI_THINKING_BUDGET` يبقى مؤثّرًا على موديلات 2.5 وحدها.
2. **التكلفة تُحسب من الكتالوج لا من المزوّد**، لأن Gemini لا يعيد تكلفة في الرد. وتوكنات
   التفكير (`usageMetadata.thoughtsTokenCount`) تُحسب ضمن الإخراج حتى تبقى المحاسبة صحيحة إن
   أُعيد تفعيل التفكير. الأسعار المكتلجة هي أسعار الـpaid tier، فبينما أنت على الـFree Tier
   سيعرض `/metrics` تكلفة تقديرية لا يفوترها Google — وهو مقصود حتى يظل الـbudget guard عاملًا.
   تنبيه تقويمي: سعر `gemini-3.6-flash` المكتلج هو السعر التمهيدي الساري حتى **2026-12-31**؛
   بعدها يصبح الضعف (1_500_000 / 7_500_000)، فحدّث الكتالوج وارفع `CONTENT_REVIEW_PRICING_VERSION` وقتها.
3. **المخطّط يُرسل عبر الحقل الـJSON-Schema-native مع تطبيع strict.** السائق يستعمل
   ‏`generationConfig.responseJsonSchema` (لا `responseSchema`)، لكن Gemini يدقق المخطّط بصرامة:
   مخطّط مغلق بـ`additionalProperties: false` مع خصائص خارج `required` يُرفض بـ
   ‏`INVALID_ARGUMENT` ورسالة عامّة `Request contains an invalid argument.` لذلك يمرّ المخطّط
   عبر `StrictJsonSchemaAdapter` نفسه المستعمل مع OpenRouter (كل الخصائص تصير required،
   والاختيارية تقبل `null`، وتُحذف `maxLength`/`maxItems`/`minimum`/`maximum`). مسار الأداة
   ‏(`parametersJsonSchema`) يبقى حرفيًا لأن لا شيء أثبت خلاف ذلك.
4. **الأخطاء تُصنَّف بـ`error.status` قبل كود HTTP.** أهمّ حالتين تحت 400:
   ‏`FAILED_PRECONDITION` = الـFree Tier غير متاح لبلدك/حسابك → يظهر `provider_auth_failed`؛
   و`INVALID_ARGUMENT` = طلب أو معامل غير صالح → `provider_unavailable`، إلا إن ذكرت رسالته
   المخطّط أو صيغة الرد فيكون `invalid_structured_output`.

| `error.status` | الكود الظاهر | المعنى |
|---|---|---|
| `INVALID_ARGUMENT` (المخطّط) | `invalid_structured_output` | Gemini رفض المخطّط — يُصعَّد لبشري ولا يُعاد المحاولة |
| `INVALID_ARGUMENT` (غير ذلك) | `provider_unavailable` | طلب مشوّه أو معامل غير مقبول لهذا الموديل |
| `FAILED_PRECONDITION` | `provider_auth_failed` | الـFree Tier غير متاح للبلد/الحساب، أو الفوترة مطلوبة |
| `UNAUTHENTICATED` / `PERMISSION_DENIED` | `provider_auth_failed` | مفتاح خاطئ أو غير مُصرَّح |
| `NOT_FOUND` | `provider_unavailable` | اسم موديل غير معروف |
| `RESOURCE_EXHAUSTED` | `provider_rate_limited` | تجاوز حصة الـFree Tier (RPM / RPD) |
| `DEADLINE_EXCEEDED` | `provider_timeout` | |
| `INTERNAL` / `UNAVAILABLE` | `provider_unavailable` | |


### المرحلة ج — مراجعة كاملة لمزاد حقيقي (shadow)

شغّل العامل على الطابور المخصّص:

```bash
APP_ENV=sandbox php artisan queue:work --queue=content-review --tries=3 --timeout=120
```

`--timeout=120` لازم أن يبقى فوق `CONTENT_REVIEW_TIMEOUT_SECONDS + 15`، وإلا قُتل العامل
أثناء المكالمة وتركت المراجعة ممسكة بـ lease حتى ينتهي.

ثم إمّا إرسال مزاد للمراجعة من الواجهة، أو مباشرةً:

```http
POST /api/admin/content-reviews/auction/{id}/run
GET  /api/admin/content-reviews/auction/{id}/current
```

أو دفعة صغيرة من محتوى قديم:

```bash
APP_ENV=sandbox php artisan content-review:backfill --limit=3            # dry run (الافتراضي)
APP_ENV=sandbox php artisan content-review:backfill --execute --limit=3  # يُنشئ shadow فقط
```

---

## 4. التحقق من النجاح

- `GET /api/admin/content-review/health`
  ← `enabled=true`، `mode=shadow`، `circuit.state=closed`،
  `budget.daily.remaining_micros` ينقص بعد كل مراجعة، `queue.last_job_processed_at` حديث.
- `GET /api/admin/content-review/metrics?range=today`
  ← `volume.completed` يزيد، `rates.invalid_output_percent = 0`،
  `rates.provider_failure_percent = 0`، `cost.totals_complete = true`.
- في جدول `content_reviews`: `provider='anthropic'`، و `model`، `input_tokens`،
  `output_tokens`، `cost_micros` غير فارغة، و `latency_ms` منطقي.
- `storage/logs/laravel.log`: سطر `Log::info` واحد لكل مراجعة مكتملة، ولا يحتوي المفتاح
  ولا الـ prompt ولا عنوان المزاد — مضمون بـ `ContentReviewLogContext` ومُختبَر في
  `LogRedactionTest`.
- بعد أي تعديل على الكود: `composer test:content-review`.

---

## 5. السلامة والتراجع

- كل شيء يبقى على `mode: shadow` — لا قرار آلي يُطبَّق على أي مزاد.
- الإيقاف الفوري: انشر نسخة إعدادات بـ `enabled: false`، أو ضع
  `CONTENT_REVIEW_ENABLED=false` ثم `php artisan config:clear`.
- `soom_pr` لا تُمَس: لا migration، ولا `.env` الأصلي يتغيّر — نستعمل `.env.sandbox`
  مع `APP_ENV=sandbox`.
- بعد الانتهاء: احذف قاعدة `soom_ai_sandbox`، واحذف `.env.sandbox` لأنه يحوي المفتاح،
  وتأكد أن `.env` الأصلي ما زال بلا `ANTHROPIC_API_KEY` حتى تقرر النشر الحقيقي.
- قبل أي انتقال إلى `ai_assisted` ثم `ai_automatic`: اتّبع مراحل §8 ومقاييس القبول في §9
  من `AI_CONTENT_REVIEW_RUNBOOK.md` — ‏≥200 مراجعة مكتملة، توافق ≥90% مع البشر،
  وفشل مزوّد أقل من 2%.
