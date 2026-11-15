# Builder B — Nov overhaul notes

Branch `feature/nov-overhaul-b`, based on `origin/main` `aaa2ccc33`. Not pushed.

## Task 2 — permissions (ACCESS-SECTIONS-001)

Extends the access center from PR #1067 (ACCESS-CENTER-001). There is no new permission system: the role is still a
tenant `Role`, per-person switches still live in `member_access_overrides`, and `HasRoles::permissionKeys()` is still
the one resolution point.

### The three layers

| Layer | Stored in | Edited from |
|---|---|---|
| (a) Role | `role_user` (tenant `Role`, the 7 ready-made presets + custom) | access-center drawer, «الفئة» |
| (b) Clients | `membership_scopes` (`client`) | drawer, «العملاء» (agency workspaces) |
| (c) Sections | `member_access_overrides`: deny row `section:<key>`; an `allow` row on the section's reading key when the role lacks it | drawer, «الأقسام»; invite dialog under «تخصيص» |

Sections: `campaigns` الحملات · `budgets` الميزانيات · `spend_limits` حدود الإنفاق · `invoices` الفواتير ·
`reports` التقارير · `integrations` التكاملات · `team` الفريق. Defined once in
`backend/app/Domains/Access/Support/AccessSections.php`.

- **Held by role** means the effective keys include a key that already opens the section's API. That covers the keys
  the routes check, every tenant key `ProjectAbilities::FROM_TENANT` turns into the section's project capability, and
  `settings.manage`. The rule follows the routes, so it narrows nobody: a legacy role that reads budgets through
  `analytics.view` still holds budgets and spend limits.
- **Effective** means held by role (or an active project membership, for the project-scoped sections), minus anything
  switched off.
- **Enforcement:** the `EnforceSectionAccess` middleware is appended to the `api` group and placed in the priority list
  after `ResolveMembership`. It maps a route NAME to a section (`AccessSections::all()[*]['routes']`) and returns 403
  when that section is switched off for the member. It only ever refuses. Each route's own check (`project.can:…`,
  `hasPermission`) stays deny-by-default for roles that lack the keys.
- **No re-login:** rows are read once per request (`MemberOverrideMemo`, scoped). Every write calls `forget()`.
  Nothing about access is stored in the session or the Sanctum token.
- **Existing members:** nothing is migrated or rewritten. With no `section:` row, a member's sections are exactly what
  their keys already opened. The old `PUT settings/team/{user}/role` path keeps a person's switched-off sections.

### Endpoints

- `PUT /api/v1/settings/access/members/{user}` — the edit after the invitation, one save:
  `{ category?, areas?: {area: none|view|manage}, sections?: {section: bool}, client_ids?: [uuid], reason? }`.
  The walls from #1067 still apply: grant only what you hold, only the owner hands out team/settings keys, nobody
  edits themselves, the owner is locked, other tenants get 404, and client-portal members are refused (422). It writes
  ONE audit row, `access.member_updated`, with before and after:
  `{category, sections[], client_ids[], allow[], deny[]}`.
- `POST /api/v1/settings/access/invitations` and `PUT …/invitations/{id}` also accept `sections`. They are stored in
  the invitation's `access_overrides.deny` as `section:<key>` and applied at acceptance.
- `GET /api/v1/settings/access/audit?limit=30` — the compact access log: who, whom, when, and what changed. Each entry
  carries `changes[]`: `{field, from, to}` for a value, `{field, added[], removed[]}` for a list. The access center
  shows it folded under «سجل الصلاحيات».
- `GET /api/v1/settings/access` — now also returns `sections[]` (the catalogue) and `members[*].sections` /
  `invitations[*].sections` (`{key: bool}`). The drawer fills its switches from these.

### For the dashboard builder: `GET /api/v1/me/access`

Requires auth and a bound tenant (`auth:sanctum`, `tenant`). It is read fresh on every request.

```json
{
  "success": true,
  "data": {
    "role": { "slug": "media-buyer", "name_ar": "مشتري إعلانات", "name_en": "Media buyer" },
    "is_owner": false,
    "sections": ["campaigns", "budgets", "spend_limits", "reports"],
    "all_sections": [
      { "key": "campaigns", "ar": "الحملات", "en": "Campaigns", "allowed": true },
      { "key": "invoices", "ar": "الفواتير", "en": "Invoices", "allowed": false }
    ],
    "clients": { "all": false, "ids": ["<client-workspace-uuid>"] }
  }
}
```

- `role` is null for a member with no tenant role.
- `clients.all = true` means no client ceiling, and `ids` is then empty.
- Frontend: `useMyAccess()` / `useSectionAllowed()` in `frontend/src/lib/accessSections.ts`. Query key
  `['me','access']`, refetched on focus and every 60 s. `updateMember` invalidates it.

### Navigation

`AppShell` and `AgencyShell` hide any rail, workspace-menu or "more" leaf whose path is in a section the member lacks
(`sectionOfPath`). Hidden means removed from the menu, not shown disabled. While `/me/access` is loading, or if it
fails, the shells fail open. That matches the existing `can()` rule and is safe because the server refuses anyway.

### Tests

- `backend/tests/Feature/AccessSectionsTest.php` (9 tests). Covers:
  - all 7 sections refused (403) for a role without their keys;
  - each section refused on the next request after it is switched off, then reopened;
  - switching a section on grants its key;
  - role, sections and clients changed in one save, with one audit row and the log;
  - the walls;
  - a legacy member unchanged;
  - a role change keeps the switches.
- `frontend/src/features/settings/access/AccessCenter.test.tsx` (+2) and `frontend/src/lib/accessSections.test.ts`.

### Deferred

- **Role names.** The Owner listed مدير، مدير مشروع، مالية، مسوّق، مشاهد. The presets today are
  مدير المساحة، مدير مشروع، المالية، مشتري إعلانات، محلل، معدّ تقارير، مطّلع. They map one to one
  (مسوّق = media-buyer, مشاهد = viewer), and analyst and reporter remain as extras. Renaming the labels touches the
  invitation mail, the role labels and the wording guards, and needs an Owner decision on two points. Should «مدير» be
  the workspace owner or a new non-owner admin? Should analyst and reporter be retired? Neither is decided here.
- The invite dialog's section switches only CLOSE sections for that invitation. Opening a section the category lacks is
  done after acceptance, from the drawer.
- Area switches («تخصيص له فقط», 10 areas) stay as they were under the sections, as the fine-grained layer.
