# AI Content Review — Checkpoint

Last updated: 2026-08-10
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
| 6 — dashboard (read-only + operational UI) | **Done in this session** |
| 7 — `ai_assisted` + override guard | **Not started — next** |
| 8 — `ai_automatic` + image cache | Not started |
| 9 — observability, docs, rollout | Not started |

**The backend was not touched in this session.** `git status` in `C:\Users\pc\Desktop\SB\soom`
is clean; every change lives in `C:\Users\pc\Desktop\SB\soom-dashboard`.

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

## NEXT TASK (start of Batch 7)

Batch 7 is **backend + dashboard together** and was deliberately not started: it does not fit in the
remaining context of this session, and the standing instruction is not to leave a batch half done.

Scope, unchanged:

- Show the AI recommendation to the reviewer at decision time.
- Confirm the recommendation, or decide against it.
- `relation_to_recommendation` = `confirmed` | `overridden`; override reason mandatory.
- Record the real admin (`decided_by_type = admin`, `decided_by_id` = the actual user).
  Never fabricate a user for AI: `actor_type = ai`, `changed_by = null`,
  `decided_by_type = ai`, `decided_by_id = null`.
- A manual decision is final; a late AI result must not change the auction
  (`ApplyContentReviewDecisionAction` already returns early on `decided_at !== null` — extend, do
  not duplicate, that guard).
- Audit + outbox + notifications, confirmation dialogs, conflict handling, permission checks.
- Test the race between an admin decision and an arriving AI result.

Starting points already in place: `content_review.override` exists as a permission and is already
surfaced in `available_actions`; `ContentReviewActions.tsx` filters it out with a comment marking it
as Batch 7; `DecisionRelation` and `ContentReviewDecisionRecord` are fully typed on both sides.
`content_review.confirm` was intentionally never added — confirming is authorised by the existing
`auction.review` / `auction.approve`.

**The overall task is NOT complete.** Batches 7–9 remain.
