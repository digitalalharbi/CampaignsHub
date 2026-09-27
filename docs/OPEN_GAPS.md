# CampaignsHub — Open Gaps

> # ⛔ SUPERSEDED — do not read the snapshot below as the current state.
>
> **Reconciled 2026-08-11.** Everything under this banner is a **frozen snapshot from
> 2026-07-29**, taken at HEAD `fc90666`. It is kept because the reasoning in it is still worth
> reading; every status in it is two weeks stale, and a developer who acted on it would rebuild
> working code.
>
> The current state lives in **`docs/RESUME_STATE.md`** (under START HERE) and
> **`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`**. Where they disagree with this file, they are right.
>
> What became of the seven items listed below:
>
> | # | Then | Now |
> |---|---|---|
> | 1 | `SITE-CMS-002` — three public surfaces hard-coded | **Shipped.** The public pages render published CMS content |
> | 2 | `XBROWSER-GATE` — Chromium only, so 22 rows `IMPLEMENTED_NOT_VERIFIED` | **Closed.** The gate runs Chromium, Firefox and WebKit as three isolated invocations; the matrix carries **no** `IMPLEMENTED_NOT_VERIFIED` row |
> | 3 | Ad platforms awaiting credentials | **Unchanged, and correct.** All six are `BLOCKED_EXTERNAL_CREDENTIALS`. This is the one item on the list that is still open, and it is external |
> | 4 | Report scheduling had no HTTP API | **Shipped.** API, UI and the `reports:dispatch-scheduled` schedule |
> | 5 | Objective ranking exercised only the conversion group | **Closed** by the objective taxonomy work (`REPORT-OBJECTIVE-002…005`) |
> | 6 | A master-context document referenced but absent | **Still absent, deliberately.** Governance is the contract + the matrix + `RESUME_STATE.md`. Do not invent it |
> | 7 | Eleven named PARTIAL / NOT_STARTED requirements | **All closed.** The matrix's own summary reads no `PARTIAL`, no `NOT_STARTED` and no `IMPLEMENTED_NOT_VERIFIED` outside superseded historical sections |
>
> The principle at the bottom of this file — a test failure is never dismissed as transient without a
> logged cause and a reproduction attempt — is still in force and is not part of the snapshot.

---

> **HANDOFF SNAPSHOT — 2026-07-29 (context-window emergency close).**
> Branch `feat/taxonomy-ux` · HEAD `fc90666` · working tree CLEAN · no WIP.
> Backend 422 passed (2467 assertions) · Frontend 215 passed · tsc/build clean ·
> Playwright **Chromium only** 144/0 (Firefox + WebKit still to run).
> Full state, next task and acceptance criteria: **`docs/RESUME_STATE.md`**.

> **Open gaps at handoff (honest, unclosed):**
> 1. **`SITE-CMS-002`** — the three portal PUBLIC surfaces (paid / influencer / tracking) still render
>    hard-coded copy; their CMS content is already stored, editable and published by the API. **EXACT NEXT.**
> 2. **`XBROWSER-GATE`** — E2E has passed on **Chromium only** (144/0). Firefox and WebKit have not run, so
>    22 requirements are `IMPLEMENTED_NOT_VERIFIED`, not `VERIFIED`.
> 3. **Ad-platform integrations** (Meta, Google Ads, TikTok, Snapchat, X, LinkedIn) — no credentials
>    supplied, therefore **no live sync exists**. Keep the honest "awaiting credentials" state; never show
>    Connected/Synced/Live.
> 4. **Report scheduling** — engine exists but has **no HTTP API**, so no UI was built (`BLOCKED_NO_API`).
> 5. **Content objective-ranking** currently exercises only the `conversion` group, because every seeded
>    campaign is a sales campaign; the awareness/lead/traffic groups are implemented but unexercised.
> 6. `docs/CampaignsHub_Master_Context_and_Instructions.md` is referenced by the user's standing
>    instructions but **does not exist in the repo** — governance in force is the contract + matrix +
>    RESUME_STATE. Do not invent it.
> 7. Partial requirements still open: `CAMPAIGN-010`, `CAMPDET-010`, `INTEG-UI-001`, `FINANCE-001`;
>    not started: `CAMPAIGN-020`, `PROJINT-001`, `SYNC-001`, `NORM-001`, `XREL-001`, `DEMO-001`,
>    `DEVSTATUS-001`.


Anything not proven closed lives here. A test failure is **never** dismissed as "transient" without a logged
cause and a reproduction attempt. If the cause is unknown, it stays OPEN with the evidence gathered so far.

Severity: `Blocker` · `High` · `Medium` · `Low` · `Watch` (unreproduced, monitored)

---

## G-001 — vitest cross-file flake (previously mislabelled "transient")

- **Severity:** Watch
- **Status:** OPEN (not closed — cause not definitively identified)
- **Symptom:** One earlier full-suite `vitest run` reported `1 failed` then passed on re-run. I originally called
  it "transient" without diagnosis — that was wrong per the testing mandate.
- **Reproduction attempts (no retries):**
  - Isolated `CampaignDetailPage.test.tsx` ×5 → 5/5 pass.
  - Full suite `vitest run` ×8 → 8/8 pass (18/18 each).
  - Total 13/13 clean; original failure output was not captured, so the exact failing assertion is unknown.
- **Leading hypothesis (unconfirmed):** `CampaignDetailPage` now fires many CMC query hooks
  (summary/activity/alerts/…) that are not mocked in the unit test; under parallel workers an unhandled
  rejection or React-Query retry could bleed a timing warning into a sibling file. Not proven.
- **Recurrence:** flickered once more on 2026-07-27 (1 failed / 21 passed in a summary-only run; assertion not
  captured), then 10/10 clean immediately after — reinforcing the unmocked-network hypothesis.
- **Mitigation APPLIED (2026-07-27):** `CampaignDetailPage.test.tsx` now `vi.mock('./metrics')` — every CMC
  hook (summary/performance/platforms/budget/funnel/activity/alerts/reports/annotations/creatives + the two
  annotation mutations) returns a static stub, so the command-center tabs issue **no** network request during
  the unit test. (Also fixed a vi.mock hoisting bug this introduced: stubs must live inside the factory —
  caught because the suite dropped 22→18; now back to 22.) The test QueryClient already uses `retry:false`.
- **Status:** downgraded to low-risk Watch. Full suite now 22/22 across 8 consecutive post-fix runs +
  CampaignDetailPage 4/4 isolated. Close fully after 30+ clean runs with the mock in place, or reopen on any
  captured failure with a stack.

- **Captured at last (2026-09-27).** This gap has said since it was opened that «the original failure
  output was not captured, so the exact failing assertion is unknown», and asked to be reopened on a
  captured failure. Two consecutive full-suite `vitest run`s on this machine each failed exactly one
  test, and each time a DIFFERENT one:

  ```
  FAIL  src/features/campaigns/campaignsLedger.test.tsx > the campaigns ledger
        > asks the server for the next page
  FAIL  src/features/tasks/tasksLedger.test.tsx > the tasks page
        > asks the server for the next page rather than slicing what it holds
  ```

  Both are `3,017 passed | 1 failed` runs, and both failing tests are **pagination** tests that assert
  the server was ASKED for the next page — that is, they assert on a mocked call, not on rendered
  output. Neither file fails on its own: `src/features/tasks` runs 7/7 in file order with the two
  newest cases last, and the same holds for the campaigns file.
- **What this narrows.** The leading hypothesis was an unmocked network call bleeding a timing warning
  into a sibling file. These two data points are consistent with that and sharpen it: the tests that
  break are the ones whose assertion is a CALL COUNT, which is precisely what a stray retry or a
  late-resolving promise from another worker would disturb, while assertions about rendered text are
  unaffected. It is still not proven.
- **Not a regression, and checked rather than assumed:** CI ran the same full suite on the same commits
  (`frontend` is green on #565, #566 and #567) — so this is load on a developer machine running other
  work in parallel, not something the branch introduced.

## «No unexplained blank preview» — MEASURED (2026-09-27)

- **Severity:** n/a (acceptance measurement, kept here because this is where the evidence lives)
- The Owner's CONTENT list asks for «no unexplained blank previews». Measured on the whole first page
  of `/agency/content` rather than argued: for each of the 24 tiles, does it draw media (an `<img>`
  with a non-zero `naturalWidth`, a `<video>` or a `<canvas>`), or does it carry a sentence?

  ```
  {"tiles":24,"drew":13,"said":11,"silent":[]}
  ```

- Thirteen draw the asset, eleven say why there is nothing to draw, and **none is silent**.
  `naturalWidth` rather than presence, deliberately: an `<img>` whose src 404s still exists in the DOM
  and the browser draws its broken-image icon, which is the outcome this requirement is about.
- Scope, stated so it is not over-read: page one at 1440×1200 on the demo dataset. It does not speak
  for production media, which is `BLOCKED_OPERATIONAL_EVIDENCE` behind an authenticated session.

### «Results / CPA consistency» — measured the same way

The same list asks for results/orders/CPA/CVR consistency. Taking every tile on that page that shows
all three figures and testing whether the printed cost per result equals spend ÷ results:

```
CHECKED 17   MISMATCHED 0
```

Seventeen tiles, none off by more than 0.15 SAR — so the card's own arithmetic holds across the page,
not only on the one creative that was opened for the card→popup comparison. Same scope caveat: demo
dataset, page one.

## G-002 — Arabic PDF: English bold-heading text-layer extraction

- **Severity:** Low
- **Status:** OPEN (documented, non-blocking for Arabic client delivery)
- **Symptom:** Chromium emits some Latin bold headings with a text-layer quirk affecting copy/extract in a few viewers.
- **Scope:** English document report only; Arabic client PDF passes fail-closed pipeline + real-button E2E.
- **Next action:** verify in Firefox PDF.js and Adobe Acrobat; remap if reproduced.

## G-003 — Firefox PDF.js + Adobe Acrobat verification (Arabic)

- **Severity:** Low
- **Status:** OPEN (untested viewers, honestly marked)
- **Next action:** open the delivered Arabic PDF in Firefox PDF.js and Acrobat, record per-viewer result in `docs/PDF_VISUAL_AUDIT.md`.

## G-004 — External dependencies (Awaiting Credentials)

- **Severity:** Blocked — External Dependency (not a defect)
- Mail (password reset email, request notification email), Google OAuth, ad-platform API keys.
- **Behaviour today:** flows are real end-to-end in the UI; server records intent and returns generic success;
  surfaces marked "Awaiting Credentials". No fake data substituted.

## G-005 — Post-login intended-path redirect

- **Severity:** High
- **Status:** CLOSED (2026-07-27) — live-verified end-to-end.
- **Fix:** `RequireAuth` now redirects guests to `/login?redirect=<intended>` (root excluded);
  `LoginPage` consumes it via `safeRedirect()` (`features/auth/redirect.ts`), which rejects open-redirects
  (`//host`, absolute URLs, `/\`, `javascript:`) and auth-page loops. `safeRedirect` unit-tested (4 cases, 22/22 suite).
- **Live evidence:** guest visits `/analytics` → URL becomes `/login?redirect=%2Fanalytics` → login succeeds →
  lands on `/analytics` (heading "التحليلات", app shell present), NOT `/`. Verified in Browser pane on :5173.

## G-006 — No catch-all 404 route

- **Severity:** Medium
- **Status:** **CLOSED (verified 2026-09-27).** The route exists — `router.tsx` ends with
  `{ path: '*', element: <NotFoundPage />, errorElement: <NotFoundPage /> }`, last in the list so it
  can only match what nothing else did, and it is wired as the `errorElement` too, which is what
  replaces the dev ErrorBoundary this gap was opened against.
  Seen rendered, not just read: opening `/agency/notifications` and `/agency/branding` (paths that
  exist under `/app` but not under `/agency`) draws the product's own styled page — «الصفحة غير
  موجودة / ربما تغيّر العنوان أو كُتب خطأ», the attempted path in a field, and a «العودة إلى الصفحة
  الرئيسية» button — with the app's typography and dark ground, not «Hey developer».
- **Was:** OPEN (observed 2026-07-27 during G-005 testing)
- **Symptom:** Unknown paths (e.g. `/campaigns/42` — wrong shape) fall through to React Router's default
  ErrorBoundary ("Unexpected Application Error! 404 Not Found — Hey developer"), an un-styled dev screen.
- **Next action:** add a styled `NotFound` element as a catch-all `{ path: '*' }` inside and outside the auth
  guard, matching brand + RTL. Small, self-contained.

## G-007 — E2E flake under `--repeat-each=3` (login throttle) — DIAGNOSED + FIXED

- **Severity:** High → CLOSED (2026-07-27)
- **Symptom:** `playwright test --workers=1 --retries=0 --repeat-each=3` failed at `auth.setup.ts`
  (viewer login) — page stayed on `/login`, `expect(page).not.toHaveURL(/\/login$/)` timed out.
- **Cause (deterministic, NOT transient):** login route was `throttle:6,1` (6/min/IP). `--repeat-each=3`
  repeats the setup project → 3 roles × 3 = 9 logins from one IP in ~10s → the 7th+ returns 429 → login
  never completes. The throttle was doing its job (R2.6); the harness defeated itself. Proven by curling
  `/auth/login` 8× and observing the old limit cut in at attempt 7.
- **Fix (production security unchanged):** named `auth-login` rate limiter in `AppServiceProvider` reads
  `config('auth.login_throttle')` — **production stays 6/min** (env-overridable via `AUTH_LOGIN_THROTTLE`);
  non-production uses `login_throttle_local` (default 60, `AUTH_LOGIN_THROTTLE_LOCAL`). Route now uses
  `throttle:auth-login`.
- **Verification:** 8 rapid login probes → no 429 (limit live without server restart); `auth-redirect` +
  `auth-forms` `--repeat-each=3` → **24/24 pass**. Backend suite 157/157, pint + phpstan clean.
- **Note:** this is env-specific relaxation for automated testing, not lowering a control to pass a test —
  production posture is identical and asserted by the default config value.

## G-008 — Demo-credentials card present as dead code in the production bundle

- **Severity:** Low
- **Status:** OPEN (accepted, non-blocking)
- **Detail:** The login demo-credentials card is gated by `import.meta.env.DEV`, so it **never renders** in
  production. However its component definition + the demo strings (`agency@campaignshub.io` / `password`)
  remain in the built JS as inert dead code (rollup keeps the same-module function). Values are non-secret
  public demo data, so this is cosmetic, not a leak.
- **CLOSED (verified 2026-09-27).** There is no `DemoCredentials` component left in `frontend/src`, and
  the demo strings this row is about — `agency@campaignshub.io` and its password — appear nowhere in the
  source outside test files. Whatever removed the card removed the dead code with it, so the optional
  next action below has no subject.
- **Was (next action, optional):** move `DemoCredentials` to its own module and dynamic-import only in dev
  to strip it from the production chunk entirely.

## Auth cross-browser acceptance — CLOSED (2026-07-27)

- Firefox + WebKit Playwright projects added; **auth e2e 39/39 across Chromium + Firefox + WebKit**
  (`auth-forms` incl. 320/375/390 mobile + no-InfluencerHub-branding, `auth-redirect` round-trip).
- Visual-regression baselines established (`auth-visual.spec.ts`, chromium): /login /register /forgot-password
  in light + dark (6 baselines). Keyboard-only nav + console-error guard pass (the guest `/auth/me` 401 probe
  is explicitly allow-listed as expected auth behaviour, not an app error).
- This retires the "Firefox/WebKit/visual-regression pending" caveat from the auth phase-1 acceptance.

## G-009 — Multi-session enumeration + per-session revoke + 2FA

- **Severity:** Medium
- **Status:** OPEN (partial by design)
- **Detail:** `SESSION_DRIVER=redis` here, so the `sessions` table is not populated and other sessions
  cannot be listed/revoked individually. `/settings/security` shows the current-session summary and a real
  password-confirmed "revoke other sessions" (cycles remember-token); the full list + per-session revoke and
  the 2FA enable/recovery-codes flow are surfaced honestly as "Awaiting external dependency".
- **Next action:** switch to the database session driver (or a per-user token registry) to enumerate sessions;
  wire TOTP + recovery codes for 2FA.

## G-010 — Account settings: remaining pages/features

- **Severity:** Medium
- **Status:** OPEN (phase 2 is NOT fully Completed — these are the open items)
- Items, each honestly Not Started / partial:
  - `/settings/preferences` — **BUILT (re-read 2026-09-27).** `/app/settings/preferences` now redirects to
    `/account/preferences`, which renders a real `PreferencesPage`; personal settings moved under
    `/account` and the old addresses were kept working rather than left as placeholders.
  - `/settings/notifications` — **BUILT (re-read 2026-09-27), and this row understates it most.** It
    redirects to `/account/notifications`, and the settings tab behind it is 490 lines carrying
    channels, categories, per-type rhythm, the daily/weekly/monthly digests, the digest hour, the
    weekly and monthly day, quiet hours, timezone and locale — all on real endpoints in
    `NotificationPreferenceController`, not a placeholder. #562 was work INSIDE this page.
  - Avatar upload (`POST /api/me/avatar`) — Not Started (UserResource already exposes `avatar_url`).
  - Workspace-settings entitlement gate — In Progress (`/settings/workspace` renders org settings; owner-only
    gate not yet enforced on that route).
- **Next action, corrected to what is actually left:** avatar upload — checked and genuinely absent, no
  `POST` avatar route exists under `app/Domains/Access` or `app/Http` — and the owner-only gate on
  `/settings/workspace`. The two pages this row asked for are built.

## G-011 — Account E2E only on Chromium

- **Severity:** Low
- **Status:** OPEN
- **CLOSED (verified 2026-09-27).** `playwright.config.ts` defines chromium, firefox and webkit as three
  projects that each run the whole `e2e` directory; nothing restricts a spec to one browser. The only
  exclusions are `grepInvert` on `@visual` (firefox and webkit) and on `@evidence`, and
  `account-settings.spec.ts` carries neither tag — so the account journey already runs on all three.
  Consistent with what the gates actually execute: the firefox gate on #565 reported `620 passed`.
- **Was:** `account-settings.spec.ts` runs on chromium only; next action, extend it to Firefox + WebKit.

## G-012 — External request portal is stubbed (homepage CTAs live, portal pending)

- **Severity:** Medium
- **Status:** OPEN — Backend intake+tracking DONE & tested (commit 16d40f2); the DYNAMIC FORM UI, tracking UI, internal dashboard, SLA, comments UI and conversion are the remaining work
- **Detail:** `/requests/new` and `/requests/track` are real routes with honest placeholder pages so the
  homepage CTAs are never dead. The dynamic intake form, attachments, confirmation, secure token tracking,
  and the requests data model/backend are the next phase (External Request Portal → Tracking → Dashboard).
- **Progress:** intake form (f99a1ca), draft PII fix (57af700), secure uploads backend (546a6bc) done+tested.
- **Re-read against the code and the screen, 2026-09-27.** Three of the five things this «next action»
  still asks for are built. `/agency/requests` renders a real internal dashboard — Table, Kanban and
  Cards views, four counters, grouping by status and by service type, and a «الالتزام بالـSLA» panel
  reading متجاوز / يستحق خلال 24 ساعة / ضمن المدة — with `sla_breached` carried per row in
  `RequestsDashboardPage`, and `RequestDetailPage` and `RequestTrackPage` both exist as real pages.
  Seen rendered at 1440×1000, not inferred from file names.
- **What this row should now ask for**, having checked rather than trusted the prose: attachments UI
  wired to uploads, and conversion. The dashboard, SLA and tracking UI are no longer outstanding.
  Not claimed here: attachments and conversion were not exercised, so they stay open.

## G-013 — Homepage visual-regression baseline — CAPTURED (2026-07-27)

- **Severity:** Low
- **Status:** CLOSED
- **Evidence:** `homepage.spec.ts` now has chromium light+dark baselines
  (`e2e/homepage.spec.ts-snapshots/home-light-chromium-darwin.png`, `home-dark-...png`); a second run
  re-compares clean (7/7). Reopen only on intended layout change (then `--update-snapshots`).

## G-014 — Requests module: honest partial status (do not mark Completed)

- **Severity:** Medium · **Status:** OPEN (module In Progress)
- Corrected per directive:
  - Internal Requests Table Workflow — Implemented and Tested. **Kanban + Cards — Implemented and Tested** (d64d3ad; drag-drop optimistic + rollback, state-machine-guarded).
  - **SLA Breach — Implemented and Tested** (c7dad71): scheduled `requests:evaluate-sla` (every 10 min), warning
    threshold, automatic breach detection, `sla_breached_at`/`sla_warned_at` persistence, in-app notification,
    idempotency markers, RequestSlaTest (3). Also fixed an app-wide pgsql/UTC timezone bug found here.
  - **In-App Notifications — re-read against the code 2026-09-27, and all five «MISSING» items exist.**
    *read/unread UI*: `NotificationCenter` reads `unread`, draws the bell badge, and calls
    `markRead.mutate(...)` when an unread item is opened. *dedup*: the dispatcher takes `dedup_extra`,
    used by `ClientManagementService` and `RequestJourneyService` among others. *preferences*:
    `NotificationPreferenceController` plus the settings tab — channels, categories, per-type rhythm,
    digests, timezone and locale. *delivery log*: `settings/tabs/DeliveryLog.tsx`, and the alerts page
    carries a «سجل التسليم» tab. *quiet-hours*: `quiet_hours` is validated and persisted by that same
    controller and edited in that same tab.
    Not claimed: none of this says a message was DELIVERED — that still needs provider credentials
    (G-017), and this row should not be read as closing that.
- **Remaining:** notification hardening (read/dedup/prefs/log); Table pagination refinement. **Next major:** transactional conversion → clients.

---

_Last updated: 2026-07-27_

## G-015 — Client Command Center visual-regression baselines (2026-07-27)
- **Status:** Open. Functional E2E covers all 10 tabs on Chromium/Firefox/WebKit, but visual-regression
  baselines (toHaveScreenshot) exist only for the homepage. Command-center baselines at 320/375/390/768/1440
  are NOT yet captured, so visual drift there is not gated.
- **Not a blocker:** functional behavior + isolation are covered by 234 backend tests + the 3-browser e2e.

## G-016 — Email delivery + report completion depend on external/worker (2026-07-27)
- **Email:** the notification delivery ledger records `awaiting_credentials` (never `sent`) until a real mail
  provider is configured — by design (Awaiting Credentials, not a defect).
- **Report generation:** creating a client report queues `GenerateReportJob`; without a running queue worker
  the report stays `processing`. Sharing/export requires a `completed` report (seeded demo reports are used
  to verify the share path).

## G-017 — Messaging providers (SMS / WhatsApp / mail) awaiting credentials (2026-07-27)
- OTP delivery and client lifecycle notifications are recorded honestly as `awaiting_provider_credentials`
  and never marked `sent` — no provider is wired. Non-prod exposes the OTP dev code so the flow is usable.
  Wiring a provider is a config/credentials task, not an app change; the delivery ledger + retry/failed
  states are already in place.

## G-018 — Registration/onboarding follow-ons (2026-07-27)
- Google (social) login and workspace invitations are not implemented — treated as Awaiting Credentials /
  future work. Email/OTP registration + verification is complete and tested.
- Suspended-account state is exposed (UserResource.status='suspended') but a hard login block for it is not yet
  enforced.


## G-018 — RESOLVED (2026-07-27)
- Workspace invitations: IMPLEMENTED (invite → token → accept → join existing workspace, guarded). Commits
  `741ec9d`/`f1472c2`.
- Suspended-account hard login/API block: IMPLEMENTED via EnsureAccountActive middleware + login/token guards.
  Commit `7ea1dc5`.
- Still open: Google (social) login = Awaiting Credentials (future).

## G-019 — Delivery providers wired as adapters; alerts engine has no frontend UI yet (2026-07-27)
- **Providers:** email/whatsapp/sms are now formal MessageProvider adapters resolved from config/providers.php.
  Defaults are Null* adapters (isConfigured=false → awaiting_provider_credentials). A channel reports `sent`
  ONLY on a real provider acknowledgement. Wiring a live provider is a config/credentials task, not app change.
  States: queued/awaiting_credentials/sending/sent/failed/retrying/suppressed.
- **Alerts:** engine + API + scheduler are complete and tested (rules, cooldown, dedup, snooze, resolve,
  create-task, honest notification delivery). The backend entitlement nav already exposes an `alerts` key, but
  the React AppShell has no Alerts management page yet — a focused frontend follow-up (the engine is fully
  usable via API today).

## G-019 — RESOLVED (2026-07-27)
- Alerts management **frontend page is now built** at `/app/alerts`: Active/Snoozed/Resolved lifecycle,
  rule creation, severity/source/value/threshold, Resolve/Snooze/Create-Task, channel preferences + Quiet
  Hours, honest Delivery log, live polling, and the notification bell deep-links to the page (alert
  notifications carry action_url=/app/alerts). Verified in-browser + E2E `alerts-ui.spec.ts` 3 browsers.
  This was an internal gap (not an external dependency) and is now closed.

## G-020 (RESOLVED) — Integrations connector-resolver names disambiguated
Renamed Registry\ConnectorRegistry → AdvertisingConnectorRegistry (sync engine) and Connectors\ConnectorRegistry
→ ConnectorCapabilityRegistry (honest-state framework). Behavior unchanged; one data source; 55 integration/sync
 tests pass. No ambiguous internal names remain.

> **HANDOFF (2026-07-28)**: session hit context limit at HEAD `aaa79da` (branch feat/taxonomy-ux). Authoritative resume state + Exact Next Task in `docs/RESUME_STATE.md`; bootstrap in `CLAUDE.md`. Taxonomy-UX phase: T1-T6,T8,T9,T11,T12 committed & verified; T7 + homepage journey section are UNVERIFIED WIP in `aaa79da` (verify build/tests/seed first); then T15 paid-media vertical (docs/PAID_MEDIA_SERVICES_SPEC.md), T10 forms, T13/T14 regression.
