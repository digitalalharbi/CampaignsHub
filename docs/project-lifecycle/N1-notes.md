# Builder N1 — project lifecycle FOUNDATION (spec §0–§3)

Branch `feature/project-lifecycle-n1` (based on `feature/project-lifecycle`). Not pushed. Status `IMPLEMENTED_NOT_VERIFIED`
until the lead's full suite, CI and a walk. No production id anywhere; all fixtures generated (`tests/Concerns/LifecycleFixtures.php`).

## Contract — where things live (for N2 and the later builders)

| What | Where |
|---|---|
| Rules (binding) | `docs/rules/project-lifecycle.md`; summary + link in `CLAUDE.md` («Project lifecycle — permanent rules») |
| States + allowed-transitions table | `App\Domains\Projects\Lifecycle\ProjectStatus` (draft/active/paused/archived/deleted); clients `App\Domains\ClientWorkspaces\Enums\ClientStatus` (+ `deleted`, `allowedTargets()`, `all()`) |
| The ONE writer | `ProjectLifecycle` interface (container-bound to `ProjectStateMachine`): `transition()`, `transitionClient()`, `archive()`, `unarchive()`, `archiveImpact()`, `history()` |
| Live scope | `LiveProjects` (`STATUSES`=active+paused, `RUNNING`=active, `LISTED`=draft+active+paused; `constrain*()`, `whereProjectLive()`, `idsQuery()`, `filter()`, `isLive()`); `Project::live()/running()/listed()/inLifecycle(...)`; SQL view `live_projects` |
| Archive effects | `ArchiveEffects` (table of kinds in its docblock), recorded in `project_archive_effects` |
| Snapshot | `ProjectSnapshot` + `lifecycle:snapshot --project=<id>` |
| Ops | `lifecycle:apply-archive-effects --project=<id>|--all-archived [--apply]` (dry run default) |
| Retention | `TrashRetention::daysFor($tenantId)` — plan feature `projects.trash_retention_days`, default 30 (`config/lifecycle.php`) |
| Permissions | `projects.archive`, `projects.restore`, `projects.purge` (new), `projects.delete`, `clients.archive`, `clients.delete` (existed) |
| API | `POST projects/{id}/archive {unlink_accounts?, reason?}` · `POST projects/{id}/restore {confirm: true}` · `GET projects/{id}/archive-impact` · `GET projects/{id}/lifecycle` · `ProjectResource.lifecycle_status/archived_at/previous_status/purge_after` |
| TS | `frontend/src/lib/projectLifecycle.ts` (types, transitions mirror, `lifecycleLabel()`, API calls) |
| Tests to extend | `ProjectLifecycleContractTest`, `ProjectLifecycleSourceGuardTest`, `LiveProjectsSourceGuardTest`, `LiveProjectsScopeAppliedTest`, `LifecycleOpsCommandsTest` |

## Decisions

1. **Extend, don't duplicate.** `projects.status` stays the column (canonical values now); `client_workspaces.client_status` is the
   client state; existing `archived_at/archived_by` on clients kept; `deleted_at` (SoftDeletes) kept beside status `deleted`.
2. **Backfill without behaviour change** (migration `2026_11_18_100000`): archived→archived, paused→paused, soft-deleted→`deleted`
   (purge_after NULL: nothing pre-existing is auto-purged), active/completed/closed→active, draft/setup/onboarding **with any binding**
   →active (they synced and aggregated; the UI already derived «نشط/قيد الإعداد»), without a binding→draft (no data → no figure moves).
   Each changed row gets a transition row (`lifecycle_backfill`). `status_before_lifecycle` keeps the exact old value; `down()` restores it.
3. **Draft is not live.** The first active binding promotes a draft to active through the machine (`ProjectIntegrationBinding::saved`,
   reason `account_linked`). New projects: UI create/clone still `draft`; internal creators that wrote `setup` now start `active`
   (`ProjectStatus::LEGACY` normaliser on `creating`), DB default `active`.
4. **Paused**: live for aggregation/alerts/pickers, but does **not sync** (unchanged SYNC-GATE-001 behaviour) and gets no daily digest
   (unchanged) — expressed as `LiveProjects::RUNNING`, still one source.
5. **Runtime + static guard**: `Project::updating` throws if `status` changes outside the machine; `ProjectLifecycleSourceGuardTest`
   catches raw `projects` updates / `$project…->update(['status'…])` and any client_status writer (two demo `firstOrNew` fills allowlisted).
6. **Restore exactness**: switches (bindings, schedules incl. `next_run_at`, alert/automation rules, agent routines) and tasks (back to their
   own status, reason removed) are restored only where the row still holds the archive's value (`skipped_changed` otherwise).
   Auto-closed **alerts stay resolved** and **expired proposals stay expired** (`kept_closed`): point-in-time signals; the evaluator
   re-raises an alert that still holds. Un-archive returns to `previous_status` (draft/active/paused).
7. **Shared account**: every effect is `WHERE project_id = this AND tenant_id = this`; another project's binding to the same ad account
   is a different row. `sharedAccounts()` is the warning data (archive response + archive-impact).
8. **Archive permission**: archive moved from `projects.update` to `projects.archive`, restore to `projects.restore` (+ `confirm`).
   Pause/resume stay `projects.update`. Granted to `tenant-owner` roles by the migration; the owner preset holds every key.
9. **Rule 4 (client state never affects spend)**: removed `whereNull('archived_at')` client filters from the agency overview, client budgets,
   budgets page (`FundingController`, `RenewNextMonth`) and role dashboards; the agency dashboard aggregates live projects of every
   reachable client. Client ROSTER counts and portal access keep their client filters (not aggregation).
10. **Plan feature**: builder M's `PlanEntitlementGate` (feature/notifications-v2) is NOT merged on this base. The key is read from
    `subscription_plans.features` (the existing per-plan JSON the admin plan editor writes), default 30 from config. **Merge point:**
    register `projects.trash_retention_days` in M's gate and switch `TrashRetention` (its only reader) to it.
11. `ProjectLifecycle` (old display-derivation class) renamed `ProjectStatusDisplay` to free the name for the contract.
12. `DeleteProject` now soft-deletes through the `deleted` transition (status, deleted_by, purge_after, log). Archive effects on delete,
    trash, purge, move: N2.

## Every place moved to the shared scope (Step 2)

| Area | File | Was | Now |
|---|---|---|---|
| Sync sweeps + job gate | `Integrations/Services/AccountAssignment.php` (`scopeToAssigned`, `isActivelyAssigned`) — feeds SyncAdPlatforms, SyncMeasurement, SyncStores, SyncCensus, StructureSweepTargets, Ga4PropertySync | `PROJECT_STATUSES_THAT_DO_NOT_SYNC` | `LiveProjects::constrainRunning` / `RUNNING` |
| Daily digests | `Notifications/Console/SendDailyDigests.php` | `status = 'active'` const | `->running()` |
| Digest / alert-mail / notification / request-mail audiences, recipient pickers | `Notifications/Services/DigestScope.php` (`ceilingFor`, `existing`) — used by AlertEventMailer, AlertDecisions, NotificationAudience, RequestMailer, TeamNotifications, NotificationPreference, ExternalRecipient pickers | all projects | `->live()` |
| Digest body | `Notifications/Services/DailyDigest.php` | ids as given | `->live()` |
| Spend summary (internal/external) | `Notifications/Services/SpendSummaryDispatcher.php`, `ProjectSpendSummary.php` | `!= archived` / not deleted | `->live()` |
| Request manager notifications | `Requests/Services/ClientRequestManagerNotifier.php` | `NOT IN (closed, archived)` | `->live()` |
| Budget notices | `Funding/Services/FundingNotifier.php` (`evaluate`, `added`) | none | `LiveProjects::isLive` |
| Spend-limit mail | `Alerts/Services/SpendLimitThresholdMail.php` | none | `isLive` |
| GA4 alert signals | `Alerts/Services/Ga4AlertSignals.php` | none | `->live()` |
| Alerts list scope | `Alerts/Http/Controllers/AlertController.php` | all projects of clients | `->live()` |
| Attention board | `Alerts/Services/AttentionBoard.php` | `status = 'active'` | `running()` + campaigns `runningIdsQuery()` |
| Automation sweep | `Automation/Console/EvaluateAutomationRules.php` | none | `whereIn(project_id, idsQuery())` |
| Agent routines (recommendations) | `Agents/Console/RunAgentRoutines.php`, `Agents/Services/RoutineStarter.php` | `status !== 'active'` | `runningIdsQuery()` / `isRunning` |
| Scheduled reports | `Reports/Services/ScheduledReportDispatcher.php` | none | `whereIn(project_id, idsQuery())` |
| Weekly delivery packs | `Delivery/Console/PrepareWeeklyPacks.php` | `status = 'active'` | `running()` |
| Delivery overview schedules | `Delivery/Services/DeliveryOverview.php` | all | `live()` |
| Agency dashboard | `Agency/Http/Controllers/AgencyDashboardController.php` | all projects of non-archived clients; campaigns by client | live projects of reachable clients; campaigns by live project; active = `running()` |
| Agency overview | `Agency/Http/Controllers/AgencyOverviewController.php` | all | `live()`; client archived filter removed (rule 4) |
| Client budgets | `Agency/Http/Controllers/ClientBudgetController.php` | all | `live()`; client filter removed |
| Role dashboards | `Agency/Services/RoleDashboardBuilder.php` | all | `live()`; client filter removed |
| Agency team managers | `Agency/Http/Controllers/AgencyTeamController.php` | not deleted | `idsQuery()` |
| Portfolio («جميع المشاريع») | `Projects/Services/PortfolioOverview.php` | all | `live()` |
| Clients list stats | `ClientWorkspaces/Services/ClientPortfolioStats.php` | spend incl. archived; count `!= archived` | spend `constrain`; count `constrainListed` |
| Client analytics | `ClientWorkspaces/Services/ClientAnalyticsService.php` | not deleted | `constrain` |
| Client portal overview / audience | `ClientWorkspaces/Services/PortalOverview.php`, `Requests/Http/Controllers/ClientPortalController.php` (audience) | all | `live()` |
| Budgets page | `Funding/Http/Controllers/FundingController.php`, `Funding/Services/RenewNextMonth.php` | non-archived clients | live projects; client filter removed |
| Creatives library | `Campaigns/Services/CreativeRows.php::applyReach` | reach only | + `whereProjectLive` |
| Integrations clients overview | `Integrations/Http/Controllers/ClientsIntegrationOverviewController.php` | all | `constrainListed` |
| Store auto-assignment | `Commerce/Services/StoreAssignment.php` | `status = 'active'` | `running()` |
| Plan slot usage | `Subscriptions/Services/SubscriptionService.php` | `!= archived` | `constrainListed` |
| Projects list / pickers / scope switcher (GET /projects) | `Projects/Http/Controllers/ProjectController.php` | `!= archived` | `listed()`; `include_archived` / `status=` via `inLifecycle()` |

## Tests run (targeted, serial, own DB)

- Lifecycle set: ProjectLifecycleContractTest 18, ProjectLifecycleSourceGuardTest 4, LiveProjectsSourceGuardTest 2,
  LiveProjectsScopeAppliedTest 16, LifecycleOpsCommandsTest 2 — green.
- Targeted sweep `Digest|Agency…|Funding|Notification|Automation|Routine|ScheduledReport|SpendLimit|Subscription|PlanLimit|Sync…|
  Creative|Delivery|Portal…|Budget…`: 1,699 passed. Wider sweep `Sync|Assign|Binding|Ga4|Store|Commerce|Measurement|Alert|Project|
  Client|Request|Agent|Report|Campaign|Access|Integration|Team|Onboarding|Provision|Registration|Lifecycle|Scope|Guard|Tenan|Portal|
  Dashboard|Overview`: 5,108 passed, 7 failed → 6 fixed (fixtures that wrote a project status directly, the legacy `setup` assertion,
  two new routes in the capability-coverage list); the 7th, `BoundAccountVisibilitySourceGuardTest` naming
  `Metrics/Services/PeriodCoverage.php`, is PRE-EXISTING on the base (DATE-RANGE-001, 8dc50a098) and untouched here.
- Frontend: `tsc -b` clean; vitest `src/features/projects` 17 files green, `src/lib/projectLifecycle.test.ts` green; `src/lib`
  has one PRE-EXISTING failure (`countedNouns` → `features/agency/AgencyHeadline.tsx`, not touched).
- pint + phpstan clean on every changed file. No `--parallel`/full suite (lead runs it).

Fail-first: `LiveProjectsScopeAppliedTest` / `ProjectLifecycleContractTest` were written against behaviour the
base did not have (archived spend in the clients list, archived project in digests/pickers, no effects/restore, no permissions).

## Deferred, and why

- **`AlertEvaluator` (tenant-wide alert rules)** — N3 owns that file in parallel. Project-scoped rules are switched off and open
  alerts closed by the archive; tenant-wide rules still need `LiveProjects::whereProjectLive` in `sweepAll()` and each per-rule project
  query. N3 has `LiveProjectsAdapter` — wire it to `LiveProjects`.
- **Client archive → archives its projects** (impact window, typed name) — §8 UI/N2; `ClientManagementService::archive` only moves the
  client state today.
- **Delete effects / trash / purge / move**, view-only archived page, restore window UI — N2.
- Name maps for display of existing rows (notification recipients, inventory labels, request lists) intentionally keep archived names.
- `ClientInvoiceService` keeps archived projects (billing records); `DriveReach`, `ProjectReach`, portal `ownedProjectIds` are access,
  not aggregation (an archived project stays readable view-only).
- Matrix / ledger / RESUME_STATE not edited (Owner order).
