# Project lifecycle — Owner spec (2026-11-15), binding

Branch `feature/project-lifecycle`. One batch, one deployment. DB additive + reversible only (migrations with down()).
No secrets in commits. No heavy server work. NO production access from builders: everything is built and tested on
local fixtures; production reads/changes are run later by the lead through a guarded ops path.

## PROTECTION (takes precedence over everything)
A production workspace holds a live client + live project whose data and reports are shared with the client. They must
NEVER be touched. A separate ARCHIVED test project was registered wrongly under that same client; only it is subject to
treatment. Rules:
- NO deletion of any project/client/workspace in this order — not by migration, script or hand. This batch BUILDS the delete
  feature only; the Owner deletes from the UI himself.
- NO archiving or status change of the live client or live project.
- Any data change on the archived project: WHERE on its id only, after a read check, reversible.
- Production ids are NOT in this repo: forbidden in code, seeds, tests, docs and commit messages. Ops commands take ids as
  arguments/inputs.
- Before/after snapshot of the live project (accounts count, campaigns count, last successful sync per platform, 30-day
  metric totals, scheduled reports, client-portal users, budgets/limits/rules/subscriptions) — any difference (except new
  metrics from a normal sync) → stop and revert. If the read check finds data shared between the two projects: modify
  none of it, report it in detail.
- Therefore: any migration/command that touches project data must be id-scoped, read-check first, reversible, and print a
  before/after snapshot; no bulk operation without an explicit condition. Provide an idempotent artisan ops command
  `lifecycle:apply-archive-effects --project=<id>|--all-archived [--dry-run]` (dry-run default; applying archive effects
  = closing alerts/tasks, stopping schedules/syncs; NEVER deletes) and `lifecycle:snapshot --project=<id>` (read-only,
  counts only, JSON) for the lead to run in production.

## 0. Permanent engineering rules — create docs/rules/project-lifecycle.md and add a short summary + link in CLAUDE.md
1. SaaS first: every project/client feature works for ALL workspace types (agency, direct business, client portal, future)
   and all plans. No logic/table/component tied to a type; shared workspace-level code, per-type routes are wrappers.
2. Hierarchy: workspace → client → project → ad accounts → campaigns. A project belongs to exactly ONE client and ONE
   workspace (FKs mandatory). No client-less project; workspaces without a client concept get a hidden default client.
3. Project states: draft / active / paused / archived / deleted(soft). Allowed transitions defined in ONE function; every
   transition logged with actor + time; never set status directly in the DB outside that function.
4. Client states: prospect / onboarding / active / needs_attention / paused / completed / archived / deleted. Client state
   NEVER affects computing its data/spend: aggregation depends on PROJECT state only (an «onboarding» client with an active
   project that spends must show its spend).
5. «Active only»: any aggregation, alert, notification, email, digest, automation, recommendation, task or sync runs ONLY on
   active + paused projects. Archived/deleted always excluded from ONE source — a view or a shared scope function used by
   all code; never hand-write status filters per query.
6. Ad accounts: one account may link to several projects only with explicit confirmation, shown with a «مشترك» badge.
   Stopping/unlinking one project never affects the other projects linked to the same account.
7. Deletion: two phases — soft (trash with configurable retention) then permanent. NO cascade permanent delete of clients or
   workspaces without an impact window + typing the name. Audit and notice-delivery logs are NEVER deleted (name snapshot kept).
   Deleting inside the system never touches the ad platforms.
8. Active-data protection: any migration/script that modifies/deletes project data must be id-scoped, preceded by a read
   check, reversible, and print before/after snapshots; no bulk ops without an explicit condition.
9. One source of numbers: spend/budgets/ratios on every page (dashboard, summary, client list, budgets, campaigns, reports,
   notifications) read from the same aggregation functions and the same currency conversion engine (FxEngine).
10. i18n: every status/text via translation keys; never show internal values like «active».

## 1. Data model + permissions
Project and client: status, archived_at/archived_by, deleted_at/deleted_by, purge_after, name snapshot in logs. Status-transition
log for projects and clients. Permissions (existing permission system, server-side): projects.archive, projects.restore,
projects.delete, projects.purge, clients.archive, clients.delete — default workspace owner only; others granted from settings.
Plan-feature key projects.trash_retention_days default 30, editable per plan from admin; enabled for all plans now.

## 2. Shared scope
One function/view for «live projects» (active + paused) within a workspace. REPLACE every aggregation/alert/sync/
notification/digest/automation/recommendation/task/picker query with it. List every place changed in the report.

## 3. Archive
On archive: stop syncing the project's accounts, or unlink with an «unlink accounts» option; stop alerts, internal+external
notifications, emails, digests, automation, recommendations, tasks, scheduled reports; auto-close open alerts and tasks with
reason «المشروع مؤرشف»; exclude from every list/picker/subscription/aggregation/budget/dashboard — shown only under «المؤرشفة».
Shared account linked to another project → warning; archive stops only THIS project's link. Un-archive restores everything
as before, after confirmation.

## 4. Delete
Button «حذف» in the workspace-settings projects table and on the archived project page; shown only with projects.delete.
Soft delete: applies all archive effects, unlinks all accounts, moves the project to «المحذوفات» with «استعادة»; permanent
deletion automatically after the retention period. Permanent delete (manual from trash with projects.purge, or automatic):
deletes project data, keeps audit + notice logs with the name snapshot. Confirm window: shows impact (accounts, campaigns,
budgets, rules, recipients, scheduled reports), requires typing the project name, states the ad platforms are unaffected.
Client: if the project is the client's only one, ask «المشروع فقط» or «المشروع والعميل»; if the client has other projects only
the project is deleted and the client is unaffected. Internal notification to the workspace owner on delete and 3 days before
automatic permanent deletion.

## 5. Restore + move
Restore from «المؤرشفة» or «المحذوفات» returns the project to its previous state; unlinked accounts are re-linked with
confirmation if still available. «نقل المشروع لعميل آخر» inside the SAME workspace: workspace-owner permission, impact window,
full log; cross-workspace move forbidden.

## 6. Fix the false alert «بيانات أمس ما وصلت من المنصة»
Symptom: successful syncs every 30 min covering 2026-11-12→15, yet critical alerts fire: Snapchat «last day received
2026-11-11», Google «last day received 2026-11-10», named «المشروع» instead of the project name, and raised from an archived
project with no accounts. Required: root-cause analysis recorded; correct definition: day D is covered for an account if a
successful sync whose period includes D finished after the end of D in the account's time zone plus a configurable settle
window (default 6 h); zero spend or paused campaigns = data, not a gap. Alert fires ONLY if there is no coverage after the
window, or the last sync failed, or the link expired. Evaluate after the daily sync cycle. Auto-close when a successful sync
covers the day. Alert content: project name, account + id, last successful sync + its period, reason of the verdict. No alert
for an archived/deleted project. Re-evaluate every open alert of this type in all workspaces and close the false ones with the
reason (as an idempotent ops command with --dry-run).

## 7. Aggregation + currency (analysis + fix)
Symptom (live client, Google account in EUR, a SAR budget 50,000 open-ended): budgets page 14,235 SAR (28%) with «ناقص سعر صرف»;
clients list $4,229.08 for 30 days; dashboard + projects summary «$0 of $8.75K». Admin rates: EUR→USD manual dated 2026-11-09,
SAR→USD pegged. Required: analyse root cause (archived project? client state? rate validity? missing chained conversion?
different aggregation functions?) and where 8.75K comes from; ONE central conversion engine with automatic chaining through
USD; NEVER zero spend because of a missing currency — only the affected part flagged «سعر صرف غير متوفر» with currency and
account name and excluded from the total with a statement; an expired manual rate → last rate used with «سعر قديم — بتاريخ …»
+ an internal admin alert 3 days before expiry; the dashboard's budget denominator = the budgets page budget converted by the
central engine; every page reads the same aggregation functions (rule 9).

## 8. UI
Workspace-settings projects table: fix «الإعداد» column showing 10000% instead of 100% (and every place using that formatter);
status column Arabic via keys with design-system badges; buttons by state+permission: active «فتح / أرشفة / حذف», archived
«فتح / استعادة / حذف», deleted «استعادة / حذف نهائي»; filters active / archived / trash; clear «العميل» column; «نقل لعميل آخر».
Archived «فتح» opens the project page itself in VIEW-ONLY mode with a top banner showing the state and «استعادة» / «حذف»
buttons (not a redirect to the client page). Client page: «المشاريع» tab separates active from archived; «أرشفة العميل» shows
an impact window listing all active projects that will be archived with it and requires typing the client name. Shared-account
warning on the integrations page and the archive/delete windows. Same design system, RTL, 100% mobile, all browsers.

## 9. Existing data (run later by the lead in production, never by builders)
Archived test project: read check documenting everything linked; confirm nothing shared with the live project; after deploy
apply the new archive effects (close its alerts/tasks, exclude from aggregations/emails/digests); do NOT delete or move it.
All workspaces: run the same archive effects for all currently archived projects, no deletion; report each affected project
and what closed.

## 10. Tests (mandatory)
Protection: live-project snapshot before/after identical except new sync metrics; no deletion ran. Scope: archived/deleted never
appear in any aggregation/alert/email/digest/picker/sync; an «onboarding» client with an active spending project shows its
spend. Archive/delete: archive and un-archive restore everything; opening an archived project → view-only page; soft delete
hides + restore returns; permanent delete removes data but keeps logs with name snapshot; deleting a project of a client with
another project leaves both untouched; shared account → archiving/deleting one project doesn't affect the other. Move: inside
the same workspace moves all data; across workspaces rejected. Permissions: no-permission user rejected in UI and API. Alerts:
covering sync → no alert; no coverage after the window → alert with project + account; a later successful sync closes it.
Currency/aggregation: client with EUR account + SAR budget → dashboard = summary = clients list = budgets = campaigns for the
same period, max difference 0.01; deleted rate → only the affected part excluded with a flag; expired rate → last rate with «قديم».
UI: table shows 100%, Arabic statuses; all scenarios in agency, direct-business and client-portal workspaces, desktop + mobile.
No production ids anywhere in code/tests/seeds.

## Final report must include
1 live-project before/after table + explicit confirmation untouched; 2 link to docs/rules/project-lifecycle.md + the CLAUDE.md
line; 3 every place moved to the shared scope; 4 root cause of the false alert + alerts closed per workspace; 5 root cause of
zeroed spend + source of 8.75K + a five-page comparison for the live client before/after; 6 result of the archived project's read
check and whether data is shared; 7 archived projects that got archive effects in each workspace; 8 ad accounts linked to more
than one project; 9 screenshots (projects table with filters, delete window, trash, view-only archived page, client archive
window, shared-account warning); 10 deferred.
