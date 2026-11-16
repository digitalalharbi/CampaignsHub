# AF1 — landing flip and the agency pages that were left scrolling

Branch `feature/agency-fit-1`, based on `origin/main` a01dc3b01 (the deployed release #1071). Not pushed. AF2 converts the
other pages in parallel; nothing here touches them.

## 1. The role dashboard is the landing (Owner: «يظهر أول ما يدخل المستخدم»)

E-notes said to flip three things together. They are flipped together, in one commit:

| Thing | Before | After |
|---|---|---|
| Server destination (`Portal::landingPath`, read by sign-in, OAuth, the memberships endpoint, support visits, onboarding) | `/app/dashboard`, `/agency` | `/app/home`, `/agency/home` |
| Router indexes | `/app` → `/app/dashboard`, `/agency` → `/agency/dashboard` (CI-REPAIR decision #2) | both → `/…/home` (this REVERSES that decision, on purpose) |
| Rail «الرئيسية» leaf, phone tab bar Home, `safeRedirect` fallback, PWA `start_url`, marketing «Dashboard» links, `RequirePlatformAdmin` bounce | `/…/dashboard` | `/…/home` |

The full analytics board stays reachable: `/…/dashboard` still routes, and it is the first leaf of the «النتائج» group
(«لوحة التحليلات»). Old bookmarks and emails (which link `/app/dashboard`) keep working. No test was deleted or skipped.

Tests moved to the new landing: 9 backend feature files (destination / landing_path assertions; 268 pass), and in the
frontend `redirect.test`, `pwaManifest.test`, `navGrouping.test` (`/…/home` joins the leaf-path list), `RequirePlatformAdmin.test`,
`PublicHomePage.test`; e2e `registration-onboarding` (agency and app walks), `workspace-invitation`, `auth-redesign`,
`access-center`, `pwa-install` (the start-URL case now also proves `/agency/dashboard` is reachable).
E-notes counted ~77 + ~10 files; only these assert the LANDING. The rest `goto('/…/dashboard')` as an address and keep working.

## 2. Fit — pages converted (D's primitives: FitPage, KpiMini, TabStrip, Pager, ResponsiveList, DetailPanel, useFitRows)

Measured by `e2e/agency-fit.spec.ts` at 1366×768, 1440×900, 1920×1080 in ar and en (no vertical or horizontal scroll, nothing cut
off by the lock, no scroll region scrolling at rest, no console errors) and at 360/390/430/768/1024/1366/1920 (never sideways).
Before: `/agency/dashboard` scrolled 5,438px; integrations «all clients» 47px. The others fit the demo seed but scrolled or paged
internally with a fuller estate, so the spec SEEDS one (12 budgets, 8 limits; `e2e/agencyFitSeed.ts`, removed afterwards).

| Page | Shape |
|---|---|
| `/agency/home`, `/app/home` (role dashboard) | already fitted by builder E (`useFitViewport`); now the landing and measured here |
| `/agency/dashboard` (all-clients level) | title + 4 compact counts + tabs: Needs attention (default) · Figures · Trend & platforms · Clients (cards, paged) · Budgets (pace chart + paged table, projects in a layer) · Mix · Content |
| `/agency/budgets` | totals + FX bar + a page of whole board rows + pager; drawer / period / renew-all / export unchanged |
| `/agency/spend-limits` | figures + enforcement sentence + tabs Limits (row per limit, paged, details layer) · Readings |
| `/agency/integrations` all clients | one-line rows, action filter in the toolbar, paged |
| `/agency/integrations` client space | figures + Sources / Platforms / Audit tabs (`?section=`), sources paged; chooser pages too |
| `/agency/clients/:id` main screen | name + chips + tab strip + 4 counts + budget (3 projects) + actions; classification editor in a layer |

Shared, additive: `useTabParam` (tab in the address, via `useLatestSearchParams` so the period picker's mount write cannot
swallow a tab click — the known Firefox trap), `useColumns`, `FitPage` `dir` / `slideOverAvoid`, `ProjectChooser fit`,
`ClientCards fit`, `ConnectionHub fit`, `HubSummary compact`, `AgencyPeriodBar`, `ClientBudgetCard limit`.

## 3. Decisions and the exceptions

- **Creative tab on the board flows** (`fit={tab !== 'creative'}`): `CreativePulseSection` is a 1,200-line filter bar + poster
  wall + findings, a document of its own. It is the one tab on these pages that can scroll (below the lock it always can).
- **Client tabs other than «نظرة عامة» flow** (campaigns, billing, conversations, files … are lists/forms of their own), like
  the team page's access center in D's notes. «Main screen» = the overview.
- **An expanded integrations source row** grows in place (its own state, inside the paged list); the list pages on COLLAPSED rows.
- Tab strips that tests drive by role keep their markup: the client page's tabs are still `button`s, the integrations hub's
  `hub-section-link-*` are unchanged. The board uses `TabStrip` (role `tab`) and its tests/specs were updated.
- Feature-first: the board's answer to «what needs attention now?» is the default tab; the old DOM-order assertion became a tab-order
  assertion. `feature-first.spec` (spend limits) returns early now — the chart is on another tab, so there is no order on one screen
  to check; the property holds by construction.
- Mail links (`/app/dashboard` in notification emails) are unchanged — they still resolve.

## 4. Deferred, with the reason

- `/agency/dashboard?client=<id>` and `/app/dashboard` (the single-client level) mount `AnalyticsPage surface="dashboard"`, the
  shared 2,000-line analytics engine — D's deferred list and AF2's territory; not converted here.
- The client page's non-overview tabs (above) and `ClientProjectsTab` lists are not fitted.
- AppShell / AdminShell still use the old rail (D-notes); the role home on `/app` is fitted inside it.

## 5. Running locally (own ports/DB; never adopt another checkout's stack)

```
E2E_ORIGIN=http://localhost:5483 E2E_FRONTEND_PORT=5483 E2E_BACKEND_PORT=8210 E2E_PRINT_PORT=5583 \
E2E_DB_DATABASE=mediabuying_af1_e2e E2E_REDIS_PREFIX=af1-e2e- \
npx playwright test agency-fit.spec.ts shell.spec.ts --project=chromium   # and --project=webkit
```
Backend: `~/Developer/ch-scripts/ptest.sh <worktree> --filter='…'` (own database). Firefox cannot launch on this Mac.

## 6. Verification (this tree, local, real build)

- `tsc -b` clean; full `vitest`: all files pass (the five landing/nav tests, the dashboard/limits/integrations/clients tests were
  updated to the tab/panel they read; two ratchets obeyed: `pageHeaderAdoption` line removed, `noForbiddenTerms` — no retired word
  in the new labels).
- Backend: landing-related feature tests (Portal*, Membership*, Onboarding*, Invitation*, OAuth*, LoginSingleDoor, SupportAccess,
  WorkspaceSwitchIsolation, PlatformConsole, GoogleLink …) pass through `ptest.sh` (serial, own database).
- chromium, `agency-fit.spec.ts` (new): 227 pass — 17 pages/tabs × (3 desktop sizes × ar/en) + 7 widths, with a seeded fuller estate.
- chromium broad subset (shell, first-viewport sweep, responsive sweep, role dashboard, board/period/budgets/limits/integrations/
  client specs, sign-in/onboarding/invitation/landing specs, access, PWA …): 725 pass, 6 skipped (data-conditional, as on main),
  1 failure found and fixed — `budgets-ledger` «eight projects fit one screen» (the pager cost a row; fixed in the density commit).
- webkit: 356 pass over the fit spec, shell, role dashboard, board, period, budgets, limits, integrations, client, PWA, onboarding,
  invitation, sign-in and access specs. Firefox cannot be launched on this Mac; CI runs it. Reasoned from code: every tab/param
  write goes through `useLatestSearchParams` (the stale functional-`setSearchParams` trap), no AudioContext, and focus moves only
  inside `DetailPanel` (D's trap, already webkit-fixed).
