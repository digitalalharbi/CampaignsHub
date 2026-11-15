# Builder J — consistency pass notes (Task 4 reports & delivery, Task 6 accounts & sessions)

Branch `feature/consistency-pass-j`, based on `feature/consistency-pass`. Not pushed. Status: `IMPLEMENTED_NOT_VERIFIED`
until CI, the production command and a live walk.

## Commits

| Id | What |
|---|---|
| CONS-REPORT-READY-ONCE | «Report ready» once per report + version. Root cause: the dedup extra was `generated_at`, which every rebuild moves, and the dispatcher only collapses repeats inside 60 min. Now claimed atomically on `reports.ready_notified_version` (additive, nullable). The fail-first test reproduced exactly 24. |
| CONS-SCHEDULE-ONE | One active schedule per report. `ReportScheduleKey` + additive columns `schedule_key`, `disabled_reason`, `disabled_at`, `duplicate_of` + partial unique index (active, keyed rows). The `reports:dedupe-schedules` command. Create/edit/resume refuse a copy with 409. The dispatcher lets only the oldest copy build until the command runs. «نسخة غير مجدولة» in the schedules list. |
| CONS-DELIVERY-SCHEDULED | `/delivery/overview` rows carry `scheduled` (active schedules, next and last run, last scheduled report, ledger states) read from `report_schedules` and `report_deliveries`. Scoped by tenant, the client's projects and `ProjectReach::confinedIdsFor`. A client with active schedules and no weekly pack is no longer asked to «prepare» one. |
| CONS-CLIENT-PROJECT | `useClientProjectAutoSelect` (`features/agency/clientProject.ts`): one project → that one; several → the most recently active (`summary.data_last_synced_at`, else the server's newest-first order), picker kept, picked once per client choice. Agency routes only. Used by `AgencyScopeSwitcher`, `ReportsPage` and `AnalyticsPage`. |
| CONS-ADMIN-SWITCH | A platform admin who also holds an agency membership can switch both ways without signing out. See below. |

## Production commands (after deploy)

The migrations are additive. Existing schedules stay unkeyed, so production duplicates cannot fail them.

```bash
php artisan reports:dedupe-schedules --dry-run   # counts only, writes nothing
php artisan reports:dedupe-schedules             # switch the copies off
php artisan reports:dedupe-schedules             # idempotence check: must say "copies switched off: 0"
```

Dry-run output, one line with no names, tenants or addresses:
`[dry-run] schedules: N · groups: G · groups with copies: D · copies to switch off: C · workspaces affected: W · rows to key: K`.

- If the 24 notices came from copies of one schedule, expect `groups with copies` ≥ 1 and `copies to switch off` ≈ 23.
- If they came from rebuilds of one report, expect C = 0. CONS-REPORT-READY-ONCE already fixes that case.
- K is every row on the first run (none carries a key yet).
- Copies with **different recipients or formats are not copies**. They stay active on purpose, because switching one off would stop somebody's delivery.

**Undo one copy.** Resume it from the schedules screen. This is refused while its twin is active; pause the twin first. Or run:

```sql
UPDATE report_schedules SET active = true, schedule_key = NULL, disabled_reason = NULL, disabled_at = NULL, duplicate_of = NULL
WHERE id = '…';
```

Each disable also writes an audit row, `report_schedule.duplicate_disabled`, with its before state.

## CONS-ADMIN-SWITCH — how it holds the tenancy rules

- `ResolveMembership` still keeps every platform-admin request in platform scope, so the admin console is unchanged.
- **`EnsurePortal`** (portal routes only). When there is no active membership and the user is a platform admin, the session's chosen membership is used. It is read through `MembershipSelector::selected`, which re-checks on every request that the membership is the user's own, active and usable. It only applies to an allowed portal, and a tab that names any other workspace is still refused. The client cannot send a workspace that is then trusted.
- **`/auth/memberships`.** `current` is that verified choice, so `AgencyIdentity` names the workspace the admin is in. A new `platform_admin` flag is returned.
- **UI.** `features/account/WorkspaceHop.tsx` sits inside the existing `AccountMenu` (header/menu). No sidebar or shell file was edited. It shows «لوحة الإدارة» plus the admin's workspaces, with the current one marked. It renders only for an admin who holds at least one membership, and switching goes through the existing `completeWorkspaceSwitch`. The two portal refusal screens show «العودة إلى لوحة الإدارة» to an admin.
- An admin **without** the agency membership still gets «بوابة الوكالة غير متاحة لحسابك». That is correct, and is pinned by `PortalDistinctnessTest`. Switching into another person's membership returns 403.

## Deferred, and why

- **The platform flag inside a workspace.** `HasRoles::hasPermission` and `ClientScopeResolver` let `is_platform_admin` bypass every tenant permission and client ceiling. So inside an agency workspace, an admin acts with full rights rather than their membership's role.
  - This was already true on the existing per-request path. The switch adds nothing to it.
  - Narrowing it touches every permission check and the support-visit flow, so it needs an Owner decision first.
- **Root cause of the 24 notices on production.** This cannot be told apart from the code alone: copies of one schedule versus rebuilds of one report. Both are fixed. The dry-run counts will show which one it was.
- **Reports from a schedule with no recipients.** These have no ledger row, so the delivery row shows the schedule (active, last and next run) but no `last_report`.
  - Tagging the report with its schedule id would mean writing a new key into `reports.config`, which the generator reads. That was not risked here.
- **Picker behaviour change.** The multi-project preselect also applies on other agency pages that read the shared project store (integrations, campaigns). `novIntegrations.test.tsx` and `clientUnit.test.tsx` were updated to match.
- **Not run here.** The e2e specs (`project-first-visit` clears both stores, so it is unaffected) and the full suites, which are left to the batch owner.
