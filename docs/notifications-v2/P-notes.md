# Builder P — internal notifications, notification centre, follow-up assistant (NOTIF-V2-INTERNAL-001, NOTIF-V2-FOLLOWUP-001)

Branch `feature/notifications-v2-p`, based on `feature/notifications-v2` (builders L and M merged). Not pushed, no PR. Matrix and ledger not
edited. Scope: Owner spec §5 (internal notifications + centre) and §6 (follow-up assistant). Status: `IMPLEMENTED_NOT_VERIFIED` — targeted
tests, pint, phpstan, tsc, vitest and Playwright green here; full suite, CI and production not run.

## What it is

| Part | Where |
|---|---|
| Internal events through the engine | `app/Domains/Notifications/Internal/` — `InternalEvents` (the one call a domain makes), `EngineKinds` (event → group, page, label, legacy alias), `ActiveProjects` (live-project adapter), `Observers/BudgetAndProjectObservers`, `LegacyBridge`, `InternalScan` + `notifications:v2-internal-scan` |
| Centre API | `Internal/Http/NotificationCenterController` (snooze, mute, mutes); `NotificationController` (tabs, `type`, `priority`, facets, `project_name`, `workspace_name`); `NotificationInbox::visible` hides muted and snoozed |
| Follow-up | `app/Domains/Notifications/Followup/` — `FollowupTasks`, `AutoCloser`, `MorningBrief`, `MorningBriefRunner` + `notifications:v2-morning-brief`, `ReportRerouter`, `ReroutedReportCheck`, `Http/FollowupController`; routes `routes/api/followup.php` |
| Schema (additive, reversible) | `2026_11_19_100000_notifications_v2_internal_center_and_followup.php`: `notification_user_snoozes`, `notification_mutes`, `followup_tasks`, `followup_task_events` (append-only), `followup_briefs`, `followup_user_settings` — `tenant_id` + `BelongsToTenant` on every one |
| UI | `frontend/src/features/notifications/` — `NotificationCenter` (drawer), `FollowUpTasks`, `FollowUpPage` (`/followup` under `/app` and `/agency`), `FollowUpPrefsCard` (in `/account/notifications`), `RerouteDialog`, `UpgradeLock`, `unreadBadge`, `centerCopy`, `followupApi` |
| E2E support | `php artisan e2e:followup` (E2E database only) + `frontend/e2e/followup-center.spec.ts` |

## Decisions (ambiguous points)

| Point | Decision | Why |
|---|---|---|
| «Route the existing producers through the engine» | New facts (budget/limit created-edited-renewed-deleted, project created/archived, no budget, held/privacy messages, unsubscribe/reduce/unconfirmed, pending recommendations, plan limit) go through `InternalEvents` → `NotificationEngine::raise`. The five legacy families (budget crossings, alerts, sync, link, automation) KEEP the bell writer and are bridged (`LegacyBridge`): the fact gets its catalog name and opens its follow-up task | «No lost alerts»: builder L's per-day upsert, «worse brings it back», push and email are tested behaviour. Moving them is a behaviour change per event; the bridge adds what the engine owns beyond the row without touching the row |
| Facts without a call site | Found by `InternalScan` (every 10 min), each raised once (marker in cache on top of the engine's per-day de-dup) | Held/privacy messages, unsubscribes and pending recommendations are written by other builders' code; a sweep of their logs needs no edit in their files |
| Budget / limit / project changes | Model observers, not controllers | Every write path is covered (API, renewal wizard, imports, seeders). Project events need a signed-in person: a seeder's project is not news |
| Event wording for created/edited/renewed/deleted | One catalog event each (`budget.changed`, `budget.renewed`, `spend_limit.changed`) with a payload `variant`; the composer looks up `<key>_<variant>` first | Keeps the §3 catalog at one event per fact while saying «who changed from X to Y» truthfully |
| Active projects only | `ActiveProjects` = status `active` or `onboarding`, not deleted. **Merge point:** builder N1's live-project scope replaces the bodies of `isLive()`, `liveIds()`, `filter()` — nothing else changes | Scope not on this base |
| Who sees a task | Internal members (`agency`/`app` portals) whose profile sees sensitive events; tasks of a project reach people who reach the project (the digest ceiling), assigned or not; a task without a project only its assignee. Client portal: none | Handing a task over must not hide it from the person who handed it over |
| Auto-close | `AutoCloser` reads: budget now covers more than 5 days / exists, a sync run succeeded after the task opened, the held message has a later terminal log row, the proposal is no longer pending, the project is no longer live. Runs after a budget or sync change and in the scan | The assistant only reads; the person (or the sync) did the work |
| Re-route | Allowed for reports and for events the catalog lets be external; refused for sensitive operational notices (spec §7), unconfirmed recipients, recipients not subscribed to the project, and when `notifications.external` is off. Goes through the engine with the subscription (same pre-send checks, review, immutable log). `ReroutedReportCheck` holds it for privacy if the subscription no longer covers the project | Spec §6 + §7 + §8. Built against `MessageComposer`/`PreSendCheck`; until builder Q's composer is bound the pipeline logs `skipped (external_template_not_installed)`, the tests use a fake external-ready composer |
| Morning brief | A snapshot (`followup_briefs`) announced as the engine event `followup.morning_brief`, addressed to the person, in the channels they chose (a `channels_only` payload narrows the router's channels, never widens). Workspace timezone, work days, once per workspace day, own projects only, money only where they hold `money.view`; client portal never | Quiet hours, plan and the immutable log apply as for any delivery |
| Followed projects | The person's existing `project_ids` preference (the one digests and alerts narrow by); the card edits it through the existing `/settings/notifications` | One setting, not a second list |
| Snooze / mute | Per person. Mute stores every name of the same fact (legacy kind and event key) so one mute hides both | A fact must not reappear under its other name |
| Drawer | Portalled to `document.body`; Escape closes; page scroll locked | A header with `backdrop-filter` is the containing block of fixed descendants and clipped the drawer to itself (found in Playwright) |
| Tab accessibility | Primary tab «الكل» is named «كل الإشعارات» to assistive tech so it does not collide with the existing filter chip «الكل» | Keeps the existing tests and spec valid |
| Entitlement refusal for re-route | Controller answers 403 + `meta.upgrade` (the engine's `EntitlementBlocked` has no global renderer) | |

## Settings (level + default)

| Setting | Level | Default |
|---|---|---|
| Morning brief on/off, time, channels | User (`followup_user_settings`) | on, 08:00 workspace timezone, in-app only |
| Followed projects | User (`notification_preferences.project_ids`, existing) | empty = every project the person reaches |
| Snooze (1 h / 08:00 tomorrow workspace tz) | User, per notification | none |
| Mute (type × project) | User | none |
| Task snooze (1 h / tomorrow / next week), assignee | Per task | open, unassigned |
| «Renew within» threshold | Code constant `AutoCloser::RENEW_WITHIN_DAYS` = 5 days; brief «ending» = 7 days | not yet a workspace setting (spec §2 puts follow-up settings on builder R's page) |
| Plan-limit warning | `InternalScan::PLAN_LIMIT_RATIO` 80 %, once per limit per month | |

## Entitlement keys used (defaults unchanged: all on)

`notifications.internal` (centre actions, every internal delivery) · `notifications.push` / `.sound` (L's, plus the new event types) ·
`followup.tasks` (list, tasks, scan, re-route when combined with `notifications.external`) · `followup.morning_brief` (brief, settings, job).
Enforced on the server: routes use `plan.feature:<key>` (403 + `meta.upgrade`), the scheduled jobs call the gate, the sender re-checks.
A key switched off keeps every row; switched on again restores it (tested).

## Components moved to / kept at the shared level

Nothing under `/agency`. One route per feature (`/followup`) mounted by `/app` and `/agency`; terms («مشروع / حملة», «عميل / علامة») come from the workspace-type
profile in the API (`meta.terms`), no screen or class compares a type key.

## Tests (targeted, `ptest.sh`, no `--parallel`, no full suite)

| File | Proves |
|---|---|
| `NotificationsV2/InternalNotificationsCenterTest` (13) | every §5 event exists with AR+EN and a page; assigned-only routing; project + workspace named; active projects only; client portal gets no sensitive event and only its project; who/before/after wording; critical bypasses quiet hours, info deferred; grouping; 300+ mark-all-read → 0 (colleague keeps theirs); tabs and filters; snooze; mute; cross-workspace 404; key off → 403 `upgrade` and `blocked_by_plan` |
| `FollowupTasksTest` (10) | set-budget / renew / relink tasks open and auto-close; snooze; assign; reach; archived project; key off then on; client portal 403; history append-only |
| `FollowupBriefAndRerouteTest` (11) | brief per person's projects, time, once a day, weekends, switch-off, channels, entitlement; re-route through pipeline + log, review, privacy hold, refusals, plan, isolation |
| `FollowupInvariantsTest` (8) | the assistant never writes budgets/limits/campaigns/links (data and source); agency + direct business in their own words; client portal; isolation; off/on keeps rows; no emoji |
| `InternalPushAndSoundTest` (3) | new events ride the five push switches; sound only important/critical; quiet hours |
| Vitest `centerCopy`, `NotificationCenterDrawer`, `FollowUp` (+ existing bell tests) | copy parity and no emoji; tabs, filters, snooze/mute, follow-up tab, lock; brief, tasks, history; prefs |
| Playwright `followup-center.spec.ts` | agency and direct business at 1366 and 390 px, client portal member; chromium + webkit |

`AlertSweepCharacterisationTest`: the two deterministic ids consumed by the new budget-limit event shift every later id; the baseline was regenerated
and is identical after normalising ids (checked).

### Results (2026-11-15, this machine)
- Backend targeted + guards: see the final report. **Pre-existing, not mine:** `BoundAccountVisibilitySourceGuardTest::test_every_reader_of_a_figure_table…` fails on
  `Domains/Metrics/Services/PeriodCoverage.php` (reads `daily_metrics` without `BoundAccountVisibility`) — present on the base, reproduced with my changes stashed.
- Playwright (own ports 5284 / 8111 / 5384, own database `mediabuying_nv2p_e2e`, never another checkout's server): chromium 9/9 + webkit 9/9 for the new spec, and the existing
  `notifications-bell.spec.ts` 3/3 on chromium. **Firefox does not launch on this machine** (`firefox.launch()` times out) — no Firefox result exists.

## Deferred (and why)

- **Client-portal bell UI.** The client portal's pages (`/portal/*`) have no shared header and no bell; the API, privacy rules and tests are in place for a portal member, the mount belongs with that portal's frame (not touched here).
- **Settings-page controls for follow-up** (spec §2 «follow-up assistant settings»: renew threshold, brief defaults per workspace) — builder R owns the page; the constants above are the defaults to expose.
- **Legacy producers on the engine's own writer.** They are bridged, not moved (see Decisions).
- **A real external message for «أعد التوجيه».** Waits for builder Q's `MessageComposer` (design, identity, footer); the request, checks and log are real.
- **Live Web Push to a phone, real email delivery of the brief.** No device, no VAPID key, no provider here; unit/engine level proven.
- **Followed-project filtering of the centre's list.** The existing `project_ids` preference narrows what is ROUTED (alerts, digests, engine events); rows addressed before a change stay.
- **Mount points of N1's live-project scope:** `ActiveProjects` only (see Decisions).
