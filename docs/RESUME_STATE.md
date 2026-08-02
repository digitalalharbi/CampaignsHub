# RESUME STATE — CampaignsHub

> **AUTHORITATIVE HANDOFF — written 2026-07-29 at a context-window emergency close.**
> A new session reads this file FIRST, then `docs/MASTER_EXECUTION_CONTRACT.md`,
> `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, `docs/OPEN_GAPS.md`, then resumes at
> **Exact next task** below — without redoing completed work and without asking the user.

---

## Current branch
`feat/taxonomy-ux` — repo `/Users/mohammedalharbimacbook/Developer/CampaignsHub-UI`

## Current commit
`LOGIN-FINAL` — the network error root-caused, and five final sign-in doors on one engine.
Preceded by `f8a1779` (INFL-003), `f00e7af` (PAY-002b), `883c40c` (I18N-001 + UI-MODAL-001).

**894 backend · 476 vitest · E2E on chromium + firefox + webkit, `retries: 0`.**

Session order: `f71319d` (SIGNUP-002d + review queue) → `d4f57ff` (3 E2E root causes) → `c5cc4e7`
(PLAN-001) → `d4872d1` → `c143817` (PLAN-001e, sign-up in two steps) → `34f2831` (PAY-001…005) →
`c909a33` (SIGNUP-006) → this one.

**802 backend · 433 vitest · 339 E2E on chromium + firefox + webkit, 0 failed, retries: 0.**

Preceded by `d4f57ff` (three cross-browser E2E failures root-caused) and `f71319d` (SIGNUP-002d +
SIGNUP-003/004 — registration becomes an application).

Preceded this session by `e820720` (SIGNUP-002 policy) → `e076858` (SIGNUP-002 backbone) →
`641cfef` (SIGNUP-001 state machine) → `d293707` (LOGIN + AGENCY-006).

Preceded by `a382e04` (INFL-002, the creator's side) → `2f88246` (users.tenant_id dropped) →
`f1c2f49` (the paid-SaaS contract ratified) → `7722821` (the REG-001 regression closed).

Last work unit: `f0c813e` (CMS backend) → `320b569` (CMS editor + homepage rendering) → `fc90666` (docs).
Frozen delivery tags, **never move or rewrite**: `v1.0.0-baseline`, `v1.1.0-expanded-final`.

## Working tree status
**CLEAN** — `git status --porcelain` is empty at `7722821`.
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
| AGENCY-005 white-label per client space | `3982fff` | **VERIFIED** — plus SEC-BRAND-001, the ownership check on branding writes |
| PORTAL-AUTH-001a backfill | `40fb5a5` | **VERIFIED** — identities exist |
| PORTAL-AUTH-001b steps 2–4 | `fd77ca7` | **VERIFIED** — both engines live, membership preferred, parity gate green |
| PORTAL-AUTH-001c step 5 | — | **BLOCKED_OPERATIONAL_EVIDENCE** — measured by `/admin/cutover`; dev shows 14 live legacy sessions |
| ADMIN-004 cutover-readiness board | `6c0c880` | **VERIFIED** — evidence only; there is no endpoint that performs the cutover |

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
18. Brand colour overrides must set BOTH `--brand-*` and `--color-brand-*`. Tailwind v4 utilities read
    the second; the hand-written CSS reads the first. Setting one looks applied in devtools and
    changes nothing on screen. Validate the value as hex before it reaches a style attribute.
19. There is NO endpoint and NO button that performs the portal cutover, and there must not be.
    Retiring the legacy engine is a reviewed code change. `/admin/cutover` reports evidence only; a
    test asserts the POST is 405.
20. `navGrouping.test.ts` pins both rails BY PATH as they were before grouping. If a section is ever
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

## Latest work unit — I18N-001 (the API answers in the customer's language)

The backend had **no `lang/` directory at all**. An Arabic sign-in form labelled «البريد الإلكتروني»
refused with "These credentials do not match our records."; every validation error, every expired
session, every 404 and every rate limit came back in Laravel's English. The interface was translated
and the answers were not — worst at exactly the moment something has gone wrong.

- `app/Http/Middleware/SetLocale.php` reads `Accept-Language` as the **ranked list it actually is**
  (`en;q=0.3,ar;q=0.9` is an Arabic preference, and taking the first tag got it backwards). Region
  subtags are dropped; an unsupported language falls through to the next supported one.
- `config/app.php` **and `.env`/`.env.example`** default to `ar`, with English as the FALLBACK so a
  key translated on one side only renders in English rather than as the raw key.
- `lang/ar/validation.php` is the highest-leverage file in the unit: every validated field in the
  application draws its message from it, including `attributes` so a message reads «حقل كلمة المرور
  مطلوب» rather than «حقل password مطلوب».
- The exception renderer in `bootstrap/app.php` resolves the locale a **second** time, because a 404
  on a path no middleware group owns never reaches `SetLocale`.
- `ApiResponse::success/error` take a nullable message and resolve the default at call time — a
  literal in the signature is fixed at parse time and can only ever be one language.
- The SPA sends `Accept-Language` **from the store at request time**, not once at module load: a
  header fixed when the client was constructed keeps answering in whichever language the tab opened
  in, so switching to English and mistyping a password would still produce an Arabic refusal.

**The whole PHPUnit suite had been exercising English only.** Symfony's `Request::create()` — which
every `getJson`/`postJson` goes through — supplies `Accept-Language: en-us,en;q=0.5` whether or not
the test asked for it, so 841 green tests said nothing about the product's own default. `Tests\TestCase`
now clears it; an unstated language means the product default, which is also what a webhook or a curl
actually sends. This was caught only because a message that should have changed did not.

### UI-MODAL-001 — a real defect found by root-causing the E2E failure

`campaigns-linking.spec.ts` failed on WebKit at ~25%: the campaign name field was empty immediately
after `fill()`. Traced with an in-page probe on the value setter and a MutationObserver over eight
then twenty-five repeats. The value setter was never called with the typed text at all, and the RHF
stack (`updateValidAndValue` ← `ref`) showed the field being re-initialised to its default.

**`Modal`'s focus effect listed `onClose` in its dependencies.** Every call site passes an inline
arrow (`onClose={() => setOpen(false)}`), which is a new function on every render of the parent — so
the effect re-ran on EVERY re-render and re-focused the panel. Any query settling behind an open
modal (the taxonomy options, the user list) pulled the caret out of the field mid-typing, and the
keystrokes landing in that instant went nowhere. Its selector,
`'[data-autofocus],button,input,textarea,select'`, also returns the first match in **document** order
— always the close button in the title row — so `data-autofocus` had never done anything anywhere in
the product.

`onClose` now lives in a ref, the effect depends on `[open]` alone, and `[data-autofocus]` is queried
before the fallback. `Modal.test.tsx` fails without the fix and passes with it; the previously flaky
spec then ran 12/12 on WebKit.

### E2E hygiene — a helper that accumulated state on every run

`seedExternals` called `POST /integrations/connect` on every test, and `EstablishSandboxConnection`
correctly mints a fresh credential, connection and account set each time — a person connecting twice
means it. So each run left one more Sandbox account bound to the same project. After ~100 runs the
project held ~100 externals all named «Sandbox Awareness», and `campaigns-linking`, which finds its
target by that name, began linking two DIFFERENT externals to campaigns A and B: no conflict, no 409,
and the move-confirmation it asserts never appeared.

The helper now reuses the project's existing advertising binding, so the project keeps exactly one
Sandbox binding however often the suite runs. The spec's real precondition — the target external
starts UNLINKED, which used to hold only because every run got a brand-new row — is now stated and
asserted rather than inherited. The ~97 surplus Sandbox connections this created on the local dev
database were deleted (cascade removes their accounts and externals); the seeded demo connection was
kept. Nothing outside those test-created connections was touched.

## Latest work unit — LOGIN-FINAL

### «A network error occurred» — diagnosed, not reworded

The client had exactly two answers: the server's envelope message, or the network one. Everything
that was not a well-formed envelope fell into the second, so a customer whose request reached a
server and came back with a status was told their internet was down.

**Reproduced against the running stack.** With the API unreachable the Vite dev proxy answers
**502 with an empty `text/plain` body** — so axios HAS a `response`, `response.data` is `''`, the
envelope lookup yields `undefined`, and the fallback fires. The same hole swallowed HTML error
pages, gateway timeouts, and `TypeError`s thrown in our own code.

`lib/api/errors.ts` now describes a failure from what is actually known: a status is a fact about
the server and is described as one (401/403/404/419/422/429/408/502/503/504/5xx, AR + EN), and only
a request that was **sent and got no answer** is called a network problem. `ApiError.kind` names
which it was, so a caller can offer a retry where retrying makes sense. Live proof: with the API
stopped, `/app/login` now says «الخدمة غير متاحة مؤقتًا» instead of blaming the connection.

### Two more live defects the same investigation turned up

- **The 419 branch was dead code.** Laravel's own `Handler::prepareException()` converts
  `TokenMismatchException` into a plain `HttpException` BEFORE any render callback runs, so
  `$e instanceof TokenMismatchException` never matched and the framework's English «CSRF token
  mismatch.» reached Arabic customers. Matched on the status now.
- **`ClientTaxonomyController` 500'd on every call.** It queried `users.tenant_id`, dropped in
  `2f88246`, so the client classification / settings / team dropdowns were empty. The guard test for
  that column only looked for a property read, never for a QUERY, so a whole endpoint sat broken
  behind a green suite. The guard now scans for both — and it must distinguish a `tenant_id` clause
  belonging to the user query from one belonging to a role, a membership subquery or a `whereHas`
  closure, all of which are correct.

### The five doors

`/admin/login`, `/app/login`, `/agency/login`, `/influencers/login` render ONE component from one
`PORTAL_DOORS` table: one `login()`, one destination resolver, one refusal path. The portal in the
URL travels as a preference the server checks; it cannot open a portal, only get the sign-in refused
before a session exists. `/portal/login` stays OTP — linked from every door with the method stated
in words, never given a password field it does not have.

Live after `migrate:fresh --seed`: all four password accounts land in their own portal
(`/admin`, `/app/dashboard`, `/agency`, `/influencers`) and every foreign door answers 403 naming
where the account belongs; the recovery button completes the sign-in and lands correctly.

## Latest work unit — INFL-003 (the second half of the influencers contract)

Three things the roster and the collaboration could not express.

**Nominations.** A collaboration records what was AGREED; nothing recorded what was asked and what
came back, so a creator who was turned down left no trace and got proposed again next quarter by
somebody who had not been in the room. A rejection now REQUIRES a reason, and deciding is a separate
permission — `influencers.approve`, new — from proposing: anyone who may add a creator to the roster
may suggest one, but committing the agency to them is somebody else's call, and collapsing the two
makes the shortlist a rubber stamp its own author holds. An approved nomination converts to a
collaboration once, idempotently, with the link kept so the trail runs idea → decision → contract.

**Attribution, and the line that matters: a click is MEASURED, a redemption is REPORTED.** The
platform serves `/t/{code}` itself — a web route, no session, no tenant, resolved by a globally
unique code and counted with an atomic increment — so a link's clicks are as real as anything in the
product. A discount code is redeemed in the brand's own store, which this platform has never seen; it
carries `redemptions_source`, which reads `awaiting_credentials` until a person or a store supplies a
figure. `count_is_measured` is on every row so the interface can tell two zeroes apart instead of
showing them as the same fact. `source` on a result is set by the SERVER, never read from the
request, or a hand-typed number could label itself as platform-measured.

**Results per deliverable**, because «which post worked» is the only question that changes what you
commission next. Keyed on (deliverable, source): a correction replaces rather than stacks, and a
future platform sync sits beside the manual figure instead of overwriting somebody's work. An
unknown reach yields a NULL engagement rate — never a 0% that would read as «nobody engaged».

### Known gap this exposed, not closed here

The influencers portal's only demo account, `layla@creators.demo`, is a **creator**. The agency side
of that portal — roster, collaborations, and now nominations and attribution — has no demo login, so
none of it can be demonstrated by signing in. The permission gate and the public redirect were
verified live (403 for the creator; a stranger's unknown code redirected to the site); the manager
path is proven by 17 backend tests through the real HTTP stack and 6 UI tests. Adding a sixth demo
account belongs to SIGNUP-006, not here.

## Latest work unit — PAY-002b (changing plan part-way through a paid period)

`SubscriptionProration` is the whole decision, kept apart from the lifecycle because it is the only
part that is pure arithmetic on money and therefore the part that must be provable without a
database, a gateway or a webhook. The rule in one sentence: **the customer pays the difference for
the time they have not used, and never pays twice for time they have already bought.**

- **Upgrade** — the unused fraction of the paid period is credited against the new plan's prorated
  price and only the difference is charged. The plan does NOT move when it is requested: a charge is
  opened, and `planChangePaid()` applies it from `ApplySubscriptionPaymentEvent`, which is reached
  only from a verified webhook.
- **Downgrade** — booked for the period end. Nothing charged, nothing refunded, capability kept
  until the period the customer paid for runs out. Applying it at once would take away what has been
  bought and quietly keep the money, and no refund path exists in any case while both gateways hold
  no credentials.
- `plan_change` is routed APART from `renewalPaid` at the webhook, because `renewalPaid` pushes the
  period end forward a whole month — a part-period upgrade going through it would hand out a free
  month on top of the plan.
- Direction comes from the PERIOD prices, not from the prorated difference: on the last day of a
  period the difference rounds to nothing, and treating that as a downgrade would apply a more
  expensive plan immediately for free.
- Two new columns needed saying out loud: `current_period_start` (proration is a fraction of a
  period, and a period with only an end has an assumed length that is wrong the moment one was ever
  extended), and the `scheduled_*` set, which grants nothing — `plan_id` still names what the
  customer is entitled to while a change waits.

### A real hole, found by wiring it

`POST /subscriptions/change` — the ops assignment that moves a tenant onto a plan with **no money** —
was gated on `subscriptions.manage`, **which every workspace owner holds**. One POST granted the
largest plan for nothing, straight past the checkout, the webhook and the entire activation
contract. It is now `is_platform_admin` only, and `SubscriptionTest` asserts an owner is refused.

Live-reviewed at `/app/subscriptions` on the demo advertiser: the review panel showed 1499.00 SAR
new price, −499.00 SAR credit, 1000.00 SAR due; confirming left `plan` at `growth` with
`scheduled_change.awaiting_payment = true` and the banner reading «باقتك الحالية لم تتغيّر»; the
withdrawal cleared it.

## STANDING DIRECTIVE (owner, 2026-08-02) — read before choosing the next unit

The next phase is **visible, per-portal product work**, not backend or tests alone. Every portal is
to be reviewed LIVE, page by page, and developed against its own purpose:

| Portal | Its job |
|---|---|
| `/admin` | running the whole platform |
| `/app` | the advertiser's own campaigns |
| `/agency` | the agency and its clients |
| `/influencers` | influencers and UGC |
| `/portal` | requests, quotes, invoices, delivery |

Also required: restore any page or feature that has disappeared, remove dead links, and simplify
anything tangled or overlapping. Each unit ships frontend + backend + permissions, is reviewed live
in the browser, is committed clean, and updates this file and the matrix before the next one starts.

## Exact next task
**REVIEW-001 (per-portal live audit)** is the one to take first — it is both an open matrix row and
the directive above. `docs/REGRESSION_AUDIT_PORTALS.md` already covers `/app` and `/agency`;
`/admin`, `/influencers` and `/portal` have never had the same pass. Walk each portal in the browser
against its stated purpose: own dashboard, own menu and taxonomy, own settings, real isolation,
loading/empty/error states, working search-filters-views-details-actions, ≤2 menu levels, nothing
copied between portals.

Known specific gaps to fold in:
- ~~Five unlinked placeholder routes under `/app`~~ — CLOSED by REVIEW-001a. Four deleted;
  `notifications` now leads to the page that existed behind it. `PagePlaceholder` is gone.
- ~~No demo login for the AGENCY side of `/influencers`~~ — CLOSED by REVIEW-001b
  (`talent@demo-agency.local`).

Then the remaining PARTIAL rows: **PAY-005, OPS-002, NORM-001, PROJINT-001**. Nothing is
NOT_STARTED any more.

Previously recorded here, and still true of the payment layer:

PLAN-001 is done: plans are data, both terms, the paid 7-day trial per plan, editable from /admin and
read by one public catalogue.

SIGNUP-001 through SIGNUP-005 are done, tested and live-reviewed. The binding rule they exist to
enforce is now structurally true rather than a policy someone has to remember: **`POST /auth/register`
answers 202 with an APPLICATION and creates no tenant, no workspace, no user, no membership and no
session.** `AuthTest::test_applying_creates_no_workspace_no_account_and_no_session` asserts all five
absences on the same request that used to produce all five.

What is next, and why in this order:

All three of the gaps recorded here have since been closed — notifications (NOTIF-SUB-001),
subscription invoices (SUBINV-001) and the gateway console (PAYSET-001). What remains is external or
blocked:

1. **Credentials.** Moyasar and Stripe both report Awaiting Credentials, and the mail transport is
   `log` (recorded as `sandbox`, never as `sent`). Nothing here can be finished without real keys, and
   nothing pretends otherwise.
2. **A PDF renderer for the invoice download.** Currently plain text, deliberately: an untested Arabic
   PDF path is worse than none — see the CampaignsHub PDF text-layer defect already fixed once.
3. **PORTAL-AUTH-001c** stays BLOCKED_OPERATIONAL_EVIDENCE. Do not retire `ClientPortalToken`.

### The matrix was audited row by row, and several rows were STALE

Four rows read NOT_STARTED for work that had in fact landed, which is its own kind of dishonesty — a
matrix nobody trusts is a matrix nobody reads. Corrected with the evidence:

- **PLAN-001/002/003** — the plans engine, backend enforcement, and over-limit behaviour.
- **PAY-001/003/004**, **OPS-001** — the adapters, webhook-only activation, invoices, self-operation.
- **APP-ADS-001** — `external_ad_sets` / `external_ads` landed in CAMPDET-010.
- **PORTAL-PAY-001** — `POST /client/invoices/{invoice}/pay` already opens a charge through the same
  `PaymentProvider` port, and Moyasar/Stripe are now registered in `config/billing.php` too.

**PAY-002 is genuinely PARTIAL and stays that way.** Renewal, cancellation, refunds, chargebacks and
retries are done. **Upgrade/downgrade mid-term and proration are not built** — changing plan re-prices
from the next period and nothing computes a part-period credit. That is the single largest unbuilt
piece of the commercial contract.

**A real defect was found doing this audit**: the plan-limit refusal used `abort(Response)` from
middleware, which this application's exception handler renders as a **500**. Customers hitting a plan
cap saw a crash instead of "you have used 3 of 3". Middleware now returns the response.

Historical, for reference:
1. ~~**PAY-001**~~ — Moyasar as the official primary adapter, Stripe as the alternative, both behind the
   existing `PaymentProvider` port. No credentials exist, so both ship **Awaiting Credentials** and
   the sandbox path is what gets tested.
2. **PAY-002** — checkout, signed webhooks, idempotency, no duplicate charge. The invariant to write
   a test for BEFORE any payment code: nothing may call `TransitionAccountState::provision()` as a
   shortcut out of `PaymentPending`. That state is the webhook-only activation anchor, and a
   browser returning from a payment page must never be what clears it.
3. **PAY-003** — trial auto-conversion, renewal, past due, grace, suspension (data preserved),
   cancellation, refund, reactivation.
4. **PAY-004** — trial-abuse prevention. "One trial per payment method" belongs in the ADAPTER: Moyasar
   and Stripe expose different fingerprint semantics, and a shared assumption in the core would either
   be wrong for one of them or silently unenforced. Each adapter reports what it can, with an honest
   fallback when a provider supplies nothing.
5. **SIGNUP-006** — the five independent demo accounts, then OPS-001, INTG-001, the remaining rows.

**Do not simply delete `RegisterTenantAction`** when tidying: it is the named auto-activate branch the
contract explicitly keeps supported for self-serve trials. It provisions nothing itself and throws
when the policy has a gate configured, which is what makes keeping it safe.

**PORTAL-AUTH-001c (step 5)** stays NOT next: blocked on evidence from a real environment, not on
code. `/admin/cutover` measures the three conditions; last dev reading was 0 conflicts, 0 parity
mismatches, **14 live legacy sessions**. Do not delete `ClientPortalToken` to tidy up.

### Decision 24 — `users.tenant_id` is gone, and the grant is now always explicit
Dropped in `2026_07_31_090000_grant_memberships_then_drop_users_tenant_id`, which grants a membership
to every user that still had a tenant and none, THEN drops — in one transaction, refusing the drop if
anyone would be left unplaceable. Proven both ways: fresh seed leaves 0 stranded; the upgrade on dev
data rescued 3. "Who belongs to this tenant?" is `User::scopeInTenant`, once, fail-closed on a null
tenant. `$user->tenant` survives as an accessor over the active membership.

It hid a real defect: `TeamController::invite` assigned a role but granted no membership, so invitees
landed nowhere. Expect more of these — anywhere the column was quietly standing in for a grant.

### Decision 21 — the creator surface INVERTS the money, it does not narrow it (INFL-002)
The agency sees `agreed_fee` (billed to the client) always, and `influencer_fee` + margin behind
`influencers.view_costs`. The creator sees `influencer_fee` — their pay — and NEITHER of the other
two, at any permission level. This is why `CreatorPresenter` is a separate class rather than a
`hideCosts` flag on the agency's: a boolean cannot express an inversion, and reusing the agency
presenter with costs hidden would have shown a creator the agency's markup on their own work.

### Decision 22 — `terms_sent_at` is a timestamp, not a status value (INFL-002)
It gates whether a creator can see a collaboration at all. A status can be set to anything by a form;
"was an offer actually made?" needs an answer a dropdown cannot change. Without it the creator's
surface would show every internal draft, including fees still being argued about.

### Decision 23 — a creator is not a low-privilege operator (INFL-002)
They hold NO `influencers.*` permission, so every agency endpoint refuses them by default rather than
by a special case. What they may do follows from `CreatorAccess` and the agreement's state. They
submit; only the agency approves — a creator who could set `approved` would sign off their own work.

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

### Decision 25 — the portal decides the surface; the account type does not

Reported as «جميع البوابات أصبحت تظهر كتجربة وكالة», reproduced live before touching any code: a
`freelancer` registration — and a registration with no account type at all — landed in `/app` and was
served the agency console.

There were **two** portal systems. `Portal` decided the route tree; `AccountEntitlements::nav()`
decided the menu, from `account_type`, through a `personal` / `company` fork. `personal` WAS the
agency console, and it was also the fallback for an unknown type. `freelancer`, `in_house_team` and
every self-registered account that skipped the question fell into it. Only `agency.php` and
`influencers.php` carried `portal:` middleware, so the engines behind those menu items answered.

What is true now:

- **`Portal::sections()` is the single catalogue.** `AccountEntitlements` narrows it by plan and
  module; it never chooses between menus, and a null portal yields `[]` rather than a default.
- **Every tenant-scoped route group names its portal.** Zero groups are left on authentication alone.
- **`clients`, `requests`, client invoicing and client conversations live in `/agency`.** Moved, not
  deleted; old `/app` paths redirect there and the agency gate answers honestly.
- **The account type still chooses the STARTING portal** (`Portal::forAccountType`) and now the
  onboarding answer moves the founding membership with it. It decides nothing after that.

Five further defects surfaced while proving it, each fixed and each with a test:
`account_type` was stored twice and defaulted to `agency`; onboarding asked freelancers for a first
client; `clients.view_all` erased an explicit client scope; `client-workspaces` applied no client
ceiling at all; and `fetchCurrentUser` turned any failed probe into a logout — which on WebKit and
Firefox left a `/login` entry in history that Back landed on.

Deliberately left open, and recorded in the matrix rather than quietly skipped: `/app/*` has no
portal guard of its own (REG-010), and `AlertEvaluator` writes a fixed `/app/alerts` action URL
(REG-011). Closing REG-010 means moving the E2E suite off `owner@demo-agency.local` for advertiser
surfaces, which is its own unit.

### Decision 26 — a wrong portal is a failed sign-in, not a bad destination

Reported twice, and the second report named the real problem: the portal check ran AFTER the session
was created, so choosing the wrong portal signed you in, moved you somewhere, and then showed a "not
available" page. It behaved nothing like a wrong password even though it is the same kind of mistake.

The check now runs inside `POST /auth/login`, after the password is verified and **before**
`Auth::login`. No session, no navigation; the form answers. The 403 carries `portal_mismatch`, the
refused portal and the account's real destination, so the panel can name both and offer a button —
which re-submits the same credentials claiming no portal, because their password was never wrong.

Ordering that matters: **a bad password is never told about the portal.** Answering "wrong portal"
to a failed password confirms the account exists and where it belongs, to someone who has just
proved they do not have its password.

**Demo identities are per portal.** Every tab used to offer `owner@demo-agency.local`, so all five
portals were being exercised with one agency account — which is a large part of why the REG-001
regression stayed invisible. Each tab now names its own seeded account and what kind it is, and the
client tab links to `/portal/login` because that portal has no password to offer.

**Social sign-in is built and honest.** Authorization Code + PKCE with `state` and `nonce`, all three
server-side and single-use; `oauth_identities` with unique indexes in both directions. The linking
rules are the part worth re-reading before touching it: matching is on `provider_user_id`, a matching
EMAIL never adopts an account, and an unknown provider account never creates one. No credentials
exist in any environment, so both providers render disabled with the reason and are classified
**Awaiting Credentials** — the live round trip is externally blocked, and nothing claims otherwise.

**REG-010 and REG-011 are closed.** Guarding `/app` surfaced three more defects, each fixed with a
test: the account menu hard-coded `/app/account/*`; the agency portal had no settings page at all;
and an unauthenticated API call without an `Accept` header returned 500 rather than 401, because
Laravel tried to redirect to a named `login` route this API does not have.

### Decision 27 — the agency picks a client before a project, and personal settings follow the person

**AGENCY-006.** `AgencyShell` had no scope control at all, so every project-scoped page mounted in the
agency portal with no project in context — invisible while `/app` was ungated, because agency
operators simply used the advertiser portal's copy and its switcher. The agency's control is client
first, then project: a project only means something once you know whose it is, and two clients
routinely both have a "Launch". Both lists come from endpoints the server already narrows, the stored
selection is re-validated on every mount, changing client always clears the project, and nothing is
auto-selected.

**Client scope and project scope are SEPARATE grants** — learned while building it. An analyst or
client-viewer can hold specific projects with no client scope, and a strictly client-first control
reported "no clients" to people who demonstrably had work. The client step now appears only when
there are clients to choose between.

**Personal settings follow the person.** `account` is in no portal's `sections()` (that list is
workspace sections), so the legacy redirect sent it to `/app` and the guard refused it. It is now
portal-relative unconditionally, and `/influencers/account/*` is mounted — a creator's own Profile
link had been a 404 since the influencers portal was built.

**A guest has no portal, so none is guessed.** The legacy redirect used to write `/app` into the
sign-in redirect while nobody was signed in, and that guess stuck: after signing in, an agency
operator was delivered to the advertiser portal's copy of their own profile and refused.

### The gate: 53 → 14 → 2 → 0, every failure root-caused

`retries: 0` untouched, nothing skipped, 0 flaky. Five of nine causes were product bugs rather than
stale tests — the three above, the redirect reading the portal before the session probe resolved, and
one I introduced myself: giving `fetchCurrentUser` a retry made `VerifyEmailPage`, which awaited it
before navigating, hold the user on a spinner for three round trips under load. It navigates first
now and guards `if (u)`, because a failed refresh must not sign out someone who just verified.

The lesson worth keeping: adding latency to a function other code awaits in a critical path surfaces
as a flake somewhere else entirely.

### Decision 28 — the client portal's own account could not open the client portal

`client@demo-portal.local` signed in, the server answered `portal: "portal"`, routed it to `/portal`
— and every endpoint there returned 401. The portal was gated on the OTP cookie **alone**.
`ClientPortalIdentity` was already consulted, and already preferred the membership, but only to
narrow the reach of a session the cookie had already opened. The engine that "wins" could never be
the one that let you in.

Nothing in a status check could see it: each 401 was a correct answer to a request that was
correctly authenticated. It took signing in as the demo account and pressing the link.

The membership now opens the portal, as a **synthesised, never-persisted** `ClientPortalToken`, so
the eighty-odd readers downstream keep one shape and the `client_portal_tokens` count that
PORTAL-AUTH-001c is waiting to see reach zero cannot move. The tenant comes from the membership
rather than from the request, and no membership still means no session — an advertiser, an agency
operator and a guest are refused exactly as before.

Second defect found in the same place: `spaces` derived the client list from `external_requests`
rows carrying the contact's email, so an invited portal user saw an **empty portal** until somebody
happened to submit a request under their address. The space they had been explicitly granted was
simply not in the list. It now comes from the membership scope.

Also root-caused rather than retried: `PaidMediaServicesTest` asserted the ten service categories in
insertion order over a query with **no `ORDER BY`** — it was reading Postgres's physical row order,
which held by luck and broke in the full suite. Ordered by `sort_order`, the column that means order
and the one the catalogue itself reads.

### Decision 29 — influencers & UGC is withdrawn, and nothing was lost doing it

Four portals are offered in this release: `/admin`, `/app`, `/agency`, `/portal`. Influencers & UGC
returns later as its own sub-system.

**It is switched off, not deleted.** `brand.features.influencers_ugc_enabled=false`, read in exactly
one place — `Portal::isEnabled()` — and asked by everything that OFFERS the portal. Every table,
model, service, controller, permission and test stays where it is; the sub-system's own suite runs
green with the flag on, and `PortalAccessTest` proves the same membership opens the portal again the
moment it is flipped back. Turning it on is a decision, not a rebuild.

Three things this shape gets right that a deletion would not:

- **The enum keeps its case.** Removing it is the other way to say "withdrawn", and it would orphan
  every membership, collaboration and nomination row that names the portal — including the ones that
  have to survive to be handed back.
- **The service type is preserved, not deactivated.** `RequestType::offered()` withholds the
  influencer type from the intake while leaving the row active, because that row is what every
  existing influencer request hangs off. Deactivating it would turn real requests into rows pointing
  at a type the product no longer admits exists. A count alone cannot tell those apart, so the test
  reads the row straight from the table.
- **The addresses still answer.** `/influencers/*` redirects to `/services?unavailable=influencers`,
  a real catalogue page carrying a one-sentence explanation. A 404 reads as "you typed it wrong" for
  a URL that was correct last week; a blank page reads as broken; a "coming soon" card is the
  placeholder this product does not ship.

**The gate is the backend's.** `EnsurePortal` refuses the portal *before* reading any membership, so
holding one grants nothing, and registration refuses both the portal and its service module however
the payload is written. The interface not drawing a link has never stopped anybody typing a URL.

`PortalResolver::membershipsFor()` also filters withdrawn portals, because it is the one place every
routing decision reads: an operator whose only membership is the influencers portal is not delivered
to a redirect chain, and one who also holds the agency portal is taken straight there instead of
being shown a switcher with a dead option in it.

### Decision 30 — a console that can only show the happy path cannot be evaluated

`/admin` was four counters and two bar-lists. It printed **database codes** at a reader —
`self_serve_company`, `trialing`, `past_due` — into an Arabic-first interface, so half the page had
quietly stopped being Arabic. And it could not answer the question an owner opens a console with:
how is the platform doing, and what needs me today.

Three decisions inside the rebuild are worth keeping:

- **Committed is not collected.** The money card is priced from `subscriptions.unit_amount`, not from
  the plan it names — deriving it from `subscription_plans` would silently re-price every existing
  customer the next time the owner edits a plan, and there is now a test that proves it does not. The
  card says «committed» and the note beside it says platform collection is not live and that the
  invoices in this database are agency-to-client billing belonging to the agency.
- **Empty months are zeros, not gaps.** A growth series that omits them draws a straight line through
  the gap and turns a quiet quarter into apparent steady growth.
- **The attention list returns zeros and displays none of them.** A row that vanishes from the API is
  indistinguishable from one that was never computed; a strip of zeros on screen is wallpaper. So the
  payload is complete and the page filters — and "nothing is pending" is said in words.

Privacy held while adding charts, which is exactly the change that starts leaking customer work: a
test asserts that `campaign`, `creative`, `impressions`, `clicks` and `roas` never reach the payload.

**The demo had to grow with it.** A fresh install had two tenants created in the same second, so
every chart was a single mark and the attention list was permanently empty.
`DemoPlatformHistorySeeder` seeds ten workspaces across ten months, on three plans, in the states an
operator actually deals with — paying, trialing, past due, cancelled, suspended. Every row is named
`(Demo)`, the spread is deterministic, and only `created_at` moves: back-dating money would put
figures in months where none were committed.

One trap recorded because it looks like a bug until you know why: the seeder must `saveQuietly()` to
stop observers stamping `created_at` back to now — which also skips the `creating` event that mints
the UUID, so it supplies the key itself.

**And the doors got their social sign-in.** `SocialSignIn` lived only on the legacy `/login`, so the
four doors the product actually sends people to had none. It is shared now; both providers render
disabled with the reason, because an enabled button with nothing behind it claims support the
platform does not have. `/admin/login` offers no sign-up at all — the platform owner is never created
by a public form.

### Decision 31 — the whole product was Arabic-only under `dir="ltr"`

The advertiser dashboard was the worst of it: choosing English flipped the direction and left 118
Arabic words on the page. An interface that changes direction while its content does not reads as
broken rather than as unfinished, and it was the flagship page of that portal.

Two things about how this was found are worth keeping.

**A source grep gave the wrong answer.** Counting Arabic string literals per file said all eight
`/app` sections were untranslated; five of them were already correct and the count was picking up the
`ar:` half of their bilingual tables. The measurement that works is a WALK of each portal's rail in
English asserting zero Arabic — it cannot be fooled by how the strings are stored, and it covers a
section added next month without anybody remembering to add it to a list. All four portals now carry
that test.

**The manual check could not have found the persistence bug.** Language and theme were not
remembered, while the sidebar's collapsed state was — so English lasted until the next full page
load and then silently reverted. Clicking around inside the SPA kept it, which is exactly what a
human check does; only the full navigations an automated walk performs exposed it.

Three more defects came out of the same pass: a `useMemo` that built KPI labels without the language
in its deps (heading translated, numbers beside it did not), a grid item that refused to shrink below
its table's `min-w-[420px]` and dragged the phone layout sideways with it, and Arabic-Indic numerals
in the finance ageing buckets against the product's Latin-digits rule.

### Decision 32 — two rate limits, root-caused rather than retried away

`/register` was the last public route still carrying a literal `throttle:6,1` while every other one
had an environment-aware limiter. The suite opens two accounts per browser project and runs three
projects back to back, so the seventh registration in a rolling minute came back 429 and the form
stayed on `/register` — a rate limit wearing the costume of a broken form, on whichever browser
happened to run seventh. The login allowance was too tight for the same reason: six seeded roles sign
in at the start of every run, and back-to-back runs cleared sixty inside a minute, failing the
storage-state setup and reporting `419 did not run`.

Production stays at six a minute for both and remains env-overridable. Only the off-production
allowance moved. Raising the production limit or adding retries would each have been the wrong fix:
the first weakens a real control, the second hides it.

### Decision 33 — a test that picks by index is a test that passes alone

`campaigns-linking` chose its workspace with `projects[1]`. The project list is neither ordered nor
fixed — every registration-and-onboarding run adds one — so which project that index landed on
depended on what had run before it. The spec passed in isolation and failed inside the full gate, on
whichever browser reached it after the list had grown.

It now creates (or reuses) a project it names itself. The first attempt at that took the owning
client from `/client-workspaces`, which is agency-scoped and answers 403 for the roles some specs run
as — turning a missing precondition into `Cannot read properties of null`. The client id comes from
an existing project instead, which every caller already has.

Same shape as the two rate limits: the suite outgrew an assumption that was true when it was written.

### Decision 34 — the integrations page led with a fake provider

`/app/integrations` ordered its grid by whatever the API returned, which put `sandbox` — a local
fake provider that exists so the product can be demonstrated without credentials — at the head of
the list, above Meta and Google, wearing a green «connected» chip. Somebody opening that page to
connect their advertising met a connected generic connector first and the platforms they came for
eleventh.

Sorted rather than filtered: the other eleven connectors are real and stay reachable. What changed
is which ones the page leads with, and that the fake provider is last of all. A second defect went
with it — the «الحساب» line printed `connection.status`, the raw state enum, under a label promising
an account, beside a chip already showing that state. There is no account identifier on that payload,
so nothing is claimed now.

**PROJINT-001 was stale, not unbuilt.** `PlatformOverviewController` and the panel above the
technical bindings had both been written and never verified. What was missing was the acceptance
test, which now asserts all six platforms are named with their own capability list and per-platform
state, that no sync is claimed, and that «0 — لا توجد مفاتيح حقيقية بعد» is stated as a NUMBER rather
than implied by an empty page — which would read as still loading.

### Decision 35 — a selector that guessed, and the day a second row appeared

`campaigns-linking` found its row with "the smallest div containing both the name and a Link button".
That held while exactly one external carried a given name. The moment a second appeared — two
projects each with a Sandbox binding is enough — `.last()` picked a container with no button in it,
and the failure read as a missing row rather than as an ambiguous selector. It failed on whichever
browser ran after the first had seeded its own.

The row now carries `data-testid="link-external-row"` and `data-external-name`, so the component
names it instead of the test guessing. Third instance in this branch of the same lesson: the suite
outgrew an assumption that was true when it was written.

### Decision 36 — the gate is only as honest as the servers under it

A three-browser run came back **501 passed, 5 failed**, and every one of the five was mine.

`playwright.config.ts` starts the backend as `sh -c "php artisan queue:work … & php artisan serve"`
— the worker and the server together, because report generation is queued. I had started
`php artisan serve` by hand first, and `reuseExistingServer: !CI` did exactly what it says: Playwright
adopted my server and never started its own, so the suite ran with no queue worker at all.
`report-pdf-download` then waited ninety seconds on all three browsers for a job nothing was draining,
and the two `registration-onboarding` specs failed on chromium in the same run and passed the moment
the configured servers were used.

The matrix already carries this as **QUEUE-WORKER-001**, closed once before for the same spec. It cost
a full 26-minute run to rediscover, so it is written here too: **do not hand-start the backend before
a gate.** Let Playwright own both servers, or start the worker alongside `serve` exactly as the config
does. A failure caused by a missing service is not a defect in the product, and reporting it as one —
or, worse, re-running until it passes — would put a false red and then a false green in the record.

### Decision 37 — a figure and a figure's basis are different things (NORM-001)

The normalisation layer was never missing. Every `daily_metrics` row has always recorded the currency
it arrived in, the one it was converted to and the rate used, the platform's timezone and the
project's, the attribution window that counted its conversions, and whether it came from an API or
from demo data. None of it reached a reader. Spend was displayed converted with nothing saying a
conversion had happened, and `meta.currency` was the literal `'SAR'` on every metrics response —
right for this installation, and a claim the data was never asked to support.

What the new panel exists to prevent is not an arithmetic error. Two campaigns whose conversions were
counted under different attribution windows are not comparable; a dashboard that shows them side by
side without saying so computes correctly and leads the reader somewhere false. So each row reports
what is ACTUALLY in the range, and the awkward answers — a second display currency, a second
attribution window, demo rows among real ones — are called out rather than resolved quietly.

Three defects found by reading the code:

- **`MetricDefinitionSeeder` was never called.** `DatabaseSeeder` ran four structural catalogues and
  not this one, so `metric_definitions` was EMPTY on every install. `DailyMetric::definition()` had
  always returned null, and the table that says whether a metric may be summed had never been read.
- **The catalogue named 15 of the 31 keys the aggregator emits.** It was written once and the
  aggregator grew past it. A half-catalogue is worse than none: the gaps read as metrics the product
  does not have. `MetricsTest` now fails if a metric is added without a definition.
- **`meta.currency` was hardcoded.** It is read from the rows now, and is `null` for a range with no
  money in it — which is an answer a caller can act on, where a confident «SAR» over an empty period
  is not.

And one found only by opening the page:

- **The objectives query had no tenant scope.** It reached for `DB::table('daily_metrics')` inside a
  subquery, which carries no global scopes, and answered with every objective in the installation.
  The live review caught it because the page contradicted itself — every other row said «no data in
  this period» while that one confidently named a campaign. On a project that HAD data it would not
  have contradicted itself. It would have printed another tenant's campaigns as this project's, with
  nothing to mark them. Fixed by reading through the model, and pinned by a regression test.

That last one is the argument for live review in one example: six passing tests and a green build,
and the defect was a cross-tenant read that only showed itself as a sentence not matching its
neighbours.

### Decision 38 — 135 failures that were all one broken pipe (SERVELOG-001)

A gate came back with failures spreading as it ran: chromium clean, firefox failing in clumps, webkit
worse, 135 by the time it was stopped. Specs across every area, all of them dying the same way —
`TypeError: Cannot read properties of null (reading '0')` on `(await res.json()).data`.

Laravel's dev-server router writes one request line to `php://stdout` for every request
(`Illuminate/Foundation/resources/server.php:21`), unconditionally: there is no flag and no env var to
switch it off. Over a 500-test three-browser run that is tens of thousands of writes into a pipe. When
the reader on the other end stalls, the write fails with EPIPE, PHP emits
`Notice: file_put_contents(): … Broken pipe`, and because the CLI server runs with `display_errors` on,
**the notice is prepended to the HTTP response body**. From that moment every JSON response is
malformed, `.data` is null, and the failure surfaces in whichever spec touches the API next.

Proved both directions against the running server using the suite's own stored session: on a
reader-less pipe the FIRST of 500 requests came back corrupted; with STDOUT redirected to a file,
0 of 500 did. `playwright.config.ts` now redirects it, and keeps STDERR on the pipe so genuine startup
errors still reach the Playwright output.

Two things worth keeping from this. First, none of the 135 was an application defect, and a suite with
retries enabled would have papered over a short run and left the real cause in place — this is the
case `retries: 0` exists for. Second, both gate failures this session were the ENVIRONMENT rather than
the product (the other was the missing queue worker, QUEUE-WORKER-001). Before reading a wave of
failures as regressions, check that the servers under them are sound: a defect that appears in
alphabetical order and worsens over time is a property of the run, not of the code.

### Decision 39 — four streams, and the refusal to add them up (PAY-005)

Money moves through this product in four directions and only ONE of them is the platform's. Tenants
owe CampaignsHub for subscriptions. An agency's clients owe the AGENCY for its invoices. The request
payments are those same invoices filtered by where they came from. Creator payouts would be the
platform paying out, except no payout ledger exists.

Two additions would each be a lie, and the endpoint is shaped so neither is easy to make:

- Platform subscriptions **+** agency invoices reports customers' money as the platform's business
  result. `belongs_to` is on every stream, so the distinction travels with the figure.
- Request payments **+** agency invoices counts the same invoice twice, because the first is a VIEW of
  the second. `subset_of` says so on the stream that would cause it.

`combined_total` is present and **null**, with the reason attached. An omission is something a reader
fills in with a calculator; a stated refusal is not.

Two things fixed while building it. The platform stream is priced from `subscriptions.unit_amount` —
the amount actually agreed with that customer — where `revenue()` prices from the plan, so raising a
plan's price would have made two surfaces in one console disagree about the same figure. And creator
payouts report «not implemented» rather than «0.00»: a zero claims nothing is owed, which is a
measured result this system has never measured.

Live review caught what six passing tests did not: the cards printed the backend's English `note`
under Arabic headings, beside Arabic chips and Arabic counts. A source grep could not have found it —
the English lives in PHP. The copy is in the page now, in both languages, and an E2E WALKS the
rendered panel asserting no Latin-only paragraph survives in Arabic. Also fixed «1 invoices».

### Decision 40 — orphaned servers, and a setup that could not say why

Two runs died in `auth.setup` with six identical timeouts. The assertion was a bare
`not.toHaveURL(/\/login$/)` on the default 5s window, which cannot tell «the server refused these
credentials» from «the SPA has not finished booting». Setup now waits on the login RESPONSE, asserts
200, and puts the body in the failure message — and it immediately said what a day of guessing had
not: **401**, then **502**.

Neither was an auth defect. `sh -c "worker & serve"` left both children running whenever a run was
interrupted, so `reuseExistingServer` kept adopting half-dead servers from previous runs — at one
point three Vite instances were stacked on port 5173, proxying to a backend that no longer existed.
The command now runs `trap "kill 0" EXIT INT TERM`, which takes the whole process group down with the
shell.

The lesson is the same as SERVELOG-001 and QUEUE-WORKER-001, for the third time: **check the servers
before reading a wave of failures as regressions.** And an assertion that cannot distinguish two very
different causes will eventually cost more than the minute it takes to write one that can.

### Decision 41 — the reason was always computed, and always thrown away (OPS-002)

`SubscriptionLifecycle` has sixteen public methods. A trial converts, a renewal fails, a grace period
runs out, an account is suspended, a customer comes back. Four of them take a `$why` explaining the
act — and **not one of the sixteen wrote an audit row**. An owner could see that a workspace had been
suspended and had no way to find out when, by whom, or on what grounds.

Recorded at the **model**, not the call site. The lifecycle mutates subscriptions from about ten
places, most of them running unattended on a schedule, and payments are written by webhook handlers.
An audit line per call site is one somebody eventually forgets to add when they write the eleventh —
which is exactly how a trail develops holes nobody notices until it is needed. An observer is the
difference between «we remembered everywhere» and «it cannot be missed».

Three decisions inside it worth keeping:

- **Only material columns.** `current_period_end` moves on every renewal and `updated_at` on every
  write. Recording those buries the suspension that matters under a thousand rows that do not.
- **Payment entries carry no gateway session.** `provider_session_id` and `checkout_url` are excluded,
  with a test asserting the session id never appears in the log. An audit trail that leaks a payment
  session is worse than the gap it was written to close.
- **The four categories are prefix-matched**, so a `subscription.*` action added later is covered
  without anybody editing a list — the same reasoning as the observer.

And the page: `/admin/audit` now resolves the actor and workspace to NAMES. A trail that answers
«who» with a UUID answers nobody, because the reader has to go and look it up somewhere else, which
in practice means the question goes unanswered. Unattended lifecycle work has no actor at all and
says «النظام» rather than leaving a blank, which would read as missing data.

### Decision 42 — the fourth selector that guessed

`campaigns.spec.ts` opened with a comment saying «a demo project is auto-selected by the switcher»
and trusted it. That held while the seeded projects were the only ones. It stopped holding once
`campaigns-linking` began creating a project of its own: the switcher's default landed there, the
spec opened `E2E Link B <timestamp>` — a throwaway with no platform bindings — and timed out waiting
for a Link button that campaign could never have shown. **Firefox only**, because the project did not
exist yet when chromium ran, which is exactly the shape of every cross-test-order defect this suite
has produced.

Fixed by pinning the project by NAME, the same fix `pinnedProject` already applies elsewhere. A new
`seededProject()` helper finds an existing project and **throws** when it is missing, rather than
creating one: `pinnedProject` creating a project is right for a spec that needs somewhere to work and
wrong for a spec that needs seeded data, because a fresh project has no campaigns, no metrics and no
bindings, so the spec fails on assertions that were never about it.

Fourth instance of the same lesson in this branch: **a selector that guesses will eventually guess
wrong, and it will do it on whichever browser runs after the state changed.** Worth reading as a rule
now rather than as four anecdotes — if a spec needs particular data, it should name that data.

### Decision 43 — clicking before the app is awake

`auth-redesign` clicked the «forgot password» link straight after `page.goto('/login')`. `goto`
resolves on `load`, which on a single-page app is well before React has hydrated and bound the
router — a click landing in that window hits an anchor whose handler has already called
`preventDefault()` but whose navigation is not yet wired, and is simply lost. The URL sits on
`/login` until the assertion gives up.

It surfaced once, on firefox, in a full three-browser run: the browser that happened to be slowest
that day. **The fix is to click a live app, not to give the assertion longer.** A raised timeout
would have hidden the race and left the click still landing on a page that was not ready — and would
have made the next occurrence, whenever the machine was busier, look like a new defect.

Same shape as Decision 42, one layer down: there the spec assumed which project was selected, here it
assumed the page was interactive. Both are assumptions about state nobody had established.

### Decision 44 — a fixed clock for work that grows

`portal-audit`'s rail walks open EVERY link in a portal — a dozen-odd full page loads — inside one
test on the default 30s. That was already tight and got tighter with each section added to the
product, so it expired on whichever portal firefox reached when the machine was busiest: the agency
rail in one run, the advertiser's in the next, with nothing wrong on either page.

The budget is now proportional to the number of links the walk actually found. **Nothing is relaxed
except the clock** — every assertion still runs on every link, and a page that genuinely fails to
render still fails. What changed is that a slow machine no longer looks like a broken page.

Worth separating from the three environment findings above (SERVELOG-001, QUEUE-WORKER-001,
Decision 40): those were the servers being wrong. This one is the test asking for more time than it
allowed itself, and it would have kept getting worse as the product grew.

### WHERE THIS STOPPED — read this first

**VERIFY-100 is closed.** All ten `IMPLEMENTED_NOT_VERIFIED` rows now have an acceptance test in
`e2e/verify-100.spec.ts`, green on chromium, firefox and webkit. Each asserts what its requirement is
FOR rather than that a page returns 200. The matrix table now reads **79 VERIFIED · 1 PARTIAL
(PORTAL-AUTH-001) · 6 BLOCKED_EXTERNAL_CREDENTIALS · 0 IMPLEMENTED_NOT_VERIFIED**.

Two real findings came out of writing them:

- **FINANCE-001 named a path that does not exist.** The row said `/app/finance`; `billingRoutes` is
  mounted only under the agency tree, so an advertiser going there fell through to the agency guard
  and was told the portal was not theirs — correct behaviour, wrong path in the row. Corrected to
  `/agency/finance`, which is right: agency→client invoicing is the agency's money, the separation
  PAY-005 draws.
- **HOME-GATEWAY-001 needed no new test.** It was already covered end to end by `homepage.spec.ts`,
  `homepage-journeys.spec.ts` and REVIEW-002. What was missing was the row saying so.

#### The simplification pass — STARTED, NOT FINISHED

Only **`/app/dashboard`** has been done (SIMPLIFY-001). It opened with three bands of configuration —
a saved-views bar, an objective row, a platform row — between the reader and any number. All three are
now behind one «تخصيص العرض» button, with a line beside it stating what is applied in words so folding
hides no state. Nothing was removed. Reviewed live on desktop and at 375px; 3 E2E on 3 browsers.

**Not started**, against the brief's criteria (two-level menus, one primary action per page, advanced
detail into drawers, no repeated cards, no status codes shown to users, no shared experience between
portals):

- `/app` — the other eleven pages. The rail is 6 groups / 12 leaves; already two levels, but
  «المحتوى», «الملفات», «المهام» and «التكاملات» are candidates for folding into their parents.
- `/agency` — **7 groups / 15 leaves, the widest rail in the product** and the most likely to
  overwhelm. Start here.
- `/admin` — 8 leaves.
- `/portal` — not surveyed.

The pattern SIMPLIFY-001 establishes is worth reusing rather than reinventing: **fold configuration
behind one control, and state what is applied in words.** It satisfies «إجراء رئيسي واحد» and
«نقل التفاصيل المتقدمة إلى Drawer» without deleting anything, and the three tests written for it are a
template — the control exists, the folded thing still works, and it fits a phone.

#### Discipline note

I edited source while a full gate was running and invalidated it — the exact mistake recorded earlier
in this file. Killed the run and discarded the result rather than reading it. **Do not edit under a
running gate**; Vite HMR changes the page beneath the test and every failure after that point is
meaningless.

### Still open

Measured from `/dev/status`, which parses the matrix rather than keeping a second list.

The transcribed «Open» paragraph under the matrix table had drifted badly — it still named ten rows
that were closed, PROJINT-001 and INTEG-UI-001 among them. It has been recomputed from the table, and
the table is what `/dev/status` reads.

**Done and committed:** the four offered portals, the influencers withdrawal, the marketing
destinations, the responsive/theme sweep, and now PROJINT-001 + INTEG-UI-001.

**Next, in order:**

1. **VERIFY-100** — CAMPAIGN-010/020, CAMPDET-010, REPORT-SCHEDULING, FINANCE-001, SYNC-001,
   XREL-001, DEMO-001, HOME-GATEWAY-001, DEVSTATUS-001. Each needs a targeted acceptance test of its
   own behaviour plus live review — never a documentation-only status change. Most of these pages are
   already walked by the portal specs for content, language and phone layout, so what is missing is
   the behaviour test.

**Blocked, honestly:** the six ad-platform integrations, Moyasar and Stripe are Awaiting Credentials
— no live round trip has been made and nothing claims otherwise. Mail transport is `log`, recorded as
sandbox. `PORTAL-AUTH-001` stays PARTIAL: REVIEW-001c closed half of it (a `ClientPortal` membership
now opens the portal); retiring the OTP token engine waits on `/admin/cutover` reading zero on all
three conditions — BLOCKED_OPERATIONAL_EVIDENCE, not code.

**The gate:** 922 backend · 483 vitest · 505+ E2E on chromium, firefox and webkit. `retries: 0`,
nothing skipped.
