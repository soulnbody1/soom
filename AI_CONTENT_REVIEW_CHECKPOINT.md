# AI Content Review — Checkpoint

Last updated: 2026-08-06
Branch: `soom-auctions` · No commit, no push performed.

## Status

| Batch | State |
|---|---|
| Preliminary (reopen + update draft) | Done (pre-existing) |
| 1 — contracts, enums, config, permissions | Done |
| 2 — tables, models, repositories, seeder | Done |
| 3 — analysis core, providers | Done |
| 4 — adapter, actions, job, sweeper, guards | Done |
| 5 — admin API, permissions, outbox router | **Done in this session** |
| 6 — dashboard (read-only) | **Not started — next** |
| 7 — `ai_assisted` + override guard | Not started |
| 8 — `ai_automatic` + image cache | Not started |
| 9 — observability, docs, rollout | Not started |

---

## The MySQL "29 vs 28" question — resolved

The previous checkpoint's numbers were **not comparable**, and the discrepancy is fully explained.
The comparison was re-run from a **dropped and recreated database**, using
`vendor/bin/phpunit --configuration=phpunit.mysql.xml --log-junit=…`, with the baseline taken from a
**detached `git worktree` at `HEAD`** (no stash, no reset, working tree untouched).

| Run | Test cases | Failing | Skipped |
|---|---|---|---|
| baseline (worktree at `HEAD` 4ed086e) | 340 | **26** | 0 |
| working tree before Batch 5 | 357 | **26** | 0 |
| working tree after Batch 5 | 357 | **26** | 0 |

The failing-test-name sets are **byte-identical across all three** (verified by `Compare-Object` on
sorted junit-derived name lists, saved under the session scratchpad as `*.failing.txt`).

Answers to the specific questions:

- **Why 29 vs 28?** Neither number is reproducible, because the earlier runs executed against a
  **dirty, accumulated database**. `tests/Pest.php:15` has `RefreshDatabase` **commented out**, and
  the auction tests are PHPUnit classes that call `Artisan::call('migrate')` (not `migrate:fresh`) in
  `setUp`. **There is no DB isolation between tests at all**, so results depend on leftover rows and
  on execution order. With a clean database the count is a stable 26 on every run.
- **Was a test skipped?** No. `skipped = 0` in all three runs (the 25 skips are in the sqlite suite,
  not the MySQL one).
- **Was a failure double-counted?** No. Zero duplicate names in either failing list.
- **Did assertions/datasets change?** Yes, and this is the second half of the explanation: the two
  runs never contained the same tests. The working tree adds `AuctionReopenAndUpdateTest` (16) and
  `AuctionReopenConcurrencyMysqlTest` (1) — exactly the +17 cases and the +136 assertions.
  Those 17 all pass, so `passed` rose while `failing` did not.
- **Was the same set run?** **No** — that was the flaw in the earlier comparison. It is now
  controlled for: the baseline is a worktree that genuinely lacks those two files, and the failing
  sets still match exactly.

All 26 are the documented **cross-test data-leak failures** (list/filter endpoints seeing rows from
other tests, plus resource-shape tests). Their root cause is the missing `RefreshDatabase`.

**Not repaired, deliberately.** Enabling `RefreshDatabase` is not a limited or safe change: the trait
is commented out in `tests/Pest.php`, which binds Pest closures only — the auction tests are plain
PHPUnit classes it would not even reach — so a real fix means touching `tests/TestCase.php` and every
`setUp` in the suite, changing all 573 tests at once. That is a suite-wide refactor, outside Batch 5.
Recorded here as the known cause.

---

## Batch 5 — what shipped

### New files
```
app/Policies/ContentReview/ContentReviewPolicy.php
app/Http/Controllers/ContentReview/ContentReviewController.php
app/Http/Controllers/ContentReview/ContentReviewSettingsController.php
app/Http/Controllers/ContentReview/ContentReviewPolicyController.php
app/Http/Controllers/ContentReview/ContentReviewHealthController.php
app/Http/Requests/ContentReview/ContentReviewHistoryRequest.php
app/Http/Requests/ContentReview/PublishContentReviewSettingsRequest.php
app/Http/Requests/ContentReview/PublishContentReviewPolicyRequest.php
app/Http/Resources/ContentReview/ContentReviewResource.php
app/Http/Resources/ContentReview/ContentReviewDecisionResource.php
app/Http/Resources/ContentReview/ContentReviewSettingsResource.php
app/Http/Resources/ContentReview/ContentReviewPolicyResource.php
app/Services/ContentReview/Actions/RunContentReviewAction.php
app/Services/ContentReview/Actions/RetryContentReviewAction.php
app/Services/ContentReview/Actions/ForceManualReviewAction.php
app/Services/ContentReview/Actions/PublishContentReviewSettingsAction.php
app/Services/ContentReview/Actions/PublishContentReviewPolicyAction.php
app/Services/ContentReview/Actions/TestContentReviewProviderAction.php
app/Services/ContentReview/Support/ContentReviewActionResolver.php
app/Services/ContentReview/Support/ContentReviewHealthReporter.php
app/Services/ContentReview/Support/ProviderHealthJournal.php
app/Services/ContentReview/Support/ReviewSubjectResolver.php
app/Services/ContentReview/Contracts/ContentReviewEventPublisher.php
app/Services/ContentReview/Notifications/ContentReviewNotificationCatalog.php
app/Services/ContentReview/Notifications/AdminAlertRecipientResolver.php
app/Services/Auction/Notifications/OutboxNotifier.php            (interface)
app/Services/Outbox/OutboxTopicRouter.php
app/Services/Outbox/ContentReviewOutboxNotifier.php
app/Services/Outbox/OutboxContentReviewEventPublisher.php
app/Notifications/ContentReviewAdminNotification.php
routes/api/content_review.php
tests/Feature/ContentReview/Concerns/BuildsContentReviewFixtures.php
tests/Feature/ContentReview/AdminContentReviewApiTest.php          (18)
tests/Feature/ContentReview/ContentReviewPermissionTest.php        (10)
tests/Feature/ContentReview/ContentReviewSettingsApiTest.php       (17)
tests/Feature/ContentReview/ContentReviewPolicyApiTest.php         (18)
tests/Feature/ContentReview/ContentReviewOutboxTest.php            (13)
tests/Feature/ContentReview/AdminAuctionQueryContentReviewTest.php  (4)
```

### Modified files
```
bootstrap/app.php                                   (ContentReviewException render + ContentReview 404)
app/Providers/AppServiceProvider.php                (Gate::policy, OutboxNotifier + publisher bindings, throttle)
routes/api.php                                      (require content_review.php inside api_maintenance)
config/content_review.php                           (content_review.technical.view, provider_test throttle)
app/Models/Auction/Auction.php                      (activeContentReview HasOne)
app/Models/ContentReview/ContentReviewPolicy.php    (reviews HasMany, for withCount)
app/Http/Resources/Auction/AdminAuctionResource.php (ai_review block, awaiting_ai_review)
app/Http/Controllers/Auction/AuctionController.php  (eager load, permission-gated)
app/Repositories/Auction/Queries/AdminAuctionQuery.php + ListAdminAuctionsAction  ($withContentReview)
app/Repositories/ContentReview/ContentReviewRepository.php   (nextAttemptForContent, deactivate*, countByStatus)
app/Repositories/ContentReview/ContentReviewPolicyRepository.php (allWithUsage)
app/Services/ContentReview/Contracts/ReviewSubjectAdapter.php    (resolveSubjectId, subjectReference)
app/Services/Auction/ContentReview/AuctionReviewSubjectAdapter.php  (both new methods)
app/Services/ContentReview/Actions/{Request,Process,Apply,Cancel}ContentReview*.php  (events, guards)
app/Services/Auction/Actions/DispatchOutboxMessagesAction.php    (type-hint → OutboxNotifier)
app/Services/Auction/Notifications/AuctionOutboxNotifier.php     (implements OutboxNotifier)
lang/{ar,en}/content_review.php                     (error codes, reasons, notification keys)
tests/Unit/ContentReview/EnumContractTest.php       (permission count 8 → 9)
```

### Endpoints
```
GET    /api/admin/content-reviews/{subjectType}/{subjectId}              content_review.view
GET    /api/admin/content-reviews/{subjectType}/{subjectId}/current      content_review.view
POST   /api/admin/content-reviews/{subjectType}/{subjectId}/run          content_review.run
POST   /api/admin/content-reviews/{subjectType}/{subjectId}/force-manual content_review.force_manual
GET    /api/admin/content-reviews/{contentReview}                        content_review.view
POST   /api/admin/content-reviews/{contentReview}/retry                  content_review.run
POST   /api/admin/content-reviews/{contentReview}/cancel                 content_review.cancel
GET    /api/admin/content-review/settings                                content_review.settings.manage
GET    /api/admin/content-review/settings/versions                       content_review.settings.manage
POST   /api/admin/content-review/settings                                content_review.settings.manage
GET    /api/admin/content-review/policies                                content_review.policy.manage
GET    /api/admin/content-review/policies/active                         content_review.policy.manage
GET    /api/admin/content-review/policies/{contentReviewPolicy}          content_review.policy.manage
POST   /api/admin/content-review/policies                                content_review.policy.manage
GET    /api/admin/content-review/health                                  content_review.view (budget needs costs.view)
POST   /api/admin/content-review/provider/test                           content_review.settings.manage + throttle
```

`{subjectId}` accepts the auction **public id** (or an internal id) — resolved by
`ReviewSubjectAdapter::resolveSubjectId()`, so the dashboard never needs internal ids.
`{subjectType}` is route-constrained by `whereIn` against `ReviewableSubjectType` and re-checked in
`ReviewSubjectResolver`.

### Key decisions

- **`app/Services/Outbox/` is a new neutral namespace.** The plan put `ContentReviewOutboxNotifier`
  under `Services/ContentReview/Notifications`, but that directory is scanned by
  `ContentReviewArchitectureTest`, which forbids any reference to `App\Models\Auction` — and
  `OutboxMessage` lives there. The router and the content-review notifier are infrastructure that
  must know **both** topics, so they sit above both domains. `ContentReview` code depends only on
  `ContentReviewEventPublisher` (its own contract); the outbox-backed implementation is bound in
  `AppServiceProvider`. The architecture test passes unchanged.
- **`DispatchOutboxMessagesAction` is a one-word change** — the constructor now type-hints
  `OutboxNotifier` instead of `AuctionOutboxNotifier`. `AuctionOutboxNotifier` is untouched apart
  from `implements`; both of its hard rejections still fire for auction messages.
- **Event names follow the requirement list, not the plan's draft names.**
  `content_review.failed` (was `attempt_failed_repeatedly`), `content_review.provider_unavailable`
  (was `provider_unhealthy`), plus `content_review.circuit_open`. Full catalogue:
  `queued`, `completed`, `failed`, `escalated`, `auto_decided`, `stale`, `provider_unavailable`,
  `circuit_open`, `budget_exhausted`.
- **`content_review.failed` is recorded but NOT alerted.** In a decision-applying mode a failure is
  always followed by `escalated`; alerting on both notified admins twice for one piece of news. A
  test proves exactly one alert per failed run.
- **Alert rate limiting** is `Cache::add` keyed per `(event, subject)` for subject-scoped events and
  per `event` globally for `provider_unavailable` / `circuit_open` / `budget_exhausted`.
- **Latent bug found and fixed by the tests.** A unique index covers
  `(subject_type, subject_id, content_hash, attempt)`. `RequestContentReviewAction` hard-coded
  `attempt = 1`, so **any** re-run over unchanged content — the new `run` and `retry` endpoints, but
  also an ordinary resubmission — hit a 500 on a constraint violation. It now calls
  `ContentReviewRepository::nextAttemptForContent()` and continues the attempt sequence, with
  `max_attempts` shifted so each request still gets a full retry budget.
- **Retry preserves history**: the failed row keeps `status = failed` and only loses
  `current_marker`; a new row is created with `trigger = admin_retry`. Refused with
  `review_not_retryable` unless the source is `failed` **and** still active, and with `review_stale`
  if the content hash moved.
- **Force-manual** cancels every pending review for the subject, sets `decided_at`, and clears
  `current_marker` on all of them — so a late-arriving AI result is refused twice over
  (`ProcessContentReviewAction` skips terminal/decided rows; `ApplyContentReviewDecisionAction`
  returns early on `decided_at !== null`).
- **Append-only publishing** is serialised by a `Cache::lock` per scope/subject-type, with the
  existing `unique(scope, version_number)` index as the backstop; both map to a `409 version_conflict`.
  Published versions stay immutable through the models' `updating` hooks.
- **No N+1.** The list eager-loads `activeContentReview.decisions.decidedBy` only when the caller
  holds `content_review.view`, and the resolved mode is memoised on the `Request` attributes
  (`ContentReviewResource::resolvedMode`) rather than in a container singleton, so a queue worker can
  never serve a stale mode. `AdminAuctionQueryContentReviewTest` proves the query count is identical
  for 1 and for 6 reviewed auctions.
- **Metrics endpoint deferred to Batch 9.** The plan listed it under Batch 5; the approved Batch 5
  scope does not, and Batch 9 does. The health endpoint covers what Batch 5 asked for.
- **`content_review.confirm` was not added.** Confirming a recommendation is Batch 7 and is
  authorised by the existing `auction.review` / `auction.approve`; adding the permission now would be
  dead config. `content_review.technical.view` **was** added (gates provider/model/versions/duration).

### Redaction boundary
The API returns provider name, model, policy/settings version, duration (behind
`content_review.technical.view`) and tokens/cost (behind `content_review.costs.view`). It never
returns the raw provider response, the prompt, chain of thought, the API key, image bytes, storage
paths, or the content hash. Proven by `ContentReviewPermissionTest` sweeping seven endpoints for a
planted `sk-ant-leak-canary` and for `api_key` / `raw_response` / `policy_instructions` /
`chain_of_thought` / the hash / the media path.

---

## Verification

- `php artisan test` → **628 passed, 25 skipped, 0 failed** (was 548 before Batch 5; +80 new tests).
- `composer test:auction:mysql` → 357 tests, **26 failing, byte-identical to the `HEAD` baseline**.
- `php artisan route:list --path=content-review` → 16 routes.
- `AuctionNotificationDispatchTest` and the whole auction suite pass unmodified.
- Pint: PASS on every file this batch created or modified.
  `routes/api.php` still reports pre-existing `concat_space` issues from its original `require` lines
  — untouched on purpose, reformatting it would add unrelated diff noise.

---

## Operational notes (unchanged, still Batch 9)

- Two workers in production:
  `php artisan queue:work --queue=default --tries=3 --timeout=120`
  `php artisan queue:work --queue=content-review --tries=3 --timeout=120`
- `CACHE_STORE=database` is required so the circuit breaker, budget guard, concurrency limiter and
  the new publish locks share atomic locks across processes.
- Ships disabled / manual, `auto_reject_categories` empty.

---

## NEXT TASK (start of Batch 6)

Dashboard, read-only, in `C:\Users\pc\Desktop\SB\soom-dashboard`. Before writing any component,
read `src/lib/AxiosBase.ts`, `src/lib/types.ts`, `src/hooks/useTypedTranslation.ts` (its
`TranslationKey` union is derived from `ar.json`, so every new string must land in **both**
`ar.json` and `en.json` or the build fails), and the existing auction query hooks.

Build: the `ai_review` card in the auction detail, attempt history, stale banner, settings read view,
policy versions read view, provider health, and the loading / empty / error / permission / conflict
states. Gate technical and cost rows on the presence of the `technical` and `cost` keys — the API
omits them entirely when the permission is missing. No raw JSON on screen.

Then run `npx tsc --noEmit`, `npm run lint`, `npx vitest run`, `npm run build`.

**The overall task is NOT complete.** Batches 6–9 remain.
