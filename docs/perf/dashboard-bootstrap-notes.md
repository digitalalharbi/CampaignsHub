# PERF-BOOTSTRAP-001 — dashboard bootstrap, read cache, client-budgets, production OPcache

Branch `perf/dashboard-bootstrap` off `origin/main` 9e50264d3. Measured 2026-11-19 on a laptop (Postgres 16 and Redis local,
`php artisan` in-process, no network, no php-fpm), against a database seeded for the purpose. **These are relative numbers on one
machine, not production latencies**: the production 1-vCPU host will be slower in absolute terms; what carries over is the
statement count and the request count.

## The problem, as measured

`/agency/dashboard` fires 13 API calls in parallel. Seven of them are the same on every authenticated page (`/auth/me`,
`/auth/memberships`, `/me/access`, `/subscriptions/current`, `/settings/branding`, `/notifications/summary`,
`/funding/disclaimer`). On the host they took 1.0–1.4 s inside the burst and ~0.2 s alone — they were queueing for a PHP worker,
not working. Locally each of the seven costs 4–22 ms alone, which agrees: the cost is the queue, so the fix is fewer requests.
`/agency/client-budgets` was 0.65–0.85 s alone.

## 1. `GET /api/v1/bootstrap`

One request returning `me`, `memberships`, `access`, `subscription`, `branding`, `notifications_summary`, `funding_disclaimer`.

* **No second implementation.** Each section is produced by the controller action behind the endpoint it replaces
  (`AuthController::me`, `MembershipController::index`, `MyAccessController::payload`, `SubscriptionController::currentPayload`,
  `BrandingController::branding`, `NotificationController::summary`, `FundingController::disclaimerText`). `BootstrapTest` holds
  every section **byte-equal** to its endpoint's `data`.
* **Authorised exactly as each endpoint is**, on the request in hand: the route group's portal list (copied into
  `BootstrapController::ENDPOINTS` and pinned against the router by a test), the access-center section switch
  (`AccessSections::forRoute`, which is how a switched-off `budgets` section removes the disclaimer), and the action's own
  permission (`subscriptions.view`). A section the caller may not read is `null` — never partial, never another tenant's. Unlike
  `EnsurePortal`, a section whose portal is not the *active* membership's is left out instead of being served from another
  membership; the page then asks that endpoint, which does the swap. A section that throws is `null` too (reported, the rest go out).
* **Frontend.** On a membership surface (`/app`, `/agency`, `/influencers`, `/portal`) the boot probe *is* the bootstrap
  (`features/bootstrap/bootstrap.ts`, called from `Providers`): it files each section with `setQueryData` under the exact key its
  hook reads (`['memberships']`, `['me','access']`, `['settings','branding']`, `['subscriptions','current']`,
  `['notifications','summary']`, `['funding','disclaimer']`) and sets the user, *before* the auth store leaves `loading` — so
  nothing below mounts and asks again. A `null` section is not filed and its hook asks its own endpoint. If the bootstrap fails
  (not 401/403), the plain probe and the memberships prefetch run — the previous behaviour. The bell's `staleTime` went 0 → 5 s
  (a zero re-asked at once for the answer that had just landed; the 30 s interval and focus refetch are unchanged).
* The seven endpoints all still exist and work.

**Request count, `/agency/dashboard` first load:** 13 → 6 (one bootstrap replaces seven; the dashboard's own five remain).
Measured by `e2e/dashboard-bootstrap.spec.ts` from the browser's own network events (chromium, own ports and database, real Redis
cache): `/bootstrap /client-workspaces /projects /agency/dashboard /agency/client-budgets /alerts/events`. The 13 is the figure
reported from production; the same page for the seeded e2e account would have sent the seven + these five = 12 (the 13th call was not
reproduced and not identified here), and this branch's "before" was not re-measured in a browser. `bootstrap.test.tsx` does the same count under vitest with the real `Providers` and the real consumers of
each answer, at the HTTP-adapter level, and fails if any of the seven is asked.

Server cost of the bootstrap itself (warm cache): 49 ms / 120 statements, against 74 ms / 183 statements for the seven endpoints
called separately.

## 2. Read cache (Redis, 10 min)

`app/Domains/Bootstrap/Support/WorkspaceReadCache.php`. Cached: branding (per tenant), the subscription + plan + renewal shape (per
tenant), `/me/access` (per tenant × membership × person), the disclaimer (per tenant; the two language files' mtimes are in the key,
so a deploy that rewords it is seen at once). Key: `ws:v1:{section}:e{epoch}:t:{tenant}[:m:{membership}:u:{user}]`.

* **Only the answer is cached, after authorisation.** Permissions are still resolved from the database on every request
  (`hasPermission`, `ClientScopeResolver`, the route gates); the cache only spares recomputing the *document* that describes them.
* **Subscription *usage* is deliberately not cached.** It counts projects/clients/seats, changes on every create, and is what
  the plan-limit meter shows; it is read live (`test_usage_is_never_served_from_the_cache`). Consequence: the cache removes
  only ~3 of that endpoint's ~80 statements — the remaining cost is `usageSummary()`; making *that* cheaper is a separate piece of work.
* **Invalidation = an epoch per section, bumped by the SQL itself.** `WorkspaceCacheInvalidator` is a `DB::listen` on every
  INSERT/UPDATE/DELETE/TRUNCATE; a write to a watched table bumps that section's epoch (part of every key), and again after COMMIT
  when inside a transaction. This catches every write path — Eloquent, a pivot `sync()` (no model event), `DB::table()`, a queue
  worker, a seeder — which hooking each writer would not. Watched: branding → `tenants`; subscription → `subscriptions`,
  `subscription_plans`, `subscription_payment_methods`; access → `roles`, `permissions`, `permission_role`, `role_user`,
  `member_access_overrides`, `membership_scopes`, `memberships`. Coarse on purpose (a write anywhere abandons the section for all
  tenants): these tables change rarely and are read on every page; the failure mode is only ever "recompute".
* **Redis down:** every cache call is wrapped; the payload is computed directly, and the process does not retry for 30 s.
  `test_a_dead_cache_store_fails_nothing` uses a store that throws on every call.
* Known staleness bound (10 min TTL, no write to a watched table): the renewal block's `reason` (`no_gateway` etc.) follows
  gateway *configuration*, which lives in env/config, not in a watched table; and `users.is_platform_admin` is in the key, not watched.
* Tests: `WorkspaceReadCacheTest` — keys carry the tenant; two tenants interleaved never see each other's branding / plan / access;
  branding save, plan change, status change, catalogue edit, role-permission `sync()`, member override add/remove, client scope, role
  replacement all read back on the very next fetch; with the invalidator unhooked three of those fail (checked).
* **Honest value:** branding and the disclaimer are derived from rows already in memory, so caching them saves ~0 (a Redis read ≈ a
  memory read); they are cached because it was asked, and they cost nothing. `/me/access` 14 → 6 statements. The real win here is
  request count (section 1), not this cache.

## 3. `/api/v1/agency/client-budgets`

Profile (query log with timing, grouped by normalised SQL): of 1,475 statements, 1,440 were `FundingBalance::of()` run once per
project — 12 statements each, 120 projects (the controller itself was already batched). Fix:

* `FundingBalance::summaries()` — the figures `ProjectBudgetReader` draws, for *many* budgets, one statement per table (payments
  grouped by project; first spend per project with `BoundAccountVisibility::apply`; one `VALUES`-joined aggregate over
  `funding_spend_days` for spent / unrated count / last day / 7-day and 3-day windows). `of()` is **untouched** and is the definition;
  the status word is now one private function both call.
* `SpendLedger::readEach()` — one `accountDays()` read split per project by that project's own window, each reading built by the
  same `readRows()` that `read()` ends in. **FxEngine is still the only converter**; nothing about rates, stale rules or
  withheld parts moved.
* `accountDays()` no longer builds a Carbon per row to re-print a date string it already has (−7 ms).
* Index (new migration `2026_11_23_100000`): `daily_metrics (project_id, metric_key, metric_date)`, `CREATE INDEX IF NOT EXISTS`;
  `down()` drops only it. Measured benefit is real but modest: the account-days statement 28 → 14 ms on the 590 k-row seed; **no
  effect on the end-to-end number beyond that**. Build time 0.33 s for 590 k rows (the migration runs in a transaction and holds a write
  lock on `daily_metrics` for that time; on a table ten times larger expect seconds — the deploy's 600 s SSH window is not at risk).
  No other missing index was found: `funding_payments(project_id, paid_on)`, `funding_spend_days(project_id, spend_date)`,
  `unified_campaigns(project_id)`, `project_integration_bindings` (partial unique on account+project) already serve every statement.
* A rewrite of `MetricsAggregator::campaignSetBase()`'s `SELECT DISTINCT unified_campaign_id` (43 ms) as per-campaign `EXISTS` probes
  was tried, produced identical output, and was **slower** (346 vs 270 ms), so it was reverted and is not in this branch.

Seed: 40 clients × 3 projects × 4 campaigns added to the demo agency (130 projects, 745 campaigns in the tenant), every account bound
(unbound spend is invisible by design), each project funded; `daily_metrics` 113 k rows, then 589 k rows (35 → 200 days of history).

| | statements | wall (warm, median of 5–6) |
|---|---|---|
| before, 113 k rows | 1,475 | ~650 ms |
| after, 113 k rows | 35 | ~250 ms |
| before, 589 k rows | 1,475 | ~700 ms (665–801) |
| after, 589 k rows | 35 | ~270 ms (266–276) |

**The 250 ms target is met on the 113 k-row seed and missed by ~20 ms on the 589 k-row one.** What is left is ~145 ms of database
time in five whole-slice aggregates inside `MetricsAggregator` (`budgetPacing`, `timeseriesByCampaign`, the demo-policy `DISTINCT`s) and
~120 ms of PHP, 55 ms of which is 5,200 `FxEngine::convert()` calls in `SpendLedger` — the central money path, deliberately not touched.

**Output is identical:** the full JSON before and after was diffed on the seed (only `meta.request_id` differs).
Tests: `FundingBalanceSummariesTest` (summaries() == of() key by key on prepaid / credit / open-start / ended period / unrated currency /
no spend / overspent / voided, and `ProjectBudgetReader` == the old `of()` + `summarise()` path); `ClientBudgetsQueryBudgetTest`
(3 → 12 clients costs ≤ 5 more statements, absolute ceiling 80, and the figures equal the budgets page).

## 4. Production PHP image and deploy

* `deploy/php-opcache.production.ini` → `/usr/local/etc/php/conf.d/zz-opcache.ini` in `deploy/backend.production.Dockerfile` **only**:
  `validate_timestamps=0`, `memory_consumption=128`, `interned_strings_buffer=16`, `max_accelerated_files=20000`. The development image
  (`infrastructure/docker/backend.Dockerfile`) and CI are untouched. Loaded-and-read-back with `php -n -d zend_extension=opcache`;
  **the image itself was not built here (no Docker on this machine)** — CI's `docker build -f deploy/backend.production.Dockerfile` step covers it.
* `scripts/deploy-production.sh`: `event:clear` beside `route:clear`, `event:cache` after `route:cache` (`event:cache` run locally: succeeds).
* Observed, not changed: the image's `CMD` is `php artisan serve` with `PHP_CLI_SERVER_WORKERS=8`, not php-fpm. The OPcache settings
  apply to either; if the host actually fronts the image with php-fpm (`pm.max_children=5`) that pool config is outside this repository.
* No env key added (env-contract trap respected).

## Rollback

* **Whole change:** revert the PR. The seven endpoints were never removed, so the old frontend and the new backend (and vice versa) both work.
* **Cache keys** are `ws:v1:*` and expire in 10 minutes; nothing depends on them. Bumping `v1` or `redis-cli --scan --pattern 'ws:v1:*' | xargs redis-cli del` clears them early.
* **Migration:** `down()` runs `DROP INDEX IF EXISTS daily_metrics_project_metric_date_idx` and touches nothing else.
* **OPcache:** delete `deploy/php-opcache.production.ini` (or set `opcache.validate_timestamps = 1`) and recreate the container.
* **Frontend only:** `bootSession` falls back to the old probe on any failure; reverting `providers.tsx` alone restores the old boot.

## Not done, and risks

* **Bootstrap only runs on page load.** A person who signs in and is routed client-side to `/agency/dashboard` still triggers the seven
  individual calls once (the login flow does not call the bootstrap). Worth a follow-up: seed from the sign-in's own post-auth step.
* `/subscriptions/current` is still ~77 statements (usage is live by design) — the largest remaining per-call cost among the seven.
* `client-budgets` is ~270 ms, not under 250, on the larger seed (above). Getting there needs work inside `MetricsAggregator` or the
  FxEngine call volume, both outside this batch's remit.
* The e2e spec was run locally (chromium only; ports 5681/8681/5781, database `mediabuying_bs_e2e`, Redis prefix `chbs-e2e-` — not the shared 8100/5273/5373 stack) and passed; the full gate was not run.
* `frontend/src/lib/noForbiddenTerms.test.ts` fails on `origin/main` itself (`TabVisualDecks.tsx`, from #1155) — not caused by or touched here.
* Coarse invalidation: any write to a watched table, by any tenant, abandons that section for everyone until it is recomputed.
