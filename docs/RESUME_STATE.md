# RESUME STATE — CampaignsHub

> **AUTHORITATIVE HANDOFF — written 2026-07-29 at a context-window emergency close.**
> A new session reads this file FIRST, then `docs/MASTER_EXECUTION_CONTRACT.md`,
> `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, `docs/OPEN_GAPS.md`, then resumes at
> **Exact next task** below — without redoing completed work and without asking the user.

---

## Current branch
`feat/taxonomy-ux` — repo `/Users/mohammedalharbimacbook/Developer/CampaignsHub-UI`

## Current commit
`2919ec9` — *perf(campaigns): open on the card list, not the chart-heavy overview*

Last work unit: `f0c813e` (CMS backend) → `320b569` (CMS editor + homepage rendering) → `fc90666` (docs).
Frozen delivery tags, **never move or rewrite**: `v1.0.0-baseline`, `v1.1.0-expanded-final`.

## Working tree status
**CLEAN** — `git status --porcelain` is empty at `fc90666`.
**No uncommitted work, no stash, no undocumented WIP.** Nothing was left half-edited; the last unit
closed on a green run.

## Completed work (this session — each committed, tested and live-reviewed)
1. **SITE-CMS-002** `7afaa00` · **XBROWSER-GATE** `131231c` (found 2 real defects Chromium had hidden)
2. **CAMPAIGN-010 + CAMPAIGN-020** `d5cdfcc` — five view modes, taxonomy chips, real multi-campaign
   comparison with objective-aware results.
3. **CAMPDET-010** `1bb796d` + `6606226` — audience, events, sync log, and **real ad sets + ads**
   (new `external_ad_sets` / `external_ads` tables, API and UI — no longer a "needs connection" note).
4. **REPORT-SCHEDULING** `f2ea999` — was BLOCKED_NO_API; API + UI built. Fixed an engine bug where
   `Carbon::next()` made every weekly schedule fire at midnight.
5. **FINANCE-001** `2d34eaa` — consolidated finance center: overview, aging, receivables worklist,
   payments ledger. Outstanding derived per invoice; pending money never counted as collected.
6. **SYNC-001 + DEMO-001** `4080a93` — real sync pipeline (queued per account, honest
   `awaiting_credentials` state) and the demo credential → connection → account → campaign chain that
   was missing. Linked campaigns went 0/39 → 39/39.
7. **PROJINT-001 + INTEG-UI-001** `a11cbfc` — project integrations rebuilt around the six real
   platforms, proven end to end live (trigger → queue → connector check → recorded run → log).
8. **ADAUDIT-001** `bd48b7f` — per-platform audit; found and fixed the `google`/`google_ads` registry
   drift and a sync gate on the non-existent `integrations.manage` permission.
9. **XREL-001 + DEVSTATUS-001** `3ae5098` — cross-module entity links; requirement board parsed from
   the matrix itself.
10. **PERF-CAMPAIGNS-001 (partial)** `2919ec9` — campaigns page opens on the card list again.

## Work in progress
**None.** Tree clean at HEAD.

## Latest work unit — AUTH-003/004/005 (auth redesign)
Login and register rebuilt around a shared `frontend/src/features/auth/AuthPanel.tsx`, built from the
**same tokens as the marketing homepage** — light surface, soft-green eyebrow pill, near-black heading,
brand-green accent — so signing in reads as the next section of one site. The hook «كل حملاتك الإعلانية
المدفوعة **في مكان واحد**» is the largest thing on the page, followed by four **tinted feature cards**
(capability + one plain sentence). /login gains a four-portal switcher; the wordmark links back to `/`.
On phones the panel is not beside the form: the form comes first and the panel collapses beneath it.

Two earlier attempts were rejected and are recorded so they are not retried: a near-black slab, then a
saturated green→teal→navy gradient. Neither appears anywhere on the homepage, which is what the auth
pages have to continue.

**Responsive lesson, learned the hard way.** Tightening the desktop composition (pulling the form toward
the divider with a logical `margin-inline-start`) was written *without a breakpoint*, so it also applied
where there is no second column: on phones and tablets the form was thrown against one edge with half the
screen empty, and at exactly 1024px — where the two-column layout switches on — the form overflowed its
own column and clipped. Both are now covered by `e2e/auth-redesign.spec.ts`:
  - the form must be centred (±2px) at 375 / 414 / 640 / 768 / **1023**, in RTL *and* LTR;
  - the form's box must lie fully inside the viewport at **1024** / **1280** / 1366 / 1440 / 375.
A layout pull that only makes sense beside a second column must be scoped to the breakpoint that renders
that column — and "no horizontal scroll" alone does not catch it, because the clip does not scroll.

Two real defects were found and fixed while wiring it, both of which had passing tests before:
- **Remember me was decorative** — held in React state and never sent, so `Auth::login($user, $remember)`
  always got `false`. Now submitted, validated, and covered by a persists / does-not-persist pair.
- **The chosen journey died on refresh** — it travelled as router state that `/verify-email` never read,
  so the wizard asked the visitor to pick the same path a second time. It is now stored on the tenant at
  registration (`account_type` + `service`), and verification resumes at the first *unanswered* step.

Google sign-in was **NOT** added: there is no Socialite package and no OAuth routes, so the button would
be dead. It is left out rather than faked.

## PORTAL PROGRAM (ADR 0002) — state as of `c5f200a`

**Adopted:** CampaignsHub is one multi-tenant modular monolith with four functionally separate portals
(`/app`, `/agency`, `/influencers`, `/portal`). See `docs/adr/0002-four-portal-modular-monolith.md`,
which also carries the audit of what the codebase actually looked like at adoption.

### Done, committed, tested

| Unit | Commit | What it delivers |
|---|---|---|
| PORTAL-0 | `618a201` | ADR + real audit (not documentation): 2 of 4 portals did not exist; client portal on its own token engine; app tree split across two prefixes |
| PORTAL-1 | `1b2a245` | `memberships` layer. `users.tenant_id` could only name one tenant, so multi-membership was structurally impossible |
| PORTAL-2a | `71256a3` | Membership is the scope source. `ResolveTenant` DELETED; `EnsurePortal` per-portal gate |
| PORTAL-2b | `5266411` | Switcher + server-derived destinations, proven live |
| PORTAL-3a | `34d7a6c` | `/app/*` consolidation; 11 legacy paths verified redirecting live |
| HARDEN-1/3 | `6a5a90f` | Request-scoped contexts (`scoped` + `terminate`); `membership_scopes` as a MANY relation |
| HARDEN-2 | `e3b8a10` | `users.tenant_id` fallback deleted; guard test blocks its return |
| HARDEN-4 | `008e979` | Access granted only explicitly (`GrantMembership`); the `User::created` auto-grant was wrong and is gone |
| HARDEN-5 | `c5f200a` | Scopes only widen by default; **no scopes = NO clients**, fail-closed; `clients.view_all` is the positive grant |

**504 backend tests · 253 vitest · `migrate:fresh --seed` clean (5 users / 5 memberships / 0 stranded).**

### Defects found by RUNNING rather than reading — all fixed

  - `migrate:fresh --seed` produced 5 users and **0 memberships**; every seeded account would have sat
    in onboarding forever. The migration backfill runs while `users` is still empty.
  - `MembershipContext` was not bound, so each injection got its own empty instance; the portal gate
    only worked because it re-queried the database.
  - Seven controllers validated foreign keys against `users.tenant_id` — the WRONG tenant the moment
    a user can switch workspace.
  - A plain `/login` claimed the `app` portal, so a multi-membership user could never reach the
    switcher. Caught live when the demo owner went straight to a dashboard.
  - `EnsurePortal` switched membership without moving the tenant scope.
  - Suspension read off the legacy column: one suspended agency locked its client out of an
    UNRELATED workspace they also belonged to.

### Agency portal — `/agency/*` is live

| Unit | Commit | State |
|---|---|---|
| AGENCY-001 scope grants + limits via `ClientAccess` | `94ee16c` | **VERIFIED** — one engine, not a duplicate |
| AGENCY-002 `/agency/dashboard` | `14b888a`, `c198f66` | **VERIFIED** — live: owner 5/5/19/1, scoped manager 1/1/1/0 |
| AGENCY-003 `/agency/*` shell + 12 sections + entry gate | `c198f66` | **VERIFIED** — all sections render, zero console errors |
| AGENCY-004 team & client scopes | `6b74658` | **VERIFIED** — add / remove / replace, live grant→narrow cycle |
| DEMO-002 scoped demo manager | `6b74658` | **VERIFIED** — `manager@demo-agency.local`, one client |
| UX-403-001 out-of-scope reads as a boundary | `6b74658` | **VERIFIED** — found by live id-tampering |

**Design decisions to keep:**

1. The agency portal REUSES `/app` engines (clients, projects, campaigns, reports, finance, files,
   conversations) through the shared, scope-aware `ClientAccess`. They are MOUNTED under `/agency/*`,
   not copied. Do not create agency copies — two implementations of the same rules is what ADR 0002
   forbids. `routes/api/agency.php` stays thin on purpose: only genuinely agency-shaped reads
   (dashboard, team) live there.
2. Shared pages must never hard-code `/app/...` in a link. Use `usePortalPath()` (`src/app/portalPath.ts`),
   which resolves against whichever portal the URL is in. Hard-coding it threw an agency operator into
   the advertiser portal mid-journey.
3. Nothing goes in the agency nav rail before it works. A nav entry that leads nowhere is a broken
   promise, not a roadmap.
4. React Query no longer retries any 4xx (`src/app/providers.tsx`). A 403 will not become a 200, and
   retrying hid the honest answer behind a spinner.

**Live-review accounts** (dev seed, password `password`):

| Account | Shows |
|---|---|
| `owner@demo-agency.local` | agency portal, unrestricted — 5 clients |
| `manager@demo-agency.local` | agency portal, confined to ONE client — the ceiling in action |
| `owner@demo-company.local` | advertiser only — `/agency/*` gives the honest denial screen + API 403 |

### The platform owner's console — `/admin/*` (NEW, and the answer to the audit)

| Unit | Commit | State |
|---|---|---|
| AUDIT-PORTALS-001 regression audit | `5698891` | **VERIFIED** — `docs/REGRESSION_AUDIT_PORTALS.md` |
| ADMIN-001 `/admin/*` console | `5698891` | **VERIFIED** — overview, tenants, settings, audit |
| ADMIN-002 plans, subscriptions, revenue | `d8de729` | **VERIFIED** — committed value per currency, never cash |
| ADMIN-003 permissions, integrations, status | `c6ee5a1` | **VERIFIED** — three read tabs on `/admin/settings` |
| SEC-ADMIN-001 `is_platform_admin` hardening | `d8de729` | **VERIFIED** — removed from `$fillable`; three routes closed |
| NAV-001 grouped rails | `c182e14`, `0c2204c` | **VERIFIED** — two levels, nothing hidden, portals stay distinct |

**What the audit actually found.** Nothing was lost in the `/app/*` move: 74 routes before, 90 now,
every old path resolving; the advertiser rail has the same fifteen entries; all eight settings
sections still registered. The real problem was a layer that was **never built** — the platform owner
had nowhere to go (`PortalResolver` → `/onboarding`), and could not get there anyway because the
seeded account was unverified. Platform functions had therefore settled inside the ADVERTISER's
workspace settings.

**Decisions to keep (9–12):**

9.  `/admin` is gated on `is_platform_admin`, **never** on a membership or a permission. The owner
    belongs to no tenant; a membership would place them inside a workspace they administer. A role or
    permission would put the console one role edit away from any customer.
10. `Portal::Admin` is a case of the enum but is **not** a membership portal. Iterate
    `Portal::membershipPortals()`, never `cases()`, wherever a membership is being created —
    `cases()` would quietly mint an `admin` membership in whatever tenant was at hand.
11. The console shows **no customer work** — no campaigns, clients, reports or figures. Owning the
    platform is not a reason to read a tenant's data, and a console that put it one click away would
    see it happen without a decision. Tenant detail is a DRAWER, not a route.
12. Suspending a tenant requires a reason (server-enforced) and is audited with an actor. It reports
    when the tenant serves public request intake rather than blocking the action.
13. `is_platform_admin` is NOT in `User::$fillable`. Set it only with `forceFill`. It was mass-assignable,
    which put the whole platform one `update($request->validated())` away from any customer.
14. Platform revenue is COMMITTED subscription value, never cash. `invoices`/`payments` carry a NOT NULL
    `client_workspace_id` — that ledger is an agency invoicing ITS client, and counting it would report
    customers' money as the platform's own result.
15. The permission catalogue is code (`PermissionSeeder`) and has NO write route. A key invented at
    runtime grants nothing, because no `hasPermission()` call checks for it.
16. Operational status reuses `DevStatusController::snapshot()`. Do not write a second status page —
    two of them drift, and the one you are not looking at is the wrong one.
17. Sidebar groups are OPEN by default (`src/layouts/SidebarNav.tsx`). Closing them by default puts
    every section behind a click plus a guess about which label holds it — the same list with the
    labels removed. The user may collapse a group; the one holding the current page opens anyway.
18. `navGrouping.test.ts` pins both rails BY PATH as they were before grouping. If a section is ever
    dropped from a group it fails naming it. Do not relax it — grouping is exactly where a working
    feature becomes unreachable while its route still exists.

**Where platform settings now live:** `/admin/settings`, as ONE tabbed page (public site, portal
notes, taxonomies, services). `/app/settings/public-pages|portals|taxonomies` redirect there.

### The other three portals

| Unit | Commit | State |
|---|---|---|
| PORTAL-CLIENT-001 isolated client spaces | `ef774fe` | **VERIFIED** — two brands, two spaces, unowned slug 404 |
| INFL-001 influencers & UGC portal | `d1317a2` | **VERIFIED** — roster / collaborations / deliverables, cost behind its own permission |
| PORTAL-AUTH-001 | `f38c09d` | **PARTIAL** — URL space unified; auth engines NOT merged. See `docs/PORTAL_AUTH_MIGRATION.md` |
| ROUTE-002 legacy redirect sweep | `98ddc18` | **VERIFIED** — 15 missing root paths added |
| BUG-CLIENTS-001 / BUG-INVITE-001 | `98ddc18` | **VERIFIED** — both found by running the suite, not by reading |

**More decisions to keep:**

5. Client-portal reads narrow at ONE choke point, `ClientPortalController::contactScope()`, and the
   space header is attached by ONE axios interceptor. There are 20+ `/client/*` call sites; the one
   that forgets the filter is the one that shows another brand's data.
6. The influencers portal has TWO different boundaries on purpose: the roster is agency-wide (a
   creator is not owned by a client), collaborations are client-scoped. Do not "fix" the roster to
   match — it would only make an account manager re-add creators the agency already has terms with.
7. Withheld money is ABSENT, never zeroed. A zero or a dash can be read as the real figure, and a
   rounded one can be worked backwards into a margin.
8. Nothing goes in a nav rail before it works — this now holds for all four portals.

**Live-review accounts** (dev seed, password `password`; client portal is OTP, dev auto-fills):

| Account | Shows |
|---|---|
| `owner@demo-agency.local` | agency portal, unrestricted — 5 clients |
| `manager@demo-agency.local` | agency portal, ONE client — the ceiling in action |
| `creators@demo-agency.local` | influencers portal, cost withheld |
| `creators.finance@demo-agency.local` | influencers portal, cost + margin visible |
| `owner@demo-company.local` | advertiser only — `/agency/*` and `/influencers/*` deny honestly |
| `customer@demo-client.local` (OTP) | client portal with TWO isolated spaces |

### NOT done — do not mark these complete

  - **PORTAL-AUTH-001 (the auth half)**: the client portal still runs its own OTP token-cookie
    session. `docs/PORTAL_AUTH_MIGRATION.md` has the reason it was not half-built and the order to
    do it in.
  - **AGENCY-005**: agency white-label per client space. The design is settled, so start from it
    rather than re-deciding: the Branding Center ALREADY supports `scope = 'client'` with a
    `scope_id`, and `BrandingService::resolve()` already falls back client → tenant → platform. What
    is missing is (a) `GET /api/v1/client/branding`, resolving the space from the caller's OWN portal
    session — it must NOT accept a client id, so asking for another agency's branding is not
    expressible — and (b) the portal shell applying the returned colours and mark. Report the stored
    flag as `white_label_requested`, never as a capability: whether an agency MAY hide the platform's
    name is a subscription question decided upstream, and a reader must not mistake a stored
    preference for an entitlement. Do NOT build a second branding engine.
  - **INFL-002**: a creator-facing portal (creators signing in to submit their own content). Blocked
    behind the same auth work — creators have no password either.
  - **Dropping `users.tenant_id`**: no decision reads it, but 46 test files and `UserFactory` still
    pass it at creation. See `docs/TENANT_ID_MIGRATION.md`.
  - **`db:seed` over an existing database fails** in `DemoCreativesSeeder` — an FK violation on
    `creative_daily_metrics` when external creatives are replaced. `migrate:fresh --seed` (the
    documented path) is clean and verified: 9 users, 8 memberships, 0 stranded. Only the re-seed
    path is affected.
  - **Five unlinked placeholder routes** under `/app`: approvals, tracking, optimization,
    notifications, opportunities. Reachable only by typing the URL; linked from nothing.

## Exact next task
**AGENCY-005** — agency white-label per client space. The design is settled and written in the
NOT-done list below: the Branding Center already supports `scope = 'client'`, so this is one endpoint
resolving the space from the caller's OWN portal session plus the portal shell applying the result.

Then: **PORTAL-AUTH-001 (the auth half)**, **INFL-002**, dropping `users.tenant_id`, and a full
cross-browser E2E + `migrate:fresh --seed` + clean-install pass for closure. — follow the five steps in `docs/PORTAL_AUTH_MIGRATION.md` in
order, starting with the contacts → users + ClientPortal membership backfill, and asserting the
resulting scope matches `contactOwnedWorkspaceIds()` for every existing contact BEFORE anything
reads from it. Then AGENCY-005, then INFL-002.

Deferred (still open, not superseded):
**PERF-CAMPAIGNS-001** — Firefox is **61/62**; the failing spec MOVES between runs
(`campaigns-linking:24`, then `campaigns.spec:38`), so it is a load-dependent first-paint flake, not a
broken assertion — each passes when its file runs alone. Next step: profile the campaign DETAIL page,
which now also issues the related-entities query on every tab, and cut its first-paint work.
**Do not loosen assertions or add blanket retries.**

Then: **NORM-001** (surface raw + source + objective-compat in the UI) and **FILE-001** (unified files
library) — the two requirements from the user's list not yet reached this session.

## Files currently involved (what the next task touches)
- `frontend/src/features/marketing/PublicHomePage.tsx` — **reference implementation** of the overlay.
- `frontend/src/features/settings/publicPagesApi.ts` — `getPublishedPage(page)` + `PageContent` types.
- `frontend/src/features/settings/PublicPagesSettingsPage.tsx` — the editor (already complete).
- The portal public surfaces themselves: locate their routes in `frontend/src/app/router.tsx`
  (request intake portal variants + the tracking view under `frontend/src/features/requests/`)
  **before** editing — do not guess file names.
- `backend/app/Domains/Settings/Services/PublicPageDefaults.php` — the portal section keys
  (`hero`, `highlights`, `steps`) the public surfaces must honour.
- `backend/tests/Feature/PublicPageSettingsTest.php` — extend, do not rewrite.

## Agents and worktrees
No background agents running. Registered git worktrees:

| Path | Commit | Branch | Note |
|---|---|---|---|
| `…/Developer/CampaignsHub-UI` | `fc90666` | `feat/taxonomy-ux` | **ACTIVE — work here** |
| `…/Developer/CampaignsHub-C3` | `029ccec` | `feat/metrics-c3` | idle older side branch |
| `…/Developer/CampaignsHub-Preview` | `37aa464` | detached | frozen preview snapshot |
| `…/Desktop/MediaBying System` | `37aa464` | `main` | different project — do not touch |

No agent work is lost: everything produced last session is in `f0c813e`, `320b569`, `fc90666`.

## Database migrations
All applied; schema matches the code at HEAD. Most recent five:
```
2026_07_28_333000_create_request_services.php
2026_07_29_000100_create_saved_dashboard_views.php
2026_07_29_000200_add_tax_treatment_to_billing.php
2026_07_29_000300_normalize_legacy_task_status_priority.php
2026_07_29_000400_create_public_page_settings.php   ← newest (public page CMS)
```
`…000300…` is a **data** migration (legacy task `status='open'` / `priority='medium'` normalised at the
writer AND in the database) — already-normalised data will show no further changes on re-run.

## Running services and ports
| Service | Address | Started by |
|---|---|---|
| Vite dev server (frontend) | `http://localhost:5173` | `npm run dev` in `frontend/` |
| Laravel API | `http://127.0.0.1:8000` | `php artisan serve` in `backend/` |
| PostgreSQL | `127.0.0.1:5432` | local service |
| Redis | `127.0.0.1:6379` | local service (`QUEUE_CONNECTION=redis`) |
| **Queue worker** | — | `php artisan queue:work --queue=reports,default` in `backend/` |

**The queue worker is REQUIRED for the E2E suite.** Report generation is queued, so without a worker
`report-pdf-download.spec.ts` waits 90s for a status that can never arrive and fails. That failure was
mistaken for a product defect for several runs; it is purely a missing worker.

All three were listening at handoff. A new session should re-check and restart rather than assume.
Known trap: the dev API server can serve stale routes after new routes are added — restart it before a
browser verification.

## Test results (at `fc90666`)
| Suite | Result |
|---|---|
| Backend (PHPUnit) | **444 passed, 2612 assertions, 0 failed** (on a `migrate:fresh --seed` database) |
| ↳ `PublicPageSettingsTest` | 6 passed, 33 assertions |
| Frontend unit (vitest) | **235 passed, 49 files, 0 failed** |
| `tsc --noEmit` | clean |
| `npm run build` | clean |
| Pint (backend style) | clean |
| Playwright E2E | **Chromium 70/70 · Firefox 62/62 · WebKit 62/62 — 0 failed** (requires the queue worker) |

Matrix status counts: 35 VERIFIED · 4 IMPLEMENTED_NOT_VERIFIED (awaiting a browser re-run) ·
4 PARTIAL · 6 NOT_STARTED · 6 BLOCKED_EXTERNAL_CREDENTIALS. REPORT-SCHEDULING is no longer BLOCKED_NO_API.

## Known failures
None outstanding — no failing test and no test skipped for being broken at HEAD.
The only open **verification** debt is the browser matrix (`XBROWSER-GATE`).

## Open external dependencies
- **Ad-platform integrations** (Meta, Google Ads, TikTok, Snapchat, X, LinkedIn) —
  `BLOCKED_EXTERNAL_CREDENTIALS`. No API keys / OAuth apps supplied, so no live sync exists. The UI must
  keep showing honest states — never "Connected"/"Synced"/"Live" without a real operation.
- **Report scheduling** — `BLOCKED_NO_API`: the engine exists but has **no HTTP API**, so no UI was built.
  Building those endpoints is real work, not a user blocker.
- `docs/CampaignsHub_Master_Context_and_Instructions.md` is referenced by the user's permanent-instruction
  block but **does not exist in the repository** (checked at root and in `docs/`). The governance actually
  in force is `MASTER_EXECUTION_CONTRACT.md` + `REQUIREMENTS_TRACEABILITY_MATRIX.md` + this file. If the
  user holds that document elsewhere it should be added — do not invent its contents.

## Commands to resume
```bash
cd /Users/mohammedalharbimacbook/Developer/CampaignsHub-UI
git status && git log --oneline -5 && git worktree list

# services
(cd backend  && php artisan serve --host=127.0.0.1 --port=8000)   # terminal 1
(cd frontend && npm run dev)                                       # terminal 2

# green baseline before touching anything
(cd backend  && php artisan test)
(cd frontend && npx tsc --noEmit && npm run test -- --run && npm run build)

# next task's proof loop (SITE-CMS-002)
curl -s http://127.0.0.1:8000/api/v1/public/pages/portal_paid | head -c 400
```

## Acceptance criteria (SITE-CMS-002)
1. Each of the three portal public surfaces renders **published** CMS content: hero texts, buttons
   (label + destination), section enable/disable and order.
2. A saved **draft** changes nothing for a visitor; only **publish** does.
3. With nothing published, each surface renders shipped defaults — never a blank page.
4. Content changes require **no code edit**, and the homepage does not regress.
5. Backend test extended and green; frontend `tsc` + unit + build green; a **live** before/after check
   recorded in `docs/PROGRESS.md` with the actual observed values.
6. Matrix row `SITE-CMS-002` updated with an honest status and the commit SHA.

## Do-not-repeat decisions
- **Never** use native `<input type="date">`: Chromium localises it by *browser* locale and ignores the
  element `lang` (probed and confirmed). Use `frontend/src/components/ui/DateField.tsx` everywhere —
  LTR box, `YYYY-MM-DD`, tabular numerals — including filters and financial forms.
- **VAT** is picked as a *tax treatment* key (basic 15% default, zero-rated, exempt, out-of-scope) and the
  rate is derived server-side. 5% is never offered as a current option, only tagged historical.
- **No blended ROAS/CPA across mixed objectives.** Default dashboard objective is **Awareness**; creative
  performance ranks within per-objective groups using per-group medians.
- **Honest states only** — never render Connected / Synced / Paid / Sent / Live without a real operation.
- **Latin digits everywhere**; keep current fonts and brand identity; no internal jargon on public pages.
- Saved views are **server-persisted**, not localStorage.
- Keep system settings (sidebar) and user settings (account icon) split; never reintroduce personal items
  into the system nav.
- Commit messages containing Arabic go through `git commit -F <file>`, never inline `-m`.
- Stale HMR console errors are not evidence of a defect — confirm against a clean production build.
- Never treat an old report as proof a section is "already developed" — open the page live and check.
- Passing tests are **not** proof of completion; documentation is **never** a substitute for code.

---
---

# ARCHIVE — earlier phase notes (superseded by the sections above; kept for traceability)

## 🚧 ACTIVE PHASE (2026-07-28): CORE CAMPAIGN-MANAGEMENT DEEPENING — delivery packaging HALTED
User halted delivery/clean-install/final-package. Goal: make CampaignsHub a REAL unified center to manage/monitor/review ALL paid campaigns from one place — functionally AND visually — across Homepage/Dashboard/Campaigns/Analytics/Reports/Alerts/Integrations/Campaign-details. Do NOT package delivery until execution items 1–16 are closed. Single orchestrator, sequential, no parallel agents, economical (no long interim messages). Keep preview running. Enrich `/dev/status` to show: Current Task, Last Green Commit, Preview/FE/BE/DB/Redis/Queue/Scheduler status, last BE/FE/E2E results, Exact Next Task. Update RESUME_STATE + docs/PROGRESS.md after each tested commit.
**Execution order:** 1 Campaign-Mgmt Audit → 2 Shared CampaignOverview component (used by BOTH marketing preview & real dashboard) → 3 Marketing homepage alignment → 4 Dashboard command center (unified filters + objective-specific KPIs + freshness) → 5 Campaign classification/listing (taxonomy-fed; overview/table/cards/comparison/needs-attention) → 6 Campaign details → 7 Metric normalization (canonical metrics + provider mapping) → 8 Demo-data quality (math-consistent) → 9 Analytics/Reports alignment (same unified data) → 10 Alerts alignment (real metrics, deep-link) → 11 Integrations readiness audit (`docs/INTEGRATIONS_READINESS_AUDIT.md`) → 12 Integration backend remediation → 13 Integrations UI → 14 E2E data flow → 15 Visual review → 16 Full regression → 17 Clean install → 18 Final package.
**✅ Item 1 DONE — `docs/CAMPAIGN_MANAGEMENT_AUDIT.md` committed `91ac204`.** Verdict: PARTLY. Strong normalization (`app/Domains/Metrics`: NormalizedMetric/DailyMetric/MetricsAggregator); 6 ad connectors are honest awaiting-credentials stubs (`app/Domains/Integrations/Providers/*`) + `SandboxAdvertisingConnector`; **NO live sync jobs** (only Reports jobs queued); Dashboard/Campaigns partial + demo-backed; marketing preview ≠ real dashboard (parity gap).
**✅ Modules audit DONE — `docs/MODULES_AND_CLASSIFICATIONS_AUDIT.md`.** Key gaps: Ad Contents/Creatives = غير منفذ (build it); Tasks/Files no standalone page; Finance = 3 separate lists (needs unified center); Alerts/Messages/Projects-Integrations = يحتاج إعادة تصميم; Integrations must show the 6 REAL platforms (not "Sandbox/Advertising Connector" cards — "Sandbox" only as a dev «وضع تجريبي» tag).
**EXPANDED PHASE = lift EVERY module to command-center quality + rebuild project integrations around the 6 real ad platforms.** Two mandated audit docs: `docs/MODULES_AND_CLASSIFICATIONS_AUDIT.md` (done) + `docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md` (TODO, per-platform OAuth/token/discovery/sync/pagination/rate-limit/retry/idempotency/tests matrix for Meta/Google/TikTok/Snapchat/X/LinkedIn — never claim a platform done if only Adapter/Mock exists).
**MANDATORY EXEC ORDER (this phase):** 1 Modules audit ✅ → 2 Alerts redesign (command center: categories/severity/status-workflow/filters/rich-card/actions; Alerts≠Notifications) → 3 Messages (unified inbox + context linkage) → 4 Finance center (overview KPIs + quotes/invoices/payments + honest statuses) → 5 Ad Contents/Creatives (BUILD: grid/table/performance/comparison/needs-attention + detail; shared "top creatives" component) → 6 Project Integrations redesign (per-project «المنصات المرتبطة» around the 6 platforms + connect journey) → 7 AD_PLATFORM_INTEGRATIONS_AUDIT.md → 8–13 per-platform remediation (real where creds exist, else «جاهز ويحتاج بيانات اعتماد» + sandbox end-to-end) → 14 cross-module relationships (entity→related entities links) → 15 demo-data upgrade (interlinked, math-consistent CPA=Spend/Results etc., labeled «بيانات تجريبية») → 16 visual review → 17 full regression → 18 clean install → 19 handover. NO delivery before 1–17. Every module closes only on the acceptance checklist (interlinked data + filters/search + detail + actions + perms + isolation + states + RTL/LTR + light/dark + mobile + tests + E2E + live review) — NOT on "a page exists".
Also STILL PENDING from the campaign audit (fold in): shared CampaignOverview (marketing↔dashboard parity), dashboard unified filters + objective KPIs, campaign classification/comparison/details, metric-normalization surfacing, sandbox sync pipeline (SyncRun + jobs — none exist yet). Enrich `/dev/status` board.

**(earlier next task, now folded into the ordered plan) item 2:** Build a shared `CampaignOverview`/`UnifiedCampaignOverview` React component (put in e.g. `frontend/src/features/campaigns/overview/` or `src/components/overview/`) that renders the unified campaign command view: KPI row (spend/results/active campaigns/avg cost-per-result/return-when-goal-fits/remaining budget/last-sync/data-status), platform comparison (spend+ROAS), spend distribution, top campaigns, needs-attention, key alerts — platforms Snapchat/TikTok/Meta/Google Ads/X/LinkedIn, SAR in AR. It takes DATA as props (a normalized view-model). Use it in BOTH: (a) marketing homepage preview (`PublicHomePage.tsx`) fed labeled DEMO data («معاينة توضيحية ببيانات تجريبية»), and (b) authenticated `DashboardPage.tsx` fed the real metrics hooks (`usePlatforms/useFreshness/useBudget/useCampaigns`). Same design/metrics/classification both places (parity). Keep RTL/LTR+light/dark+responsive; targeted vitest + browser check at :5173 (home + /dashboard); keep full suites green; commit. THEN item 3 (dashboard command center: unified filter bar + objective-specific KPIs + saved views + freshness). Agents keep STALLING (watchdog) — prefer doing items directly or one short agent with frequent checkpoints.
**(superseded) In flight:** audit agent `ac6f5633359aa1a54` (stalled; audit written by orchestrator instead). Env up at `08c933c` (5173/8000=200, tree CLEAN). Integration states MUST stay honest (جاهز / يحتاج بيانات اعتماد / Sandbox / تطوير جزئي / غير منفذ / غير مدعوم — never Connected/Synced/Paid/Sent/Live unless real). No Mock/Demo adapter shown as a real connection.
Prior phase (below) is DONE & green (homepage v5, login, forms, paid-media, regression 188/0/0 at `08c933c`).

---


> New session: read this file first, then CLAUDE.md, MASTER_REQUIREMENTS, IMPLEMENTATION_MATRIX, OPEN_GAPS.
> Resume from **Exact Next Task**. Do NOT redo completed/committed work. Do NOT ask the user. No interim updates.

## Repo / branch / commit
- Repo: `/Users/mohammedalharbimacbook/Developer/CampaignsHub-UI`.
- **Current branch: `feat/taxonomy-ux`** · **Current HEAD: `d9e230d`** (T7 finalized & agent-verified; tree CLEAN). WIP that was snapshotted at `aaa79da` is now completed by both agents across `aaa79da..d9e230d`.
- Frozen delivery (DO NOT touch tag or packages): tag `v1.1.0-expanded-final` = `e9b99f2`.
  Stable ZIP `~/Desktop/CampaignsHub-Final-Delivery.zip` sha256 `b8329cf4d6ba63ab77a571e75c2305f1e1e764921be5667f3149dbb42bdd708b`;
  Expanded ZIP `~/Desktop/CampaignsHub-Expanded-Delivery.zip` sha256 `e9db7fcd2ab9708118c6c0a9448c31416f4751c0ab295cfe9608c60bd322c259`.
  Baseline tag `v1.0.0-baseline` = `47ce364`.
- Other worktrees (unrelated, leave alone): `CampaignsHub-C3` (feat/metrics-c3), `CampaignsHub-Preview` (detached), `MediaBying System` (main).

## Working tree status
**Tree CLEAN at `d9e230d`.** Both background agents that were mid-flight during the handoff have finished and committed. Their combined WIP (once "unverified snapshot" at `aaa79da`) is now completed and agent-verified:
- **Homepage journey-decision section + register/request handoff** (`a5fde925…`, in `aaa79da`+`a5d24e2`) — self-verified: `npm run build` clean, `npx vitest run` **171/171 (39 files)**, +11 new tests (journey routes incl. query params, agency/self-managed register preset, `?service` request preselect). Browser-verified: section placement + all 5 journey routes.
- **Reports/Alerts/file-category option adoption — Track 7** (`ad6b006f…`, finalized in `d9e230d`) — self-verified: `pint` clean, `phpstan app/Domains/Taxonomy` no errors, **`php artisan test` → 398 passed** (+1 alignment test asserting engine keys == live `ReportController`/`AlertController` values), `npm run build` clean, `npx vitest run` **171 passed**. Live (owner@demo-agency.local): report builder type/audience + `/app/alerts` rule type/severity/channels all load from the engine; report POST 201 + alert-rule POST 201 (no 422). Added defs: `report.type`, `report.audience`, `alert.type`, `alert.severity`, `alert.channel` (all is_system, keys==live enums) + `file.category` (tenant-manageable, allows_custom). The nested-`<button>` hydration bug the journey agent flagged was **fixed** here (`forms/internals.tsx` chip-remove/clear are now `role="button"` spans; live DOM `hasNestedButtonInButton:false`).

✅ **Certified at `f961024`** by the orchestrator's own runs: backend **398 passed** (2182 assertions), frontend build clean + **171 vitest passed**. T7 + journey are CLOSED. (`php artisan test --parallel` needs paratest which isn't installed — use plain `php artisan test`.)

## ✅ DONE — T15 paid-media vertical (committed `bc61402`, tests+browser verified)
Backend 411 tests, frontend 183 vitest, build+tsc clean, fresh reseed clean. Public endpoint `GET /api/v1/public/catalog/paid-media-services` live (200, version/ETag/cache, 10 cats/94 services, zero forbidden fields leaked). Browser-verified on live API: homepage side-card → «أحتاج خدمات إعلانية» reveals engine-fed category tabs + 8 featured cards → select 2 → exact CTA `/requests/new?module=paid-media&services=new_campaign,ad_account_audit` → intake preselects «الخدمات المختارة: 2» → step 3 renders MERGED dynamic fields (objective/platforms(once, dedup)/budget/period/regions/kpis). POST persistence + portal/dashboard/quote/invoice surfacing + validation(422 forged)+isolation covered by PaidMediaServicesTest (12). request_services canonical table; is_public fail-closed column.

## ✅ DONE — v4 homepage-audience + auth-nav correction (committed `e7dc807`, 47 vitest + tsc + browser verified)
Public homepage = external users only. Header: ابدأ الآن/تسجيل الدخول/اطلب خدمة/متابعة طلباتي (+auth→لوحة التحكم); NO «تسجيل دخول النظام»/admin wording on any public surface. Journey routes: self-managed/agency `+&module=paid-media`, #3 reveals inline services (CTA `/requests/new?module=paid-media&services=<keys>`), #4 `/requests/new?module=influencer-marketing`. Below-card «لديك حساب بالفعل؟»: دخول مساحة العمل /login + متابعة طلباتي /client/login. Business-model copy + two separated commercial tracks. Intake accepts `?module=influencer-marketing`. **No `/operations/login`, no parallel admin auth** (reuse role-routed `/login` — per user's final correction). Browser-confirmed at localhost:5173.

## ⛔ PAUSED by account session/usage limit (reset ~19:20 Asia/Riyadh) — AUTO-RESUME (do not wait for the user)
All 4 parallel agents (forms overhaul ×3 + v5 homepage ×1) were KILLED mid-edit by the session limit. Their partial, UNVERIFIED edits are saved to `git stash@{0}`; tree returned to green.

### EXACT git state (verified `git branch/rev-parse/status/stash`, 2026-07-28 ~19:25 Riyadh)
- branch = **`feat/taxonomy-ux`**
- HEAD   = **`e1003eaf3635f7792098723cb307fb6dbd3b4ec6`** (`e1003ea`)
- `git status --short` = **empty (tree CLEAN)**
- stash  = **`stash@{0}`** "partial forms-overhaul + v5-homepage agent WIP (UNVERIFIED/likely-broken)"
- **No contradiction:** `e1003ea` is a DOCS-ONLY commit (RESUME_STATE.md, 1 file) on top of the green code state `91901c1`. Code is green at HEAD; only documentation changed after `91901c1`. Green committed work: paid-media `bc61402` (411 backend / 183 vitest, browser-verified) · v4 homepage `e7dc807` (47 vitest) · form-UX primitives `1fa99d4` (8 vitest).

### AUTO-RESUME RULES (when capability is restored — do NOT ask the user to type "resume")
Read this file + spec §v5, then continue straight through to phase completion. Do NOT redo committed/green work.
**Stash recovery protocol (do NOT `git stash pop`, do NOT `git stash drop` directly):**
1. `git branch recovery/taxonomy-ux-partial-wip` (safety branch for the WIP) and `git stash show -p stash@{0} > /tmp/taxonomy-ux-wip.patch`.
2. Inspect the stash file-by-file (`git stash show --stat stash@{0}`).
3. EXCLUDE the unauthorized `frontend/src/lib/i18n.ts` change if invalid/out-of-scope.
4. Restore ONLY parts that are complete AND spec-matching.
5. Rebuild partial parts fresh from the clean green state.
6. Drop `stash@{0}` ONLY after all tests pass and the replacement is committed.
**Execution model:** finish everything **SEQUENTIALLY via a single orchestrator — NO parallel agents** (parallelism caused the cross-file conflicts, the stray i18n edit, and fast limit/context burn). One task at a time; commit each green increment.

### PROGRESS since resume
- Preview environment FIXED (dev-up: FE :5173, BE :8000, queue+scheduler+redis, /dev/status all 200). Leave running.
- ✅ **v5 homepage DONE + committed `510918a`** (15 vitest, tsc clean, forbidden-terms grep empty, browser-verified 1440×900 + mobile + console/network clean). HEAD is now `510918a`.
- ✅ Forms-UX adoption DONE + committed `b2cb214` (ErrorSummary/ReviewList/FormStepper/useFormDraft across Register/Onboarding/Campaigns/Clients/Projects/Reports/Alerts/Settings; Integrations/Subscriptions honestly skipped — no validated form). Full FE **209 vitest / 45 files**, tsc clean, build ok. `stash@{0}` DROPPED (superseded; archive kept: branch `recovery/taxonomy-ux-partial-wip` + `/tmp/taxonomy-ux-wip.patch`).
- ✅ Safe-migration re-confirm: `migrate:fresh --seed` clean + backend **411 tests** green.
- ✅ Three-app E2E regression GREEN + committed `b2d7278`: **188 passed / 0 failed / 0 flaky** (chromium+firefox+webkit). Stale specs updated to v5; client-command-center drives taxonomy comboboxes; report-pdf dead audience-step removed; homepage chromium baselines refreshed (manual-reviewed). No masking.
- ✅ Login `/login` customer-language redesign DONE + committed `2382177` (two-pane, coherent green, responsive, no internal wording; browser-verified desktop+mobile; auth vitest 10, auth e2e 51/0; login baselines refreshed).
- ✅ Integrations naming unified «التكاملات»/Integrations (was «مركز الاتصالات») committed (copy-only). Taxonomy = 30 unique defs, no dups; integrations already one canonical `/app/integrations` (+`/app/connections` redirect). No structural duplication found.
- ✅ Final regression COMPLETE at `3adfd65` (tree CLEAN, preview UP): FE tsc clean / vitest **209/45** / build ok; BE **411**; full E2E **188 passed / 0 failed / 0 flaky** (chromium+firefox+webkit). Phase (v5 homepage + login redesign + integrations naming + forms-UX + regression) DONE.
- Remaining roadmap (NOT this phase's gate): clean-install rehearsal + final delivery package; external-credential providers stay Awaiting Credentials (honest adapters).
- Reply format the user wants: DONE/COMMIT/PREVIEW/NEXT or BLOCKED/REASON/…; final message only after homepage+login+integrations review all green with preview up.

### BINDING remaining (in order)
1. ✅ homepage **v5** DONE (`510918a`).
2. Keep the usage journeys + dynamic paid-media services working.
3. Remove ALL internal jargon from the public page (SaaS/Tenant/Workspace/مساحة العمل/للمشتركين/للوكالات/…).
4. APPLY the Form-UX primitives to ALL target forms (not just create the components): Register/Onboarding/Clients/Projects/Campaigns/Reports/Alerts/Integrations/Subscriptions/Settings — additive, keep suites green.
5. Re-confirm safe legacy-value migration (deactivate-not-delete; no data loss).
6. Test Operations Console + SaaS Workspace + Client Portal (the three apps).
7. Run Backend + Frontend + E2E on Chromium + Firefox + WebKit.
8. Fix ALL failures + flaky.
9. Leave the preview running.
10. Document the result + one clean final commit.
**v5 homepage must satisfy:** customer-direct language; no SaaS/Tenant/Workspace in public copy; تسجيل الدخول / إنشاء حساب / اطلب خدمة / متابعة طلباتي; clear usage-journey choice; paid services visible from the start; service catalog from the Taxonomy public API only (no hardcoded lists); balanced 65/35 hero; preview platforms Snapchat/TikTok/Meta/Google Ads/X/LinkedIn; shorter page, balanced equal-height cards.
**Phase closes (send the SINGLE final report) only when ALL true:** Backend Failed=0 · Frontend Failed=0 · E2E Failed=0 · Flaky=0 · Chromium/Firefox/WebKit passed · no data loss · no unmanaged options · homepage v5 done · forms adoption done · working tree clean · preview running. **No interim updates in between.**
- Do NOT re-spawn the 4 dead agents.

## (historical) ⏳ IN FLIGHT — Forms overhaul (T10) + v5 homepage rebuild — 4 agents off `05726a0`
- Shared form-UX primitives DONE+committed `1fa99d4`: `src/components/forms/{formFlow.tsx(FormStepper,ErrorSummary,ReviewList),useFormDraft.ts}` (+tests, 8 green), exported from forms/index.ts.
- Agent `ab5988d86738c38c6` — forms overhaul: auth/RegisterPage + onboarding (stepper/error-summary/draft/review; journey as review-only).
- Agent `a4e5aff86ddc14a27` — forms overhaul: clients + projects + campaigns (error-summary/draft; keep engine selects + objective→KPI).
- Agent `a1d2e3d175fd85f21` — forms overhaul: reports + alerts + integrations + subscriptions + settings (error-summary; keep engine selects + honest integration states).
- Agent `a323a5fc851e68cb7` — v5 homepage rebuild (spec §v5): customer language, ZERO internal jargon (SaaS/مساحة العمل/للمشتركين/للوكالات/دخول مساحة العمل/تسجيل دخول النظام all banned), 65/35 hero fits 1440×900, options card «كيف تريد البدء؟» (4 journeys), login «تسجيل الدخول»+«متابعة طلباتي» only, realistic dark preview (Snapchat/TikTok/Meta/Google Ads/X/LinkedIn, SAR), shortened page, mobile order, console/network clean.
- All 4 = no git; disjoint dirs; orchestrator commits each verified slice.

## ⏳ REMAINING after these land (finish the phase — no interim reports)
Reconcile → full frontend build+vitest + backend `php artisan test` → browser-verify v5 homepage (1440×900 fit, forbidden-terms grep empty, console/network clean, RTL/dark/mobile) + spot-check forms error-summaries → commit. Then re-confirm safe legacy migration (deactivate-not-delete; no loss — backend suite covers). Then T13/T14 full E2E regression (Chromium/Firefox/WebKit + RTL/LTR + light/dark + mobile; three apps; 0 failed/0 flaky/0 skipped-non-external). Only THEN the final report.

## (historical) ⏳ IN FLIGHT (T15 paid-media vertical, v2 homepage-inline directive) — 3 agents running off `f961024`
User directive (2026-07-28): services must be visible in the homepage FIRST viewport (not behind a "browse" click). Spec authority = `docs/PAID_MEDIA_SERVICES_SPEC.md` §"⚑ v2". Orchestration rule this round: **agents do NO git; orchestrator commits each verified slice sequentially** (prevents the prior concurrent-commit reversions). Contract shared by all three = the canonical keys in the spec.
- **Shared hook (already written, committed-pending):** `frontend/src/features/paid-media/publicCatalog.ts` — `usePublicPaidServices()` consuming `GET /api/v1/public/paid-services`; helpers `popularServices`/`servicesForKeys`/`mergedNeeds`. Read-only import for both FE agents.
- **Agent BACKEND** (`a5debbf120fc231aa`): seed `request.paid_service` (10 categories × ~90 services, is_system=false, allows_custom, each option `metadata.needs` + `popular` on 8); migration `services` jsonb (+optional `service_details`) on `external_requests`; persist + surface in request resource/portal/dashboard; carry into quote+invoice line items; **public endpoint `GET /api/v1/public/paid-services`** (platform-scope only, ApiEnvelope, no tenant leak); tests. Keep 398+ green.
- **Agent HOMEPAGE** (`ad99ca3c3e44f9bd3`): two-column hero; side card usage-selection; «أحتاج خدمات إعلانية» reveals inline category tabs + 6–8 popular service cards + multi-select + «الخدمات المختارة: N» + CTA → `/requests/new?module=paid-media&services=<keys>`; «عرض جميع الخدمات» in-page drawer; engine-fed; strings in `homeCopy.ts` only. Owns `frontend/src/features/marketing/**`.
- **Agent INTAKE** (`a9ed0cbbd2fed5b40`): `?module=paid-media&services=` preselect → editable chips; dynamic fields via `mergedNeeds` (each token once, no loss across steps); review+submit posts `services[]`+`service_details`; default intake unchanged. Owns `frontend/src/features/requests/**`.
- **Orchestrator to do after agents land:** reconcile working tree → `migrate:fresh --seed --force` → commit slices → browser-verify full flow (homepage first-viewport services → select → CTA → intake dynamic fields → submit → request/portal/dashboard → quote → invoice; RTL/LTR, light/dark, mobile) → then T10 forms overhaul → T13/T14 E2E regression. If context rolls over mid-flight: read each agent's final report via its task output, reconcile the working tree, do NOT re-spawn duplicates.

## PHASE = Taxonomy & UX (feat/taxonomy-ux, off v1.1.0-expanded-final). Close only when ALL tracks Implemented & Tested.
### Completed & committed (verified before the WIP snapshot)
- T1 engine backend `4f4a42e`+wired `56a1a9d`; re-aligned to LIVE enums `5181773` (23 defs / 215 opts).
- T2 Settings→Taxonomies manager `16a9ba2` (`/settings/taxonomies`).
- T3 unified form controls + taxonomy API `cebbbb8` (`src/components/forms/**`, `src/features/taxonomy/taxonomyApi.ts`).
- T4/5/6 adopt engine in Requests filters / Clients / Campaigns (objective→KPIs, multi-selects→jsonb) `96d65b2` + resource round-trip `3836d88`.
- T8 Integrations redesign `2be3a2c` (browser-verified). T9 homepage redesign `db64503` (browser-verified).
- T11 safe migration via `5181773` (drifted keys DEACTIVATED not deleted; tenant options untouched; no data loss).
- T12 permissions/audit/tenant isolation (taxonomies.*/options.*) backend done; owner granted.
- T7 reports/alerts/file-category adoption `d9e230d` (agent-verified — see Working tree status). Journey-decision homepage section `aaa79da`+`a5d24e2` (agent-verified).
Backend now **398 passing**, frontend **171 vitest**, build clean, live POST 201s (per agent runs at `d9e230d`).

## Exact Next Task (in order)
1. **Certify `d9e230d` (one fast independent pass), then close T7 + journey.** Run `cd frontend && npm run build && npx vitest run` and `cd ../backend && php artisan test` yourself. Expected all green (agents reported 398 backend / 171 vitest). If any fail, fix + commit. No re-implementation needed — both slices are DONE and committed. Then proceed directly to T15.
2. **T15 Paid-media service vertical** (spec `docs/PAID_MEDIA_SERVICES_SPEC.md`, task #43) — implement FULLY, not spec-only:
   - Backend: seed `request.paid_service` hierarchical taxonomy (10 categories × ~90 services with `metadata.needs`) in `TaxonomyEngineSeeder`; add `services` jsonb to `external_requests`; carry selected services into quote + invoice line items; surface in client-portal request detail + internal requests dashboard + client record + project + quote + invoice + activity log; accept multiple services; do NOT change existing enums; tests (isolation, legacy preservation, no loss).
   - Frontend: homepage journey #3 → «أحتاج خدمات إعلانية»/«استعرض الخدمات» → `/requests/new?module=paid-media`; selector «ما الخدمة التي تحتاجها؟» (category tabs + search + multi-select + chips + clear-all + custom + create-when-permitted; engine-fed; not 90 in a list); dynamic form whose steps adapt to selected services via `metadata.needs` (merge shared questions once, per-service fields, no lost answers); review & submit. Managed under Settings→Taxonomies (existing manager covers request.paid_service). Keep service ≠ objective ≠ platform ≠ status.
3. **T10 Forms UX overhaul** — steppers/draft/validation/error-summary/searchable/create/dependent/review across Register/Onboarding/Requests/Clients/Projects/Campaigns/Reports/Integrations/Alerts/Billing/Settings.
4. **T13/T14 Regression** — full E2E Chromium/Firefox/WebKit + RTL/LTR + light/dark + mobile; three apps on one engine scoped by permission/plan; **0 failed/0 flaky/0 skipped**, retries=0; matrix updated; tree clean.

## Database / migrations
Postgres `mediabuying` (dev), all migrations applied, reseeded aligned via `migrate:fresh --seed --force`. Reset: `cd backend && php artisan migrate:fresh --seed --force`.

## Running services / ports / preview
`bash scripts/dev-up.sh` (backend :8000 workers=4, queue:work --queue=reports,default, scheduler, Vite :5173, Postgres/Redis). `dev-status.sh` / `dev-down.sh`. Preview: http://localhost:5173 · /dev/status · http://127.0.0.1:8000. `.env` local `E2E_RELAX_RATE_LIMITS=true` (local only). E2E: `cd frontend && CI=1 npx playwright test`.

## Test results (last verified, pre-WIP)
Backend 397; frontend 156 vitest; build clean; login 200. Expanded E2E was 188/0/0/0 before this phase (re-run after WIP verify + T15). WIP `aaa79da` UNVERIFIED.

## Known failures / caveats (not defects)
- Agent curl `/auth/login` 500 "Session store not set" = Sanctum needs `Origin: http://localhost:5173`; browser login works.
- Public request intake stays on request-types enum for anonymous submit (taxonomy endpoint is auth-gated) — correct.
- G-020 resolved (registries renamed AdvertisingConnectorRegistry / ConnectorCapabilityRegistry).

## Open external dependencies (Awaiting Credentials — never claim connected)
Email · WhatsApp · SMS · Google OAuth · Payment gateway · Ad-platform/GA4/store/CRM/Google-Drive live sync. Null/Sandbox adapters deliver flows; nothing logged sent/connected/paid without a verified provider.

## Commands to resume
```
cd /Users/mohammedalharbimacbook/Developer/CampaignsHub-UI
git status && git log --oneline -8
bash scripts/dev-up.sh
cd frontend && npm run build && npx vitest run
cd ../backend && php artisan test && php artisan db:seed --class=Database\\Seeders\\TaxonomyEngineSeeder
```

## Acceptance criteria (phase closes only when)
No hardcoded unmanaged options · no multi-select without option management · no duplicated classifications · no data loss · no placeholders/dead buttons · permissions enforced · tenant isolation passed · Operations/SaaS/Client passed · Chromium/Firefox/WebKit + mobile + RTL/LTR + light/dark passed · backend/frontend/E2E failed=0, flaky=0, skipped=external-only · tree clean. Paid-media vertical proven end-to-end: category→multi-service→search→custom→dynamic fields→submit→request+portal+dashboard→quote→invoice→no loss after refresh.

## Do-not-repeat decisions
- Keep `v1.1.0-expanded-final` tag + both ZIPs UNTOUCHED; new work only on `feat/taxonomy-ux`.
- Engine option keys for enum-backed fields MUST equal LIVE enum values (source of truth) — do NOT reintroduce aspirational keys (that was the blocker; fixed `5181773`).
- Integrations canonical `/app/integrations` (absorbs Connection Center + Drive-under-Files); Branding under Settings; Finance one backend surfaced as المالية/الاشتراك/الفواتير. Do NOT re-split.
- Migration policy: DEACTIVATE/merge, never delete used options.
