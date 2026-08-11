# AI Content Review — Engineering & Implementation Plan

**Status:** approved as the execution reference. The **preliminary phase is complete and green** — see below. Decisions D1-D7 were approved by the product owner; the notes recorded against each of them at the end of this document are binding.

**Preliminary Phase (done, shipped before Batch 1).** The rejected-auction dead end is closed. `POST /api/soom/auctions/{auction}/reopen` moves `rejected → draft` over the existing state-machine edge, and `PATCH /api/soom/auctions/{auction}` edits draft auctions only. The supported loop is `rejected → reopen → draft → update → submit-review → pending_review`. No auction status was added and `AuctionStateMachine::ALLOWED` was not modified. Covered by `AuctionReopenAndUpdateTest` (16 tests) and `AuctionReopenConcurrencyMysqlTest` (a real two-process race).
**Backend root:** `C:\Users\pc\Desktop\SB\soom` (Laravel 12, PHP 8.2, API-only, Sanctum)
**Dashboard root:** `C:\Users\pc\Desktop\SB\soom-dashboard` (Next.js 15 App Router, React 19, TanStack Query v5, next-intl `ar` default / RTL)

Every claim in §2 and §4 was verified by opening the referenced file at the referenced line during this study. Where the project documentation and the code disagreed, **the code, the database schema, and the tests won**.

---

## Context

Today an auction is approved or rejected entirely by an admin employee. The seller calls `POST /api/soom/auctions/{auction}/submit-review`, the auction moves to `pending_review`, and an admin calls `POST /api/admin/auctions/{auction}/review` with `{action, reason}`. That endpoint is the *only* review gate in the product, it is fully synchronous, and it carries real financial consequences — approval mints an immutable configuration snapshot and creates a seller-deposit obligation.

We are adding an AI review layer whose **first** consumer is auction pre-approval, architected so the same machinery can later review ads, product descriptions, images, and documents without a rewrite — while never letting a provider outage, a malformed response, or a cost spike degrade or block the auction pipeline. **The safe default in every failure mode is human review, never auto-approval.**

---

## 1. Executive Summary

**What is added.** A self-contained `ContentReview` subsystem (`App\Domain\ContentReview`, `App\Services\ContentReview`, `App\Models\ContentReview`, …) that builds a normalized, hashed snapshot of a piece of content; runs deterministic rule checks; asks a pluggable AI provider for a **structured** verdict; validates that verdict against a closed schema; feeds it to a pure **Decision Engine** governed by a **versioned policy**; and then either records an advisory recommendation or applies an automatic decision through a **subject adapter** — the only class permitted to touch the auction domain.

**Three admin-controllable modes**, resolved per subject type from versioned DB settings with an `.env` master kill switch: `manual` (today's behaviour, unchanged), `ai_assisted` (AI recommends, admin decides), `ai_automatic` (AI may approve/reject when confidence *and* policy *and* eligibility allow; everything else escalates). A fourth internal mode, `shadow`, runs the AI with provably zero effect on decisions — used for rollout and accuracy measurement.

**Four verified facts that shape the whole design:**

1. `auction_status_history.changed_by` is `nullable()->constrained('users')->nullOnDelete()` and `actor_type` is `string(20)` defaulting to `'system'` — recording the AI as an actor needs **no migration on any existing table**. Verified: `database/migrations/2025_06_12_090000_create_auctions_table.php:425-437` and `app/Repositories/Auction/AuctionAuditRepository.php:68-87` (which already accepts `?int $actorId`).
2. `auction_configuration_snapshots.created_by` is `nullable()` with `nullOnDelete()` — an AI-approved auction can mint the immutable snapshot with a null creator. Verified: `database/migrations/2026_07_13_060000_create_auction_configuration_snapshots.php:38,48`.
3. **A rejected auction can now be corrected and resubmitted.** The preliminary phase (shipped before Batch 1) added `POST /api/soom/auctions/{auction}/reopen` (`rejected → draft`) and `PATCH /api/soom/auctions/{auction}` (draft-only edit), closing the gap that previously made rejection a dead end. Auction content is therefore **mutable while in `draft`**, which makes content hashing a live correctness requirement rather than a theoretical one: an auction that was reviewed, rejected, edited, and resubmitted must never be judged by the result of the previous version.
4. The project already owns every primitive we need: a transactional **outbox** (`outbox_messages` + `AuctionOutboxRepository::leaseNextPending`), an **append-only versioned settings** pattern (`auction_configuration_versions` + `CreateConfigurationVersionAction`), an **immutable hashed snapshot** pattern (`AuctionConfigurationSnapshotHasher`), a **provider-interface + adapter + container binding** pattern (`AuctionRefundProcessorInterface` → `ManualReviewRefundProcessor`, bound at `AppServiceProvider:35`), a deadlock-retrying **transaction runner** (`AuctionTransaction`), and a **`current_marker`** uniqueness idiom (`AuctionSettlement`). We reuse all six rather than inventing new ones.

**Blast radius on existing code is deliberately tiny:** one behaviour-preserving refactor (`ReviewAuctionAction` gains transaction-free `…Locked()` entry points), one type-hint in `DispatchOutboxMessagesAction`, one additive block in `AdminAuctionResource`, one hook at the end of `SubmitAuctionForReviewAction`, plus wiring in `AppServiceProvider`, `routes/api.php`, and `routes/console.php`. Everything else is new files.

---

## 2. Current Auction Review Flow (traced file by file)

### 2.1 Seller submits

```
POST /api/soom/auctions/{auction}/submit-review
  routes/api/auction.php:52   (group: auth:sanctum, role:admin,user, AttachServerTime, prefix soom/auctions)
  → AuctionController::submitForReview           app/Http/Controllers/Auction/AuctionController.php:148-153
      Gate::authorize('submitForReview', $auction)
      → AuctionPolicy::submitForReview           app/Policies/Auction/AuctionPolicy.php:32-36
          seller only, status ∈ {Draft, Rejected}
  → SubmitAuctionForReviewAction::execute        app/Services/Auction/Actions/SubmitAuctionForReviewAction.php:22-47
      AuctionTransaction->run(...)               app/Services/Auction/Support/AuctionTransaction.php:13-36
        AuctionRepository::lockForStateChange    app/Repositories/Auction/AuctionRepository.php:35-38  (SELECT … FOR UPDATE)
        guards: seller_only_submit_review | invalid_auction_times | active_terms_required
        AuctionStateMachine::transition(→ PendingReview, actorId=$sellerId, actorType='user',
                                        reason=__('auction.audit.seller_submitted_review'))
```

There is **no FormRequest** and no request body on this endpoint. `{auction}` binds by `public_id` via `app/Models/Auction/Concerns/HasPublicId.php`.

Two sibling seller endpoints were added by the preliminary phase and sit in the same route group
(`routes/api/auction.php:53-54`):

```
PATCH /api/soom/auctions/{auction}
  → AuctionController::update            Gate::authorize('update')  → AuctionPolicy::update (seller only)
  → UpdateDraftAuctionRequest            every field `sometimes`; cross-checks amounts, currency, schedule
  → UpdateDraftAuctionAction             AuctionTransaction → lockForStateChange → status must be Draft
                                         (else AuctionException::domain('auction_not_editable'))
                                         → atomic media replacement → activity log `auction.updated`
                                           carrying only the changed field names

POST /api/soom/auctions/{auction}/reopen
  → AuctionController::reopen            Gate::authorize('reopen')  → AuctionPolicy::reopen (seller only)
  → ReopenRejectedAuctionAction          AuctionTransaction → lockForStateChange → status must be Rejected
                                         (else AuctionException::domain('auction_not_reopenable'))
                                         → transition(→ Draft, actorId=sellerId, actorType='user')
```

Neither endpoint enqueues a review, and neither submits the auction — the seller must call
`submit-review` explicitly, which is what produces a fresh `content_reviews` row.

### 2.2 State machine

`app/Services/Auction/Support/AuctionStateMachine.php`

- `ALLOWED` (L14-77): `draft → [pending_review, cancelled]`; `pending_review → [rejected, awaiting_seller_deposit, scheduled, cancelled]`; `rejected → [draft, cancelled]`; `awaiting_seller_deposit → [scheduled, cancelled]`.
- `NOTIFIABLE_STATUSES` (L82-92): `pending_review, rejected, awaiting_seller_deposit, scheduled, live, ended, handover_pending, completed, unsold`.
- `transition()` (L96-144): guard (L108, throws `AuctionException::invalidTransition`) → no-op when `from === to` (L112) → timestamp side effects via `match` (L119-129; **nothing is set for `PendingReview` or `Rejected`**) → `forceFill()->save()` → `audit->statusChanged(...)` → if target ∈ `NOTIFIABLE_STATUSES` then `audit->outbox('auction.status_changed', …)` → `$auction->refresh()`.
- The signature is **already actor-agnostic**: `transition(Auction $auction, AuctionStatus $to, ?int $actorId, string $actorType, ?string $reason = null, array $metadata = [])`. No change is needed here to record an AI actor.

**Gap found, and closed before Batch 1.** `AuctionPolicy::submitForReview` permits status `Rejected`, but `ALLOWED['rejected']` contains only `[draft, cancelled]`, and until the preliminary phase no endpoint performed `rejected → draft`, so resubmission was unreachable. The preliminary phase added the `reopen` endpoint, which uses the **existing** `rejected → draft` edge (no state-machine change), plus a draft-only `PATCH` edit endpoint. The supported correction loop is now:

```
rejected ──reopen──► draft ──PATCH update──► draft ──submit-review──► pending_review
```

`AuctionStateMachine::ALLOWED` was **not** modified: `rejected → pending_review` is still not a legal
direct edge, and the loop deliberately routes through `draft` so every correction cycle is visible in
`auction_status_history`. This is the flow the AI review system attaches to.

### 2.3 Admin decides

```
POST /api/admin/auctions/{auction}/review        routes/api/auction.php:93 (group L69: auth:sanctum, role:admin)
  ReviewAuctionRequest                           app/Http/Requests/Auction/ReviewAuctionRequest.php
      authorize(): user()->role === 'admin'
      rules(): action => required|in:approve,reject ; reason => required|string|max:1000
  → AuctionController::review                    app/Http/Controllers/Auction/AuctionController.php:155-165
      Gate::authorize($action === 'approve' ? 'approve' : 'review', $auction)
        AuctionPolicy::approve → permission 'auction.approve'   (AuctionPolicy.php:43-46)
        AuctionPolicy::review  → permission 'auction.review'    (AuctionPolicy.php:38-41)
  → ReviewAuctionAction::approve|reject          app/Services/Auction/Actions/ReviewAuctionAction.php
```

**`approve(Auction $auction, int $adminId, string $reason)` — L32-66**, entirely inside `AuctionTransaction->run()`:

1. `lockForStateChange($auction->id)` (L35)
2. `AuctionConfigurationSnapshotRepository::createForApprovedAuction($auction, $adminId)` (L36) — idempotent; locks the `AuctionConfigurationVersion`, builds via `AuctionConfigurationSnapshotFactory`, validates via `…Validator`, hashes via `…Hasher`
3. if `wasRecentlyCreated` → `audit->log('auction_configuration_snapshot_created', $auction, $adminId, 'admin', {source_version_id, snapshot_id, snapshot_hash})` (L37-43)
4. `AuctionConfigurationSnapshotReader::forAuction($auction)` (L44) — re-validates and re-hashes (tamper detection)
5. `createSellerDepositObligation()` (L45, body L68-82) → `AuctionDepositRepository::firstOrCreateDeposit`, `type='seller'`, `status=PendingSubmission`
6. target = `seller_deposit_required_minor > 0 ? AwaitingSellerDeposit : Scheduled` (L47-49)
7. if target is `AwaitingSellerDeposit` and `seller_deposit_due_at === null` → set from `$snapshot->sellerDepositDeadlineMinutes()` (L51-56)
8. `stateMachine->transition($auction, $targetStatus, $adminId, 'admin', $reason)` (L58-64)

**`reject(Auction $auction, int $adminId, string $reason)` — L84-95:** `trim($reason) === ''` → `AuctionException::domain('rejection_reason_required')`; then transaction → lock → `transition(→ Rejected, $adminId, 'admin', $reason)`. No snapshot, no deposit.

### 2.4 What is actually persisted about a review

**There is no `reviewed_by`, `reviewed_at`, `approved_at`, `rejected_at`, or `rejection_reason` column on `auctions`.** The entire review record is a single `auction_status_history` row (`create_auctions_table.php:425-437`):

```php
$table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
$table->string('from_status', 40)->nullable();
$table->string('to_status', 40);
$table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
$table->string('actor_type', 20)->default('system');
$table->string('reason')->nullable();
$table->json('metadata')->nullable();
$table->timestampTz('created_at')->useCurrent();
```

Secondary: `auction_activity_logs` (L439-452) receives `auction_configuration_snapshot_created` **on approval only**. The `auctions` row itself gains only `status`, `published_at` (state machine, when the target is `Scheduled`), and `seller_deposit_due_at`.

Read APIs: `GET /api/admin/auctions/{auction}/activity` and `.../status-history` (`app/Http/Controllers/Auction/AuctionAuditController.php`, both gated by `viewAny`, `ip_hash` stripped from the response).

### 2.5 Events, outbox, notifications

`AuctionStateMachine:134-141` writes exactly one outbox row per notifiable transition: event `auction.status_changed`, payload `{auction_public_id, from, to, reason}`, `topic='auction.events'`, `aggregate_type = Auction::class`, `event_id = ULID` (unique), `status = pending`.

Dispatch: `DispatchAuctionOutboxJob` (`$tries = 5`) scheduled `everyMinute()->withoutOverlapping()` at `routes/console.php:14` → `DispatchOutboxMessagesAction` → leases in its own `DB::transaction` via `AuctionOutboxRepository::leaseNextPending($workerUlid)` (`lockForUpdate`, 5-minute stale-lock reclaim, `attempts++`) → `AuctionOutboxNotifier::notify()` → `markAsProcessed` / `markAsFailed`.

`AuctionOutboxNotifier:19-27` **hard-rejects** two things:

```php
if (! AuctionNotificationCatalog::supports($message->event_type)) { throw new \RuntimeException(...); }
if ($message->aggregate_type !== Auction::class) { throw new \RuntimeException(...); }
```

Fan-out is decided by `AuctionNotificationCatalog::EVENTS` (L30-73) — `auction.status_changed` is `personal + realtime + public`.

`PersonalDeliveryResolver::statusChanged()` (L89-127) is the exact seller mapping: `pending_review → status.pending_review`; `rejected → status.rejected_with_reason` (or `status.rejected` when no reason); `awaiting_seller_deposit → status.awaiting_seller_deposit`; `scheduled` (only when coming from `pending_review`) → `status.scheduled`; `default => throw new \RuntimeException("Unsupported status_changed target: {$to}")` (L125). **Recipients are seller-only. No admin notification audience exists anywhere in the codebase.**

Delivery: `PersonalNotificationSender` dedupes on `data->event_id` before `$user->notify(new AuctionOutboxNotification(...))` (`via() = ['database','broadcast']`) and dispatches `SendFcmNotification` when the user has an `fcm_token`. Copy is hard-forced to Arabic — `NotificationValueFormatter::LOCALE = 'ar'`.

### 2.6 Permissions

Custom, not Spatie. `users.auction_permissions` (json, nullable) → `app/Policies/Auction/Concerns/ChecksAuctionPermissions.php:11-21`:

```php
$explicit = $user->getAttribute('auction_permissions');
if (is_array($explicit) && in_array($permission, $explicit, true)) { return true; }
return $user->role === 'admin' && in_array($permission, config('auction.admin_permissions', []), true);
```

`config/auction.php:4-24` lists 19 permissions including `auction.review` and `auction.approve`. Policies are registered explicitly in `app/Providers/AppServiceProvider.php:43-50`.

### 2.7 Tests that pin this flow

| File | What it pins |
|---|---|
| `tests/Feature/Auction/AuctionConfigurationSnapshotTest.php` | L47 one immutable snapshot per approval + double-approve idempotency + activity log; L130 an incomplete source version blocks approval |
| `tests/Feature/Auction/AuctionConfigurationSnapshotMysqlTest.php` | L43 two real OS processes racing `approve()` → exactly one snapshot, one seller deposit |
| `tests/Feature/Auction/AuctionFinancialFlowTest.php` | L280 zero seller deposit → `scheduled`; L292 required deposit → `awaiting_seller_deposit` |
| `tests/Feature/Auction/AuctionNotificationDispatchTest.php` | L110 reject → outbox → Arabic seller notification carrying the reason; L186 approve → public announcement without private data |
| `tests/Feature/Auction/AuctionErrorCodeTest.php` | L96-106 the only HTTP-level hit on `/review` (the 403 path) |
| `tests/Feature/Auction/AdminAuctionActivityTest.php` | L75-115 status-history returns `pending_review → rejected` with reason and actor |
| `tests/Unit/Auction/AuctionCodeQualityTest.php` | architecture rules — see §4/C12 |

**Pre-existing coverage gaps:** zero tests for `submit-review`; zero happy-path HTTP tests for `/review`; no test of the `auction.approve` vs `auction.review` permission split.

---

## 3. الملفات والمكونات الحالية المرتبطة بالمراجعة

**Backend — 68 files reviewed.** Every path below was opened or grepped during this study.

*Domain / enums / exceptions* — `app/Domain/Auction/Enums/{AuctionStatus,OutboxStatus,AuctionDepositStatus,SettlementStatus,NextActionCode,BidBlockingReason}.php`; `app/Domain/Auction/Exceptions/{AuctionException,AuctionErrorCodeCatalog,AuctionConfigurationSnapshotImmutableException,AuctionConfigurationVersionInUseException}.php`; `app/Domain/Auction/ValueObjects/Money.php`.

*Support services* (`app/Services/Auction/Support/`) — `AuctionStateMachine.php`, `AuctionAudit.php`, `AuctionTransaction.php`, `AuctionNotificationCatalog.php`, `AuctionConfigurationSnapshotFactory.php`, `AuctionConfigurationSnapshotReader.php`, `AuctionConfigurationSnapshotHasher.php`, `AuctionConfigurationSnapshotValidator.php`, `AuctionMediaService.php`, `AuctionMetricsRecorder.php`.

*Actions* (`app/Services/Auction/Actions/`) — `SubmitAuctionForReviewAction.php`, `ReviewAuctionAction.php`, `CreateAuctionAction.php`, `CreateConfigurationVersionAction.php`, `DispatchOutboxMessagesAction.php`, `ListAdminAuctionsAction.php`, `ProcessAuctionRefundAction.php`, `ReconcileAuctionsAction.php`, `CancelAuctionAction.php`.

*Notifications* (`app/Services/Auction/Notifications/`) — `AuctionOutboxNotifier.php`, `PersonalDeliveryResolver.php`, `PersonalNotificationSender.php`, `AuctionRealtimeBroadcaster.php`, `AuctionAnnouncementBroadcaster.php`, `OutboxPayloadResolver.php`, `NotificationValueFormatter.php`; `app/Notifications/AuctionOutboxNotification.php`.

*HTTP* — `app/Http/Controllers/Auction/{AuctionController,AuctionAuditController,AuctionConfigurationController,AuctionOperationalSettingsController,PaymentSubmissionController}.php`; `app/Http/Requests/Auction/{ReviewAuctionRequest,AdminAuctionIndexRequest,CreateConfigurationVersionRequest}.php`; `app/Http/Resources/Auction/{AdminAuctionResource,UserAuctionResource,AuctionMediaResource,MoneyResource,Concerns/EmitsStableKeys}.php`; `app/Http/Middleware/RoleMiddleware.php`; `app/Traits/ApiResponseTrait.php`; `app/Http/Responses/ApiErrorResponse.php`.

*Persistence* — `app/Models/Auction/{Auction,AuctionStatusHistory,AuctionActivityLog,OutboxMessage,AuctionConfigurationSnapshot,AuctionConfigurationVersion,AuctionMedia,AuctionSettlement,Concerns/HasPublicId}.php`; `app/Models/User.php`; `app/Repositories/Auction/{AuctionRepository,AuctionAuditRepository,AuctionOutboxRepository,AuctionConfigurationSnapshotRepository,AuctionConfigurationRepository,AuctionDepositRepository,Queries/AdminAuctionQuery}.php`.

*Policies / providers / infrastructure* — `app/Policies/Auction/AuctionPolicy.php`, `app/Policies/Auction/Concerns/ChecksAuctionPermissions.php`; `app/Providers/AppServiceProvider.php`; `app/Services/Auction/Refunds/{AuctionRefundProcessorInterface,ManualReviewRefundProcessor}.php`; `app/DTO/Auction/{BaseAuctionDTO,Contracts/PersistenceDTO,CreateOutboxMessageDTO,CreateAuctionInputDTO,RefundProcessingResult}.php`; `app/Jobs/Auction/DispatchAuctionOutboxJob.php` (+ 9 sibling jobs); `routes/api.php`, `routes/api/auction.php`, `routes/api/admin.php`, `routes/console.php`, `bootstrap/app.php`; `config/{auction,queue,services,filesystems,logging,app}.php`; `lang/en/auction.php`, `lang/ar/auction.php`; `database/migrations/{2025_06_12_090000_create_auctions_table,2026_07_13_060000_create_auction_configuration_snapshots,2026_08_03_090000_add_auction_deadline_fields,2026_07_12_040000_add_payment_deadline_override_fields}.php`; `tests/TestCase.php`, `tests/Pest.php`, `phpunit.xml`, `phpunit.mysql.xml`, `composer.json`, plus the seven test files in §2.7.

**Dashboard — 34 files reviewed.** `package.json`, `tsconfig.json`, `components.json`, `i18n.ts`, `src/i18n/request.ts`, `src/middleware.ts`, `src/app/layout.tsx`, `src/lib/{AxiosBase,authHelpers,formatters,utils}.ts`, `src/hooks/useTypedTranslation.ts`, `src/components/organisms/appSideBar/{index,nav-main}.tsx`, `src/components/organisms/UnauthorizedPage/indes.tsx`, `src/components/molecules/MainTable/index.tsx`, `src/components/ui/{dialog,tabs,badge,card,table,radio-group,select,skeleton,alert-dialog}.tsx`, and the auctions module: `src/app/dashboard/auctions/{page,review/page,[auctionId]/page,settings/page}.tsx`, `settings/components/{PaymentMethodsTab,PaymentMethodDialog,ConfigurationTab,ConfigurationVersionForm,ConfigurationConfirmStep,configurationForm.helpers,ConfigurationDetailDialog,TermsTab,OperationalSettingsTab}`, `components/{AuctionReviewDialog,DialogConflict,QueryStates,AuctionStatusBadge,ServerPagination,MoneyText,CopyIdButton,AuctionKpiCards}.tsx`, `[auctionId]/components/{OverviewTab,TimelineTab,SnapshotCard,DeadlinesCard}.tsx`, `hooks/{useAuctionQueries,useAuctionMutations,useQueueFilters}.ts`, `lib/{types,constants,errors}.ts`, `src/i18n/languages/{ar,en}.json`, `src/app/dashboard/auctions/__tests__/*`.

---

## 4. المشكلات والقيود في التدفق الحالي

| # | Issue (verified) | Consequence for AI review |
|---|---|---|
| C1 | Review identity lives **only** in `auction_status_history` — one row carrying `changed_by` + `actor_type` + `reason`. There is no review entity of any kind. | An AI attempt (queued / running / failed / retried / superseded) has nowhere to live. A dedicated ledger is mandatory. |
| C2 | One endpoint (`/review`) discriminates approve vs reject by request body, and `reason` is `required` even for approve. | AI-applied decisions must not travel through the HTTP layer at all; they need a domain entry point. New admin capabilities need new endpoints. |
| C3 | `ReviewAuctionAction::approve/reject` **open their own transaction** via `AuctionTransaction->run` (L34, L90). | A caller that must atomically write a decision record *and* mutate the auction would nest transactions; a nested savepoint defeats `AuctionTransaction`'s deadlock retry (`DB::transaction($callback, 1)` at `AuctionTransaction:21`). → the `…Locked()` refactor. |
| C4 | `approve(Auction $auction, int $adminId, …)` types the actor as a non-null `int` and forwards it to the snapshot's `created_by`. | Needs `?int $actorId` + `string $actorType`. The schema already tolerates null (verified, §1 fact 2). |
| C5 | `AuctionOutboxNotifier:25-27` rejects any `aggregate_type !== Auction::class`, and `DispatchOutboxMessagesAction:18` type-hints that concrete class directly. | The outbox cannot carry non-auction events. → a topic router (which also makes ad events free later). |
| C6 | `PersonalDeliveryResolver::statusChanged:125` throws on unmapped targets, and no admin audience exists anywhere. | Admin alerts (escalation, budget, provider down) need a new resolver and a new recipient query. |
| C7 | Every job runs on the `default` queue. No `onQueue`, no `backoff()`, no `retryUntil`, no Horizon anywhere in `app/Jobs`. | AI calls would compete with `DispatchAuctionOutboxJob`, auction finalization, refunds, payments. → a dedicated `content-review` queue plus a documented worker change. |
| C8 | Settings are split in two: env-only `config/auction.php` (exposed read-only by `AuctionOperationalSettingsController` with `'source' => 'environment'`) and DB-versioned `auction_configuration_versions`. There is no generic settings table. | Mode / thresholds / kill switch need a DB home, and there is a proven append-only pattern to copy rather than invent. |
| C9 | `config('auction.deadlines.review_sla_hours')` (`config/auction.php:77`) is frozen into snapshots as `review_sla_minutes` but **never enforced** by any job or query. | Dead config. Do not build on it, and do not confuse it with AI timeouts. |
| C10 | ~~`rejected → pending_review` is not in `ALLOWED`, and no endpoint performs `rejected → draft`.~~ **Closed by the preliminary phase** via `POST /soom/auctions/{auction}/reopen`, which uses the existing `rejected → draft` edge. | The "resubmit a rejected auction" scenario is now fully exercisable end-to-end and is covered by `AuctionReopenAndUpdateTest`. Every rejection — human or AI — is actionable by the seller. |
| C11 | ~~Auctions are immutable post-creation.~~ **Closed by the preliminary phase** via `PATCH /soom/auctions/{auction}`, which edits **draft auctions only**. Auctions in `pending_review` and every later status remain immutable. | Content drift is now real, but only through the `draft` state. A review is always requested at the `draft → pending_review` boundary, so the hash is computed against content that cannot change while the review is in flight — unless an out-of-band edit occurs, which the hash still catches. Content hashing is a first-class correctness guard, not a speculative one. |
| C12 | `tests/Unit/Auction/AuctionCodeQualityTest.php` forbids `\b(float\|double)\b` anywhere under `app/{Domain,Services,Models,Http/Controllers,Http/Requests,Http/Resources}/Auction`; forbids `new AuctionException(__(`; forbids five legacy table-name strings across `app/`, `routes/`, `tests/`; forbids conditional keys in `UserAuctionResource`. | Confidence must be an **integer 0-100**; cost an **integer `cost_micros`**. `AuctionReviewSubjectAdapter` lives under `app/Services/Auction/` and is therefore subject to the float ban. Exceptions must use static factories. |
| C13 | `phpunit.xml` runs on `sqlite` `:memory:` with `QUEUE_CONNECTION=sync` and `CACHE_STORE=array`; `phpunit.mysql.xml` targets a persistent `*_testing` MySQL database; tests use neither `RefreshDatabase` nor `DatabaseTransactions`, and `tests/TestCase.php::guardAgainstNonTestingDatabase()` enforces the database name. | New tests must follow the same conventions, must use `Queue::fake()` for queue assertions, must not assume Redis, and must never point at `soom_pr`. |
| C14 | In the admin group, `Route::get('/{auction}', …)` is the **last** route in the `admin/auctions` prefix (`routes/api/auction.php:104`) — a catch-all. | New static admin paths must not be added under `admin/auctions`. Our new routes use the separate `admin/content-reviews` and `admin/content-review` prefixes, so there is no conflict. |
| C15 | `AdminAuctionResource` gates optional blocks with `Gate::forUser($user)->allows(<ability>, <model or class>)` (L22-27), not with raw permission strings. | The new `ai_review` block must be gated the same way, through a policy ability, not by reading `config()` directly in the resource. |

---

## 5. القرارات المعمارية المقترحة

**A1 — A new bounded subsystem named `ContentReview`, not `AiReview`.** It models *reviewing content*, of which AI is one strategy and a human is another. Namespaces mirror the project's existing per-subsystem convention exactly: `App\Domain\ContentReview\{Enums,Exceptions,ValueObjects}`, `App\DTO\ContentReview`, `App\Models\ContentReview`, `App\Repositories\ContentReview`, `App\Services\ContentReview\{Actions,Contracts,Providers,Support,Notifications}`, `App\Http\{Controllers,Requests,Resources}\ContentReview`, `App\Jobs\ContentReview`, `App\Policies\ContentReview`, `routes/api/content_review.php`, `config/content_review.php`, `lang/{en,ar}/content_review.php`.

**A2 — Dependency direction: `Auction → ContentReview`, never the reverse.** `ContentReview` defines a `ReviewSubjectAdapter` contract and never imports `App\Models\Auction\*`. `App\Services\Auction\ContentReview\AuctionReviewSubjectAdapter` implements it and lives inside the auction domain. A `ReviewSubjectRegistry` resolves adapters by `ReviewableSubjectType`. Adding ads later means one enum case, one adapter class, one registry binding — and zero changes inside `ContentReview`.

**A3 — Separate *analysis* from *decision* from *application*.**
- `ProcessContentReviewAction` (job-side): build content → deterministic checks → gates → provider call → validate → persist.
- `ContentReviewDecisionEngine`: a pure function with no I/O and no clock: `(StructuredReviewResult|null, DeterministicCheckResult, ReviewPolicy, ReviewMode, AutomationContext, ContentReviewErrorCode|null) → ReviewDecisionDTO`.
- `ApplyContentReviewDecisionAction`: owns the single transaction, re-validates staleness, writes the decision row, and delegates the subject mutation to the adapter.

The provider **never** decides. The decision engine **never** talks to a provider. The adapter **never** interprets AI output.

**A4 — No new auction statuses.** The auction stays `pending_review` for the whole AI lifecycle; review state lives in `content_reviews.status`. Adding a status would touch `AuctionStatus`, `AuctionStateMachine::ALLOWED`, `NOTIFIABLE_STATUSES`, `PersonalDeliveryResolver::statusChanged` (which throws on unmapped targets), `isPubliclyVisible()`, the mobile app, the dashboard `AUCTION_STATUSES` array, and every auction test file — all for an internal implementation detail with no domain meaning to a seller or a bidder.

**A5 — Two versioned settings tables, not one, and not a generic settings table.** `content_review_policies` (semantic: prohibited categories, thresholds, what is analyzed — changes rarely, must be legally traceable, immutable once used) and `content_review_settings` (operational: kill switch, mode, provider, model, timeouts, budgets — changes often). Both append-only with `version_number` + `is_active` + `created_by` + `published_at`, copying the shape of `AuctionConfigurationVersion` + `CreateConfigurationVersionAction`. Flipping the kill switch creates a row, and that row *is* the audit trail — no separate audit table is needed.

**A6 — Mode resolution is layered with an `.env` master switch on top** (§13.2). There is deliberately **no per-auction override column**; automation eligibility is instead gated by a category allowlist and a value cap inside the settings JSON, which is what a staged rollout actually needs.

**A7 — Integers only for confidence and cost.** `confidence` is `unsignedTinyInteger` 0-100; cost is `cost_micros`, an `unsignedBigInteger` of USD micro-units. This satisfies `AuctionCodeQualityTest`'s float ban (which reaches the adapter under `app/Services/Auction/`) and eliminates float-comparison bugs in the decision engine.

**A8 — Reuse the outbox for all fan-out; add a topic router.** No new event bus and no Laravel event listeners (the auction subsystem has none).

---

## 6. لماذا البنية المقترحة قابلة لمراجعة الإعلانات مستقبلًا

Everything auction-specific is confined to exactly **four** places:

1. `ReviewableSubjectType::Auction` — one enum case.
2. `AuctionReviewSubjectAdapter` — builds the content DTO, computes automation eligibility, and applies the decision by calling `ReviewAuctionAction`.
3. The `content_review_policies` row with `subject_type = 'auction'` — its own prohibited categories, thresholds, and analyzed fields.
4. `AdminAuctionResource`'s `ai_review` block plus the auction screens in the dashboard.

Adding **ad review** later is therefore: add `ReviewableSubjectType::Ad`; write `App\Services\Ad\ContentReview\AdReviewSubjectAdapter`; bind it in the registry; publish a policy row with `subject_type = 'ad'`; add an `ai_review` block to the ad resource; add a dashboard panel. The tables `content_reviews` and `content_review_decisions` are already polymorphic on `(subject_type, subject_id)`. The queue, job, sweeper, providers, validator, decision engine, budget guard, circuit breaker, settings screen, permissions, metrics, and outbox routing are all reused untouched.

**What we deliberately do not build now**, to avoid a speculative internal framework: no plugin discovery, no generic "content moderation UI", no per-subject-type dynamic form builder, no multi-provider fan-out or voting, no ad endpoints, no ad enum case. The registry is a plain array binding in `AppServiceProvider`, not a discovery mechanism.

---

## 7. Boundaries بين Auction Domain و AI Review Domain

```
┌──────────────────────── Auction Domain ─────────────────────────┐
│ SubmitAuctionForReviewAction ── in-TX ──► RequestContentReviewAction
│ ReviewAuctionAction::approveLocked() / rejectLocked()  ◄──┐      │
│ AuctionStateMachine (actorType: 'admin' | 'ai' | 'system')│      │
│ AuctionReviewSubjectAdapter ──────────────────────────────┘      │
│   implements ContentReview\Contracts\ReviewSubjectAdapter        │
└──────────────────────────────┬───────────────────────────────────┘
                               │  Auction depends on ContentReview
┌──────────────────────────────▼─── ContentReview Domain ──────────┐
│ Contracts: ReviewSubjectAdapter · ContentReviewProvider          │
│ RequestContentReviewAction · ProcessContentReviewJob             │
│ ProcessContentReviewAction · StructuredReviewResultValidator     │
│ ContentReviewDecisionEngine (pure) · ReviewPolicyResolver        │
│ ApplyContentReviewDecisionAction (the single transaction owner)  │
│ Providers: Fake · Anthropic (selected by configuration)          │
│ knows NOTHING about Auction or Ad                                │
└──────────────────────────────────────────────────────────────────┘
```

**Hard rules, enforced by `ContentReviewArchitectureTest` (§29):**

- No file under `app/{Domain,Services,Models,Repositories,DTO,Jobs,Http,Policies}/ContentReview/` may contain the string `Auction`.
- No file under `app/Services/ContentReview/Providers/` may reference a model, a repository, or a state transition.
- `ContentReviewDecisionEngine` may not use `DB`, `Cache`, `Http`, `Log`, `Storage`, or `Carbon::now()` (time is injected).
- Only `ApplyContentReviewDecisionAction` may call `AuctionTransaction`/`DB::transaction` within the subsystem.
- No `\b(float|double)\b` under `app/Services/Auction/ContentReview/` (inherited from the existing `AuctionCodeQualityTest` scan).

---

## 8. Data Model المقترح

### 8.1 `content_reviews` — the attempt ledger

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `public_id` | char(26) unique | ULID via `HasPublicId`; this is the API `id` |
| `subject_type` | string(40) | `'auction'` |
| `subject_id` | unsignedBigInteger | the subject's internal PK |
| `content_hash` | char(64) | sha256 of the canonical content JSON |
| `trigger` | string(30) | `submitted_for_review` \| `admin_manual` \| `admin_retry` \| `sweeper` |
| `mode` | string(20) | frozen at enqueue: `manual` \| `ai_assisted` \| `ai_automatic` \| `shadow` |
| `status` | string(20) | `queued` \| `running` \| `completed` \| `failed` \| `cancelled` \| `superseded` |
| `outcome` | string(30) nullable | `auto_approved` \| `auto_rejected` \| `escalated_to_human` \| `advisory_only` \| `no_decision` |
| `reason_code` | string(60) nullable | the decision-engine rule that fired (§14) |
| `recommendation` | string(20) nullable | `approve` \| `reject` \| `needs_human` |
| `confidence` | unsignedTinyInteger nullable | 0..100 |
| `risk_level` | string(20) nullable | `low` \| `medium` \| `high` \| `critical` |
| `requires_human_review` | boolean default `true` | safe default |
| `summary_ar`, `summary_en` | string(600) nullable | admin-facing only; never chain-of-thought |
| `findings`, `violations`, `missing_information`, `policy_checks`, `categories` | json nullable | bounded and validated |
| `deterministic_findings` | json nullable | from system rule checks, independent of the AI |
| `provider`, `model`, `prompt_version` | string(40) / string(80) / string(40) nullable | |
| `result_schema_version` | unsignedSmallInteger default 1 | |
| `policy_id` | unsignedBigInteger nullable | FK `content_review_policies` `nullOnDelete` |
| `policy_version`, `settings_version` | unsignedInteger nullable | denormalized for history |
| `attempt` | unsignedTinyInteger default 1 | |
| `max_attempts` | unsignedTinyInteger | frozen from settings |
| `error_code` | string(60) nullable | closed enum, safe to expose |
| `error_message` | string(500) nullable | redacted by `ErrorMessageRedactor` |
| `input_tokens`, `output_tokens` | unsignedInteger nullable | |
| `cost_micros` | unsignedBigInteger nullable | USD micro-units |
| `duration_ms` | unsignedInteger nullable | |
| `image_count`, `images_analyzed` | unsignedTinyInteger default 0 | |
| `requested_by` | unsignedBigInteger nullable | FK `users` `nullOnDelete`; null when system-triggered |
| `lease_owner` | char(26) nullable | worker ULID |
| `leased_until` | timestampTz nullable | stale-lease reclaim |
| `current_marker` | unsignedTinyInteger nullable | `1` for the active review, `NULL` once superseded |
| `queued_at`, `started_at`, `completed_at`, `decided_at`, `superseded_at` | timestampTz nullable | |
| `created_at`, `updated_at` | timestampTz | |

**Indexes and constraints**

- `unique (subject_type, subject_id, current_marker)` → **at most one active review per subject**. This is the same `current_marker` idiom already used by `AuctionSettlement` (`Auction::settlement()` filters `current_marker = 1`), so it is a familiar pattern in this codebase.
- `unique (subject_type, subject_id, content_hash, attempt)` → **no duplicate attempt for the same content version**. This is the cost and idempotency key.
- `index (status, queued_at)` — the sweeper.
- `index (subject_type, subject_id, created_at)` — the history panel.
- `index (created_at)` — budget aggregation.
- MySQL/PostgreSQL only (skipped on sqlite, following `create_auction_configuration_snapshots.php:60-70`): `CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100))`.

### 8.2 `content_review_decisions` — the actor ledger

| Column | Type | Notes |
|---|---|---|
| `id`, `public_id` | bigIncrements, char(26) unique | |
| `review_id` | unsignedBigInteger nullable | FK `content_reviews` `nullOnDelete`; null for a purely manual decision |
| `subject_type`, `subject_id` | string(40), unsignedBigInteger | always populated |
| `decision` | string(20) | `approved` \| `rejected` \| `escalated` \| `recommended` |
| `decided_by_type` | string(20) | `admin` \| `ai` \| `system` |
| `decided_by_id` | unsignedBigInteger nullable | FK `users` `nullOnDelete`; **NULL for `ai` and `system` — never a fake user** |
| `relation_to_recommendation` | string(30) nullable | `none` \| `confirmed` \| `overridden` \| `unavailable` |
| `ai_recommendation` | string(20) nullable | snapshot of what the AI said at decision time |
| `ai_confidence` | unsignedTinyInteger nullable | |
| `reason` | text nullable | mandatory when `overridden` |
| `decided_at`, `created_at` | timestampTz | |

Indexes: `(subject_type, subject_id, decided_at)`, `(review_id)`, `(decided_by_type, decided_at)`.

This table is **additive** to `auction_status_history`. That table remains the auction domain's truth of "who moved the status"; this one is the review domain's truth of "what was recommended, and who confirmed or overrode it".

### 8.3 `content_review_policies` (append-only)

`id`, `public_id`, `subject_type` string(40), `version_number` unsignedInteger, `name` string(120), `policy` json, `prompt_version` string(40), `result_schema_version` unsignedSmallInteger default 1, `is_active` boolean default false, `created_by` FK users nullable `nullOnDelete`, `published_at` timestampTz nullable, `created_at`, `updated_at`.
Unique `(subject_type, version_number)`; index `(subject_type, is_active)`.

Model guards mirror `AuctionConfigurationVersion::booted()`: throw on `updating` and `deleting` once any `content_reviews.policy_id` references the row.

`policy` JSON shape, validated on write by `PublishContentReviewPolicyRequest`:

```json
{
  "locales": ["ar", "en"],
  "analyzed_text_fields": ["title", "description"],
  "analyze_images": true,
  "max_images": 4,
  "image_max_edge_px": 1024,
  "prohibited_categories": ["weapons","drugs","counterfeit","adult","stolen_goods","live_animals","medical_claims"],
  "auto_reject_categories": ["weapons","drugs","adult","stolen_goods"],
  "human_review_categories": ["counterfeit","medical_claims","high_value"],
  "violation_codes": ["prohibited_item","misleading_description","contact_info_in_content","price_manipulation","missing_images","image_mismatch","offensive_language","duplicate_listing","incomplete_information"],
  "thresholds": {
    "min_confidence_approve": 85,
    "min_confidence_reject": 90,
    "grey_zone_low": 50,
    "grey_zone_high": 85
  },
  "max_risk_level_for_auto_approve": "low",
  "deterministic_rules": {
    "min_description_length": 30,
    "require_at_least_one_image": true,
    "forbid_contact_patterns": true,
    "reserve_must_not_exceed_starting_multiplier": 100
  }
}
```

### 8.4 `content_review_settings` (append-only, operational)

`id`, `public_id`, `scope` string(40), `version_number` unsignedInteger, `settings` json, `is_active` boolean default false, `created_by` FK users nullable `nullOnDelete`, `published_at` timestampTz nullable, `created_at`, `updated_at`.
Unique `(scope, version_number)`; index `(scope, is_active)`. `scope` is `'global'` or a `ReviewableSubjectType` value (`'auction'`).

```json
{
  "enabled": true,
  "mode": "ai_assisted",
  "provider": "anthropic",
  "model": "claude-sonnet-5",
  "timeout_seconds": 45,
  "max_attempts": 3,
  "backoff_seconds": [60, 300, 900],
  "max_concurrent": 5,
  "daily_budget_micros": 5000000,
  "monthly_budget_micros": 100000000,
  "max_output_tokens": 2000,
  "analyze_images": true,
  "circuit_breaker": { "failure_threshold": 5, "window_seconds": 300, "open_seconds": 600 },
  "automation": {
    "allowed_category_ids": [],
    "max_starting_amount_minor": 100000,
    "require_images": true
  }
}
```

### 8.5 `content_review_image_checks` (Batch 8)

`id`, `image_sha256` char(64) unique, `provider` string(40), `model` string(80), `verdict` string(20), `risk_level` string(20) nullable, `findings` json nullable, `cost_micros` unsignedBigInteger nullable, `created_at`.
Lets an image analyzed once be reused across re-runs and across subjects.

### 8.6 Entity relationships

```
Auction (1) ──(subject_type='auction', subject_id)──► content_reviews (N; exactly one with current_marker = 1)
content_reviews (1) ─────────────────────────────────► content_review_decisions (0..N)
content_review_policies (1) ─────────────────────────► content_reviews (N)
```

There is deliberately **no FK from `content_reviews` to `auctions`** — the table is polymorphic by design. Referential integrity for the subject is enforced by the adapter (`isReviewable()` returns false for a missing or non-`pending_review` subject), and orphans are a non-issue because auctions are not hard-deleted in this flow.

---

## 9. Migrations المطلوبة

All additive, all reversible, and **none touching an existing column or table**. Naming follows the project's `YYYY_MM_DD_HHMMSS_verb_noun` convention.

| # | File (under `database/migrations/`) | Batch | Contents |
|---|---|---|---|
| M1 | `2026_08_05_100000_create_content_review_policies_table.php` | 2 | §8.3; `down()` drops the table |
| M2 | `2026_08_05_100100_create_content_review_settings_table.php` | 2 | §8.4 |
| M3 | `2026_08_05_100200_create_content_reviews_table.php` | 2 | §8.1 including both unique indexes and the guarded CHECK constraint |
| M4 | `2026_08_05_100300_create_content_review_decisions_table.php` | 2 | §8.2 |
| M5 | `2026_08_09_100000_create_content_review_image_checks_table.php` | 8 | §8.5 |

**Zero changes to existing tables** — verified as unnecessary: `auction_status_history.changed_by` is nullable with `actor_type string(20)`, and `auction_configuration_snapshots.created_by` is nullable with `nullOnDelete()`.

CHECK constraints follow the existing guarded style (`create_auction_configuration_snapshots.php:60-70`): applied only when `DB::connection()->getDriverName()` is `mysql` or `pgsql`, so the sqlite `phpunit.xml` suite keeps working.

Seeder: `database/seeders/ContentReviewSeeder.php` publishes policy v1 (`subject_type = 'auction'`) and settings v1 (`scope = 'auction'`, `enabled = false`, `mode = 'manual'`) — **disabled by default**.

---

## 10. Enums والحالات المقترحة

All under `app/Domain/ContentReview/Enums/`, plain backed string enums with `declare(strict_types=1)` and no `label()` methods — labels resolve through `__('content_review.<group>.<value>')`, matching the existing convention (`AdminAuctionResource` uses `__('auction.statuses.'.$this->status->value)`).

| Enum | Cases |
|---|---|
| `ReviewableSubjectType` | `auction` *(the only case now)* |
| `ReviewMode` | `manual`, `ai_assisted`, `ai_automatic`, `shadow` |
| `ContentReviewStatus` | `queued`, `running`, `completed`, `failed`, `cancelled`, `superseded` |
| `ContentReviewOutcome` | `auto_approved`, `auto_rejected`, `escalated_to_human`, `advisory_only`, `no_decision` |
| `ReviewRecommendation` | `approve`, `reject`, `needs_human` |
| `ReviewRiskLevel` | `low`, `medium`, `high`, `critical` |
| `ViolationSeverity` | `low`, `medium`, `high`, `critical` |
| `ReviewTrigger` | `submitted_for_review`, `admin_manual`, `admin_retry`, `sweeper` |
| `DecisionActorType` | `admin`, `ai`, `system` |
| `DecisionRelation` | `none`, `confirmed`, `overridden`, `unavailable` |
| `ContentReviewErrorCode` | `provider_timeout`, `provider_rate_limited`, `provider_unavailable`, `provider_auth_failed`, `invalid_structured_output`, `content_unavailable`, `image_fetch_failed`, `budget_exhausted`, `circuit_open`, `policy_missing`, `subject_not_reviewable`, `content_changed`, `unknown_error` |

Behaviour methods are kept minimal, mirroring `AuctionStatus::isPubliclyVisible()`: `ContentReviewStatus::isTerminal()`, `ReviewMode::allowsAutomaticDecision()`, `ReviewMode::callsProvider()`, `ContentReviewErrorCode::isRetryable()`, `ReviewRiskLevel::isAtMost()`, `ViolationSeverity::isAtLeast()`.

---

## 11. Review Provider Contract

`app/Services/ContentReview/Contracts/ContentReviewProvider.php`

```php
interface ContentReviewProvider
{
    public function name(): string;

    public function analyze(ProviderReviewRequest $request): ProviderReviewResponse;
}
```

`app/DTO/ContentReview/ProviderReviewRequest.php` (`final readonly`, extending `BaseContentReviewDTO`, itself a copy of `BaseAuctionDTO`'s shape):
`subjectType`, `policyInstructions` (rendered from the policy, **never** from user content), `resultSchema` (the JSON schema the provider must satisfy), `textBlocks` (`array<{field, locale, value}>`, each already sanitized and delimiter-wrapped), `structuredFacts` (`array<string, scalar>` — prices, dates, category name; never raw user prose), `images` (`array<{ordinal, mime, bytes|null, sha256}>`), `model`, `maxOutputTokens`, `timeoutSeconds`, `locales`.

`app/DTO/ContentReview/ProviderReviewResponse.php`:
`payload` (decoded, still **untrusted**), `model`, `inputTokens`, `outputTokens`, `costMicros`, `latencyMs`, `providerRequestId`.

Failures are signalled by throwing `ContentReviewProviderException` carrying a `ContentReviewErrorCode` — **never** by returning a partial payload.

**Adapters**

- `FakeContentReviewProvider` — deterministic and scriptable (`respondWith(array $payload)`, `failWith(ContentReviewErrorCode $code)`, `calls(): int`). Used by every automated test.
- `AnthropicContentReviewProvider` — the one real adapter. `Http::withToken(config('services.anthropic.api_key'))->timeout($request->timeoutSeconds)`, requesting a tool/JSON-schema-constrained response so the model is structurally bound to the contract; images are sent as base64 content blocks.
- `ContentReviewProviderFactory` — resolves by `settings.provider`, then `config('content_review.provider')`; an unknown name raises `provider_unavailable`.

Container binding in `AppServiceProvider::register()`, mirroring the existing refund-processor binding at `AppServiceProvider:35`:

```php
$this->app->bind(
    ContentReviewProvider::class,
    fn ($app) => $app->make(ContentReviewProviderFactory::class)->make()
);
```

**Contract test** — `tests/Feature/ContentReview/ProviderContractTest.php` runs identical assertions against every registered adapter using `Http::fake()`: the timeout is honoured; the API key never appears in the exception message; 401 / 429 / 500 / connection-timeout map to the right `ContentReviewErrorCode`; token counts are returned when the payload supplies them.

---

## 12. Structured Review Result

`app/DTO/ContentReview/StructuredReviewResult.php` — `final readonly`, constructible **only** by `StructuredReviewResultValidator::validate(array $payload, ReviewPolicy $policy): StructuredReviewResult`.

Wire contract, `result_schema_version = 1`:

```jsonc
{
  "recommendation": "approve" | "reject" | "needs_human",   // required
  "confidence": 0..100,                                     // required, strict integer
  "risk_level": "low" | "medium" | "high" | "critical",     // required
  "requires_human_review": true | false,                    // required
  "summary_ar": "string <= 600",                            // required
  "summary_en": "string <= 600",                            // optional
  "categories": ["<from policy.prohibited_categories>"],    // <= 10
  "violations": [{                                          // <= 20
    "code": "<from policy.violation_codes>",
    "severity": "low" | "medium" | "high" | "critical",
    "field": "title" | "description" | "images" | "price" | "schedule" | "location" | "other",
    "evidence": "string <= 200"
  }],
  "findings": [{ "field": "...", "note": "string <= 300" }],        // <= 20
  "policy_checks": [{ "rule_code": "...", "passed": true | false }], // <= 40
  "missing_information": ["string <= 120"]                          // <= 10
}
```

**Validation rules** — a Laravel `Validator` using only `required`, `in:`, `integer`, `boolean`, `array`, `max:`, with no free text accepted where an enum is expected. Unknown keys are **dropped**, not rejected (forward compatibility). Unknown enum values **are** rejected. `confidence` must be a strict integer — a float or a numeric string fails. Arrays exceeding their cap fail. `evidence` is stripped of control characters and truncated.

Any validation failure → `ContentReviewException::domain('invalid_structured_output')` → the attempt is recorded as `failed` with `error_code = invalid_structured_output` → **escalate to human. Never auto-decide.**

**Stored:** exactly the validated fields above.
**Never stored:** chain of thought or any internal reasoning; the raw provider envelope; the rendered prompt; image bytes; any seller PII beyond what already exists on the auction row.

---

## 13. Review Policy و Policy Versioning

### 13.1 The policy is data, not a prompt

`app/Domain/ContentReview/ValueObjects/ReviewPolicy.php` is a `final readonly` value object hydrated from `content_review_policies.policy`, exposing typed accessors: `thresholds()`, `autoRejectCategories()`, `humanReviewCategories()`, `analyzedTextFields()`, `maxImages()`, `imageMaxEdgePx()`, `violationCodes()`, `deterministicRules()`, `maxRiskLevelForAutoApprove()`, `locales()`.

The prompt is *rendered from* the policy by `ReviewPromptRenderer`, and `prompt_version` is stored alongside every review. **The decision engine reads the policy, never the prompt** — so a prompt tweak can never silently change automation behaviour, and every stored review answers "which policy version decided this" through `policy_id` + `policy_version`.

`ReviewPolicyResolver::activeFor(ReviewableSubjectType $type): ReviewPolicy` returns the highest `version_number` with `is_active = true` for that subject type, cached for the request only. No active policy → `error_code = policy_missing`, escalate to human, alert admins.

### 13.2 Mode resolution — `ReviewModeResolver::resolve(ReviewableSubjectType $type): ReviewMode`

Precedence, first match wins:

1. `config('content_review.enabled') === false` (env `CONTENT_REVIEW_ENABLED`) → **`manual`**. This is the master kill switch and requires no DB read.
2. The active `content_review_settings` row for `scope = <subject_type>` with `settings.enabled === false` → `manual`.
3. That row's `settings.mode`.
4. The active row for `scope = 'global'` — same two checks.
5. `config('content_review.default_mode', 'manual')`.

The resolved mode is **frozen onto `content_reviews.mode` at enqueue time**, and re-checked at decision-application time. If the *current* effective mode no longer permits automation, the review escalates to a human instead of auto-applying. The re-check may only move in the safe direction — it can downgrade `ai_automatic → escalate`, never upgrade.

**Where the mode is defined, and why (requirement: "study the best place before assuming").** The existing settings structure offers exactly two homes: env-only `config/auction.php` (read-only, surfaced by `AuctionOperationalSettingsController` with `'source' => 'environment'`) and DB-versioned `auction_configuration_versions` (append-only, immutable once used, admin-editable through the dashboard). The mode must be **admin-editable without a deploy**, **auditable**, and **instantly reversible** — which rules out env-only. It must also not pollute the auction configuration snapshot, which is a *financial* contract frozen onto each auction and validated by `AuctionConfigurationSnapshotValidator` — adding a review mode there would change the snapshot hash for a non-financial reason and would freeze the mode per auction, exactly the wrong lifetime. Therefore: **a new versioned settings table scoped per content type, plus an env master switch.** Platform-wide defaults live in the `scope = 'global'` row; the auction override lives in `scope = 'auction'`. There is no per-auction mode column — that would be an unauditable per-row escape hatch, and the real rollout need (start narrow, widen) is served far better by the category allowlist and value cap inside `settings.automation`.

---

## 14. Decision Engine

`app/Services/ContentReview/Support/ContentReviewDecisionEngine.php` — pure, no I/O, no clock, no container access.

```php
public function decide(
    ?StructuredReviewResult $result,          // null when the attempt failed
    DeterministicCheckResult $deterministic,
    ReviewPolicy $policy,
    ReviewMode $mode,
    AutomationContext $context,               // eligibility flags from the subject adapter
    ?ContentReviewErrorCode $error
): ReviewDecisionDTO
```

Rules evaluated in order; the first match wins. Every branch records a `reason_code` that the dashboard renders as human-readable Arabic text.

| # | Condition | Outcome | `reason_code` |
|---|---|---|---|
| R1 | `mode` ∈ {`manual`, `shadow`} | `advisory_only` | `mode_does_not_apply_decisions` |
| R2 | `error !== null` or `result === null` | `escalated_to_human` | `provider_failure` |
| R3 | `deterministic->hasHardFailure()` | `escalated_to_human` | `deterministic_hard_failure` |
| R4 | `result->requiresHumanReview === true` | `escalated_to_human` | `model_requested_human` |
| R5 | `result->categories ∩ policy->humanReviewCategories() ≠ ∅` | `escalated_to_human` | `policy_requires_human` |
| R6 | deterministic checks and the model disagree on approve/reject | `escalated_to_human` | `rule_model_disagreement` |
| R7 | `mode === ai_assisted` | `advisory_only` | `assisted_mode` |
| R8 | `! context->isAutomationEligible` (category not allowlisted, value over cap, images required but absent) | `escalated_to_human` | `subject_not_automation_eligible` |
| R9 | `recommendation === reject` **and** there is a violation with `severity = critical` whose code ∈ `policy->autoRejectCategories()` **and** `confidence >= thresholds.min_confidence_reject` | **`auto_rejected`** | `high_confidence_critical_violation` |
| R10 | `recommendation === approve` **and** `violations === []` **and** `confidence >= thresholds.min_confidence_approve` **and** `risk_level` is at most `policy->maxRiskLevelForAutoApprove()` | **`auto_approved`** | `high_confidence_clean` |
| R11 | otherwise | `escalated_to_human` | `grey_zone` |

`ReviewDecisionDTO` carries `{outcome, recommendation, confidence, reasonCode, reasonParams, requiresOverrideReason}`.

Note the ordering guarantee that matters most: **R1-R8 all precede both automatic outcomes.** There is no path from any error, any missing result, any deterministic failure, any policy category, any ineligible subject, or any non-automatic mode to `auto_approved`. `DecisionEngineTest` asserts this exhaustively by iterating every `ContentReviewErrorCode` and every mode.

Unit tests cover every row plus boundary values at each threshold (`confidence = min-1`, `min`, `min+1`).

---

## 15. Content Snapshot و Content Hash

`app/Services/ContentReview/Support/ContentHasher.php` copies `AuctionConfigurationSnapshotHasher` exactly: recursive `ksort`, then `hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))`.

`AuctionReviewSubjectAdapter::buildContent()` produces a `ReviewContentDTO`:

```
hashed and analyzed:
  title, description, category_id, country_id, state_id, city_id,
  latitude, longitude, currency_code,
  starting_amount_minor, reserve_amount_minor, minimum_bid_increment_minor,
  starts_at (ISO-8601), ends_at (ISO-8601), terms_version_id,
  media: [ { sort_order, mime_type, size_bytes, path_sha256 } ]

not hashed, not sent:
  status, all lifecycle timestamps, bid pointers, extension counters,
  seller identity, seller phone, deposits, payments, internal ids
  beyond the category and location ids above
```

Media are loaded with a single eager `with('media')` (no N+1). Hashing the storage path rather than carrying it means reordering or replacing an image changes the content hash without ever exposing a durable storage path to the provider.

**Staleness protocol**

- The hash is recomputed immediately before any decision is applied. On mismatch, the review is marked `superseded` (`current_marker = NULL`, `superseded_at` set), a `content_review.stale` event is emitted, and the subject escalates to human review.
- `is_stale` is exposed in the admin API so the dashboard can show «النتيجة لم تعد صالحة — يلزم إعادة المراجعة» and disable the "act on the AI recommendation" affordance.
- A new review request for the same subject supersedes the previous active one **inside the same transaction** that inserts the new row; the unique index on `(subject_type, subject_id, current_marker)` makes two simultaneously-active rows impossible even under a race.
- **The reopen/edit loop is the primary source of hash changes.** When a seller reopens a rejected
  auction, edits it, and resubmits, `submit-review` calls `RequestContentReviewAction`, which
  supersedes the previous review and inserts a new row carrying the **new** `content_hash`. The
  previous AI verdict is therefore never applied to, and never displayed as current for, the edited
  content: the superseded attempt stays in the history panel labelled as belonging to an older
  version, and `ai_review.current` points at the new attempt. An admin acting on a stale result is
  additionally blocked by the `is_stale` flag and by the hash re-check inside
  `ApplyContentReviewDecisionAction`.

---

## 16. Queue و Jobs و Retry Strategy

**A new named queue, `content-review`.** Today every job runs on `default` (verified: no `onQueue`, no `$queue` property, and no `backoff()` anywhere in `app/Jobs`). AI work must never compete with `DispatchAuctionOutboxJob`, auction finalization, refunds, payouts, or bid handling.

> **Deployment requirement — must appear in the release notes.** Production runs **two separate workers**: `php artisan queue:work --queue=default` for critical work and `php artisan queue:work --queue=content-review` for AI reviews. A single worker consuming `default,content-review` is for local development only — in production it reintroduces the head-of-line coupling this queue exists to prevent and removes the ability to stop AI work without stopping payments. Until the second worker is running, reviews sit in `queued` and the sweeper keeps re-dispatching them. **The auction flow is completely unaffected** — that is the intended failure mode, not an outage.
>
> The cache-backed components below (circuit breaker, budget guard, concurrency limiter) require a cache that is shared across worker processes and supports atomic locks. Verified for this project: `CACHE_STORE=database` → `Illuminate\Cache\DatabaseStore`, which implements `LockProvider` over the existing `cache_locks` table. See D3.

`app/Jobs/ContentReview/ProcessContentReviewJob.php`:

```php
final class ProcessContentReviewJob implements ShouldQueue, ShouldBeUnique
    use Queueable;
    public string $queue = 'content-review';
    public int $tries;                       // from settings, default 3
    public int $timeout;                     // settings.timeout_seconds + 15
    public int $uniqueFor = 900;
    public function uniqueId(): string;      // the review public_id
    public function backoff(): array;        // settings.backoff_seconds with +/-20% jitter
    public function retryUntil(): DateTimeInterface;  // queued_at + 30 minutes
    public function failed(Throwable $e): void;       // mark failed, escalate, alert admins
```

**Dispatch is dual, exactly as the outbox is dual:**

1. `RequestContentReviewAction` inserts the `queued` row inside the caller's existing transaction, then `ProcessContentReviewJob::dispatch($publicId)->afterCommit()` — low latency, and no dispatch before the row is committed.
2. `content-review:dispatch-pending` (a new console command, scheduled `everyMinute()->withoutOverlapping()` alongside the existing entries in `routes/console.php`) leases `queued` rows older than 60 seconds, and `running` rows whose `leased_until` has passed, using `lockForUpdate` — the exact shape of `AuctionOutboxRepository::leaseNextPending` — and re-dispatches them. This guarantees no review is lost if the worker was down at commit time.

Because the job leases its row behind a status guard, a second concurrent execution is a no-op.

**Resilience components** (`app/Services/ContentReview/Support/`)

- `ContentReviewCircuitBreaker` — counts consecutive failures within `circuit_breaker.window_seconds` via the cache; at `failure_threshold` the circuit opens for `open_seconds`. While open: skip the provider, fail fast with `circuit_open`, escalate, and alert admins **once**.
- `ContentReviewBudgetGuard` — daily and monthly `SUM(cost_micros)` (served by the `created_at` index), cached for 60 seconds; over budget → skip the provider, `budget_exhausted`, escalate, alert admins once per period.
- `ContentReviewConcurrencyLimiter` — `Cache::lock` slots up to `settings.max_concurrent`; when no slot is free the job calls `release(30)`, returning to the queue **without consuming an attempt**.

**Ordering inside the job:** lease → resolve settings and policy → recompute the hash and verify the review is still current → deterministic checks → circuit / budget / concurrency gates → provider call (**outside any transaction**) → validate → persist the result (a short transaction) → hand off to `ApplyContentReviewDecisionAction`.

---

## 17. Transactions و Locks و Idempotency

**Transaction map**

| Step | Transaction | Locks |
|---|---|---|
| Submit for review | the existing `AuctionTransaction->run` in `SubmitAuctionForReviewAction` | `auctions` row `FOR UPDATE` |
| Insert the `queued` review | **the same** transaction — a pure DB insert with no external call | none extra |
| Job lease | its own short transaction | `content_reviews` row `FOR UPDATE` |
| Provider call | **no transaction at all** | none |
| Persist the result | its own short transaction | `content_reviews` row `FOR UPDATE` |
| Apply the decision | **one** `AuctionTransaction->run`, owned by `ApplyContentReviewDecisionAction` | `content_reviews`, then `auctions` — always in that order |

**The required refactor (C3 + C4).** `ReviewAuctionAction` gains transaction-free entry points, and the existing public methods become thin wrappers so every current caller and test is untouched:

```php
public function approve(Auction $auction, ?int $actorId, string $reason, string $actorType = 'admin'): Auction
{
    return $this->transaction->run(fn (): Auction => $this->approveLocked($auction->id, $actorId, $reason, $actorType));
}

public function approveLocked(int $auctionId, ?int $actorId, string $reason, string $actorType = 'admin'): Auction
{
    // the current body of approve(), starting at lockForStateChange()
}
```

The same split applies to `reject` / `rejectLocked` (the blank-reason guard stays in the wrapper *and* is repeated in the locked method so the domain rule cannot be bypassed). `AuctionConfigurationSnapshotRepository::createForApprovedAuction($auction, ?int $createdBy)` already writes to a nullable `created_by`.

**Lock ordering is fixed** — `content_reviews` first, then `auctions`, on every path. The admin path locks only `auctions`, so no cycle exists.

**Idempotency layers**

| Risk | Guard |
|---|---|
| Two jobs for the same content version | unique `(subject_type, subject_id, content_hash, attempt)` plus `ShouldBeUnique` keyed on `public_id` |
| Two active reviews for one subject | unique `(subject_type, subject_id, current_marker)` |
| The same job executed twice | lease plus a status guard (`queued`/`running` only) inside a locked transaction |
| Cost charged twice | `cost_micros` is written once per attempt row; a re-run creates a new `attempt` |
| A decision applied twice | a `decided_at IS NULL` guard inside the locked transaction, plus `AuctionStateMachine` no-opping when `from === to`, plus `createForApprovedAuction` already being idempotent |
| Duplicate notifications | the existing `PersonalNotificationSender` dedupe on `data->event_id` (a ULID per outbox row) |
| AI and admin deciding simultaneously | both paths take `auctions` `FOR UPDATE`; the loser sees a non-`pending_review` status and either throws `invalid_transition` (admin — surfaced as the dashboard's existing 422 conflict handling in `useDialogConflict`) or records `outcome = no_decision, error_code = subject_not_reviewable` (AI) |
| An AI result arriving after an admin decision | the apply step re-reads the status; when it is not `pending_review` the review becomes `no_decision` + `superseded` and nothing is mutated |

---

## 18. State Flows

**Auction statuses are unchanged.** `pending_review` covers the entire AI lifecycle.

**Review status machine** (`content_reviews.status`)

```
queued ──lease──► running ──validated──► completed ──apply──► (decided_at set)
   │                 │                        │
   │                 ├── retryable error ──► queued (attempt + 1, backoff)
   │                 └── terminal error ───► failed ──► escalated_to_human
   ├── subject no longer reviewable ──► cancelled
   └── content changed / newer review ──► superseded
```

**Outcome × auction effect**

| `outcome` | Effect on the auction | `auction_status_history` row |
|---|---|---|
| `advisory_only` | none | none |
| `escalated_to_human` | none — stays `pending_review` | none |
| `auto_approved` | `pending_review → awaiting_seller_deposit` or `scheduled` | `actor_type = 'ai'`, `changed_by = NULL` |
| `auto_rejected` | `pending_review → rejected` | `actor_type = 'ai'`, `changed_by = NULL` |
| `no_decision` | none | none |

`AdminAuctionResource::nextAdminAction()` (currently at L214-228) gains one additive branch placed **before** the existing `PendingReview` case: when an active `queued`/`running` review exists, return `'awaiting_ai_review'`; otherwise the existing `'review_auction'` is returned unchanged.

---

## 19. Sequence Flows

**19.1 Seller submits, `ai_assisted`**

```
Seller → POST /soom/auctions/{id}/submit-review
  ├─ TX: lock auction → transition(pending_review) → status_history → outbox(auction.status_changed)
  │      → RequestContentReviewAction: supersede any active review,
  │        INSERT content_reviews(queued, mode=ai_assisted, content_hash)
  ├─ COMMIT
  ├─ ProcessContentReviewJob::dispatch()->afterCommit()      [queue: content-review]
  └─ 200 to the seller  ── the seller's request never waits on the AI

Worker: lease → deterministic checks → circuit/budget/concurrency gates
        → provider.analyze()  (no transaction open)
        → validate → TX: persist result, status = completed
        → ApplyContentReviewDecisionAction → engine → advisory_only
        → TX: content_review_decisions(decision = recommended, decided_by_type = ai, decided_by_id = NULL)
        → outbox(content_review.escalated) → admin alert

Admin  → GET /admin/auctions/{id} → sees the ai_review block
       → POST /admin/auctions/{id}/review {action, reason}       (the endpoint is unchanged)
       → ReviewAuctionAction, plus ContentReviewDecisionRecorder writes a second decision row
         (decided_by_type = admin, relation = confirmed | overridden)
```

**19.2 `ai_automatic`, clean content**

```
Worker → engine → auto_approved
  → TX (AuctionTransaction, owned by ApplyContentReviewDecisionAction):
       lock content_reviews (guard: decided_at IS NULL)
       → recompute the content hash → verify auction status is still pending_review
       → INSERT content_review_decisions(approved, ai, NULL, relation = none)
       → adapter->applyDecision()
            → ReviewAuctionAction::approveLocked($auctionId, null, $reason, 'ai')
                 → configuration snapshot (created_by = NULL)
                 → seller deposit obligation
                 → transition(awaiting_seller_deposit | scheduled, actorId = null, actorType = 'ai')
       → UPDATE content_reviews SET outcome = 'auto_approved', decided_at = now()
  → COMMIT → the existing dispatcher flushes the outbox
           → the seller receives the normal Arabic notification, identical to a human approval
```

**19.3 Provider outage**

```
Worker → circuit open (or three timeouts) → status = failed, error_code = provider_timeout
       → engine(error) → escalated_to_human
       → TX: content_review_decisions(escalated, system, NULL, relation = unavailable)
       → outbox(content_review.provider_unhealthy) → admin alert, rate-limited to one per hour

The auction stays pending_review. The admin reviews it manually.
Nothing else in the platform is affected.
```

**19.4 Admin overrides the AI**

```
Admin → POST /admin/auctions/{id}/review {action: 'reject', reason: '…'}
  Gate::authorize('review' | 'approve')                       (unchanged)
  + ContentReviewOverrideGuard: if an active, completed, non-stale review carries a
    recommendation contradicting $action ⇒ require permission content_review.override
    AND a non-empty reason (already required by ReviewAuctionRequest)
    ⇒ otherwise 403 { code: "content_review_override_not_allowed" }
  → decision row: decided_by_type = admin, decided_by_id = $adminId,
    relation = overridden, ai_recommendation = 'approve', ai_confidence = 91,
    reason = <the admin's text>
```

---

## 20. السيناريوهات الطبيعية والاستثنائية

| # | Scenario | Behaviour | Proven by |
|---|---|---|---|
| 1 | Fully manual review | `mode = manual` → no review row is created at all; `/review` behaves exactly as today | `ManualModeTest` plus every existing auction test staying green |
| 2 | `ai_assisted`, admin approves | an advisory row plus an admin decision row with `relation = confirmed` | `AiAssistedFlowTest` |
| 3 | `ai_assisted`, admin contradicts the recommendation | admin decision `relation = overridden`, reason mandatory, `content_review.override` required | `AiOverrideTest` |
| 4 | Auto-approve | R10 → snapshot with `created_by = NULL`, history `actor_type = 'ai'`, seller notified normally | `AiAutomaticApproveTest` |
| 5 | Auto-reject | R9 → status `rejected`, reason = the rendered AI summary, seller receives `status.rejected_with_reason` | `AiAutomaticRejectTest` |
| 6 | Needs human | R4 / R5 / R11 → `escalated_to_human`, auction untouched, admin alert | `EscalationTest` |
| 7 | Transient failure then success | attempt 1 `failed(provider_timeout)` → backoff → attempt 2 `completed`; two rows, one cost each | `RetryTest` |
| 8 | Permanent failure | attempts exhausted → `failed` → `escalated_to_human` + alert; the auction is still `pending_review` | `RetryTest::exhausted` |
| 9 | Content edited during review | hash mismatch at apply time → `superseded` + escalate + `content_review.stale`. Reachable only out-of-band, because `PATCH` is draft-only while a review runs against `pending_review`; the test therefore mutates the row directly to prove the guard fires. | `StaleContentTest` |
| 10 | Content edited after approval, before publish | `PATCH` is rejected with `auction_not_editable` (status is no longer `draft`); approval already minted an immutable snapshot and `Auction::booted()` blocks changing `configuration_version_id`. Any out-of-band change marks the review `superseded` with no re-decision. | `StaleContentTest::after_approval` |
| 11 | Rejected auction reopened, edited, resubmitted | full HTTP journey: reject → `reopen` (`rejected → draft`) → `PATCH` (new content, new hash) → `submit-review`. The old review is superseded, a **new** review row is inserted with the new `content_hash`, and the previous verdict is never applied to the new content. The rejection reason and the whole status history are preserved. | `ResubmitAfterRejectionTest` + `AuctionReopenAndUpdateTest` |
| 12 | Admin decides while the AI is running | the admin path wins by locking `auctions`; the AI apply step reads a status ≠ `pending_review` → `no_decision` + `superseded` | `ContentReviewConcurrencyMysqlTest` (two OS processes, modelled on `AuctionConfigurationSnapshotMysqlTest:43`) |
| 13 | AI result lands after an admin decision | the same guard; nothing is mutated and the review is marked `superseded` | same test |
| 14 | The job runs twice | lease + status guard + `ShouldBeUnique` + the unique `(subject, hash, attempt)` index → the second run is a no-op, with no second cost and no second notification | `IdempotencyTest` |
| 15 | The mode changes while a review is pending | the review keeps its frozen `mode`; at apply time the *current* mode is re-checked and may only downgrade (automatic → escalate), never upgrade | `ModeChangeTest` |
| 16 | AI disabled mid-flight (kill switch) | env or settings `enabled = false` → the apply step escalates; queued reviews are cancelled by the sweeper with `outcome = no_decision` | `KillSwitchTest` |
| 17 | Budget or quota exceeded | the provider is skipped, `budget_exhausted`, escalate, one admin alert per period | `BudgetGuardTest` |
| 18 | An image is deleted or changed after the scan starts | `path_sha256` is part of the content hash → stale → escalate; a missing file yields `image_fetch_failed`, and text-only analysis proceeds with `requires_human_review = true` forced | `ImageHandlingTest` |
| 19 | Mixed Arabic/English content | `policy.locales = ['ar','en']`; each text block carries a `locale` tag; `summary_ar` is required and `summary_en` optional; the admin UI renders `summary_ar`, consistent with `NotificationValueFormatter::LOCALE = 'ar'` | `MultilingualTest` |
| 20 | Off-contract AI response | the validator rejects it → `invalid_structured_output` → **never** an automatic decision → escalate | `StructuredResultValidatorTest` |

---

## 21. API Contracts الجديدة والمعدلة

Base `/api`. All admin routes sit behind `['auth:sanctum', 'role:admin']`. The success envelope is the project's `{success, message, data}` from `ApiResponseTrait`; errors are `{success: false, message, code}` from `ApiErrorResponse`.

### 21.1 Modified endpoints

**`GET /api/admin/auctions/{auction}`** and **`GET /api/admin/auctions`** — `AdminAuctionResource` gains an `ai_review` block, gated exactly like the existing optional blocks (`Gate::forUser($user)->allows('viewAny', ContentReview::class)`, which maps to the `content_review.view` permission through `ContentReviewPolicy`):

```jsonc
"ai_review": {
  "enabled": true,
  "mode": "ai_assisted",
  "review_method_label": "مراجعة بمساعدة الذكاء الاصطناعي",
  "current": {
    "id": "01J...",
    "status": "completed",
    "outcome": "escalated_to_human",
    "outcome_label": "تعذر إكمال المراجعة التلقائية وتم تحويل المزاد للمراجعة اليدوية",
    "reason_code": "grey_zone",
    "reason_label": "نتيجة غير حاسمة",
    "recommendation": "approve",
    "recommendation_label": "أوصى الذكاء الاصطناعي بالموافقة",
    "confidence": 78,
    "risk_level": "medium",
    "risk_level_label": "متوسط",
    "requires_human_review": true,
    "summary": "…",
    "violations": [
      { "code": "misleading_description", "code_label": "وصف مضلل", "severity": "high", "field": "description", "evidence": "…" }
    ],
    "findings": [{ "field": "images", "note": "…" }],
    "missing_information": ["…"],
    "image_analysis": { "analyzed": 3, "total": 5, "notes": ["…"] },
    "is_stale": false,
    "model_label": "Anthropic · claude-sonnet-5",
    "policy_version": 1,
    "started_at": "…",
    "completed_at": "…",
    "duration_ms": 4210,
    "attempt": 1,
    "max_attempts": 3,
    "error_code": null,
    "error_label": null
  },
  "decisions": [
    {
      "decision": "recommended",
      "decided_by_type": "ai",
      "decided_by": null,
      "relation_to_recommendation": "none",
      "label": "أوصى الذكاء الاصطناعي بالموافقة",
      "reason": null,
      "decided_at": "…"
    }
  ],
  "available_actions": ["run", "retry", "cancel", "force_manual", "override"]
}
```

`next_admin_action` may additionally return `"awaiting_ai_review"`. **Nothing existing is removed or renamed.** Without the permission the key is simply absent — the established convention for this resource. Note that the `EmitsStableKeys` architecture rule in `AuctionCodeQualityTest` applies to `UserAuctionResource` only, so a conditional key here is legitimate (verified).

Token counts and `cost_micros` appear only when the viewer additionally holds `content_review.costs.view`.

**`POST /api/admin/auctions/{auction}/review`** — the request and response shapes are unchanged. New behaviour: if an active, completed, non-stale AI recommendation contradicts `action`, the caller must hold `content_review.override`, otherwise `403 {code: "content_review_override_not_allowed"}`. A `content_review_decisions` row is written alongside the existing `auction_status_history` row.

### 21.2 New endpoints

`routes/api/content_review.php`, required from `routes/api.php` inside the existing `api_maintenance` group. The prefixes `admin/content-reviews` and `admin/content-review` are separate from `admin/auctions`, so they do not collide with that group's trailing `GET /{auction}` catch-all (C14).

| Method and path | Permission | Purpose |
|---|---|---|
| `GET /api/admin/content-reviews/{subjectType}/{subjectId}` | `content_review.view` | paginated attempt history for a subject |
| `GET /api/admin/content-reviews/{contentReview}` | `content_review.view` | one attempt in full |
| `POST /api/admin/content-reviews/{subjectType}/{subjectId}/run` | `content_review.run` | request a fresh review; supersedes the active one |
| `POST /api/admin/content-reviews/{contentReview}/retry` | `content_review.run` | a new attempt for the same content |
| `POST /api/admin/content-reviews/{contentReview}/cancel` | `content_review.cancel` | cancel a `queued` or `running` attempt |
| `POST /api/admin/content-reviews/{subjectType}/{subjectId}/force-manual` | `content_review.force_manual` | escalate now and stop AI for this subject |
| `GET /api/admin/content-review/settings` | `content_review.settings.manage` | active settings, resolved mode, service health — **never secrets** |
| `POST /api/admin/content-review/settings` | `content_review.settings.manage` | publish a new settings version |
| `GET /api/admin/content-review/policies` | `content_review.policy.manage` | list policy versions |
| `GET /api/admin/content-review/policies/{policy}` | `content_review.policy.manage` | one version |
| `POST /api/admin/content-review/policies` | `content_review.policy.manage` | publish a new policy version |
| `POST /api/admin/content-review/provider/test` | `content_review.settings.manage` | connectivity probe returning `{ok, latency_ms, model, error_code}` — no key, no raw response |
| `GET /api/admin/content-review/metrics` | `content_review.costs.view` | counts, outcome mix, override rate, p50/p95 duration, tokens, cost, last success/failure |

`{contentReview}` binds on `public_id` through `HasPublicId`. `{subjectType}` is constrained by a route `whereIn` against `ReviewableSubjectType` values and re-validated in the FormRequest.

---

## 22. Permissions

> **Superseded after Batch 9.** This section describes a fine-grained admin permission model
> that was built and then removed, because the platform has exactly one kind of admin and
> every authenticated admin may run every admin function. `ContentReviewPolicy`,
> `ChecksContentReviewPermissions`, `admin_permissions` and `role_admin_permissions` no
> longer exist, and no endpoint checks a `content_review.*` permission. Access control is
> `auth:sanctum` + `role:admin`, and what an admin may *do* to a review is decided by the
> review's own state. Seller and ownership authorization (`AuctionPolicy`) is untouched.
> The rest of this section is kept as the record of what was originally designed.

A new trait, `app/Policies/ContentReview/Concerns/ChecksContentReviewPermissions.php`, is a copy of `ChecksAuctionPermissions:11-21` that reads `config('content_review.admin_permissions')` while using **the same `users.auction_permissions` JSON column** — no new column, no new permission system, no migration.

```php
'admin_permissions' => [
    'content_review.view',
    'content_review.run',
    'content_review.cancel',
    'content_review.force_manual',
    'content_review.override',
    'content_review.settings.manage',
    'content_review.policy.manage',
    'content_review.costs.view',
],
```

**Deliberately not granted to every admin.** `config/content_review.php` ships with only `content_review.view`, `content_review.run`, and `content_review.cancel` in the `role === 'admin'` fallback list. `settings.manage`, `policy.manage`, `force_manual`, `override`, and `costs.view` must be granted explicitly per user through `users.auction_permissions` — the mechanism `ChecksAuctionPermissions` already supports. This directly satisfies the requirement that not every admin employee can change AI settings.

`ContentReviewPolicy` implements `viewAny`, `view`, `run`, `cancel`, `forceManual`, `manageSettings`, `managePolicy`, and `viewCosts`, and is registered in `AppServiceProvider::boot()` next to the existing `Gate::policy(...)` calls at L43-50.

Approve/reject authorization itself is **unchanged**: `auction.approve` and `auction.review`. `content_review.override` is an *additional* requirement, and only when contradicting a live AI recommendation.

---

## 23. Events و Outbox و Notifications

### 23.1 The outbox topic router — the one change to live dispatch

A new interface `app/Services/Auction/Notifications/OutboxNotifier.php` plus `OutboxTopicRouter`:

```php
match ($message->topic) {
    'auction.events'        => $this->auction->notify($message),
    'content_review.events' => $this->contentReview->notify($message),
    default => throw new \RuntimeException("Unsupported outbox topic: {$message->topic}"),
};
```

`DispatchOutboxMessagesAction` swaps its `AuctionOutboxNotifier` constructor type-hint (currently at L18) for the router. That is the entire change — `AuctionOutboxNotifier` itself is untouched, and its two hard rejections remain intact for auction messages. Its static `supports()` helper (L21-24) also stays as-is, since it is auction-specific by contract.

This is what makes ad-review events free later.

### 23.2 New event types

Topic `content_review.events`, `aggregate_type = ContentReview::class`.

| Event | Audience | Meaning |
|---|---|---|
| `content_review.escalated` | admin | a subject needs human review |
| `content_review.auto_decided` | admin (informational) | an automatic approve or reject happened |
| `content_review.attempt_failed_repeatedly` | admin | attempts exhausted |
| `content_review.provider_unhealthy` | admin | the circuit opened |
| `content_review.budget_exhausted` | admin | a daily or monthly cap was hit |
| `content_review.stale` | admin | a result was invalidated by a content change |

`ContentReviewNotificationCatalog` mirrors `AuctionNotificationCatalog` (`supports()`, `hasAdmin()`), and uncatalogued events dead-letter exactly as they do today.

### 23.3 The admin audience — a new capability

`AdminAlertRecipientResolver` selects users where `role = 'admin'` **and** the `content_review.view` permission check passes, fetching `id, name, fcm_token` only. Delivery reuses the existing database + broadcast channel pattern through a new `ContentReviewAdminNotification`.

**Rate-limited by construction:** one alert per `(event_type, subject)` per hour, and one per `event_type` globally per hour for infrastructure events, implemented with `Cache::add` plus a TTL. This prevents a provider outage from generating thousands of notifications.

### 23.4 Seller notifications — deliberately unchanged

The seller already receives `status.pending_review` on submit, `status.rejected_with_reason` on rejection, and `status.scheduled` / `status.awaiting_seller_deposit` on approval. **No new seller notification is added**, and no notification ever states that a machine made the decision — the seller-facing copy is identical whether a human or the AI approved. Adding a "review started" message would simply duplicate `status.pending_review`.

New language keys live in `lang/{en,ar}/content_review.php` under `messages.*`, `errors.*`, `statuses.*`, `outcomes.*`, `reasons.*`, `violations.*`, and `notifications.*`. The `errors.*` keys must be **exactly equal** to the `ContentReviewException::domain()` codes — the convention `bootstrap/app.php` relies on to render `{message, code}`.

---

## 24. Admin Dashboard Changes

Stack facts that constrain every item below, all verified: Next.js 15 App Router with React 19; TanStack Query v5; a single axios instance in `src/lib/AxiosBase.ts`; shadcn/ui; Tailwind v4; next-intl with `ar` as the default and RTL layout; plain `useState` forms (no react-hook-form in the auctions module); and `useTypedTranslation`, whose `TranslationKey` union is **derived structurally from `ar.json`** (`src/hooks/useTypedTranslation.ts:2-15`) — a missing key is a TypeScript compile error, so every new string must land in **both** `ar.json` and `en.json`.

### 24.1 Review queue — `src/app/dashboard/auctions/review/page.tsx`

Add two columns to the existing column array: **«حالة مراجعة AI»** (a new `AiReviewStatusBadge`) and **«التوصية»** (recommendation plus confidence). Add a filter chip row for `escalated | auto_decided | running | failed`. The existing `MainTable`, `ServerPagination`, `TableSkeleton`, and `QueryError` usage is unchanged.

### 24.2 Review dialog — `src/app/dashboard/auctions/components/AuctionReviewDialog.tsx`

Insert an **`AiRecommendationPanel`** above the existing `RadioGroup` (currently at L77-90), rendering: recommendation plus a confidence meter, a risk badge, the Arabic summary, the violations list (code label, severity, field, evidence), findings, missing information, the image-analysis line, the model and policy line, duration, and a prominent **stale banner** when `is_stale`. **Never raw JSON.**

When the selected `action` contradicts a live recommendation, an inline warning appears and the reason field is emphasised (it is already `required` — `isValid` at L41 already enforces a non-empty trimmed reason). The confirm button label becomes «تجاوز توصية الذكاء الاصطناعي». The dialog keeps its `useDialogConflict`, `CountedTextArea`, and 422-conflict behaviour untouched.

### 24.3 Auction detail — `src/app/dashboard/auctions/[auctionId]/components/`

A new **`AiReviewCard.tsx`** mounted in `OverviewTab` (summary plus the run / retry / cancel / force-manual actions, each gated on `available_actions` from the API), and a new **`AiReviewHistoryTab`** listing attempts with status, outcome, model, duration, cost (only when the API supplies it), and the decision trail.

`TimelineTab` already renders `status_history`; it needs an actor renderer so `actor_type = 'ai'` shows «الذكاء الاصطناعي» and `'system'` shows «النظام» instead of a blank name — today a null `changed_by` would render empty. This is a small but required fix.

### 24.4 Decision labels (requirement 4) — `src/app/dashboard/auctions/lib/constants.ts`

```ts
export const DECISION_LABEL_KEYS = {
  "ai|approved|none":            "contentReview.decision.aiApproved",
  "ai|rejected|none":            "contentReview.decision.aiRejected",
  "ai|recommended|none":         "contentReview.decision.aiRecommended",
  "admin|approved|confirmed":    "contentReview.decision.adminConfirmedApprove",
  "admin|rejected|confirmed":    "contentReview.decision.adminConfirmedReject",
  "admin|approved|overridden":   "contentReview.decision.adminOverrode",
  "admin|rejected|overridden":   "contentReview.decision.adminOverrode",
  "admin|approved|none":         "contentReview.decision.adminOnlyApprove",
  "admin|rejected|none":         "contentReview.decision.adminOnlyReject",
  "system|escalated|unavailable":"contentReview.decision.escalated",
} as const;
```

Arabic copy for the five mandated states:

| Key | Arabic |
|---|---|
| `aiApproved` | تمت الموافقة بواسطة الذكاء الاصطناعي |
| `aiRejected` | تم الرفض بواسطة الذكاء الاصطناعي |
| `adminConfirmedApprove` | أوصى الذكاء الاصطناعي بالموافقة، وأكد الموظف القرار |
| `adminOverrode` | تم تجاوز توصية الذكاء الاصطناعي بواسطة الموظف |
| `escalated` | تعذر إكمال المراجعة التلقائية وتم تحويل المزاد للمراجعة اليدوية |
| `adminOnlyApprove` | تمت الموافقة بواسطة الموظف |

Plus `AI_REVIEW_STATUS_LABEL_KEYS`, `AI_OUTCOME_LABEL_KEYS`, `AI_REASON_LABEL_KEYS`, `RISK_LEVEL_LABEL_KEYS`, `VIOLATION_CODE_LABEL_KEYS`, and new entries on the existing `auctionKeys` object (L141-168): `aiReview`, `aiReviewHistory`, `aiReviewSettings`, `aiReviewPolicies`, `aiReviewMetrics`.

### 24.5 Settings — a fifth tab in `src/app/dashboard/auctions/settings/page.tsx`

The page is currently a four-tab `Tabs` shell (`methods`, `terms`, `config`, `operational`). Add `<TabsTrigger value="ai-review">` → **`AiReviewSettingsTab.tsx`**, built from the two settings idioms already in the module:

- **Status header** (read-only, in the `OperationalSettingsTab` style): service state, resolved mode, circuit state, last success and last failure, today's spend against budget, review counts, the approve / reject / escalate mix, average duration, and average cost.
- **Settings form** (the `ConfigurationVersionForm` two-step `form → confirm` style, since publishing creates an immutable version): enable toggle, mode radio, provider, model, timeout, attempts, thresholds, budgets, concurrency, image-analysis toggle, and automation eligibility (category allowlist, value cap, require-images). The confirm step reuses `ConfigurationConfirmStep`'s type-to-confirm pattern when switching **into** `ai_automatic`.
- **Policy sub-panel** (the `TermsTab` / `ConfigurationTab` style): list versions, view one, publish a new one.
- An **«اختبار الاتصال»** button calling `POST provider/test`, rendering ok / latency / model or a safe error label. **The API key is never sent to, or returned to, the browser.**
- A red **«إيقاف فوري»** kill switch that publishes a settings version with `enabled = false` — one click, one confirm, immediate effect.

The full list of settings exposed on this screen: enable/disable; mode; provider; model; request timeout; max attempts; backoff intervals; max concurrency; daily budget; monthly budget; max output tokens; analyze-images toggle; max images; confidence threshold for approval; confidence threshold for rejection; maximum risk level for auto-approval; prohibited categories; auto-reject categories; human-review categories; deterministic rule parameters; automation category allowlist; automation value cap; require-images-for-automation; plus the read-only health block and the connection test.

### 24.6 Plumbing

`lib/types.ts` gains `AiReviewBlock`, `AiReviewAttempt`, `AiReviewDecision`, `AiReviewSettings`, `AiReviewPolicy`, `AiReviewMetrics`, and the unions `ContentReviewStatus`, `ReviewMode`, `ReviewRecommendation`, `ReviewRiskLevel`, `ContentReviewOutcome`; `AdminAuction` gains `ai_review?: AiReviewBlock`; `NextAdminAction` gains `"awaiting_ai_review"` (which also requires a new row in the existing `NEXT_ADMIN_ACTION_LABEL_KEYS` `Record`, since that map is exhaustive and will fail to compile otherwise — a useful guard).

`hooks/useAuctionQueries.ts` gains `useAiReviewHistory`, `useAiReviewSettings`, `useAiReviewPolicies`, `useAiReviewMetrics`. `hooks/useAuctionMutations.ts` gains `useRunAiReview`, `useRetryAiReview`, `useCancelAiReview`, `useForceManualReview`, `usePublishAiReviewSettings`, `usePublishAiReviewPolicy`, `useTestAiProvider` — all invalidating `["auctions","detail"]` and `["auctions","list"]` exactly as `useReviewAuction` does today. `lib/errors.ts` gains the new codes.

No sidebar entry is added; AI settings live inside the existing «إعدادات المزادات» page.

---

## 25. Settings and Secrets Management

| Setting | Home | Why |
|---|---|---|
| `CONTENT_REVIEW_ENABLED` (master kill switch) | `.env` → `config/content_review.php` | must work without a DB read; ops must be able to disable it during an incident |
| `CONTENT_REVIEW_DEFAULT_MODE` | `.env` | the bootstrap default before any settings row exists |
| `ANTHROPIC_API_KEY` and any provider key | `.env` → `config/services.php` | **never** in the database, never in a response, never in a log |
| provider base URL, API version, default model | `.env` → `config/content_review.php` / `config/services.php` | infrastructure |
| queue name, sweeper batch size, lease seconds, hard content limits | `config/content_review.php` | infrastructure |
| mode, provider selection, model, timeout, attempts, backoff, concurrency, budgets, image toggle, automation eligibility | **DB** `content_review_settings` (versioned) | admin-editable, auditable, instantly reversible |
| prohibited / auto-reject / human-review categories, thresholds, analyzed fields, deterministic rules, prompt version | **DB** `content_review_policies` (versioned, immutable once used) | legally traceable; every review records which policy version decided it |

`config/content_review.php` skeleton, mirroring `config/auction.php`:

```php
return [
    'enabled' => (bool) env('CONTENT_REVIEW_ENABLED', false),
    'default_mode' => env('CONTENT_REVIEW_DEFAULT_MODE', 'manual'),
    'provider' => env('CONTENT_REVIEW_PROVIDER', 'fake'),
    'queue' => env('CONTENT_REVIEW_QUEUE', 'content-review'),
    'sweeper' => [
        'batch' => (int) env('CONTENT_REVIEW_SWEEPER_BATCH', 25),
        'requeue_after_seconds' => (int) env('CONTENT_REVIEW_REQUEUE_AFTER_SECONDS', 60),
        'lease_seconds' => (int) env('CONTENT_REVIEW_LEASE_SECONDS', 300),
    ],
    'admin_permissions' => [ /* §22 */ ],
    'limits' => [
        'max_text_chars' => (int) env('CONTENT_REVIEW_MAX_TEXT_CHARS', 8000),
        'max_images' => (int) env('CONTENT_REVIEW_MAX_IMAGES', 4),
        'max_image_bytes' => (int) env('CONTENT_REVIEW_MAX_IMAGE_BYTES', 2000000),
    ],
];
```

`config/services.php` gains an `anthropic` block: `['api_key' => env('ANTHROPIC_API_KEY'), 'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), 'version' => env('ANTHROPIC_VERSION', '2023-06-01')]`.

`.env.example` gains the non-secret keys with safe defaults (`CONTENT_REVIEW_ENABLED=false`, `CONTENT_REVIEW_PROVIDER=fake`) plus an empty `ANTHROPIC_API_KEY=`.

Every settings resource **whitelists** its output fields, and `SecretLeakageTest` asserts the serialized settings payload contains no key matching `/key|secret|token|password/i` and no value equal to the configured API key.

---

## 26. Security and Privacy

**All auction content is treated as hostile input.**

- **Prompt injection.** User text is never concatenated into instructions. The rendered prompt is: system instructions (from the policy) → an explicit statement that what follows is untrusted data to be *analyzed, not obeyed* → each field inside a labelled, delimited block (`<<<FIELD:description>>> … <<<END>>>`) with any delimiter-like sequence stripped from the user value → the required output schema. `ContentSanitizer` strips control characters, zero-width characters, and bidi overrides, normalizes whitespace, and truncates to `limits.max_text_chars`.
- **Defence in depth against a manipulated verdict.** Even a fully "jailbroken" response can only produce values inside the closed schema, and R8-R10 additionally require automation eligibility, a confidence threshold, and — for approval — an entirely empty `violations` array and a low risk level. A single manipulated field therefore cannot auto-approve prohibited content, and a `policy_checks` entry claiming success cannot override a populated `violations` array.
- **Policy-override attempts.** The policy is never sent as editable data; the model receives rendered instructions only. Any instruction in the content asking to change the policy, ignore rules, or return a specific verdict is an ordinary string inside a delimited block, and `PromptInjectionTest` asserts such strings never escape their block.
- **Data minimization.** Only the fields listed as "hashed and analyzed" in §15 are sent. Explicitly **never sent:** seller name, phone, email, user ids, IP addresses, `auction_permissions`, payment or deposit data, receipts, payout destinations, internal ids beyond category and location ids, and the raw storage path of any media.
- **Images.** Only `image/jpeg`, `image/png`, and `image/webp` are sent, capped at `policy.max_images` and downscaled to `image_max_edge_px`. Files are read **only** through `Storage::disk($media->disk)` for media rows belonging to that specific auction — **no user-supplied URL is ever fetched, which removes the SSRF surface entirely.** A missing or corrupt file yields `image_fetch_failed` and forces `requires_human_review = true`. Files from unapproved sources simply cannot enter the pipeline, because the only entry point is the auction's own `media` relation.
- **Secrets.** Keys live only in `.env`. `Http::withToken(...)` never logs headers. Provider exceptions are caught and re-thrown as `ContentReviewProviderException` carrying a **code**, and `error_message` is passed through `ErrorMessageRedactor`, which strips anything matching key/token patterns, strips URLs and stack frames, and truncates to 500 characters. A test plants a known fake key in the environment and asserts it never appears in `content_reviews`, in any log, or in any API response.
- **Output containment.** Raw provider responses are never persisted and never returned to any client. The admin UI shows only validated, labelled fields. `evidence` excerpts are capped at 200 characters and are HTML-escaped by React by default.
- **Error surfaces.** All client-facing errors come from the closed `ContentReviewErrorCode` set, mapped through `lang/*/content_review.php`. No provider text, no internal class names, no SQL, no stack traces.
- **Authorization.** Every new endpoint goes through `ContentReviewPolicy`. Cost and token data are additionally gated by `content_review.costs.view`.

---

## 27. Performance and Cost Controls

- **Isolation.** The dedicated `content-review` queue (§16) means AI work can never starve `DispatchAuctionOutboxJob`, auction finalization, `StartDueAuctionsJob`, refund processing, payouts, deadline jobs, or realtime broadcasting.
- **Never in the request path.** The seller's submit request performs DB work only; the provider call happens in a worker. Admin read endpoints join at most one `content_reviews` row (`current_marker = 1`) plus its decisions, eager-loaded in `AdminAuctionQuery` so the list endpoint stays N+1-free — asserted by a test that the query count for a 20-row page is constant.
- **Cheap-first ordering.** Deterministic checks run before the provider; a hard deterministic failure escalates without spending a single token.
- **Model tiering.** `settings.model` is per subject type; a cheaper text-only model can be configured for re-checks, with a vision-capable model used only when `analyze_images` is on and images exist.
- **Image economy.** At most `policy.max_images` (default 4) images, downscaled to a 1024px max edge, selected as the primary image plus the next N by `sort_order`. Batch 8 adds `content_review_image_checks` keyed by `image_sha256`, so an unchanged image is never re-analyzed.
- **Deduplication.** The unique `(subject_type, subject_id, content_hash, attempt)` index makes a repeated request for identical content a no-op rather than a second charge.
- **Limits.** `timeout_seconds` (45), `max_output_tokens` (2000), `max_concurrent` (5), `backoff [60, 300, 900]` with jitter, `retryUntil = queued_at + 30 minutes`.
- **Budgets.** `daily_budget_micros` and `monthly_budget_micros` are enforced by `ContentReviewBudgetGuard` *before* the call. Exceeding them degrades to human review — never to auto-approval.
- **Circuit breaker.** Five consecutive failures within five minutes opens the circuit for ten minutes.
- **No batching initially.** Auction submissions arrive at human pace; batching would add latency and complexity for no measurable gain. Revisit only if the p95 queue delay exceeds the SLA.

---

## 28. Logging, Metrics and Alerts

**Logs.** Structured context arrays, matching the style of the existing `Log::` call sites (`AuctionTransaction:27`, `ReconcileAuctionsAction:41`). One `Log::info` per completed review and one `Log::warning` per failure, carrying: `review_public_id, subject_type, subject_id, mode, status, outcome, reason_code, error_code, provider, model, duration_ms, attempt, input_tokens, output_tokens, cost_micros`.

**Never logged:** API keys; prompts; raw responses; the auction title or description; any user PII; image bytes; stack traces from the provider. `ContentReviewLogContext` is the single builder for these arrays, and `LogRedactionTest` asserts it can never emit a forbidden key.

**Metrics.** Following the project's convention of putting observability in the database rather than in logs (`auction_metrics`, `auction_activity_logs`), all metrics are SQL aggregates over `content_reviews` and `content_review_decisions`, served by `GET /api/admin/content-review/metrics`: total reviews; counts by status; counts by outcome; automatic-decision rate; escalation rate; human-override rate (`relation = overridden` divided by decisions that had a recommendation); agreement rate (`confirmed` over the same denominator); p50 and p95 `duration_ms`; p95 queue delay (`started_at − queued_at`); `invalid_structured_output` count; `provider_rate_limited` count; `superseded` (stale) count; average `attempt`; tokens and `cost_micros` per day and per month; and provider health (circuit state, last success, last failure).

**Alerts**, all rate-limited per §23.3: escalation backlog above a threshold; attempts exhausted; the circuit opening; budget at 80% and at 100%; a stale-result spike; and an `invalid_structured_output` rate above 10% over 50 reviews — the signal that a prompt or a model changed under us.

---

## 29. Testing Strategy

**No test may contact a real provider.** `FakeContentReviewProvider` is bound through `phpunit.xml` (`CONTENT_REVIEW_PROVIDER=fake`) and is fully scriptable. Tests follow the project's existing conventions: classic PHPUnit classes under `tests/Feature/ContentReview/`, Pest style under `tests/Unit/ContentReview/`, no `RefreshDatabase` and no `DatabaseTransactions`, and MySQL suites guarded by `guardAgainstNonTestingDatabase()`. Because the default suite runs `QUEUE_CONNECTION=sync` and `CACHE_STORE=array` on sqlite, queue assertions use `Queue::fake()` and cache-based components (circuit breaker, limiter, alert rate limiter) are exercised through the array store.

| Layer | Files | What is asserted |
|---|---|---|
| Unit — decision engine | `tests/Unit/ContentReview/DecisionEngineTest.php` | every row R1-R11, all threshold boundaries (min-1 / min / min+1), and an exhaustive loop proving no `ContentReviewErrorCode` and no non-automatic mode can reach `auto_approved` |
| Unit — validator | `StructuredResultValidatorTest.php` | missing fields, wrong enum values, float or string confidence, oversized arrays, control characters, unknown keys dropped |
| Unit — hasher | `ContentHasherTest.php` | key-order independence, media reordering changes the hash, ignored fields do not |
| Unit — mode resolver | `ReviewModeResolverTest.php` | all five precedence layers, including the env master switch beating an enabled DB row |
| Unit — deterministic checks | `DeterministicChecksTest.php` | each rule in `policy.deterministic_rules`, hard vs soft failure |
| Unit — prompt | `PromptInjectionTest.php` | delimiters stripped from user values; injected instructions never escape their block |
| Unit — architecture | `ContentReviewArchitectureTest.php` | the §7 hard rules, scanned the same way `AuctionCodeQualityTest` scans |
| Feature — flow | `ManualModeTest`, `AiAssistedFlowTest`, `AiAutomaticApproveTest`, `AiAutomaticRejectTest`, `EscalationTest`, `ShadowModeTest` | scenarios 1-6 |
| Feature — failure | `RetryTest`, `KillSwitchTest`, `BudgetGuardTest`, `CircuitBreakerTest`, `ImageHandlingTest` | scenarios 7, 8, 16-18 |
| Feature — integrity | `StaleContentTest`, `IdempotencyTest`, `ModeChangeTest`, `ResubmitAfterRejectionTest` | scenarios 9-11, 14, 15. `ResubmitAfterRejectionTest` drives the whole HTTP loop reject → reopen → PATCH → submit-review and asserts a new `content_reviews` row with a **different** `content_hash`, the old row `superseded`, and the previous verdict never applied |
| Feature — queue | `ContentReviewQueueTest` | dispatched `afterCommit`, on queue `content-review`, `ShouldBeUnique` honoured, the sweeper re-dispatches an orphaned `queued` row, the limiter releases without consuming an attempt |
| Feature — outbox | `ContentReviewOutboxTest` | the router sends `content_review.events` to the new notifier and `auction.events` to the old one **byte-identically**; an unknown topic dead-letters |
| Feature — provider contract | `ProviderContractTest` | §11, including "the key never appears in the exception message" |
| Feature — permissions | `ContentReviewPermissionTest` | all eight permissions, the default-grant split, and the 403 `content_review_override_not_allowed` |
| Feature — admin API | `AdminContentReviewApiTest`, `ContentReviewSettingsApiTest`, `ContentReviewPolicyApiTest`, `ContentReviewMetricsApiTest` | response shapes, permission gating, no secret leakage, policy immutability once used |
| Feature — auction integration | `AuctionAiReviewIntegrationTest` | an AI approval mints a snapshot with `created_by = NULL`, writes `auction_status_history` with `actor_type = 'ai'` and `changed_by = NULL`, creates the correct seller deposit, emits the same seller notification as a human approval, and **creates no `users` row** |
| Feature — no external call in a transaction | `NoExternalCallInTransactionTest` | the fake provider fails the test if `DB::transactionLevel() > 0` when invoked |
| Feature — N+1 | `AdminAuctionQueryContentReviewTest` | a constant query count for a 20-row admin list carrying the `ai_review` block |
| MySQL concurrency | `tests/Feature/ContentReview/ContentReviewConcurrencyMysqlTest.php`, group `mysql-concurrency` | two OS processes racing admin-decision against AI-apply → exactly one decision and one status change; two parallel `run` requests → exactly one active review — modelled on `AuctionConfigurationSnapshotMysqlTest:43` |
| Migration | `ContentReviewMigrationRollbackTest` | `migrate` → `migrate:rollback` → `migrate` is clean, and no existing table was altered |
| Redaction | `SecretLeakageTest`, `LogRedactionTest` | §26 and §28 |
| Regression | the entire existing auction suite | **must stay green with zero edits** — the `ReviewAuctionAction` refactor is behaviour-preserving |
| Dashboard | `npx tsc --noEmit`, `npm run lint`, `npm run build`, `npx vitest run` | plus unit tests for `DECISION_LABEL_KEYS` completeness, the AI badge, and the stale banner |

**Optional smoke suite.** `tests/Smoke/ContentReview/RealProviderSmokeTest.php`, group `real-provider`, **excluded from the default suite** in `phpunit.xml`, skipped unless `CONTENT_REVIEW_SMOKE=1` and a key is present, and hard-guarded to abort when `APP_ENV=production`. Run manually with `php artisan test --group=real-provider`. It asserts only that the real provider returns a schema-valid response within the timeout — it never writes to auction data.

New composer script: `"test:content-review": "@php artisan test --filter=ContentReview"`.

---

## 30. Migration and Backward Compatibility

- **Existing auctions.** Nothing is backfilled. Auctions already sitting in `pending_review` when the feature ships simply have no `content_reviews` row; the API returns `ai_review.current = null` and the dashboard renders «لا توجد مراجعة آلية». An admin may click «تشغيل المراجعة» to create one. A one-off opt-in command `content-review:backfill --dry-run` (Batch 9) can enqueue the existing backlog, but it never runs automatically.
- **Existing history.** `auction_status_history` rows keep `actor_type = 'admin'` and a real `changed_by`. The dashboard's actor renderer keys off `actor_type`, so old rows render exactly as they do today; only `'ai'` and `'system'` take the new branch. No legacy data is rewritten or deleted.
- **Manual mode is the shipped default.** With `CONTENT_REVIEW_ENABLED=false` and settings `mode=manual`, runtime behaviour is byte-identical to today: no review row, no job, no extra query, and the `ai_review` key absent from every response.
- **API compatibility.** Every change is additive. No field is renamed or removed. The mobile app and the seller-facing API are untouched — the only observable difference for a seller is that an approval or rejection may arrive faster.
- **Migrations** are additive and reversible; `down()` drops only the new tables.

---

## 31. Rollout Plan و Shadow Mode

| Stage | Configuration | Exit criterion |
|---|---|---|
| 0 — Structure | tables and code deployed, `CONTENT_REVIEW_ENABLED=false` | migrations applied, the full suite green, zero behaviour change observed in production |
| 1 — Shadow | `enabled=true`, `mode=shadow`, `provider=anthropic` | ≥200 reviews completed; `invalid_structured_output` below 2%; p95 duration under 20s; cost per review within budget |
| 2 — Compare | no configuration change; use `GET metrics` plus a comparison query joining `content_reviews.recommendation` against the admin's actual decision in `auction_status_history` | agreement ≥90% on approvals and ≥85% on rejections, over ≥200 samples |
| 3 — Assisted | `mode=ai_assisted` | admins report the panel is useful; the override rate is stable; review turnaround does not increase |
| 4 — Automatic (narrow) | `mode=ai_automatic` plus `automation.allowed_category_ids = [low-risk ids]`, a `max_starting_amount_minor` cap, and `require_images=true` | ≥100 automatic decisions with zero incorrect auto-approvals found in spot checks |
| 5 — Widen | grow the category allowlist and the value cap one step at a time, publishing a new settings version for each step so each step is its own audit row | metrics hold at every step |

`shadow` is a first-class `ReviewMode` case, not a flag: R1 makes the decision engine return `advisory_only` for it, so **no shadow result can ever reach an auction** — a property proven by `ShadowModeTest` asserting zero extra `auction_status_history` rows.

---

## 32. Rollback and Kill Switch

1. **Instant, no deploy.** Dashboard → «إيقاف فوري» → publishes a settings version with `enabled=false`. In-flight reviews escalate to human at apply time; queued reviews are cancelled by the sweeper. The effect is immediate on the next resolver read.
2. **Instant, no database.** Set `CONTENT_REVIEW_ENABLED=false` in `.env` and run `php artisan config:clear`. This overrides everything, including a corrupted settings row.
3. **Downgrade instead of disable.** Publish `mode=ai_assisted` (or `shadow`) — analysis keeps running for observability while all automation stops.
4. **Code rollback.** Revert the release. The new tables remain (harmless and unreferenced); the touched existing files (`ReviewAuctionAction`, `SubmitAuctionForReviewAction`, `DispatchOutboxMessagesAction`, `AdminAuctionResource`, `AdminAuctionQuery`, `AuctionController`) revert to their current bodies with no data migration required.
5. **Full removal, last resort.** `php artisan migrate:rollback` the five migrations. No existing table is affected, so auction data is untouched.

**Returning to manual requires no migration and causes no downtime** — a hard design constraint that §13.2's resolver and §14's R1 together guarantee.

---

## 33. Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Provider outage or latency spike | reviews stall | circuit breaker, timeout, dedicated queue, escalate-to-human default, admin alert — the auction pipeline is unaffected |
| The model changes behaviour silently | wrong recommendations | `prompt_version` + `policy_version` + `model` recorded on every review; an `invalid_structured_output` rate alert; shadow-mode comparison required before any automation change |
| Cost overrun | budget blown | a pre-call budget guard, per-review cost recorded, daily and monthly caps that degrade to human review, plus 80% and 100% alerts |
| **Incorrect auto-approval of prohibited content** | reputational and legal | automation gated by category allowlist, value cap, zero violations, high confidence, and low risk; a staged rollout; auto-reject requires a *critical* violation; every automatic decision is auditable and reversible by an admin |
| Prompt injection | manipulated verdict | delimiter wrapping, sanitization, a closed output schema, and policy-side (not model-side) automation gating |
| PII leaked to the provider | privacy breach | an explicit allowlist of sent fields; a test asserts the request payload contains no seller identity field |
| **The worker is not configured for the new queue** | reviews never run | a documented deployment step; the sweeper keeps rows visible as `queued` in metrics; an alert fires when p95 queue delay exceeds ten minutes; **auctions remain fully reviewable manually** |
| Nested-transaction deadlock in the apply path | failed decisions | a single transaction owner (§17), a fixed lock order, `AuctionTransaction`'s deadlock retry at the outer level, and a MySQL concurrency test |
| The `ReviewAuctionAction` refactor touches the money path | financial regression | the refactor is a pure extraction with wrappers preserving the exact signature behaviour, covered by `AuctionConfigurationSnapshotTest`, `AuctionConfigurationSnapshotMysqlTest`, and `AuctionFinancialFlowTest`, all of which must pass **unmodified** |
| Dashboard type drift (`TranslationKey` derived from `ar.json`) | build failure | every new key added to `ar.json` **and** `en.json` in the same commit; `npx tsc --noEmit` in every batch's verification commands |
| `PersonalDeliveryResolver` throwing on an unmapped status | dead-lettered notifications | we add **no** new auction status and **no** new notifiable target, so that resolver is never touched |
| Scope creep into a generic moderation framework | delivery risk | §6's explicit non-goals; one enum case; the registry is a plain array binding |

---

## 34. Out of Scope

- Any ad review feature: no `ReviewableSubjectType::Ad`, no ad adapter, no ad endpoint, no ad screen. The design supports it; this work does not implement it.
- Any user-app or mobile change beyond what already happens (status transitions and existing notifications).
- C9 (`review_sla_hours` is frozen into snapshots but never enforced) — documented, not fixed.
- Widening the edit window beyond `draft`: `PATCH` deliberately refuses `pending_review` and every later status.
- Editing an auction *while* an AI review is in flight (the seller must reopen to `draft` first, which only rejection allows).
- Multi-provider voting or ensembles, model fine-tuning, embeddings, or corpus-wide duplicate-listing detection.
- Automated seller appeals, and "request more information" as a first-class auction state (see Decision D6).
- Replacing the manual review path, or removing an admin's ability to decide anything.
- Horizon, Redis queues, or any new infrastructure dependency beyond a second queue name.

---

## 35. Implementation Batches

Nine batches. Each is independently deployable and leaves the system in a working state. Every batch before 6 is invisible in production.

### Execution status

This document remains the plan. The table below records only *what shipped*, batch by batch; the
narrative of each batch's decisions lives in `AI_CONTENT_REVIEW_CHECKPOINT.md`, and the operational
detail lives in `AI_CONTENT_REVIEW_RUNBOOK.md`.

| Batch | Status | Notes on divergence from the plan |
|---|---|---|
| Preliminary — reopen / update draft / resubmit | ✅ | Pre-existing. |
| 1 — contracts, enums, config, permissions | ✅ | — |
| 2 — tables, models, repositories, seeder | ✅ | — |
| 3 — analysis core, providers | ✅ | — |
| 4 — adapter, actions, job, sweeper, guards | ✅ | — |
| 5 — admin API, permissions, outbox router | ✅ | The outbox router lives in a neutral `App\Services\Outbox` namespace, not under `Services/ContentReview/Notifications`, so the architecture test's "no auction reference" rule holds. The metrics endpoint moved to Batch 9. |
| 6 — dashboard, read-only | ✅ | — |
| 7 — `ai_assisted`, human decision, override guard | ✅ | `content_review.confirm` was not added; confirming is authorised by the subject policy. Only override carries its own permission. |
| 8 — `ai_automatic`, automation gates, image cache | ✅ | M6 adds one nullable JSON column (`content_reviews.image_review`) so analyzed-vs-cached counts are renderable. |
| 9 — observability, alerts, operations, docs | ✅ | M7 adds `queue_delay_ms` and `image_cache_hits` so queue-delay percentiles and the image cache hit ratio are exact SQL aggregates instead of JSON scans. A tenth permission, `content_review.metrics.view`, gates the metrics endpoint. `content-review:reconcile` and `content-review:sweep-alerts` were added beyond the plan's list. |

Production defaults after Batch 9 are unchanged from Batch 1: `CONTENT_REVIEW_ENABLED=false`,
seeded `mode=manual`, seeded `auto_reject_categories=[]`.

**Post-Batch-9 correction — the permission model.** §22 designed ten fine-grained
`content_review.*` permissions on the assumption of multiple admin roles. The platform has
one kind of admin, so that whole layer was deleted after Batch 9: no `ContentReviewPolicy`,
no `ChecksContentReviewPermissions`, no permission config, and no `Gate::authorize` in any
content review controller. Access control is `auth:sanctum` + `role:admin`;
`available_actions` and every refusal are decided by domain state alone. The pre-existing
auction permission layer and all seller ownership rules are unchanged. See §22's superseding
note and `AI_CONTENT_REVIEW_RUNBOOK.md` §12.

### Batch 1 — Contracts, enums, config, permissions *(no DB, no behaviour)*

**Goal.** Land the vocabulary and the boundaries so later batches are pure fill-in.
**New.** `app/Domain/ContentReview/Enums/*` (11 enums, §10); `app/Domain/ContentReview/Exceptions/{ContentReviewException,ContentReviewProviderException,ContentReviewErrorCodeCatalog}.php`; `app/Services/ContentReview/Contracts/{ContentReviewProvider,ReviewSubjectAdapter}.php`; `app/DTO/ContentReview/{BaseContentReviewDTO,ReviewContentDTO,ProviderReviewRequest,ProviderReviewResponse,StructuredReviewResult,ReviewDecisionDTO,DeterministicCheckResult,AutomationContext}.php`; `app/Domain/ContentReview/ValueObjects/ReviewPolicy.php`; `config/content_review.php`; `lang/{en,ar}/content_review.php`; `app/Policies/ContentReview/Concerns/ChecksContentReviewPermissions.php`.
**Modified.** `config/services.php` (anthropic block), `.env.example`.
**Migrations / API / Dashboard.** None.
**Tests.** `ContentReviewArchitectureTest` (boundary rules); `EnumContractTest` (every enum value has both an `ar` and an `en` language key).
**Depends on.** Nothing. **Risk.** None. **Rollback.** Delete the new files.
**Done when.** `composer test` is green and `php artisan config:clear && php artisan about` is clean.
**Verify.** `php artisan test --filter=ContentReview` · `composer test`

### Batch 2 — Tables, models, repositories, seeder

**Goal.** Persistence, still unused by any flow.
**New.** Migrations M1-M4; `app/Models/ContentReview/{ContentReview,ContentReviewDecision,ContentReviewPolicy,ContentReviewSetting}.php` (each `final`, using `HasPublicId`, enum casts, immutable datetimes, with `booted()` immutability guards on the two versioned models mirroring `AuctionConfigurationVersion`); `app/Repositories/ContentReview/{ContentReviewRepository,ContentReviewDecisionRepository,ContentReviewPolicyRepository,ContentReviewSettingRepository}.php` (`ContentReviewRepository` carries `insertQueued`, `leaseNextPending`, `lockForDecision`, `supersedeActive`, `activeForSubject`, `historyForSubject`, modelled on `AuctionOutboxRepository`); `database/seeders/ContentReviewSeeder.php`; `database/factories/ContentReview/ContentReviewFactory.php`.
**Modified.** `database/seeders/DatabaseSeeder.php`.
**Tests.** `ContentReviewMigrationRollbackTest`; `ContentReviewUniquenessTest` (both unique indexes, including a MySQL variant proving two parallel inserts yield one active review); `ContentReviewPolicyImmutabilityTest`.
**Depends on.** 1. **Risk.** Index design — validate on MySQL, not only sqlite.
**Rollback.** `php artisan migrate:rollback --step=4`.
**Done when.** migrate → rollback → migrate is clean on **both** sqlite and MySQL, and both unique constraints are proven under concurrency.
**Verify.** `php artisan migrate:fresh --seed --env=testing` · `composer test:auction:mysql` · `php artisan test --filter=ContentReview`

### Batch 3 — Analysis core and the Fake provider *(no auction wiring)*

**Goal.** A fully testable pipeline that can analyze an arbitrary content DTO.
**New.** `app/Services/ContentReview/Support/{ContentHasher,ContentSanitizer,ReviewPromptRenderer,StructuredReviewResultValidator,DeterministicContentChecks,ContentReviewDecisionEngine,ReviewPolicyResolver,ReviewModeResolver,ReviewSubjectRegistry,ErrorMessageRedactor}.php`; `app/Services/ContentReview/Providers/{FakeContentReviewProvider,ContentReviewProviderFactory,AnthropicContentReviewProvider}.php`.
**Modified.** `app/Providers/AppServiceProvider.php` — `register()` binds `ContentReviewProvider` through the factory.
**Tests.** `DecisionEngineTest` (all of R1-R11, boundaries, and the exhaustive no-path-to-auto-approve proof), `StructuredResultValidatorTest`, `ContentHasherTest`, `ReviewModeResolverTest`, `DeterministicChecksTest`, `PromptInjectionTest`, `ProviderContractTest`.
**Depends on.** 1, 2. **Risk.** Decision-table correctness — the highest-value test surface in the project.
**Rollback.** Revert the binding; the classes become unreferenced.
**Done when.** Every decision-table row and every validator rejection path has a passing test.
**Verify.** `php artisan test --filter="DecisionEngine|Validator|Hasher|ModeResolver|Deterministic|PromptInjection|ProviderContract"`

### Batch 4 — Auction adapter, request/apply actions, job, sweeper *(shadow-capable)*

**Goal.** End-to-end machinery wired to auctions but incapable of changing one (settings ship `enabled=false`, `mode=shadow`).
**New.** `app/Services/Auction/ContentReview/AuctionReviewSubjectAdapter.php`; `app/Services/ContentReview/Actions/{RequestContentReviewAction,ProcessContentReviewAction,ApplyContentReviewDecisionAction,CancelContentReviewAction,SupersedeContentReviewAction}.php`; `app/Services/ContentReview/Support/{ContentReviewCircuitBreaker,ContentReviewBudgetGuard,ContentReviewConcurrencyLimiter,ContentReviewImageLoader}.php`; `app/Jobs/ContentReview/ProcessContentReviewJob.php`; `app/Console/Commands/ContentReview/DispatchPendingContentReviews.php`.
**Modified — the four surgical edits.**
1. `app/Services/Auction/Actions/ReviewAuctionAction.php` — extract `approveLocked()` and `rejectLocked()`; the public methods become wrappers; the actor becomes `?int $actorId, string $actorType = 'admin'`.
2. `app/Services/Auction/Actions/SubmitAuctionForReviewAction.php` — call `RequestContentReviewAction` inside the existing transaction, after the transition.
3. `routes/console.php` — `app(Schedule::class)->command('content-review:dispatch-pending')->everyMinute()->withoutOverlapping();`
4. `app/Providers/AppServiceProvider.php` — register the auction adapter in `ReviewSubjectRegistry`.

**Migrations / API / Dashboard.** None.
**Tests.** `ShadowModeTest`, `ContentReviewQueueTest`, `RetryTest`, `CircuitBreakerTest`, `BudgetGuardTest`, `IdempotencyTest`, `StaleContentTest`, `ImageHandlingTest`, `NoExternalCallInTransactionTest`, `AuctionReviewActionRefactorTest` — **plus the entire existing auction suite staying green with no edits.**
**Depends on.** 3. **Risk.** The `ReviewAuctionAction` refactor touches the money path; it is behaviour-preserving and covered by three existing financial test files that must pass unmodified.
**Rollback.** Revert the four edits; the new classes go inert.
**Done when.** Submitting an auction in shadow mode creates a `completed` review with `outcome = advisory_only` and provably **zero** extra rows in `auction_status_history`.
**Verify.** `composer test` · `composer test:auction` · `composer test:auction:mysql` · `php artisan test --filter=ContentReview`

### Batch 5 — Admin read API, permissions, outbox router

**Goal.** Admins can see AI results. Still no automation.
**New.** `app/Http/Controllers/ContentReview/{ContentReviewController,ContentReviewSettingsController,ContentReviewPolicyController,ContentReviewMetricsController}.php`; `app/Http/Requests/ContentReview/{RunContentReviewRequest,PublishContentReviewSettingsRequest,PublishContentReviewPolicyRequest}.php`; `app/Http/Resources/ContentReview/{ContentReviewResource,ContentReviewDecisionResource,ContentReviewSettingsResource,ContentReviewPolicyResource,ContentReviewMetricsResource}.php`; `app/Policies/ContentReview/ContentReviewPolicy.php`; `app/Services/ContentReview/Actions/{PublishContentReviewSettingsAction,PublishContentReviewPolicyAction,TestContentReviewProviderAction}.php`; `app/Repositories/ContentReview/Queries/ContentReviewMetricsQuery.php`; `routes/api/content_review.php`; `app/Services/Auction/Notifications/{OutboxNotifier,OutboxTopicRouter}.php`; `app/Services/ContentReview/Notifications/{ContentReviewOutboxNotifier,ContentReviewNotificationCatalog,AdminAlertRecipientResolver}.php`; `app/Notifications/ContentReviewAdminNotification.php`.
**Modified.** `routes/api.php` (require the new route file); `DispatchOutboxMessagesAction` (one type-hint → the router); `AdminAuctionResource` (additive `ai_review` block plus `awaiting_ai_review`); `AdminAuctionQuery` (eager-load the active review); `config/content_review.php` (permissions); `AppServiceProvider` (`Gate::policy`).
**Tests.** `AdminContentReviewApiTest`, `ContentReviewSettingsApiTest`, `ContentReviewPolicyApiTest`, `ContentReviewMetricsApiTest`, `ContentReviewPermissionTest`, `ContentReviewOutboxTest`, `SecretLeakageTest`, `AdminAuctionQueryContentReviewTest`.
**Depends on.** 4. **Risk.** The outbox router touches live dispatch — `ContentReviewOutboxTest` must prove `auction.events` behaviour is byte-identical, and `AuctionNotificationDispatchTest` must pass unchanged.
**Rollback.** Revert the router type-hint and the resource block; the new routes become unreachable.
**Done when.** Every new endpoint returns the documented shape, is permission-gated, and leaks no secret.
**Verify.** `composer test` · `php artisan route:list --path=content-review` · `php artisan test --filter="ContentReview|AuctionNotificationDispatch"`

### Batch 6 — Dashboard, read-only

**Goal.** Admins see the AI panel and the settings screen. No new decision affordances yet.
**New (`soom-dashboard`).** `components/{AiReviewStatusBadge,AiRecommendationPanel,AiReviewStaleBanner}.tsx`; `[auctionId]/components/{AiReviewCard,AiReviewHistoryTab}.tsx`; `settings/components/{AiReviewSettingsTab,AiReviewStatusHeader,AiReviewPolicyPanel}.tsx`.
**Modified.** `lib/{types,constants,errors}.ts`, `hooks/useAuctionQueries.ts`, `review/page.tsx`, `components/AuctionReviewDialog.tsx`, `[auctionId]/page.tsx`, `[auctionId]/components/TimelineTab.tsx`, `settings/page.tsx`, `src/i18n/languages/{ar,en}.json`.
**Backend / Migrations.** None.
**Tests.** vitest units for the badge, `DECISION_LABEL_KEYS` completeness (a compile-time exhaustive `Record`), and the stale banner, plus the three build gates.
**Depends on.** 5. **Risk.** `TranslationKey` is derived from `ar.json`, so a missing key breaks the build — which is the desired guard.
**Rollback.** Revert the dashboard commit; the backend is unaffected.
**Done when.** In `shadow` mode an admin sees the recommendation panel and the settings screen, and every existing screen is unchanged.
**Verify (in `soom-dashboard`).** `npx tsc --noEmit` · `npm run lint` · `npm run build` · `npx vitest run`

### Batch 7 — `ai_assisted`, human decision recording, override guard, admin actions

**Goal.** The AI becomes useful; the admin still decides everything.
**New.** `app/Services/ContentReview/Support/{ContentReviewOverrideGuard,ContentReviewDecisionRecorder}.php`; `app/Services/ContentReview/Actions/ForceManualReviewAction.php`.
**Modified.** `app/Http/Controllers/Auction/AuctionController.php::review` — run the override guard and record the admin decision, with the method staying thin and all logic in services. `ReviewAuctionRequest` gets **no rule change** (the override reason is the existing `reason`). `AuctionPolicy` is untouched.
**Dashboard.** `AuctionReviewDialog` gains the override warning and the button-label switch; `AiReviewCard` gains run / retry / cancel / force-manual gated on `available_actions`; `useAuctionMutations.ts` gains the mutations.
**Settings.** Publish `mode=ai_assisted`.
**Tests.** `AiAssistedFlowTest`, `AiOverrideTest`, `ModeChangeTest`, `ContentReviewConcurrencyMysqlTest` (scenarios 12-13), plus a dashboard vitest for the override warning.
**Depends on.** 6. **Risk.** The override guard could block a legitimate urgent decision — mitigated by making it a *permission plus reason* requirement rather than a hard block, and by the kill switch.
**Rollback.** Publish `mode=shadow`.
**Done when.** An admin decision contradicting a live recommendation is recorded as `relation = overridden` with a mandatory reason, and an agreeing one as `confirmed`.
**Verify.** `composer test` · `composer test:auction:mysql` · the dashboard build gates

### Batch 8 — `ai_automatic`, automation gates, image cache

**Goal.** Enable automation for narrow, low-risk cases.
**New.** Migration M5 plus `app/Models/ContentReview/ContentReviewImageCheck.php` and `ContentReviewImageCheckRepository`; `AutomationEligibilityResolver` (category allowlist, value cap, image requirement) consumed by `AuctionReviewSubjectAdapter::automationContext()`.
**Modified.** `ContentReviewImageLoader` (reuse cached checks by `image_sha256`); the settings `automation` block becomes enforced.
**Dashboard.** The automation sub-form in `AiReviewSettingsTab` plus the type-to-confirm step when switching into `ai_automatic`.
**Tests.** `AiAutomaticApproveTest`, `AiAutomaticRejectTest`, `AutomationEligibilityTest`, `ImageCacheTest`, `AuctionAiReviewIntegrationTest`.
**Depends on.** 7 plus the Stage-2 accuracy gate in §31.
**Risk.** The highest-risk batch — mitigated by the eligibility gates, the narrow allowlist, and the kill switch. **Rollback.** Publish `mode=ai_assisted` (instant, no deploy).
**Done when.** An auto-approved auction is indistinguishable from a human-approved one to the seller, fully attributable to the AI in the admin UI, and every gate has a failing-path test.
**Verify.** `composer test` · `composer test:auction` · `composer test:auction:mysql` · the dashboard build gates

### Batch 9 — Observability, alerts, docs, optional extras

**Goal.** Operate it.
**New.** `app/Services/ContentReview/Support/ContentReviewLogContext.php`; alert rate limiting in `AdminAlertRecipientResolver`; `app/Console/Commands/ContentReview/BackfillContentReviews.php` (opt-in, `--dry-run` by default); the operations runbook.
**Modified.** The metrics endpoint gains alert-threshold fields; the dashboard status header gains alert states.
**Tests.** `LogRedactionTest`, `AlertRateLimitTest`, `MetricsAccuracyTest`, `BackfillCommandTest`.
**Depends on.** 8. **Risk.** None. **Rollback.** Revert.
**Done when.** Every metric in §28 is served and rendered, and the runbook covers all four rollback levers.
**Verify.** `composer test` · the dashboard build gates

**As built.** Migration M7 adds `content_reviews.queue_delay_ms` and `content_reviews.image_cache_hits`; `ContentReviewMetricsRepository` + `ContentReviewMetricsReporter` + `ContentReviewMetricsController` serve `GET /api/admin/content-review/metrics` behind a new `content_review.metrics.view` permission; `ContentReviewAlertMonitor` evaluates six edge-triggered alert conditions with a recovery event, swept by `content-review:sweep-alerts`; `ContentReviewWorkerHeartbeat` records the last job actually processed (never a "worker online" claim); `content-review:reconcile` reports crash debris and applies two idempotent repairs; the dashboard gains a Metrics tab. The runbook is `AI_CONTENT_REVIEW_RUNBOOK.md` at the repository root, alongside the other operational documents, rather than under `docs/` — the project has no `docs/` directory. Tests: `LogRedactionTest`, `AlertRateLimitTest`, `ContentReviewMetricsApiTest` (the plan's `MetricsAccuracyTest`), `BackfillCommandTest`, `ReconciliationTest`, `KillSwitchTest`.

---

## 36. File-by-File Execution Checklist

Legend: **N** = new, **M** = modify. Paths are repo-relative. Backend root `C:\Users\pc\Desktop\SB\soom`; dashboard root `C:\Users\pc\Desktop\SB\soom-dashboard`.

### 36.1 Backend — new files

| Path | Purpose | Key members | Relates to | Proven by |
|---|---|---|---|---|
| `app/Domain/ContentReview/Enums/ReviewableSubjectType.php` | subject registry key | `case Auction` | registry, tables | `EnumContractTest` |
| `…/Enums/ReviewMode.php` | mode | `allowsAutomaticDecision()`, `callsProvider()` | resolver, engine | `ReviewModeResolverTest` |
| `…/Enums/ContentReviewStatus.php` | attempt state | `isTerminal()` | repository, job | `ContentReviewQueueTest` |
| `…/Enums/ContentReviewOutcome.php` | decision result | — | engine, resource | `DecisionEngineTest` |
| `…/Enums/ReviewRecommendation.php` | AI verdict | — | validator, engine | `StructuredResultValidatorTest` |
| `…/Enums/ReviewRiskLevel.php` | risk | `isAtMost()` | engine R10 | `DecisionEngineTest` |
| `…/Enums/ViolationSeverity.php` | severity | `isAtLeast()` | engine R9 | `DecisionEngineTest` |
| `…/Enums/ReviewTrigger.php` | why enqueued | — | request action | `ShadowModeTest` |
| `…/Enums/DecisionActorType.php` | admin / ai / system | — | decisions table | `AiOverrideTest` |
| `…/Enums/DecisionRelation.php` | confirmed / overridden | — | decisions table | `AiOverrideTest` |
| `…/Enums/ContentReviewErrorCode.php` | closed error set | `isRetryable()` | job, providers | `RetryTest` |
| `app/Domain/ContentReview/Exceptions/ContentReviewException.php` | domain errors | `static domain(string $key, array $replace = [])` — factory only, mirroring `AuctionException` | language keys | `ContentReviewArchitectureTest` |
| `…/Exceptions/ContentReviewProviderException.php` | provider errors | carries a `ContentReviewErrorCode` | adapters | `ProviderContractTest` |
| `…/Exceptions/ContentReviewErrorCodeCatalog.php` | code ↔ language mapping | `const CODES` | `bootstrap/app.php` rendering | `EnumContractTest` |
| `app/Domain/ContentReview/ValueObjects/ReviewPolicy.php` | typed policy | `thresholds()`, `autoRejectCategories()`, `humanReviewCategories()`, `analyzedTextFields()`, `maxImages()`, `deterministicRules()`, `maxRiskLevelForAutoApprove()` | engine, renderer | `DecisionEngineTest` |
| `app/Services/ContentReview/Contracts/ContentReviewProvider.php` | provider port | `name()`, `analyze()` | adapters, factory | `ProviderContractTest` |
| `app/Services/ContentReview/Contracts/ReviewSubjectAdapter.php` | subject port | `type()`, `buildContent()`, `isReviewable()`, `automationContext()`, `applyDecision()` | auction adapter | `ContentReviewArchitectureTest` |
| `app/DTO/ContentReview/BaseContentReviewDTO.php` | DTO base | `abstract readonly`, `toArray()`, `final jsonSerialize()` — the shape of `BaseAuctionDTO` | all DTOs | — |
| `…/ReviewContentDTO.php` | normalized content | `textBlocks`, `structuredFacts`, `images`, `toHashable()` | hasher, renderer | `ContentHasherTest` |
| `…/ProviderReviewRequest.php`, `…/ProviderReviewResponse.php` | provider I/O | §11 | providers | `ProviderContractTest` |
| `…/StructuredReviewResult.php` | validated verdict | constructible only by the validator | engine | `StructuredResultValidatorTest` |
| `…/DeterministicCheckResult.php` | rule findings | `hasHardFailure()`, `findings()` | engine R3 | `DeterministicChecksTest` |
| `…/AutomationContext.php` | eligibility | `isAutomationEligible`, `reasons` | engine R8 | `AutomationEligibilityTest` |
| `…/ReviewDecisionDTO.php` | engine output | `outcome`, `reasonCode`, `requiresOverrideReason` | apply action | `DecisionEngineTest` |
| `app/Services/ContentReview/Support/ContentHasher.php` | canonical sha256 | `hash(array $data): string` — copies `AuctionConfigurationSnapshotHasher` | adapter, apply | `ContentHasherTest` |
| `…/Support/ContentSanitizer.php` | untrusted text | `sanitize()`, `stripDelimiters()`, `truncate()` | renderer | `PromptInjectionTest` |
| `…/Support/ReviewPromptRenderer.php` | policy → prompt | `render(ReviewPolicy, ReviewContentDTO)`, `version()` | provider request | `PromptInjectionTest` |
| `…/Support/StructuredReviewResultValidator.php` | schema gate | `validate(array, ReviewPolicy)` | process action | `StructuredResultValidatorTest` |
| `…/Support/DeterministicContentChecks.php` | free checks | `run(ReviewContentDTO, ReviewPolicy)` | engine | `DeterministicChecksTest` |
| `…/Support/ContentReviewDecisionEngine.php` | **pure** decisions | `decide(...)` per §14 | apply action | `DecisionEngineTest` |
| `…/Support/ReviewPolicyResolver.php` | active policy | `activeFor(ReviewableSubjectType)` | process action | `ContentReviewPolicyApiTest` |
| `…/Support/ReviewModeResolver.php` | mode precedence | `resolve()`, `effectiveSettings()` | request + apply | `ReviewModeResolverTest` |
| `…/Support/ReviewSubjectRegistry.php` | adapter lookup | `for(ReviewableSubjectType)` | everywhere | `ContentReviewArchitectureTest` |
| `…/Support/ErrorMessageRedactor.php` | safe errors | `redact(Throwable): string` | job, providers | `SecretLeakageTest` |
| `…/Support/ContentReviewCircuitBreaker.php` | provider health | `isOpen()`, `recordSuccess()`, `recordFailure()` | process action | `CircuitBreakerTest` |
| `…/Support/ContentReviewBudgetGuard.php` | spend cap | `hasBudget()`, `record(int $costMicros)` | process action | `BudgetGuardTest` |
| `…/Support/ContentReviewConcurrencyLimiter.php` | slots | `acquire()`, `release()` | job | `ContentReviewQueueTest` |
| `…/Support/ContentReviewImageLoader.php` | image bytes | `load(ReviewContentDTO, ReviewPolicy)`, downscale, cache lookup | provider request | `ImageHandlingTest`, `ImageCacheTest` |
| `…/Support/ContentReviewOverrideGuard.php` | override rule | `assertAllowed(User $user, string $subjectType, int $subjectId, string $action)` | `AuctionController::review` | `AiOverrideTest` |
| `…/Support/ContentReviewDecisionRecorder.php` | write decisions | `recordAdmin()`, `recordAi()`, `recordSystem()` | apply action, controller | `AiAssistedFlowTest` |
| `…/Support/AutomationEligibilityResolver.php` | R8 inputs | `for(ReviewableSubjectType, int $subjectId)` | adapter | `AutomationEligibilityTest` |
| `…/Support/ContentReviewLogContext.php` | safe log arrays | `for(ContentReview): array` | job | `LogRedactionTest` |
| `app/Services/ContentReview/Providers/FakeContentReviewProvider.php` | test double | `respondWith()`, `failWith()`, `calls()` | every test | all |
| `…/Providers/AnthropicContentReviewProvider.php` | real adapter | `analyze()` via `Http::withToken()` with schema-constrained output | factory | `ProviderContractTest` |
| `…/Providers/ContentReviewProviderFactory.php` | selection | `make(): ContentReviewProvider` | `AppServiceProvider` | `ProviderContractTest` |
| `app/Services/ContentReview/Actions/RequestContentReviewAction.php` | enqueue | `execute(ReviewableSubjectType, int, ReviewTrigger, ?int $requestedBy)`; supersede + insert **inside the caller's transaction**; dispatch `afterCommit` | submit action, controller | `ShadowModeTest`, `IdempotencyTest` |
| `…/Actions/ProcessContentReviewAction.php` | the pipeline | `execute(string $publicId)`: lease → checks → gates → provider → validate → persist | job, sweeper | `RetryTest` |
| `…/Actions/ApplyContentReviewDecisionAction.php` | **the only transaction owner** | `execute(ContentReview)`: lock → re-hash → verify → engine → decision row → `adapter->applyDecision()` | adapter | `AiAutomaticApproveTest` |
| `…/Actions/CancelContentReviewAction.php` | admin cancel | `execute(ContentReview, int $adminId)` | controller | `AdminContentReviewApiTest` |
| `…/Actions/SupersedeContentReviewAction.php` | invalidate | `execute(ReviewableSubjectType, int)` | request action | `StaleContentTest` |
| `…/Actions/ForceManualReviewAction.php` | escalate now | `execute(ReviewableSubjectType, int, int $adminId)` | controller | `AdminContentReviewApiTest` |
| `…/Actions/PublishContentReviewSettingsAction.php` | settings version | `execute(array $settings, int $creatorId)` — copies `CreateConfigurationVersionAction` | controller | `ContentReviewSettingsApiTest` |
| `…/Actions/PublishContentReviewPolicyAction.php` | policy version | the same shape | controller | `ContentReviewPolicyApiTest` |
| `…/Actions/TestContentReviewProviderAction.php` | connectivity | `execute(): array{ok, latency_ms, model, error_code}` — never returns the key | controller | `SecretLeakageTest` |
| `app/Services/Auction/ContentReview/AuctionReviewSubjectAdapter.php` | **the only auction ↔ AI bridge** | `type()`, `buildContent()` (§15), `isReviewable()` (status is `PendingReview`), `automationContext()`, `applyDecision()` → `ReviewAuctionAction::approveLocked/rejectLocked($id, null, $reason, 'ai')` | both domains | `AuctionAiReviewIntegrationTest` |
| `app/Services/Auction/Notifications/OutboxNotifier.php` | interface | `notify(OutboxMessage): void` | router | `ContentReviewOutboxTest` |
| `app/Services/Auction/Notifications/OutboxTopicRouter.php` | topic fan-out | `notify()` per §23.1 | dispatch action | `ContentReviewOutboxTest` |
| `app/Services/ContentReview/Notifications/ContentReviewOutboxNotifier.php` | admin fan-out | `notify()` | router | `ContentReviewOutboxTest` |
| `…/Notifications/ContentReviewNotificationCatalog.php` | event registry | `supports()`, `hasAdmin()` | notifier | `ContentReviewOutboxTest` |
| `…/Notifications/AdminAlertRecipientResolver.php` | admin audience | `recipients(string $eventType)`, rate limiting | notifier | `AlertRateLimitTest` |
| `app/Notifications/ContentReviewAdminNotification.php` | delivery | `via() = ['database','broadcast']` | sender | `ContentReviewOutboxTest` |
| `app/Jobs/ContentReview/ProcessContentReviewJob.php` | worker | §16 | queue | `ContentReviewQueueTest` |
| `app/Console/Commands/ContentReview/DispatchPendingContentReviews.php` | sweeper | `content-review:dispatch-pending` | scheduler | `ContentReviewQueueTest` |
| `app/Console/Commands/ContentReview/BackfillContentReviews.php` | opt-in backfill | `--dry-run` default | operations | `BackfillCommandTest` |
| `app/Models/ContentReview/ContentReview.php` | attempt | `HasPublicId`, enum casts, `decisions()`, `policy()`, `scopeActive()` | repositories | `ContentReviewUniquenessTest` |
| `…/ContentReviewDecision.php` | decision | `review()`, `decidedBy()` | repositories | `AiOverrideTest` |
| `…/ContentReviewPolicy.php` | policy version | `booted()` immutability guard | resolver | `ContentReviewPolicyImmutabilityTest` |
| `…/ContentReviewSetting.php` | settings version | `booted()` immutability guard | resolver | `ContentReviewSettingsApiTest` |
| `app/Repositories/ContentReview/ContentReviewRepository.php` | persistence | `insertQueued()`, `leaseNextPending()`, `lockForDecision()`, `supersedeActive()`, `activeForSubject()`, `historyForSubject()` | actions | `ContentReviewUniquenessTest` |
| `…/ContentReviewDecisionRepository.php` | decisions | `record()`, `forSubject()` | recorder | `AiOverrideTest` |
| `…/ContentReviewPolicyRepository.php`, `…/ContentReviewSettingRepository.php` | versions | `active()`, `nextVersionNumber()`, `create()` — copies `AuctionConfigurationRepository` | resolvers | API tests |
| `…/Queries/ContentReviewMetricsQuery.php` | aggregates | §28 | metrics controller | `MetricsAccuracyTest` |
| `app/Policies/ContentReview/ContentReviewPolicy.php` | authorization | eight abilities | controllers, resource | `ContentReviewPermissionTest` |
| `app/Policies/ContentReview/Concerns/ChecksContentReviewPermissions.php` | permission check | copy of `ChecksAuctionPermissions` reading `config('content_review.admin_permissions')` | policy | `ContentReviewPermissionTest` |
| `app/Http/Controllers/ContentReview/ContentReviewController.php` | subject endpoints | `history`, `show`, `run`, `retry`, `cancel`, `forceManual` — thin, `Gate::authorize` first | routes | `AdminContentReviewApiTest` |
| `…/ContentReviewSettingsController.php` | settings | `show`, `store`, `testProvider` | routes | `ContentReviewSettingsApiTest` |
| `…/ContentReviewPolicyController.php` | policies | `index`, `show`, `store` | routes | `ContentReviewPolicyApiTest` |
| `…/ContentReviewMetricsController.php` | metrics | `show` | routes | `ContentReviewMetricsApiTest` |
| `app/Http/Requests/ContentReview/*.php` (3) | validation | closed enums, bounded arrays | controllers | API tests |
| `app/Http/Resources/ContentReview/*.php` (5) | serialization | labels via `__('content_review.*')`; **whitelist only** | controllers | `SecretLeakageTest` |
| `routes/api/content_review.php` | routes | §21.2 | `routes/api.php` | `route:list` |
| `config/content_review.php` | configuration | §25 | everything | `EnumContractTest` |
| `lang/en/content_review.php`, `lang/ar/content_review.php` | copy | `messages / errors / statuses / outcomes / reasons / violations / notifications` | resources, exceptions | `EnumContractTest` |
| `database/migrations/…` (M1-M5) | schema | §9 | models | `ContentReviewMigrationRollbackTest` |
| `database/seeders/ContentReviewSeeder.php` | defaults | policy v1 plus settings v1 (**disabled**) | seeding | `ContentReviewSettingsApiTest` |
| `database/factories/ContentReview/ContentReviewFactory.php` | fixtures | states `queued`, `completed`, `failed`, `stale` | tests | all |
| `docs/CONTENT_REVIEW_OPERATIONS.md` | runbook | worker, kill switch, budget, alerts | operations | — |

### 36.2 Backend — modified files

| Path | Change | Why | Guarded by |
|---|---|---|---|
| `app/Services/Auction/Actions/ReviewAuctionAction.php` | extract `approveLocked()` / `rejectLocked()`; the public methods become wrappers; the actor becomes `?int $actorId, string $actorType = 'admin'` | single transaction owner (C3) plus the AI actor (C4) | `AuctionConfigurationSnapshotTest`, `AuctionConfigurationSnapshotMysqlTest`, `AuctionFinancialFlowTest`, `AuctionReviewActionRefactorTest` |
| `app/Services/Auction/Actions/SubmitAuctionForReviewAction.php` | after the transition and inside the same transaction, call `RequestContentReviewAction`; the job dispatches `afterCommit` | asynchronous enqueue with no external call inside the transaction | `ShadowModeTest`, `NoExternalCallInTransactionTest` |
| `app/Services/Auction/Actions/DispatchOutboxMessagesAction.php` | change the constructor type-hint from `AuctionOutboxNotifier` to `OutboxNotifier` (the router) | allow non-auction outbox events | `ContentReviewOutboxTest`, `AuctionNotificationDispatchTest` |
| `app/Http/Resources/Auction/AdminAuctionResource.php` | additive `ai_review` block gated by `Gate::forUser($user)->allows('viewAny', ContentReview::class)`; `nextAdminAction()` gains `awaiting_ai_review` | admin visibility | `AdminContentReviewApiTest`, `ContentReviewPermissionTest` |
| `app/Repositories/Auction/Queries/AdminAuctionQuery.php` | eager-load the active review and its decisions | avoid N+1 | `AdminAuctionQueryContentReviewTest` |
| `app/Http/Controllers/Auction/AuctionController.php` | `review()` calls `ContentReviewOverrideGuard`, then `ContentReviewDecisionRecorder` | the override rule plus the decision trail | `AiOverrideTest` |
| `app/Providers/AppServiceProvider.php` | `register()`: provider binding plus subject-registry binding; `boot()`: `Gate::policy(ContentReview::class, ContentReviewPolicy::class)` | wiring | `ContentReviewPermissionTest` |
| `routes/api.php` | `require __DIR__ . '/api/content_review.php';` inside the `api_maintenance` group | routing | `route:list` |
| `routes/console.php` | schedule `content-review:dispatch-pending` `everyMinute()->withoutOverlapping()` | the sweeper | `ContentReviewQueueTest` |
| `config/services.php` | an `anthropic` block | secrets stay in env | `SecretLeakageTest` |
| `.env.example` | `CONTENT_REVIEW_*` with safe defaults plus an empty `ANTHROPIC_API_KEY` | operations | — |
| `database/seeders/DatabaseSeeder.php` | register `ContentReviewSeeder` | defaults | — |
| `composer.json` | add the `test:content-review` script | developer experience | — |
| `phpunit.xml` | `CONTENT_REVIEW_ENABLED=true`, `CONTENT_REVIEW_PROVIDER=fake`; exclude the `real-provider` group | test isolation | — |

**Deliberately not modified:** `AuctionStatus`, `AuctionStateMachine`, `AuctionPolicy`, `AuctionAudit`, `AuctionAuditRepository`, `AuctionNotificationCatalog`, `PersonalDeliveryResolver`, `AuctionOutboxNotifier`, `ReviewAuctionRequest`, `UserAuctionResource`, `AuctionRepository`, and **every existing migration**.

### 36.3 Dashboard — new files

| Path | Purpose | Pattern to copy |
|---|---|---|
| `src/app/dashboard/auctions/components/AiReviewStatusBadge.tsx` | status / outcome chip | `AuctionStatusBadge.tsx` |
| `…/components/AiRecommendationPanel.tsx` | recommendation, confidence, violations, findings | `SnapshotCard.tsx` layout with `Alert` for warnings |
| `…/components/AiReviewStaleBanner.tsx` | «النتيجة لم تعد صالحة» | `ConflictAlert` in `DialogConflict.tsx` |
| `…/[auctionId]/components/AiReviewCard.tsx` | detail card plus actions | `DeadlinesCard.tsx` |
| `…/[auctionId]/components/AiReviewHistoryTab.tsx` | attempt history table | `TimelineTab.tsx` |
| `…/settings/components/AiReviewSettingsTab.tsx` | settings tab shell | `PaymentMethodsTab.tsx` |
| `…/settings/components/AiReviewStatusHeader.tsx` | read-only health and metrics | `OperationalSettingsTab.tsx` |
| `…/settings/components/AiReviewPolicyPanel.tsx` | policy versions plus publish | `ConfigurationTab.tsx` + `ConfigurationVersionForm.tsx` + `ConfigurationConfirmStep.tsx` |

### 36.4 Dashboard — modified files

| Path | Change |
|---|---|
| `src/app/dashboard/auctions/lib/types.ts` | `AiReviewBlock`, `AiReviewAttempt`, `AiReviewDecision`, `AiReviewSettings`, `AiReviewPolicy`, `AiReviewMetrics` plus the enum unions; `AdminAuction.ai_review?`; `NextAdminAction` gains `"awaiting_ai_review"` |
| `…/lib/constants.ts` | `DECISION_LABEL_KEYS` plus the five other label maps; a new row in `NEXT_ADMIN_ACTION_LABEL_KEYS`; `auctionKeys.aiReview*` |
| `…/lib/errors.ts` | the new codes in `AuctionErrorCode` |
| `…/hooks/useAuctionQueries.ts` | `useAiReviewHistory`, `useAiReviewSettings`, `useAiReviewPolicies`, `useAiReviewMetrics` |
| `…/hooks/useAuctionMutations.ts` | `useRunAiReview`, `useRetryAiReview`, `useCancelAiReview`, `useForceManualReview`, `usePublishAiReviewSettings`, `usePublishAiReviewPolicy`, `useTestAiProvider` |
| `…/review/page.tsx` | two columns plus filter chips |
| `…/components/AuctionReviewDialog.tsx` | `AiRecommendationPanel` above the `RadioGroup`; the override warning; the button-label switch |
| `…/[auctionId]/page.tsx` | mount `AiReviewCard` and `AiReviewHistoryTab` |
| `…/[auctionId]/components/TimelineTab.tsx` | render `actor_type = 'ai'` / `'system'` as a label instead of a blank name |
| `…/settings/page.tsx` | a fifth `TabsTrigger` / `TabsContent` |
| `src/i18n/languages/ar.json`, `src/i18n/languages/en.json` | every new key under `contentReview.*` and `auction.review.*` — **both files, same commit** |

---

## 37. Acceptance Criteria

**Functional**

1. With `CONTENT_REVIEW_ENABLED=false`, submit / approve / reject behaviour and every response body are identical to today, and no `content_reviews` row is created.
2. In `ai_assisted`, submitting an auction produces exactly one `completed` review whose recommendation, confidence, risk, summary, violations, findings, and missing information are visible in the admin API and the dashboard, with the auction still `pending_review`.
3. In `ai_automatic`, an eligible clean auction is approved with no human action: one `content_review_decisions` row (`ai`, `decided_by_id = NULL`), one `auction_status_history` row (`actor_type = 'ai'`, `changed_by = NULL`), one immutable configuration snapshot (`created_by = NULL`), the correct seller-deposit obligation, and the same seller notification a human approval produces.
4. In `ai_automatic`, an auction with a critical violation at or above `min_confidence_reject` is rejected with the AI summary as the reason, and the seller receives `status.rejected_with_reason`.
5. Every ambiguous, failed, ineligible, over-budget, circuit-open, stale, or off-contract case ends as `escalated_to_human` with the auction untouched. **No path exists from any failure to an automatic approval** — asserted by a test iterating every `ContentReviewErrorCode` and every mode.
6. An admin can view results, view attempt history, run, retry, cancel, force-manual, confirm, and override. Overriding requires `content_review.override` plus a reason, and is recorded as `relation = overridden`.
7. All five mandated attribution strings render correctly from `content_review_decisions` (§24.4), and **no fake user is ever created** — asserted by a test that the `users` table gains no row during any AI flow.

**Non-functional**

8. `POST /soom/auctions/{id}/submit-review` p95 latency increases by under 20ms and issues no HTTP call.
9. No external call occurs inside a DB transaction — asserted by a test that fails when `DB::transactionLevel() > 0` at the moment the fake provider is invoked.
10. The admin auction list endpoint's query count is constant across page sizes.
11. Running `ProcessContentReviewJob` twice for one review produces one result, one cost, one decision, and one notification.
12. Modifying content invalidates the result: the next apply attempt marks it `superseded` and escalates.
13. No API key, prompt, raw response, or user PII appears in `content_reviews`, in any log, or in any response — asserted by `SecretLeakageTest` and `LogRedactionTest`.
14. `content-review` jobs never occupy the `default` queue — asserted with `Queue::fake()`.

**Quality gates**

15. All existing backend tests pass **unmodified**, including `composer test:auction:mysql` and `tests/Unit/Auction/AuctionCodeQualityTest.php`.
16. `npx tsc --noEmit`, `npm run lint`, `npm run build`, and `npx vitest run` all pass in `soom-dashboard`.
17. `php artisan migrate` → `migrate:rollback` → `migrate` is clean, and no existing table is altered.
18. `ContentReviewArchitectureTest` passes: ContentReview never references `Auction`; the decision engine performs no I/O; only `ApplyContentReviewDecisionAction` opens a transaction.
19. No comments and no DocBlocks are added to any project file, and any modified file has its existing comments and DocBlocks removed.
20. Switching back to `manual` requires no migration, no deploy, and no downtime.

---

## 38. Definition of Done

- All nine batches merged, each having passed its own verification commands.
- Backend: `composer test`, `composer test:auction`, `composer test:auction:mysql`, and `php artisan test --filter=ContentReview` all green.
- Dashboard: `npx tsc --noEmit`, `npm run lint`, `npm run build`, and `npx vitest run` all green.
- Every §37 acceptance criterion demonstrated by a named, passing test.
- Production ships with `CONTENT_REVIEW_ENABLED=false` and settings `mode=manual`; the rollout ladder in §31 is executed deliberately, one stage per release, with the Stage-2 accuracy gate met before any automation is enabled.
- `docs/CONTENT_REVIEW_OPERATIONS.md` documents the worker/queue change, all four kill-switch levers, budget administration, and the alert runbook.
- No comments and no DocBlocks in new or modified project files.
- No commit and no push performed without an explicit instruction.

---

## Decisions Requiring Approval

**D1 — AI provider and model.**
*Recommended:* Anthropic with `claude-sonnet-5`, plus `claude-haiku-4-5-20251001` as the cheap tier for text-only re-checks. *Why:* strong native structured/tool-constrained output, which the entire contract depends on; strong Arabic; and vision in the same model family, so text and image review share one adapter. *Alternatives:* OpenAI or Google — equally supportable, since the `ContentReviewProvider` port exists precisely so this is a configuration change; or ship with only the Fake provider and add a real one later. *Impact:* affects `AnthropicContentReviewProvider` and `config/services.php` only. **Not blocking** — Batches 1-4 run entirely on the Fake provider; needed before Batch 5's provider-test endpoint is meaningful.

**D2 — Two versioned settings tables versus one.**
*Recommended:* keep `content_review_policies` and `content_review_settings` separate. *Why:* they have different lifecycles — a kill-switch flip must not mint a new *policy* version and thereby pollute the "which policy version decided this" audit trail — and separate `manage` permissions fall out naturally. *Alternative:* one table with two JSON columns; one fewer table, but every operational tweak creates a new policy version and the permission split disappears. *Impact:* one migration. **Blocking for Batch 2.**

**D3 — Adding a `content-review` queue.**
*Recommended:* yes, with the documented worker change. *Why:* without it, a slow provider directly delays auction finalization, outbox dispatch, refunds, and payouts — the exact coupling the requirements forbid. *Alternative:* stay on `default` — zero operations change, real risk of head-of-line blocking. *Impact:* one deployment/supervisor change. **Approved, with a hard deployment constraint:** production runs **two separate workers**, not one worker consuming both queues.

```
# critical work — unchanged
php artisan queue:work --queue=default

# AI review work — separate process, separately restartable and killable
php artisan queue:work --queue=content-review
```

A single `--queue=default,content-review` worker is acceptable only for local development; in production it reintroduces exactly the head-of-line coupling this decision exists to prevent, and it removes the ability to stop AI work without stopping payments.

**Shared-cache verification (required before wiring, done).** The circuit breaker, budget guard, and concurrency limiter coordinate through the cache, so they are only correct if the cache is shared across worker processes and supports atomic locks. Verified against this project: `.env` sets `CACHE_STORE=database`, which resolves to `Illuminate\Cache\DatabaseStore`; that store implements `Illuminate\Contracts\Cache\LockProvider` and is backed by the `cache_locks` table created by `database/migrations/0001_01_01_000001_create_cache_table.php`. Both workers point at the same MySQL database, so the locks and counters **are** genuinely distributed. If the deployment ever moves to `CACHE_STORE=file` or `array`, these three components silently degrade to per-process state — in that case they must be treated as per-worker only, and the budget guard in particular must be re-pointed at a direct `SUM(cost_micros)` query rather than a cached value.

**D4 — Default permission grants.**
*Recommended:* the `admin` role implicitly gets `content_review.view`, `content_review.run`, and `content_review.cancel` only; `settings.manage`, `policy.manage`, `force_manual`, `override`, and `costs.view` are granted per user through `users.auction_permissions`. *Why:* the requirement explicitly states that not every admin should be able to change AI settings. *Alternative:* grant everything to `admin` — simpler, weaker control. *Impact:* one array in `config/content_review.php` plus an onboarding note. **Not blocking** — trivially changed later.

**D5 — Auto-reject in `ai_automatic`.**
*Recommended:* enabled, but only for a *critical* violation whose code is in `policy.auto_reject_categories`, at or above `min_confidence_reject` (default 90), and only for automation-eligible subjects. *Why:* clearly prohibited items (weapons, drugs, adult content, stolen goods) are the cheapest and highest-agreement class, and a rejection is fully reversible by an admin. *Alternative:* auto-**approve** only, never auto-reject — safer against false positives on a seller's listing, but leaves the highest-volume obvious cases to humans. *Impact:* rule R9 plus the policy defaults. **Approved, with a launch constraint:** the capability ships, but `auto_reject_categories` **ships empty** and automatic rejection is **not enabled in production at launch**. The rollout is shadow → assisted, and auto-reject is turned on only after measured results and an explicit policy publication. Building it is now safe because a rejected seller can reopen, correct, and resubmit.

**D6 — "Request more information from the seller".**
*Recommended:* **defer. Approved as deferred.** *Why:* it would need either a new auction status or a new seller-facing flow. Its main original justification has partly disappeared: now that the reopen/edit/resubmit loop exists, a rejection carrying a specific, actionable reason already gives the seller a working correction path, which covers most of what "request more information" was for. *Alternative:* implement later as a distinct rejection sub-reason. *Impact:* one event, one notification key, one admin action. **Out of scope for this version** (§34).

**D7 — Backfilling existing `pending_review` auctions.**
*Recommended:* provide `content-review:backfill --dry-run` but **never** run it automatically. *Why:* an unbounded backfill against a live provider is the single easiest way to blow the budget on day one. *Alternative:* auto-enqueue everything on first enable. *Impact:* one command in Batch 9. **Approved:** the command is built with `--dry-run` as the default, is never scheduled, and never modifies an existing auction without an explicit operator invocation.

---

## Verification (end to end, after implementation)

```bash
# Backend  (C:\Users\pc\Desktop\SB\soom)
composer test
composer test:auction
composer test:auction:mysql
php artisan test --filter=ContentReview
php artisan migrate:fresh --seed --env=testing && php artisan migrate:rollback --env=testing
php artisan route:list --path=content-review

# Manual smoke (local, provider=fake, mode=ai_assisted)
php artisan queue:work --queue=default,content-review --once
#  1. create an auction, then POST /api/soom/auctions/{id}/submit-review
#  2. assert: the auction is pending_review, and one content_reviews row exists (completed, advisory_only)
#  3. GET /api/admin/auctions/{id} → the ai_review block is populated
#  4. POST /api/admin/auctions/{id}/review {action, reason} → a decision row with relation confirmed|overridden

# Dashboard  (C:\Users\pc\Desktop\SB\soom-dashboard)
npx tsc --noEmit
npm run lint
npm run build
npx vitest run
```

**Database safety.** The local `.env` points at `soom_pr` — never migrate or seed against it. All migration and seeding verification runs with `--env=testing` against a `*_testing` database, which `tests/TestCase.php::guardAgainstNonTestingDatabase()` already enforces for the MySQL suites.
