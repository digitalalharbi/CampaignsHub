# Project lifecycle — permanent engineering rules (Owner, 2026-11-15)

Binding for every change. Source: `docs/project-lifecycle/OWNER_SPEC_2026-11-15.md` §0. Contract code:
`backend/app/Domains/Projects/Lifecycle/` (PHP) and `frontend/src/lib/projectLifecycle.ts` (TS).

1. **SaaS first.** Every project/client feature works for every workspace type (agency, direct business, client
   portal, future) and every plan. No logic, table or component tied to a type: shared workspace-level code; per-type
   routes are thin wrappers.
2. **Hierarchy.** workspace → client → project → ad accounts → campaigns. A project belongs to exactly one client and
   one workspace (FKs mandatory). No client-less project; a workspace without a client concept has a hidden default
   client (`CanonicalWorkspace`).
3. **Project states** `draft · active · paused · archived · deleted` (`ProjectStatus`). The allowed moves are ONE table
   (`ProjectStatus::allowedTargets()`) and the status is written by ONE function,
   `ProjectLifecycle::transition()` (`ProjectStateMachine`), which logs actor + time + name snapshot
   (`project_status_transitions`) and the audit log. Never set `projects.status` anywhere else —
   `Project::updating` throws and `ProjectLifecycleSourceGuardTest` fails the build.
4. **Client states** `prospect · onboarding · active · needs_attention · paused · completed · archived · deleted`
   (`ClientStatus`), written only by `ProjectLifecycle::transitionClient()`. **Client state never affects computing
   its data or spend** — aggregation depends on project state only.
5. **Live only.** Every aggregation, alert, notification, email, digest, automation, recommendation, task, picker and
   sync runs only on live projects = active + paused, selected through ONE source: `LiveProjects` /
   `Project::live()` / the `live_projects` SQL view (`running()` = active only, for sync and daily digests;
   `listed()` = draft + active + paused, for management lists and «link to project» pickers). Never hand-write a
   project status filter — `LiveProjectsSourceGuardTest` fails on one.
6. **Ad accounts.** One account may link to several projects only with explicit confirmation, shown with a «مشترك»
   badge. Stopping or unlinking one project never affects another project linked to the same account (every effect is
   filtered by the project id; `ArchiveEffects::sharedAccounts()` is the warning data).
7. **Deletion** is two-phase: soft (trash, retention = plan feature `projects.trash_retention_days`, default 30) then
   permanent. No cascade permanent delete of a client or workspace without an impact window + typing the name. Audit
   and notice-delivery logs are never deleted (name snapshot kept). Deleting inside the system never touches the ad
   platforms.
8. **Active-data protection.** A migration/script that modifies or deletes project data is id-scoped, preceded by a
   read check, reversible, and prints before/after snapshots (`lifecycle:snapshot`); no bulk operation without an
   explicit condition. Ops commands are dry-run by default and never delete.
9. **One source of numbers.** Spend, budgets and ratios on every page read the same aggregation functions and the same
   currency engine (`FxEngine`).
10. **i18n.** Every status and text through translation keys (`projects.lifecycle.status.*`,
    `clients.lifecycle.status.*`; TS `lifecycleLabel()`); never show an internal value like «active».

Permissions (server-side, existing system; workspace owner by default, others granted from settings):
`projects.archive`, `projects.restore`, `projects.delete`, `projects.purge`, `clients.archive`, `clients.delete`.
