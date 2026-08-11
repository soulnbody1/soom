# AI Content Review — Checkpoint

Last updated: 2026-08-11
Backend branch: `soom-auctions` · Dashboard branch: `main` · No commit, no push performed.

## Status

| Batch | State |
|---|---|
| Preliminary (reopen + update draft) | Done (pre-existing) |
| 1 — contracts, enums, config, permissions | Done |
| 2 — tables, models, repositories, seeder | Done |
| 3 — analysis core, providers | Done |
| 4 — adapter, actions, job, sweeper, guards | Done |
| 5 — admin API, permissions, outbox router | Done |
| 6 — dashboard (read-only + operational UI) | Done |
| 7 — `ai_assisted` confirm / override | Done |
| 8 — `ai_automatic` + image cache | Done |
| 9 — observability, alerts, operations, docs | **Done in this session — the plan is complete** |

Batch 7 touched **both** repositories. Batches 1–6 were not re-implemented and nothing was
refactored beyond what the new contract required.

---

## Post-Batch-9 cleanup — the content review permission layer was removed

**Read this before the per-batch notes below.** The platform has exactly one kind of admin,
and every authenticated admin may run every admin function. The ten fine-grained
`content_review.*` permissions introduced across Batches 1–9 modelled a role system that
does not exist, so they were deleted. Everything the older sections say about
`content_review.view`, `content_review.run`, `content_review.cancel`,
`content_review.force_manual`, `content_review.override`, `content_review.settings.manage`,
`content_review.policy.manage`, `content_review.costs.view`,
`content_review.technical.view` and `content_review.metrics.view` is **historical**.

What was deleted:

- `app/Policies/ContentReview/ContentReviewPolicy.php` and
  `app/Policies/ContentReview/Concerns/ChecksContentReviewPermissions.php` (the whole
  `app/Policies/ContentReview/` tree), plus its `Gate::policy` registration.
- `config('content_review.admin_permissions')` and `config('content_review.role_admin_permissions')`.
- All **18** `Gate::authorize(...)` calls in the five content review controllers.
- The `override` permission check in `ContentReviewOverrideGuard` and the now-unreachable
  `content_review_override_not_allowed` error code and its two language entries.
- The permission filter in `AdminAlertRecipientResolver` — every admin is now a recipient.
- The permission gating on `technical`, `cost`, `budget`, `ai_review` and the metrics `cost`
  block in the API resources and reporters.

What deliberately did **not** change:

- **The auction permission layer is untouched.** `AuctionPolicy`, `ChecksAuctionPermissions`
  and `config('auction.admin_permissions')` predate this subsystem, are shared by payouts,
  refunds, disputes and payments, and already grant every permission in that list to any
  `role = 'admin'` user. They also carry the seller-side ownership rules.
- **Seller and ownership authorization.** `update`, `reopen`, `submitForReview`, `bid`,
  `register`, handover and dispute abilities all still run through `AuctionPolicy`.
- **Every business rule.** `available_actions` is now derived purely from the review's own
  state; an override still requires a reason; already-decided, stale, superseded and
  not-reviewable are all still refused with their stable codes.
- **The redaction boundary.** No API key, prompt, raw response, chain of thought, image
  bytes, storage path or content hash is exposed to anyone.
- **The provider-test rate limit**, which protects the provider account rather than the
  operator.

Access control is now exactly: `auth:sanctum` + `role:admin`. A guest is rejected 401, a
seller is rejected 403, and any admin is allowed.

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
  *(Batch 7 kept this decision.)*

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

## Batch 6 — what shipped (dashboard only)

All paths below are relative to `C:\Users\pc\Desktop\SB\soom-dashboard`.

### New files
```
src/app/dashboard/auctions/lib/contentReviewTypes.ts             (API contract types)
src/app/dashboard/auctions/lib/useContentReviewLabels.ts         (safe dynamic-key labels)
src/app/dashboard/auctions/hooks/useContentReviewQueries.ts      (9 read endpoints)
src/app/dashboard/auctions/hooks/useContentReviewMutations.ts    (7 write endpoints)
src/app/dashboard/auctions/components/contentReview/AiReviewPanel.tsx
src/app/dashboard/auctions/components/contentReview/AiReviewSummaryCell.tsx
src/app/dashboard/auctions/components/contentReview/ContentReviewActions.tsx
src/app/dashboard/auctions/components/contentReview/ContentReviewBadges.tsx
src/app/dashboard/auctions/components/contentReview/ContentReviewDecisions.tsx
src/app/dashboard/auctions/components/contentReview/ContentReviewFindings.tsx
src/app/dashboard/auctions/components/contentReview/ContentReviewHistory.tsx
src/app/dashboard/auctions/[auctionId]/components/AiReviewTab.tsx
src/app/dashboard/auctions/settings/components/ContentReviewSettingsTab.tsx
src/app/dashboard/auctions/settings/components/ContentReviewSettingsForm.tsx
src/app/dashboard/auctions/settings/components/ContentReviewPolicyTab.tsx
src/app/dashboard/auctions/settings/components/ContentReviewPolicyForm.tsx
src/app/dashboard/auctions/settings/components/ContentReviewPolicyDetailDialog.tsx
src/app/dashboard/auctions/settings/components/ProviderHealthCard.tsx
src/app/dashboard/auctions/settings/components/contentReviewForm.helpers.ts
src/app/dashboard/auctions/__tests__/contentReviewFixtures.tsx       (harness, not a test file)
src/app/dashboard/auctions/__tests__/contentReviewErrors.test.ts     (13)
src/app/dashboard/auctions/__tests__/AiReviewPanel.test.tsx          (16)
src/app/dashboard/auctions/__tests__/ContentReviewActions.test.tsx   (13)
src/app/dashboard/auctions/__tests__/ContentReviewHistory.test.tsx    (9)
src/app/dashboard/auctions/__tests__/contentReviewForms.test.tsx     (19)
src/app/dashboard/auctions/__tests__/contentReviewSettings.test.tsx  (13)
```

### Modified files
```
src/app/dashboard/auctions/lib/types.ts        (ai_review on AdminAuction, awaiting_ai_review,
                                                ContentReviewApiErrorCode folded into AuctionErrorCode)
src/app/dashboard/auctions/lib/constants.ts    (contentReviewKeys, badge tone maps, enum lists,
                                                microsToAmount / durationLabel / waitMillis)
src/app/dashboard/auctions/lib/errors.ts       (CONTENT_REVIEW_ERROR_CODES, getContentReviewErrorCode,
                                                isContentReviewConflict / isContentReviewUnavailable;
                                                the conflict codes now feed isStaleStateError)
src/app/dashboard/auctions/components/QueryStates.tsx  (PermissionDenied; QueryError routes 403 to it)
src/app/dashboard/auctions/[auctionId]/page.tsx        (AI review tab, mounted only when gated)
src/app/dashboard/auctions/review/page.tsx             (AI summary column, mounted only when gated)
src/app/dashboard/auctions/settings/page.tsx           (two new tabs)
src/i18n/languages/{ar,en}.json                        (auction.contentReview — 283 keys each,
                                                        + auction.nextAction.awaitingAiReview)
src/test/setup.ts                                      (afterEach(cleanup) — see below)
```

### Key decisions

- **The panel reads the embedded `ai_review` block, not a second request.** `AdminAuctionResource`
  already serializes `ai_review.current` for `content_review.view` holders, so the detail page has
  the active attempt in hand. Issuing `GET …/current` again would double the round trips and could
  disagree with the row the page is already showing. `useCurrentContentReview` still exists and is
  exported for callers that do not hold the auction payload.
- **Permission gating is the *absence of a key*, never a client-side role check.** The dashboard has
  no permission list — `src/lib/getUserPermissions.ts` is dead code from an unrelated product. So:
  no `ai_review` ⇒ the tab trigger and the queue column are not mounted at all; no `technical` ⇒ no
  provider/model/version rows; no `cost` ⇒ no token/cost rows; no `budget` in the health payload ⇒
  no budget block. A 403 on the settings/policy queries renders `PermissionDenied` (a plain "not
  available" notice), not a retryable error.
- **Labels are translated client-side from the code, not echoed from the server.** Every resource
  ships a `*_label` rendered in the *API's* locale, which is not necessarily the operator's. The
  dashboard carries its own dictionary mirroring `lang/{ar,en}/content_review.php` and uses the
  server label only as a fallback for codes it does not know — a policy may define arbitrary
  violation codes. `useContentReviewLabels` does this with next-intl's `t.has()` so an unknown key
  degrades instead of throwing. Proven by a test that plants `"SERVER SIDE LABEL"` and asserts it
  never reaches the DOM.
- **`auction.contentReview.violations` is the code dictionary; the section heading is
  `violationsTitle`.** Both were briefly the same key, and the object silently won. Worth knowing
  before adding a scalar whose name collides with a group.
- **Batch 6 wires three action controls: retry, cancel, force-manual.** `run` and `override` can
  both appear in `available_actions`; neither gets a control here. `override` is the Batch 7 assisted
  decision and wiring it now would ship half of that flow. `run` was left out because the approved
  Batch 6 scope names exactly three actions — the API function exists if it is wanted later.
- **Conflicts keep the dialog open, other failures close it.** 409/422 render the translated stable
  code inside the dialog while the mutation's `onSettled` refreshes the panel behind it, so the
  operator reads *why* the action was refused against already-current data. Anything else toasts and
  closes.
- **Invalidation is scoped to one subject.** A retry/cancel/force-manual invalidates
  `["content-review","reviews",subjectType,subjectId]`, the review's own detail key,
  `["auctions","detail",id]` and `["auctions","list"]` — and provably not the settings or policy
  caches. Publishing settings additionally clears the whole `content-review` root, because the
  resolved mode feeds every panel and the health card.
- **No new list filters.** `AdminAuctionQuery` exposes no content-review filter, and filtering an
  already-paginated page in the browser would misreport the totals. The review queue gained a
  read-only AI column instead.
- **Health does not poll.** `refetchInterval: false`, `refetchOnWindowFocus: false`, a 60 s stale
  time and an explicit refresh button. A dashboard that re-asked every few seconds would add load
  exactly when the provider is already unhealthy. A test asserts the interval is `false` and that
  one mount issues one request.
- **The model field is free text.** The publish request validates `settings.model` against
  `array_keys(config('content_review.pricing.models'))`, and no endpoint exposes that list. Inventing
  a hardcoded list in the dashboard would drift from config, so the server's `Rule::in` stays the
  authority and its 422 surfaces as a field error.
- **The policy form has no JSON textarea.** Every server constraint has a control that can express
  it: locales and analyzed fields are checkbox groups, thresholds are bounded number inputs, and the
  auto-reject / human-review lists are *selections over* the prohibited list — so the "must be a
  subset" rule cannot be violated by construction. Dropping a prohibited category also drops it from
  both derived lists.
- **`src/test/setup.ts` now runs `afterEach(cleanup)`.** `vitest.config.ts` leaves `globals: false`,
  so React Testing Library could not install its own cleanup hook and every render leaked into the
  next test — the existing suites papered over it with manual `unmount()` calls. Fixed once,
  centrally; all pre-existing tests still pass.

### Redaction boundary (dashboard side)
No raw JSON, prompt, chain of thought, raw provider response, storage path, image bytes or API key
is rendered anywhere. `AiReviewPanel.test.tsx` plants `api_key: "sk-ant-leak-canary"`,
`raw_response.chain_of_thought` and `policy_instructions` on the review object and asserts none of
them appear in `container.innerHTML`; `contentReviewSettings.test.tsx` sweeps the whole settings
surface for `api_key` / `sk-ant` / `secret`; the settings form asserts it renders no password input.

---

## Verification (Batch 6)

Run in `C:\Users\pc\Desktop\SB\soom-dashboard`:

- `npx tsc --noEmit` → **clean** (exit 0).
- `npx next lint` → **no errors and no warnings in any new or modified file**. The warnings it
  prints are pre-existing `no-img-element` / `exhaustive-deps` in unrelated modules.
- `npx vitest run` → **11 files, 117 tests, 0 failures** (was 5 files / 54 tests before this batch;
  +6 files / +63 tests).
- `npm run build` → **succeeds**, 20 routes generated.

Backend: **not modified**, so no backend test run applies. `git status` in `soom` is clean and the
Batch 5 numbers stand unchanged (`628 passed / 25 skipped / 0 failed` on sqlite; MySQL 357 tests with
the same 26 pre-existing data-leak failures as the `HEAD` baseline).

---

## Batch 7 — what shipped

### Backend — new files
```
app/DTO/ContentReview/HumanDecisionResult.php
app/Http/Requests/ContentReview/DecideContentReviewRequest.php
app/Services/ContentReview/Support/ContentReviewOverrideGuard.php
app/Services/ContentReview/Support/ContentReviewDecisionRecorder.php
tests/Feature/ContentReview/AiAssistedFlowTest.php                       (12)
tests/Feature/ContentReview/AiOverrideTest.php                           (12)
tests/Feature/Auction/ContentReviewDecisionConcurrencyMysqlTest.php       (4, MySQL only)
```

### Backend — modified files
```
app/Services/ContentReview/Actions/ApplyContentReviewDecisionAction.php  (applyHumanDecision)
app/Services/ContentReview/Contracts/ReviewSubjectAdapter.php            (+2 methods)
app/Services/Auction/ContentReview/AuctionReviewSubjectAdapter.php       (+2 methods)
app/Services/ContentReview/Support/ContentReviewActionResolver.php       (confirm, awaitsHumanDecision)
app/Services/ContentReview/Notifications/ContentReviewNotificationCatalog.php  (2 events)
app/Repositories/ContentReview/ContentReviewRepository.php               (lockActiveForSubject)
app/Repositories/ContentReview/ContentReviewDecisionRepository.php       (hasHumanDecision)
app/Http/Controllers/ContentReview/ContentReviewController.php           (decide)
app/Http/Controllers/Auction/AuctionController.php                       (review → one domain)
routes/api/content_review.php                                            (POST …/decide)
lang/{ar,en}/content_review.php                                          (7 error codes, 2 messages)
tests/Feature/ContentReview/ContentReviewPermissionTest.php              (available_actions contract)
```

### The new endpoint
```
POST /api/admin/content-reviews/{contentReview}/decide
     { "decision": "approve" | "reject", "reason"?: string (max 1000) }
```

### Key decisions

- **There is exactly one place a human decision is applied.**
  `ApplyContentReviewDecisionAction::applyHumanDecision()` serves both
  `POST …/content-reviews/{id}/decide` **and** the pre-existing
  `POST /api/admin/auctions/{auction}/review`. The older route can therefore no longer be used to
  escape the override rule — proven by
  `AiOverrideTest::test_the_manual_endpoint_cannot_bypass_the_override_permission`. This also
  satisfies `ContentReviewArchitectureTest`'s "only the apply action opens a transaction" rule
  without widening its allow-list.
- **The auction domain is reached only through the adapter.** `ReviewSubjectAdapter` gained
  `applyHumanDecision()` (delegates to `ReviewAuctionAction::approveLocked/rejectLocked` with
  `actorType = 'admin'` and the real admin id) and `allowsHumanDecision()` (runs the *subject's*
  policy — `auction.approve` / `auction.review`). The content-review controller and action stay free
  of every forbidden auction reference.
- **`content_review.confirm` was still not added.** Confirming is authorised by the subject policy,
  exactly as the manual route always was; a second permission would be dead config. Only
  **override** carries its own permission, and it is absent from `role_admin_permissions`, so a
  plain admin never inherits it.
- **A human decision does not rewrite the AI's row.** The review keeps its `status`, `outcome`,
  `recommendation`, `confidence`, `reason_code` and `decided_at` untouched; the human verdict lives
  only in `content_review_decisions`. Proven by
  `AiAssistedFlowTest::test_the_original_review_row_is_not_rewritten_by_a_human_decision`.
- **"Already decided" is the existence of an admin decision row, not `decided_at`.** The AI pipeline
  sets `decided_at` on *every* completed attempt (including an escalation), so using it as the human
  guard would have refused exactly the escalated reviews a human must then decide.
  `ContentReviewDecisionRepository::hasHumanDecision()` is checked inside the transaction, after the
  review row lock — so two concurrent admins serialise on that lock and the loser gets
  `409 review_already_decided`.
- **Lock ordering is unchanged from Batch 4**: `content_reviews` → `auctions`. The human path locks
  the review first (`lockActiveForSubject` / `lockByPublicId`), then the adapter locks the auction —
  the same order the AI path uses, so the two can never deadlock against each other.
- **Preconditions are evaluated inside the transaction, after the locks**, in this order:
  superseded/inactive/cancelled → `review_superseded`; not `completed` → `review_not_ready`;
  an admin decision already exists → `review_already_decided`; content hash moved →
  `review_stale`. The `/decide` route additionally requires the frozen mode to be at least
  `ai_assisted` (`review_not_assisted`) and an approve/reject recommendation
  (`recommendation_missing`).
- **Shadow mode records the relation but does not gate.** The true
  `confirmed`/`overridden` relation is always stored — that agreement rate is what shadow mode
  exists to measure — but the override *permission* and the mandatory reason are enforced only when
  the review's frozen mode is `ai_assisted` or higher. `/decide` refuses a shadow review outright.
- **`available_actions` no longer lies.** `override` used to be listed purely on the permission;
  `confirm` and `override` are now both listed only while the attempt still awaits a human decision
  (completed, has a recommendation, not stale, mode ≥ `ai_assisted`, no admin decision yet). The one
  existing assertion that pinned the old behaviour was updated, and a new test pins the new one.
- **New stable error codes follow the existing naming**, which drops the `content_review_` prefix
  (the sole exception, `content_review_override_not_allowed`, is reused as-is):
  `review_not_ready`, `review_superseded`, `recommendation_missing`, `override_reason_required`,
  `review_not_assisted`, `decision_conflict`, `decision_not_permitted`. `review_stale`,
  `review_already_decided` and `subject_not_reviewable` were reused, not duplicated.
- **Two outbox events, no extra notification.** `content_review.confirmed` and
  `content_review.overridden` are published on the existing `content_review.events` topic with
  `admin => false`: the seller is already notified by the ordinary `auction.status_changed` flow, and
  alerting every `content_review.view` holder on each decision would be noise. The outbox row plus
  the decision row are the audit. Both are written inside the one decision transaction, so
  idempotency follows from the same lock that makes the decision unique.
- **Audit stores IDs and a snapshot, never bulk data.** The decision row carries
  `ai_recommendation` + `ai_confidence` as they stood at decision time, the relation, the real
  `decided_by_id`, and the reason. Findings are not copied — the review row already holds them and
  is linked by `review_id`. No chain of thought, no raw provider response.

### Dashboard — new files
```
src/app/dashboard/auctions/components/contentReview/ContentReviewDecisionActions.tsx
src/app/dashboard/auctions/__tests__/ContentReviewDecisionActions.test.tsx          (20)
src/app/dashboard/auctions/__tests__/AuctionReviewDialogRecommendation.test.tsx      (7)
```

### Dashboard — modified files
```
src/app/dashboard/auctions/lib/contentReviewTypes.ts        (confirm key, HumanDecision, 7 codes)
src/app/dashboard/auctions/lib/errors.ts                    (codes + conflict set)
src/app/dashboard/auctions/hooks/useContentReviewMutations.ts (useDecideContentReview)
src/app/dashboard/auctions/components/contentReview/AiReviewPanel.tsx
src/app/dashboard/auctions/components/contentReview/ContentReviewActions.tsx
src/app/dashboard/auctions/components/AuctionReviewDialog.tsx
src/i18n/languages/{ar,en}.json                             (+17 keys each, incl. 7 error labels)
```

### Dashboard key decisions

- **The controls are driven by `available_actions`, never by re-derived client state.** Now that the
  backend only lists `confirm`/`override` while a decision is genuinely pending, the panel renders
  exactly what the server offers. A `needs_human` recommendation renders nothing.
- **The button label follows the recommendation**: "تأكيد توصية الموافقة" vs "تأكيد توصية الرفض",
  and the override button names the opposite decision.
- **The override reason is mandatory client-side too** — submit stays disabled until a non-whitespace
  reason is entered — but the backend is still the authority and its `override_reason_required` is
  rendered in-dialog if it ever fires.
- **Duplicate submits are blocked by a ref, not by `isPending`.** `isPending` only flips on the next
  render, so two clicks in one frame both reached the server; a test pins the single request.
- **403 joins 409/422 as an in-dialog refusal.** `content_review_override_not_allowed` and
  `decision_not_permitted` are decisions the operator must read against fresh data, not a toast that
  vanishes.
- **The manual review dialog now shows the live recommendation and warns before an override.** It
  reads the `ai_review.current` block the detail page already holds — no extra request — and the
  warning only appears for a completed, non-stale approve/reject recommendation that contradicts the
  selected action.

---

## Verification (Batch 7)

Backend (`C:\Users\pc\Desktop\SB\soom`):

- `php artisan test` → **653 passed, 29 skipped, 0 failed** (was 628/25 after Batch 5; +25 tests,
  and 4 of the skips are the new MySQL-only concurrency cases).
- `php artisan test --filter=ContentReview` → 289 passed, 4 skipped.
- `AuctionConfigurationSnapshotTest`, `AuctionFinancialFlowTest`, `AuctionNotificationDispatchTest`,
  `AuctionReopenAndUpdateTest` → 62 passed.
- `composer test:auction:mysql` from a dropped-and-recreated database →
  **361 tests, 26 failing**. Baseline re-measured the same way from a detached
  `git worktree` at `HEAD` (`f3ed298`) → **357 tests, 26 failing**. The failing-name sets are
  **byte-identical** (`Compare-Object` over the sorted junit-derived lists returned nothing). The
  +4 tests are this batch's concurrency cases and they all pass. **The 26 pre-existing data-leak
  failures were not repaired and did not grow.**
- `ContentReviewDecisionConcurrencyMysqlTest` + `AuctionConfigurationSnapshotMysqlTest` +
  `AuctionReopenConcurrencyMysqlTest` → 6 passed. The four races proven with two real OS processes:
  double confirm, confirm vs override, double override, admin vs late AI. In every one exactly one
  worker returns `ok`, the loser returns a stable code, and there is exactly one transition out of
  `pending_review`, one admin decision row, at most one snapshot and at most one seller deposit.
- Pint → **PASS on all 80 files** this batch created or modified.

Dashboard (`C:\Users\pc\Desktop\SB\soom-dashboard`):

- `npx tsc --noEmit` → clean (exit 0).
- `npx next lint` on every new/modified file → no errors, no warnings.
- `npx vitest run` → **13 files, 144 tests, 0 failures** (was 11 files / 117 tests).
- `npm run build` → succeeds, 20 routes.

---

## Batch 8 — what shipped

### Backend — new files
```
database/migrations/2026_08_09_100000_create_content_review_image_checks_table.php   (M5)
database/migrations/2026_08_09_100100_add_image_review_to_content_reviews_table.php  (M6)
app/Domain/ContentReview/Enums/ImageCheckVerdict.php
app/Models/ContentReview/ContentReviewImageCheck.php
app/Repositories/ContentReview/ContentReviewImageCheckRepository.php
app/DTO/ContentReview/PreparedImage.php
app/DTO/ContentReview/ImageCheckResult.php
app/DTO/ContentReview/ImageReviewSummary.php
app/Services/ContentReview/Support/ContentReviewImageScreener.php
app/Services/ContentReview/Support/AutomationEligibilityResolver.php
tests/Feature/ContentReview/ImageCacheTest.php                        (17)
tests/Feature/ContentReview/AutomationEligibilityTest.php             (22)
tests/Feature/ContentReview/AiAutomaticApproveTest.php                (15)
tests/Feature/ContentReview/AiAutomaticRejectTest.php                 (13)
tests/Feature/ContentReview/ContentReviewDefaultsTest.php              (5)
tests/Feature/ContentReview/ContentReviewAutomationApiTest.php         (7)
tests/Feature/Auction/ContentReviewAutomationConcurrencyMysqlTest.php  (5, MySQL only)
```

### Backend — modified files
```
app/Services/ContentReview/Support/ContentReviewImageLoader.php      (select + prepare, replaces load)
app/Services/ContentReview/Support/ReviewPromptRenderer.php          (IMAGES section, image_checks schema)
app/Services/ContentReview/Support/StructuredReviewResultValidator.php (image_checks)
app/Services/ContentReview/Support/ContentReviewDecisionEngine.php   (approval-only blocker gate)
app/Services/ContentReview/Actions/ProcessContentReviewAction.php    (image plan, cache, summary)
app/Services/ContentReview/Actions/DecideContentReviewAction.php     (eligibility resolver)
app/Services/Auction/ContentReview/AuctionReviewSubjectAdapter.php   (media content_revision)
app/DTO/ContentReview/{AutomationContext,StructuredReviewResult,ProviderReviewRequest}.php
app/Models/ContentReview/ContentReview.php                           (image_review)
app/Http/Resources/ContentReview/ContentReviewResource.php           (automation + image_analysis)
app/Http/Controllers/ContentReview/ContentReviewController.php       (withAutomation on 3 endpoints)
app/Services/ContentReview/Providers/AnthropicContentReviewProvider.php (image ref labels)
lang/{ar,en}/content_review.php                                      (automation_reasons, image_*)
tests/Feature/ContentReview/Concerns/BuildsContentReviewFixtures.php (real image bytes, helpers)
tests/Feature/ContentReview/ContentReviewPipelineTest.php            (real image bytes, image_checks)
```

### The image cache — how it actually works

The pipeline makes **one** provider call per attempt, so a separate per-image call would have doubled
the cost for no extra signal. Instead the result schema gained `image_checks[]`, keyed by a label the
request itself assigns (`img-1`, `img-2`, …) to each *attached* image. The flow is:

1. `ContentReviewImageLoader::select()` picks images **by position only** — sorted by
   `(sort_order, original index)`, first `min(policy.max_images, config.max_images)`. Selection never
   depends on whether an image turns out to be readable, so the same four images are chosen every
   time and a broken one is *reported* rather than silently swapped out.
2. `prepare()` reads the bytes, verifies the real type with `getimagesizefromstring` (a declared MIME
   that disagrees with the bytes is `mime_mismatch`), fingerprints the **original** bytes with
   SHA-256, and downscales to `image_max_edge_px` with GD when the extension is present. The original
   file is only ever read; nothing is written, so there is no temp file to clean up.
3. `ContentReviewImageScreener::cached()` looks the fingerprints up; hits are **not attached** to the
   request — their stored verdict is passed to the prompt as text instead.
4. Only the misses are attached, each preceded by a `IMAGE img-N` text block.
5. `record()` stores one row per newly scored image with `insertOrIgnore`, then re-reads.

**The cache key is `(image_sha256, provider, model, policy_version, result_schema_version)`, not
`image_sha256` alone.** The same picture judged by a different model, or under a different published
policy, is a different judgement — reusing it would silently serve a verdict the current
configuration never produced. `policy_version` is stored as `0` rather than `NULL` when a review runs
without a published policy, because MySQL treats every `NULL` as distinct and the unique index would
stop being unique. Over-invalidation is the cheap direction: the cost of a wrong key is one wasted
analysis, the cost of a stale hit is a wrong automatic decision.

`cost_micros` is stored as **NULL**. A single aggregate call cannot attribute a per-image cost, and
inventing a share would be a fabricated number. What *is* measurable is that a cached image is never
sent, so it consumes no input tokens at all — `image_review.counts` records `sent`, `analyzed` and
`cache_hits` separately.

### Key decisions

- **`AutomationEligibilityResolver` owns every gate, and each one has its own reason code.** The
  decision engine stays pure (the architecture test still forbids `DB::`/`config(`/`Cache::` in it),
  so the resolver runs in `DecideContentReviewAction` and hands the engine an `AutomationContext`.
  Gates: master kill switch → `content_review_disabled`; frozen mode; current mode; subject type;
  `current_marker`/superseded/cancelled; status not completed; missing recommendation; a failed hard
  deterministic rule; subject no longer reviewable; content unavailable; content hash moved; the
  subject's own rules (category allowlist, value cap, required images, terms/configuration version);
  image preparation failures; unscreened images; open circuit; exhausted budget. Any one of them and
  the outcome is `escalated_to_human` — never an attempted decision.
- **`AutomationContext` gained `approvalBlockers`, separate from `reasons`.** A *flagged* image is a
  complete analysis with a negative finding: it must stop an automatic **approval**, but stopping an
  automatic **rejection** would be backwards. An *unscreened* or *unpreparable* image is an
  incomplete analysis and blocks both directions. `AiAutomaticRejectTest` pins both halves.
- **The content hash now moves when an image is replaced in place.** `AuctionMedia` has no checksum
  column, and hashing every image's bytes inside `buildContent()` would mean re-reading object
  storage on every staleness check — including on ordinary dashboard reads. The hashable therefore
  carries the media row's `updated_at` as `content_revision`, which catches every in-app replacement
  for free, while the *cache* fingerprint is the real bytes SHA-256 computed once at analysis time.
  So "changed image bytes invalidate the cache" is guaranteed at the layer that has the bytes.
- **`ContentReviewResource` exposes `automation` only where it was asked for.** Resolving eligibility
  costs a `buildContent()` plus a hash per subject; the review queue must stay at a constant query
  count (`AdminAuctionQueryContentReviewTest` pins that). The block is opt-in via
  `->withAutomation()`, set on `show`, `current` and `decide` only, and a test asserts the list
  payload does not carry it.
- **Existing fixtures now write a real 4×4 JPEG to a faked disk.** Before this batch the auction
  fixtures attached media rows pointing at files that did not exist, and the old loader silently
  skipped unreadable images — so the automatic tests were passing *through* a hole that Batch 8
  closes. Four `ContentReviewPipelineTest` cases had to be given real bytes and an `image_checks`
  payload; nothing about their assertions was weakened.
- **M6 adds one nullable JSON column.** The plan's "zero changes to existing tables" note covered
  M1–M5, but requirement 15 asks the dashboard to show analyzed vs cache-hit counts, and those cannot
  be derived from the cache table (which is deliberately not linked to a review). `content_reviews.
  image_review` is additive, nullable and reversible, and older attempts render from `total`/
  `analyzed` alone.

### Dashboard — new files
```
src/app/dashboard/auctions/components/contentReview/ContentReviewAutomation.tsx
src/app/dashboard/auctions/__tests__/ContentReviewAutomation.test.tsx          (11)
src/app/dashboard/auctions/__tests__/contentReviewAutomaticSettings.test.tsx    (8)
```

### Dashboard — modified files
```
src/app/dashboard/auctions/lib/contentReviewTypes.ts        (automation, image checks/failures)
src/app/dashboard/auctions/lib/useContentReviewLabels.ts    (3 label groups)
src/app/dashboard/auctions/components/contentReview/AiReviewPanel.tsx
src/app/dashboard/auctions/settings/components/ContentReviewSettingsForm.tsx
src/app/dashboard/auctions/__tests__/contentReviewFixtures.tsx
src/i18n/languages/{ar,en}.json                             (+21 keys each)
```

### Dashboard key decisions

- **Eligibility is fetched, the image summary is not.** The enriched `image_analysis` already rides
  on the embedded `ai_review.current`, so it renders with no extra request. `automation` exists only
  on the single-review endpoints, so the panel calls `useCurrentContentReview` — and only when
  `mode === "ai_automatic"` and an attempt exists, so nothing changes for the other three modes. The
  block renders only if the fetched attempt id matches the one on screen.
- **Nothing about automatic mode happens silently.** Publishing settings with `mode = ai_automatic`
  *and* `enabled = true` adds a destructive-styled warning to the confirm step plus a type-to-confirm
  input: the operator must retype `ai_automatic` before the publish button leaves its disabled state,
  and stepping back clears it. A badge distinguishes three states — not eligible, eligible, and
  eligible-but-approval-blocked.
- **Reason codes are translated client-side**, like every other content-review label: the tests plant
  `"SERVER SIDE LABEL"` on the API payload and assert it never reaches the DOM.

---

## Verification (Batch 8)

Backend (`C:\Users\pc\Desktop\SB\soom`):

- `php artisan test` → **732 passed, 34 skipped, 0 failed** (was 653/29 after Batch 7; +79 tests,
  and 5 of the new skips are the MySQL-only automation concurrency cases).
- `php artisan test --filter=ContentReview` → 289 passed before this batch, 373 passed after.
- Targeted run of the decision engine, provider contract, image cache, snapshot, financial flow,
  assisted/override and notification suites → **191 passed**.
- `composer test:auction:mysql` from a dropped-and-recreated database → **366 tests, 26 failing**.
  The 26 are the same pre-existing cross-test data-leak failures documented above (list/filter and
  resource-shape tests); the failing names were extracted from the junit log and **not one belongs to
  Batch 8 or to the content-review subsystem**. The +5 tests over Batch 7's 361 are this batch's
  concurrency cases and they all pass.
- `--group=mysql-concurrency` → **30 passed**, including the five new races: two automatic workers on
  one review, automatic-approve vs admin-reject, automatic-reject vs admin-approve, a stale automatic
  result against a content change, and two processes caching the same image fingerprint. Exactly one
  domain decision applies in every case, and the image race leaves exactly one row.
- Pint → **PASS on all 110 files** this batch created or modified. The 11 style issues Pint still
  reports project-wide are in untouched pre-existing migrations.

Dashboard (`C:\Users\pc\Desktop\SB\soom-dashboard`):

- `npx tsc --noEmit` → clean (exit 0).
- `npx next lint` on every new/modified file → no errors, no warnings.
- `npx vitest run` → **15 files, 163 tests, 0 failures** (was 13 files / 144 tests).
- `npm run build` → succeeds, 20 routes.

### Production safety after Batch 8

`CONTENT_REVIEW_ENABLED` is still `false`, the seeded settings are still `enabled = false` /
`mode = manual`, and the seeded policy still carries `auto_reject_categories = []`.
`ContentReviewDefaultsTest` pins all four, and `ReviewModeResolver` collapses to `manual` whenever the
master switch is off no matter what is published. The capability exists; nothing turns it on.
The intended order is unchanged: **Manual → Shadow → Assisted → a narrow Automatic**.

---

## Batch 9 — what shipped

### Backend — new files
```
database/migrations/2026_08_11_100000_add_observability_columns_to_content_reviews_table.php  (M7)
app/DTO/ContentReview/MetricsRange.php
app/Repositories/ContentReview/ContentReviewMetricsRepository.php
app/Services/ContentReview/Support/ContentReviewLogContext.php
app/Services/ContentReview/Support/ContentReviewMetricsReporter.php
app/Services/ContentReview/Support/ContentReviewAlertMonitor.php
app/Services/ContentReview/Support/ContentReviewWorkerHeartbeat.php
app/Services/ContentReview/Actions/BackfillContentReviewsAction.php
app/Services/ContentReview/Actions/ReconcileContentReviewsAction.php
app/Http/Requests/ContentReview/ContentReviewMetricsRequest.php
app/Http/Controllers/ContentReview/ContentReviewMetricsController.php
app/Console/Commands/ContentReview/BackfillContentReviews.php
app/Console/Commands/ContentReview/ReconcileContentReviews.php
app/Console/Commands/ContentReview/SweepContentReviewAlerts.php
tests/Feature/ContentReview/LogRedactionTest.php                (5)
tests/Feature/ContentReview/AlertRateLimitTest.php              (8)
tests/Feature/ContentReview/ContentReviewMetricsApiTest.php    (16)
tests/Feature/ContentReview/BackfillCommandTest.php             (8)
tests/Feature/ContentReview/ReconciliationTest.php             (10)
tests/Feature/ContentReview/KillSwitchTest.php                  (8)
AI_CONTENT_REVIEW_RUNBOOK.md
```

### Backend — modified files
```
config/content_review.php                       (metrics, alerts thresholds, heartbeat, backfill,
                                                 reconcile blocks; content_review.metrics.view)
routes/api/content_review.php                   (GET /admin/content-review/metrics)
routes/console.php                              (content-review:sweep-alerts every five minutes)
app/Policies/ContentReview/ContentReviewPolicy.php               (viewMetrics)
app/Domain/ContentReview/Enums/ReviewTrigger.php                 (Backfill)
app/Models/ContentReview/ContentReview.php                       (2 new columns)
app/Repositories/ContentReview/ContentReviewRepository.php       (queue_delay_ms on lease,
                                                                  reconcile + backfill queries)
app/Repositories/ContentReview/ContentReviewDecisionRepository.php  (orphanCount)
app/Services/ContentReview/Support/ContentReviewHealthReporter.php  (recent rates, heartbeat,
                                                                     unpriced counts, utilization)
app/Services/ContentReview/Actions/ProcessContentReviewAction.php   (structured logging, cache hits)
app/Services/ContentReview/Actions/RequestContentReviewAction.php   (mode ceiling)
app/Services/ContentReview/Contracts/ReviewSubjectAdapter.php       (reviewableSubjectIds)
app/Services/Auction/ContentReview/AuctionReviewSubjectAdapter.php  (reviewableSubjectIds)
app/Services/ContentReview/Notifications/ContentReviewNotificationCatalog.php  (4 events, scopes)
app/Services/ContentReview/Notifications/AdminAlertRecipientResolver.php       (scoped cooldown)
app/Services/Outbox/ContentReviewOutboxNotifier.php                            (alert scope key)
app/Jobs/ContentReview/ProcessContentReviewJob.php                             (heartbeat)
lang/{ar,en}/content_review.php                 (alerts group, backfill trigger, 4 notifications)
tests/Unit/ContentReview/EnumContractTest.php   (permission count 9 → 10)
.env.example · README.md · AUCTION_DEPLOYMENT_AND_OPERATIONS.md
AI_CONTENT_REVIEW_IMPLEMENTATION_PLAN.md        (execution status table + Batch 9 "as built")
```

### The new endpoint
```
GET /api/admin/content-review/metrics            content_review.metrics.view
    ?range=today|7d|30d  or  ?date_from=Y-m-d&date_to=Y-m-d  (capped at 92 days)
    cost block additionally requires content_review.costs.view
```

### Key decisions

- **M7 adds two columns rather than deriving the numbers.** Queue delay was only expressible as
  `started_at − queued_at`, whose SQL differs between sqlite and MySQL, and the image cache hit
  count lived inside the `image_review` JSON. Both would have forced either dialect-specific raw SQL
  or a JSON scan on every metrics request. `queue_delay_ms` is written once when the worker first
  leases the review; `image_cache_hits` is written beside the existing `images_analyzed`. Both are
  additive, nullable/defaulted and reversible, and both make their metric an ordinary indexed
  aggregate.
- **Percentiles are exact, not sampled.** One `COUNT` plus one `OFFSET … LIMIT 1` read per
  percentile, bounded by the requested range. Sampling by taking the first N rows would have biased
  p95 toward whatever the insertion order happened to be, and ordering-then-truncating would have
  cut off the exact tail p95 measures. The whole aggregate block is cached for
  `CONTENT_REVIEW_METRICS_CACHE_SECONDS` (60 by default), so a dashboard refresh costs nothing.
- **`cost_micros = NULL` is never read as zero.** A completed review whose model is absent from the
  pricing table is counted as `unpriced_reviews`, and both the range block and each budget period
  carry `totals_complete`. The dashboard renders an explicit warning and the runbook says the totals
  are a lower bound. Folding a null into a `SUM` as zero would have quietly understated spend
  exactly when the pricing table is the thing that is wrong.
- **A tenth permission, `content_review.metrics.view`.** Volume, cost trend and override rate are a
  different disclosure from "read this one review", and requirement 2 asked for an independent
  permission. It is absent from `role_admin_permissions`, so no admin inherits it. The cost sub-block
  still needs `content_review.costs.view` on top.
- **Alerts are edge-triggered with an explicit recovery event.** State lives in the cache per alert
  code: an alert publishes once when the condition turns on, again only after
  `CONTENT_REVIEW_ALERT_REPEAT_SECONDS` while it stays on, and exactly one
  `content_review.recovered` when it clears. Publishing on every sweep would have meant a
  notification every five minutes for a single open circuit.
- **The notification catalogue gained a third scope.** Cooldowns were keyed per subject or globally
  per event; `content_review.recovered` is one event type shared by six alert codes, so two alerts
  clearing within the cooldown would have produced one notification. `SCOPE_ALERT` keys the cooldown
  on `(event, alert_code)`. `isSubjectScoped()` still answers the same question for existing callers.
- **`ContentReviewAlertMonitor::states()` is read-only and `sweep()` is the only writer.** The
  metrics endpoint renders alert state on every request; if reading it could publish, every
  dashboard visit would be a potential notification source. Only the scheduled command sweeps.
- **The worker heartbeat records what happened, not what is true now.** `ProcessContentReviewJob`
  stamps `content_review:worker:last_job_at` when it starts. The API exposes
  `last_job_processed_at` and `seconds_since_last_job` and nothing else — there is no
  `worker_online` field anywhere, because an empty queue is not evidence of a running worker and
  this system has no way to prove one exists. The dashboard prints "never observed" for a null and
  carries a note saying exactly that. A test pins it.
- **The backfill caps the mode instead of forcing it.** `RequestContentReviewAction` gained an
  optional `$modeCeiling` that only ever *lowers* the resolved mode. So a platform in
  `ai_automatic` produces shadow reviews for historical content — analyzed, never auto-decided —
  and a platform in `manual` still produces nothing at all, even with `--execute`. There is no flag
  that raises the ceiling. `--execute` is additionally refused while the kill switch is off, and any
  subject that already has a review row of any kind is skipped.
- **Reconciliation repairs two things and reports four.** Releasing an expired lease and clearing
  `current_marker` on a cancelled or superseded row are both idempotent and strictly narrower than a
  decision. Reviews queued past the stale window, completed rows with no application state, subjects
  with more than one active review, and decisions with no review row are counted and printed for an
  admin — guessing at any of them would mean the reconciler applying a verdict.
- **Neither new command is scheduled**, and both default to a dry run. Two tests assert their names
  do not appear in `routes/console.php`.
- **`ContentReviewLogContext` is an allowlist, not a denylist.** It builds the fifteen §28 review
  fields itself and intersects any extra context against a fixed key list, dropping non-scalars.
  `LogRedactionTest` plants `api_key`, `prompt`, `raw_response.chain_of_thought` and the content hash
  and asserts none of them survives, then runs a real review and asserts no log record anywhere
  contains the auction title, description, hash or the planted `sk-ant-leak-canary`.
- **`KillSwitchTest` was added even though it is not new behaviour.** Requirement 14 asked for the
  guarantee to be tested explicitly, and it had only ever been covered indirectly. It pins all eight
  properties, including the one that matters most: a review already in flight when the switch is
  thrown finishes its analysis and escalates to a human rather than applying an automatic decision.

### Dashboard — new files
```
src/app/dashboard/auctions/settings/components/ContentReviewMetricsTab.tsx
src/app/dashboard/auctions/__tests__/contentReviewMetrics.test.tsx          (15)
```

### Dashboard — modified files
```
src/app/dashboard/auctions/lib/contentReviewTypes.ts      (metrics, alerts, queue snapshot,
                                                            recent rates, budget additions)
src/app/dashboard/auctions/lib/constants.ts               (metrics key, ranges, percentLabel)
src/app/dashboard/auctions/lib/useContentReviewLabels.ts  (alerts group)
src/app/dashboard/auctions/hooks/useContentReviewQueries.ts  (useContentReviewMetrics)
src/app/dashboard/auctions/settings/components/ProviderHealthCard.tsx  (recent rate, heartbeat)
src/app/dashboard/auctions/settings/page.tsx              (Metrics tab)
src/app/dashboard/auctions/__tests__/contentReviewFixtures.tsx  (metrics fixtures)
src/i18n/languages/{ar,en}.json                           (+57 keys each)
```

### Dashboard key decisions

- **KPIs first, detail after.** Six tiles — reviews, provider success, escalation rate, automatic
  decision rate, p95 duration, override rate — then the active alerts, then volume, reliability,
  queue, human decisions and finally cost. No charts: every number here is a scalar or a rate, and a
  bar chart of six statuses would carry less information than the six numbers.
- **Only active alerts render.** A cleared alert is not news; when none is firing the strip says so
  in one line.
- **The metrics query does not poll.** `refetchInterval: false`, a 60 s stale time, an explicit
  refresh button, and `keepPreviousData` so switching range does not blank the screen. The server
  already caches the aggregate for a minute; a self-refreshing dashboard would only defeat it.
- **Permission gating stays "the key is absent".** No `cost` block in the payload ⇒ no cost card. A
  403 on the metrics query renders `PermissionDenied`, not a retryable error.
- **Alert and failure codes are translated client side**, like every other content-review label.

---

## Verification (Batch 9)

Backend (`C:\Users\pc\Desktop\SB\soom`):

- `php artisan test` → **787 passed, 34 skipped, 0 failed** (was 732/34 after Batch 8; **+55 tests**).
- `php artisan test --filter=ContentReview` → 368 passed, 9 skipped before this batch's additions.
- `composer test:auction:mysql` from a dropped-and-recreated database → **366 tests, 26 failing**,
  byte-identical to the documented baseline. The 26 are the same pre-existing cross-test data-leak
  failures (list/filter and resource-shape tests); the failing names were extracted from the junit
  log and **not one belongs to Batch 9 or to the content-review subsystem**. Saved to the session
  scratchpad as `batch9.failing.txt`.
- `--group=mysql-concurrency` → **30 passed**, unchanged from Batch 8.
- Pint → **PASS on all 40 files** this batch created or modified. The style issues Pint still reports
  project-wide are in untouched pre-existing files.

Dashboard (`C:\Users\pc\Desktop\SB\soom-dashboard`):

- `npx tsc --noEmit` → clean (exit 0).
- `npx next lint` on every new/modified file → no errors, no warnings.
- `npx vitest run` → **16 files, 178 tests, 0 failures** (was 15 files / 163 tests).
- `npm run build` → succeeds, 20 routes.

### Production safety after Batch 9

Unchanged: `CONTENT_REVIEW_ENABLED=false`, seeded `enabled = false` / `mode = manual`, seeded
`auto_reject_categories = []`. `ContentReviewDefaultsTest` still pins all four and `KillSwitchTest`
now proves the eight guarantees the switch makes. Nothing in this batch turns anything on.

**The plan is complete.** Batches 1–9 are done.
