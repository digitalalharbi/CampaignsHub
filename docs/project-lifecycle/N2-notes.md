# Builder N2 — delete, trash, restore, permanent delete, move, UI (spec §3 remainder, §4, §5, §8)

Branch `feature/project-lifecycle-n2` (based on `feature/project-lifecycle` = N1 + N3 + G). Not pushed. Status
`IMPLEMENTED_NOT_VERIFIED` until the lead's full suite, CI and a walk. No production id anywhere; every fixture is generated
(`tests/Concerns/LifecycleFixtures.php`). Nothing here deleted, moved or archived any existing row: the migration is
additive; the only code that deletes is `ProjectPurger`, reachable only for a project already in «المحذوفات».

## What exists now (for the lead and later builders)

| What | Where |
|---|---|
| Soft delete (one lifecycle call) | `ProjectLifecycle::softDelete()` — `deleted` transition (purge_after = now + `TrashRetention`), every archive effect with accounts unlinked, + delete-only effects `share_revoked`, `lead_form_unpublished`, `conversion_destination_off` (`ArchiveEffects`, recorded per row) |
| Delete endpoint | `DELETE projects/{id}` `{confirm_name, client_action?: project_only\|project_and_client, confirm_client_name?}` → `DeleteProject` |
| Delete window data | `GET projects/{id}/deletion-impact` + `budgets`, `spend_limits`, `alert_rules`, `automation_rules`, `recipients`, `shared_accounts`, `client{other_projects, can_delete_client}`, `retention_days`, `ad_platforms_untouched` |
| Trash list | `GET projects?status=deleted` (alias `trash`): soft-deleted, not purged, newest deletion first |
| Restore | `POST projects/{id}/restore {confirm, relink_accounts?}` from archived or deleted → `RestoreProject`; `GET projects/{id}/restore-impact` (target state, accounts to re-link + availability, client comes back?) |
| Permanent delete | `POST projects/{id}/purge {confirm_name}` (projects.purge) → `ProjectPurger`; plan `ProjectPurgePlan` |
| Retention job | `lifecycle:purge-expired [--apply]` (dry run default), scheduled daily 02:30 with `--apply` |
| Notices | `LifecycleNotices` (`project.deleted`, `project.purge_soon`) → workspace owners only, via `NotificationDispatcher` |
| Move | `GET projects/{id}/move-impact?client_workspace_id=` · `POST projects/{id}/move {client_workspace_id, confirm_name}` (projects.move) → `ProjectMover` |
| Client archive | `GET app/clients/{id}/archive-impact` · `POST app/clients/{id}/archive {confirm_name}` archives its draft/active/paused projects; client restore un-archives those |
| «مشترك» data | `GET projects/{id}/integrations` → each binding `shared_with[]` |
| UI | `features/settings/tabs/ProjectsTab.tsx` (table), `features/projects/lifecycle/ProjectLifecycleDialogs.tsx` (all windows), `features/projects/lifecycle/ProjectOpenPage.tsx` (view-only archived page, route `…/projects/:projectId` in `/app` and `/agency`), `features/clients/ClientProjectsTab.tsx`, `TabSettings` client-archive window, integrations-page warning |
| Keys | `lib/projectLifecycle.ts` `LABELS` (`projects.lifecycle.*`, AR + EN), `lt()`, `lifecycleTone()`, `projectRowActions()` |
| Setup % | `lib/percent.ts` `formatWholePercent` (the one formatter; `percent.test.ts` guards src) |

## Decisions

1. **Effects, not ad-hoc updates.** The old `DeleteProject` deactivated bindings / revoked links / paused schedules / archived
   forms with direct updates that nothing could reverse. All of it is now an `ArchiveEffects` row, so restore is exact.
2. **Restore from the trash reverses only the delete's effects** (`ArchiveEffects::restore($onlyTransitionId)`). An archived
   project that is deleted and restored comes back *archived* with its archive effects intact; un-archiving then returns it to
   its state before the archive. Fixed in the machine: deleted → archived now reads the pre-archive state from the log
   (`stateBeforeLastArchive`) — before, the delete overwrote `previous_status` and the original state was lost.
3. **Re-link** only with confirmation (`relink_accounts`, default true in the window) and only if the ad account is still in the
   inventory (status not removed/deleted/revoked/disconnected → `skipped_unavailable`). A shared account gets back only THIS
   project's binding row.
4. **Tombstone, not row delete.** Purge deletes the data rows and keeps the `projects` row (status `deleted`, soft-deleted,
   `purged_at`, `meta` cleared). Reason: `funding_notices` and `app_notifications` (→ `notification_deliveries`) cascade from
   `projects`; they are notice logs. A tombstone is never listed and never restorable (`findWithTrashed` excludes purged).
5. **Purge plan** (`ProjectPurgePlan::TABLES`, 73 tables): purge (data), detach (invoices, quotes, requests, request
   conversions, message threads, leads — client-level records; NULL means «no project», never «all»), keep_log (transitions,
   archive effects, app notifications, funding notices, conversion deliveries + their destination), never (integration
   credentials, provider connections — a connection cascades into the ad-account inventory). `report_share_access_logs.share_id`
   became SET NULL (reversible migration) so purging reports keeps the share access log. Notification recipients are PURGED, not
   detached: a NULL project there would widen a recipient to every project.
6. **Client deletion** only via «المشروع والعميل» on the client's last non-deleted project, typed client name, never the hidden
   canonical client; server-enforced (422 otherwise). The client is soft-deleted through `transitionClient()` and restored with
   the project. There is **no automatic or manual permanent purge of clients** (rule 7 — not built).
7. **Move**: `ProjectMover::TABLES` re-points rows that carry both `project_id` and `client_workspace_id` (bindings, tasks,
   campaigns, funding, proposals, …); billing documents, logs and connection rows stay. Logged as a `project_status_transitions`
   row with from = to and reason `moved` (both client names in context) + audit. Permission `projects.move` (new, tenant-owner
   only via the migration; also in the access catalogue's «manage projects» like N1's `projects.purge`).
8. **Retention job default**: dry run unless `--apply`; the scheduler passes `--apply`. Pre-lifecycle soft-deleted rows have
   `purge_after = NULL` and are never auto-purged.
9. **Notifications**: `docs/notifications-v2/CONTRACTS.md` is not on this base → existing `NotificationDispatcher` (in-app +
   delivery log). Merge point: register `project.deleted` / `project.purge_soon` in notifications-v2.
10. **Client column / create in a workspace without clients**: `ProjectResource.client_name` (eager-loaded on the listing) so the
    column reads in every portal; the settings create form hides «العميل» when the workspace has no client list and lets the
    server resolve the hidden default client. «نقل لعميل آخر» shows only when there is another client (data-driven, no
    type-specific code).
11. **«فتح»** routes to `…/projects/:id`: archived → the view-only page; live → unchanged destination (agency: the client page;
    advertiser: the dashboard scoped to the project). `AgencyProjectToClientRedirect` is kept exported but no longer routed.

## Tests

- Backend new: `ProjectTrashPurgeMoveTest` (21, three workspace types where it matters; `Http::preventStrayRequests()` — no
  provider call), `ProjectPurgePlanCoverageTest` (4, live-schema walk). Updated: `ClientManagementTest` (typed name),
  `ProjectRouteCapabilityCoverageTest` (4 routes), `ScheduledWorkInventoryTest` (the job).
- Targeted sweep `Project|Client|Lifecycle|Guard|Capability|Route|Permission|Access|Catalogue|Schedule|Console|Purge|Trash|…`:
  2,182 run → all green after the three guard updates, except `BoundAccountVisibilitySourceGuardTest`, PRE-EXISTING on the base
  (names `FxConversionReportCommand`, `PeriodCoverage`, `ReportFxFacts` — none touched here).
- Frontend: `percent.test.ts` (fail-first: caught three call sites), `ProjectsTab.lifecycle.test.tsx` (7),
  `ProjectOpenPage.test.tsx` (4); `src/features/{projects,settings,clients,integrations}`, `src/lib`, `src/app`, `src/layouts`:
  green except the PRE-EXISTING `countedNouns` (`AgencyHeadline.tsx`). `tsc -b` clean; pint + phpstan clean on every changed file.
- E2E `e2e/project-lifecycle.spec.ts`: agency + direct business at 1366 and 390 (create → archive → view-only → restore → delete
  → trash → restore → delete → purge, only on projects it creates; no horizontal scroll), client-portal user refused by the API.
  Run on an isolated DB/ports (`E2E_DB_DATABASE=mediabuying_n2_e2e`, 8162/5262/5362): **chromium 5/5, webkit 5/5**; firefox did
  not launch in this host (sandbox `Operation not permitted` / `RenderCompositorSWGL failed`) — 0 ms failures, not product ones.
- Found by the 390px run and fixed: `Modal` (bottom sheet) was `z-50`, the same layer as the phone's bottom tab bar, which sat over
  the sheet's footer and swallowed its confirm button. Now `z-[60]`, the overlay layer `DetailPanel` and the drawers use — this
  affects every modal on a phone, not only these windows.
- Full frontend vitest: 5,240 passed, 1 failed (`countedNouns`, PRE-EXISTING).

## Deferred, and why

- **`DELETE client-workspaces/{id}`** (old agency endpoint, `clients.delete`) still soft-deletes a client without an impact window;
  no UI calls it. Hardening it (refuse while it has non-deleted projects, typed name) changes `PlanLimitEnforcementTest` fixtures
  — left for the lead to decide; rule 7 is about *permanent* deletion, which this endpoint does not do.
- **Client permanent purge** — not built (rule 7 needs its own impact window; the spec asks for none here).
- **Plan entitlement gate** for `projects.trash_retention_days` — N1's merge point (TrashRetention reads plan JSON) unchanged.
- **Purge of very large projects** runs one `DELETE … WHERE project_id` per table in one transaction; fine for fixtures and normal
  projects. If a production project holds millions of metric rows, chunking is a follow-up (no data at risk — it is id-scoped).
- Matrix / ledger / RESUME_STATE not edited (Owner order).
