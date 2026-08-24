# AI Content Review — Operations Runbook

Audience: whoever is on call for the Soom backend.
Scope: the `content_review` subsystem only. Nothing here changes auction behaviour.

**The feature ships off.** `CONTENT_REVIEW_ENABLED=false`, the seeded settings publish
`enabled = false` / `mode = manual`, and the seeded policy has `auto_reject_categories = []`.
With the switch off the platform behaves exactly as it did before the subsystem existed: no review
row, no job, no provider call, no extra query.

---

## 1. Environment

| Variable | Default | What it does |
|---|---|---|
| `CONTENT_REVIEW_ENABLED` | `false` | Master switch. `false` collapses the resolved mode to `manual` no matter what is published. |
| `CONTENT_REVIEW_DEFAULT_MODE` | `manual` | Fallback mode when no settings version is published. |
| `CONTENT_REVIEW_PROVIDER` | `fake` | `fake`, `anthropic` or `openrouter`. A published settings version overrides it. |
| `CONTENT_REVIEW_MODEL` | `claude-sonnet-5` | Must be a model the selected provider offers. A model the provider does not catalogue falls back to that provider's `default_model` rather than being sent verbatim. |
| `CONTENT_REVIEW_QUEUE` | `content-review` | The dedicated queue name. |
| `CONTENT_REVIEW_TIMEOUT_SECONDS` | `45` | Per provider call. |
| `CONTENT_REVIEW_MAX_ATTEMPTS` | `3` | Attempts per request, then the review fails and escalates. |
| `CONTENT_REVIEW_BACKOFF_SECONDS` | `60,300,900` | Retry backoff, jittered ±20%. |
| `CONTENT_REVIEW_MAX_CONCURRENT` | `5` | Provider calls in flight across all workers. |
| `CONTENT_REVIEW_DAILY_BUDGET_MICROS` | `5000000` | Daily cap. `1_000_000` micros = 1 USD. |
| `CONTENT_REVIEW_MONTHLY_BUDGET_MICROS` | `100000000` | Monthly cap. |
| `CONTENT_REVIEW_ALERT_QUEUE_DELAY_SECONDS` | `600` | Oldest queued age that raises `queue_delay_high`. |
| `CONTENT_REVIEW_ALERT_INVALID_OUTPUT_PERCENT` | `10` | Invalid-output rate that raises `invalid_output_spike`. |
| `CONTENT_REVIEW_ALERT_INVALID_OUTPUT_SAMPLE` | `50` | Minimum sample before that rate is trusted. |
| `CONTENT_REVIEW_ALERT_PERMANENT_FAILURES` | `5` | Permanent provider failures per hour that raise `provider_unavailable`. |
| `CONTENT_REVIEW_ALERT_ESCALATION_BACKLOG` | `50` | Undecided escalations that raise `escalation_backlog`. |
| `CONTENT_REVIEW_ALERT_REPEAT_SECONDS` | `21600` | How long a still-active alert stays quiet before it repeats. |
| `CONTENT_REVIEW_METRICS_CACHE_SECONDS` | `60` | Server-side cache for the metrics aggregate. |
| `CONTENT_REVIEW_METRICS_MAX_RANGE_DAYS` | `92` | Largest custom metrics range the API accepts. |
| `ANTHROPIC_API_KEY` | — | Read from `config('services.anthropic.api_key')`. **Never** committed, logged, or returned by any endpoint. |
| `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` | Provider base URL. |
| `ANTHROPIC_CONTENT_REVIEW_MODEL` | `claude-sonnet-5` | The anthropic driver's default model. |
| `OPENROUTER_API_KEY` | — | Read from `config('services.openrouter.api_key')`. Same secrecy rules as the Anthropic key. |
| `OPENROUTER_BASE_URL` | `https://openrouter.ai/api/v1` | Provider base URL. |
| `OPENROUTER_SITE_URL` | — | Optional `HTTP-Referer` attribution header. |
| `OPENROUTER_APP_NAME` | `Soom` | Optional `X-Title` attribution header. |
| `OPENROUTER_CONTENT_REVIEW_MODEL` | `google/gemini-2.5-flash` | The openrouter driver's default model. Must be a pinned slug, never a router. |

Two infrastructure requirements:

- **`CACHE_STORE=database` (or redis).** The circuit breaker, the budget guard, the concurrency
  limiter, the alert state machine, the worker heartbeat and the settings publish lock are all
  atomic cache operations that must be shared across processes. With `CACHE_STORE=array` each
  worker gets its own private copy and none of those guards work.
- **A real queue connection.** `QUEUE_CONNECTION=sync` runs the provider call inside the web
  request.

---

## 2. Workers

Two separate workers in production. They are separate on purpose: a slow or stalled provider must
never starve auction jobs (deadlines, outbox, refunds) of worker capacity.

```
php artisan queue:work --queue=default --tries=3 --timeout=120
php artisan queue:work --queue=content-review --tries=3 --timeout=120
```

The project has **no Docker, Compose, or Supervisor configuration in the repository** — process
supervision is whatever the host already uses for the existing `default` worker. Add the
content-review worker the same way, as a second process with the same restart policy. Nothing in
this batch added or changed a deployment file.

`--timeout=120` must stay above `CONTENT_REVIEW_TIMEOUT_SECONDS + 15`, which is the job's own
timeout. A worker timeout below the job timeout kills the process mid-call and leaves the review
holding a lease until it expires.

---

## 3. Scheduler

Registered in `routes/console.php`:

```
content-review:dispatch-pending   every minute,      withoutOverlapping
content-review:sweep-alerts       every five minutes, withoutOverlapping
```

`dispatch-pending` re-dispatches reviews that are `queued` past the requeue window and reclaims
reviews whose worker lease expired. It is a no-op while the kill switch is off.

`sweep-alerts` evaluates the six alert conditions and publishes an outbox event when one turns on
or clears. It writes no review data.

Two commands are **deliberately not scheduled** and are run by hand:

```
php artisan content-review:backfill      # dry run by default
php artisan content-review:reconcile     # dry run by default
```

---

## 4. Health checks

Three read-only surfaces. **Access is authentication, not permission**: there is one kind
of admin, and every authenticated admin sees every part of the content review surface —
readings, costs, technical detail, settings, policies and metrics alike. Sellers and
ordinary users reach none of it, and a guest is rejected.

| Surface | Use |
|---|---|
| `GET /api/admin/content-review/health` | Live provider, circuit, queue, budget. |
| `GET /api/admin/content-review/metrics` | Aggregates over `today` / `7d` / `30d` or `date_from`+`date_to`. |
| Dashboard → Auction settings → Metrics | The same numbers, rendered. |

### How to tell what is wrong

**The queue is behind.** `queue.oldest_queued_age_seconds` above
`CONTENT_REVIEW_ALERT_QUEUE_DELAY_SECONDS`, `queue.queued` climbing, or the `queue_delay_high`
alert active.

**No worker is consuming.** `queue.last_job_processed_at` is null or far in the past while
`queue.queued > 0`. Note carefully: this field is the last time a content-review job actually ran.
There is **no worker-online flag** anywhere in this system, and nothing on the dashboard claims a
worker is up — an empty queue is not evidence of a healthy worker, and a full queue with a recent
heartbeat means the worker is running but slow.

**The circuit is open.** `circuit.state = "open"`, `circuit.open_until` in the future. Every review
that arrives while it is open fails with `circuit_open` and escalates to a human.

**The budget is exhausted.** `budget.daily.remaining_micros` or `budget.monthly.remaining_micros`
at zero, or `budget.*.exhausted = true`. Reviews degrade to human review; nothing is lost.

**The provider is failing.** `recent.failure_percent` over a non-zero `recent.sample`,
`last_failure_at` recent, `last_failure_code` set. The failure code is the redacted stable code —
the provider's own message is never surfaced.

**The model or the prompt drifted.** `provider.invalid_structured_output` climbing and
`rates.invalid_output_percent` above 10% over 50 or more calls. This is the signal that the model
changed behaviour under a fixed prompt.

**Costs look too low.** Check `cost.unpriced_reviews` and `cost.totals_complete`. A completed
review whose model is absent from the pricing table stores `cost_micros = NULL`, and a null cost is
**never** counted as zero. If `totals_complete` is false, every cost number on the screen is a
lower bound.

---

## 5. Alerts

Six conditions, all edge-triggered and rate limited:

| Code | Fires when | Severity |
|---|---|---|
| `queue_delay_high` | oldest queued review older than the threshold | warning |
| `circuit_open` | the breaker is open | critical |
| `budget_exhausted` | a configured daily or monthly budget has nothing left | critical |
| `provider_unavailable` | permanent provider failures per hour reach the threshold | critical |
| `invalid_output_spike` | invalid-output rate above the threshold over a large enough sample | warning |
| `escalation_backlog` | undecided escalations above the threshold | warning |

Behaviour:

- An alert is published **once** when the condition turns on, and again only after
  `CONTENT_REVIEW_ALERT_REPEAT_SECONDS` while it stays on.
- When the condition clears, exactly one `content_review.recovered` event is published, carrying
  the alert code.
- Delivery is rate limited a second time at the notifier by
  `CONTENT_REVIEW_ALERT_GLOBAL_COOLDOWN`, keyed per event (and per alert code for recovery), so a
  flapping condition cannot become a notification storm.
- Individual review failures are **not** alerted. In a decision-applying mode a failure is always
  followed by an escalation, and alerting on both would notify twice for one piece of news.

Alert state lives in the cache. Clearing the cache re-arms every alert; the next sweep will
re-publish anything still active. It never touches review data.

---

## 6. Recovery

### Provider outage
Do nothing urgent. The circuit breaker opens after the configured failures, every affected review
escalates to a human, and auctions remain fully reviewable by hand. When the provider recovers the
first success closes the circuit and the next sweep publishes `content_review.recovered`. If the
outage will be long, publish `mode = manual` from the dashboard to stop spending attempts on it.

### Worker down
Start the `content-review` worker. Queued rows are untouched and `content-review:dispatch-pending`
re-dispatches them within a minute. Reviews whose lease expired mid-flight are reclaimed by the same
command. Nothing needs to be re-created by hand.

### Malformed responses
Check `rates.invalid_output_percent`. A single bad response fails one review non-retryably (it is
not a transient error) and escalates it. A sustained rate means the prompt, the published policy, or
the model changed. Downgrade to `shadow` or `manual`, then compare `prompt_version`,
`policy_version` and `model` on the affected rows — all three are recorded on every review.

### Budget exhaustion
Reviews degrade to human review; nothing is lost and nothing is auto-decided. Either wait for the
period to roll over or publish a settings version with a larger
`daily_budget_micros` / `monthly_budget_micros`. Reservations expire on their own TTL.

### Stale reviews and crash debris
```
php artisan content-review:reconcile            # report only
php artisan content-review:reconcile --apply    # apply the two idempotent repairs
```
It repairs exactly two things: an expired worker lease goes back to `queued`, and a cancelled or
superseded row stops claiming to be the active one. Everything else — reviews queued past the stale
window, completed rows with no application state, subjects with more than one active review,
decisions with no review row — is **counted and reported, never guessed at**. Those need a human.

### Large backlog
Raise `CONTENT_REVIEW_MAX_CONCURRENT` (respecting the provider's own rate limit) and add a second
content-review worker. If the backlog is the *escalation* backlog rather than the queue, that is a
staffing problem, not a system problem — the `escalation_backlog` alert distinguishes the two.

---

## 7. Backfill

Auctions that were already waiting for review when the feature shipped have no review row. Nothing
is backfilled automatically, ever.

```
php artisan content-review:backfill                              # dry run, the default
php artisan content-review:backfill --limit=25                   # dry run, bounded
php artisan content-review:backfill --execute --limit=25         # create shadow reviews
```

Rules the command enforces:

- **Dry run is the default.** Without `--execute` it reads, counts and prints; it writes nothing,
  changes no auction, creates no review and dispatches no job.
- **`--execute` is refused while `CONTENT_REVIEW_ENABLED=false`.**
- **Created reviews are capped at `shadow`**, whatever the published mode is. A backfilled review
  can never apply an automatic decision to historical content. There is no flag to change this.
- **A manual platform creates nothing**, even with `--execute` — the ceiling only lowers the mode,
  it never raises it.
- **Content that already has any review row is skipped.** Completed reviews are never re-run.
- `--limit` (default 50, max 500) and `--chunk` bound the work.

---

## 8. Rollout

Do not skip a stage. Each stage is a published settings version, which is its own audit row.

| Stage | Configuration | Exit criterion |
|---|---|---|
| 0 — Structure | `CONTENT_REVIEW_ENABLED=false`, `mode=manual` | migrations applied, suite green, zero observed behaviour change |
| 1 — Shadow | `enabled=true`, `mode=shadow` | the acceptance metrics in §9 |
| 2 — Assisted | `mode=ai_assisted` | override rate stable, turnaround not worse, admins report the panel is useful |
| 3 — Automatic, narrow | `mode=ai_automatic` **plus** a short `automation.allowed_category_ids`, a low `max_starting_amount_minor`, `require_images=true` | ≥100 automatic decisions with zero incorrect auto-approvals in spot checks |
| 4 — Widen | grow the allowlist and the cap one step at a time | the §9 metrics hold at every step |

Switching the published mode to `ai_automatic` while `enabled = true` requires typing
`ai_automatic` into a confirm field in the dashboard. That is deliberate.

---

## 9. Shadow acceptance metrics

Before leaving shadow, and again before every widening step, read
`GET /api/admin/content-review/metrics` over at least a 30-day range and require **all** of:

| Metric | Where | Gate |
|---|---|---|
| Sample size | `volume.completed` | ≥ 200 completed reviews |
| Agreement with humans | `rates.agreement_percent` | ≥ 90% on approvals, ≥ 85% on rejections, over ≥ 200 decisions |
| False approvals | manual spot check of `auto_approved` against the admin's own verdict | zero found |
| False rejections | same, for `auto_rejected` | zero found |
| Human escalation rate | `rates.escalation_percent` | stable, and not rising step to step |
| Provider failure rate | `rates.provider_failure_percent` | < 2% |
| Structured output failure rate | `rates.invalid_output_percent` | < 2% |
| Average cost | `cost.range_cost_micros ÷ volume.completed` | within the daily budget at projected volume |
| Average latency | `latency.p95_duration_ms` | < 20s |

**Model confidence is not an acceptance metric.** The gate is measured agreement with human
decisions on real content. A model that reports 99% confidence and disagrees with reviewers 20% of
the time fails this gate.

---

## 10. Kill switch

```
CONTENT_REVIEW_ENABLED=false
php artisan config:clear
```

What it guarantees, each proven by `tests/Feature/ContentReview/KillSwitchTest.php`:

- The resolved mode collapses to `manual` even if an `ai_automatic` version is published.
- No new review row is created and no job is dispatched.
- Manual review keeps working exactly as before, through the same endpoint.
- A review already in flight still completes its analysis but **escalates to a human** instead of
  applying an automatic decision.
- The sweeper dispatches nothing.
- No review, decision or outbox row is deleted, and stored reviews stay readable in the admin API.
- The health endpoint reports `enabled = false` and `mode = manual`.

Jobs already sitting on the queue are not deleted. They run, find the mode collapsed to `manual`,
and take the human-fallback path. That is the intended behaviour: fail safe toward a human, never
toward an automatic decision.

The faster, no-deploy equivalent is the dashboard: publish a settings version with
`enabled = false`, or with `mode = ai_assisted` / `shadow` to keep the analysis running for
observability while all automation stops.

---

## 11. Rollback

In order of preference, none of which deletes data:

1. **Publish `enabled=false`** from the dashboard. Instant, no deploy, one audit row.
2. **Set `CONTENT_REVIEW_ENABLED=false`** in `.env` and clear the config. Overrides everything,
   including a corrupted settings row.
3. **Downgrade the mode** to `ai_assisted` or `shadow`. Analysis continues; automation stops.
4. **Stop the content-review worker.** Reviews queue up harmlessly and auctions stay reviewable by
   hand.
5. **Revert the release.** The tables remain, unreferenced and harmless. No data migration is
   needed in either direction.

**Do not run `migrate:rollback` in production to disable the feature.** It is not the disable
mechanism, and it drops review history that the audit trail depends on. Migration rollback is for
test and staging environments only.

---

## 12. Adding a provider

The subsystem is provider-agnostic below the `ContentReviewProvider` contract, so a new vendor is
four things and nothing else. An architecture test (`vendor names never leak outside the provider
layer`) fails the build if any of it spreads further.

1. **Credentials** in `config/services.php`, never in `config/content_review.php` — a test asserts
   the domain config carries no secrets, and the settings endpoint rejects any payload key
   containing `key` or `secret`.
2. **A catalog entry** under `config/content_review.php` → `providers.<name>`, with a
   `default_model` and one row per model: `input` and `output` in micros per 1M tokens, `vision`,
   and `structured` (`tool` | `json_schema` | `json_object`). Zero rates mean free, not unpriced.
3. **A driver** in `app/Services/ContentReview/Providers/` implementing `name()`, `isConfigured()`
   and `analyze()`. It may not touch `App\Models`, `App\Repositories` or `DB::`, and it must map
   every failure onto a `ContentReviewErrorCode`.
4. **One line** in `ContentReviewProviderFactory::PROVIDERS`. That is what makes it selectable in
   the admin screen, reportable on `/health` and acceptable to the settings validator.

`ProviderCatalogTest` then enforces that the driver has a catalog, that every rate is a
non-negative integer, and that the declared default model is one the provider actually offers.

**Never catalogue a router.** OpenRouter exposes ids such as `openrouter/auto` that resolve to a
different model on every call. A catalog entry describes capabilities, and a router has none that
hold: in one live run the same id landed on a safety classifier that can only answer
`User Safety: safe`, and on a reasoning model that spent the entire output ceiling thinking and was
cut off mid-JSON. Pin a slug whose model page on <https://openrouter.ai/models> lists structured
outputs, and image input if image analysis is on.

Three behaviours worth knowing before you pick a model:

- **A model priced at zero never consumes budget.** `estimateMicros` returns 0, so a free model
  keeps running with an exhausted daily cap. That is correct, but it means the budget guard is not
  a throttle for free tiers.
- **Images follow the model, not the setting.** A model catalogued with `vision: false` has its
  images withheld by the pipeline, so `images_analyzed` stays 0 rather than claiming a screening
  that never happened.
- **`max_output_tokens` is a ceiling on reasoning too.** On OpenRouter it caps every output token,
  thinking included, so a reasoning model can burn the whole budget before writing a character of
  the answer. That surfaces as `provider_output_truncated`, which is the signal to raise the
  ceiling — the default 2000 suits models that do not think out loud, and a reasoning model wants
  several times that. The code is deliberately not retryable: the same prompt under the same
  ceiling truncates again.

---

## 13. Authorization model

There is **one kind of admin**. The content review subsystem defines no permission of its
own: every admin endpoint is protected by `auth:sanctum` plus the `role:admin` middleware,
and nothing beyond that. An authenticated admin may read every review, run, retry, cancel,
force manual, confirm, override, publish settings and policies, probe the provider, and
read costs, technical detail and metrics.

What still restricts an action is the **state of the domain**, never the identity of the
caller:

- `available_actions` is computed from the review alone — retry only for a failed active
  attempt, cancel only while queued or running, confirm and override only while a completed
  assisted recommendation is still undecided.
- An override still requires a written reason.
- A review that is already decided, stale, superseded, or whose auction has left
  `pending_review` is refused with a stable error code.
- The `/decide` route still refuses a shadow review.

Seller-side authorization is untouched: a seller may still only update, reopen or submit
their own auction, and no seller can reach an admin endpoint.

`POST /api/admin/content-review/provider/test` remains rate limited
(`CONTENT_REVIEW_PROVIDER_TEST_PER_MINUTE` / `_PER_HOUR`), because the limit protects the
provider account, not the operator.

---

## 14. What is never exposed

Checked by `ContentReviewPermissionTest`, `LogRedactionTest` and `ContentReviewMetricsApiTest`,
each of which plants a canary and asserts it never appears:

- the API key, in any response, log line, exception message or error payload
- the rendered prompt
- the raw provider response, including any chain of thought
- image bytes, storage disks and storage paths
- the content hash
- the auction title, description or any seller identity, in logs

Logs carry exactly one allowlisted context array, built by `ContentReviewLogContext`, which drops
every key outside its list and every non-scalar value. One `Log::info` per completed review, one
`Log::warning` per failure.
