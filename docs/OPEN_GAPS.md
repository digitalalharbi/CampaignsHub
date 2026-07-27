# CampaignsHub — Open Gaps

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
- **Status:** OPEN (observed 2026-07-27 during G-005 testing)
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
  production. However its component definition + the demo strings (`owner@demo-agency.local` / `password`)
  remain in the built JS as inert dead code (rollup keeps the same-module function). Values are non-secret
  public demo data, so this is cosmetic, not a leak.
- **Next action (optional):** move `DemoCredentials` to its own module and dynamic-import only in dev to strip
  it from the production chunk entirely.

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
  - `/settings/preferences` — Not Started (route is a placeholder; overlaps profile locale/theme/number-format).
  - `/settings/notifications` — Not Started (route is a placeholder; needs channel + per-type prefs backend).
  - Avatar upload (`POST /api/me/avatar`) — Not Started (UserResource already exposes `avatar_url`).
  - Workspace-settings entitlement gate — In Progress (`/settings/workspace` renders org settings; owner-only
    gate not yet enforced on that route).
- **Next action:** build preferences + notifications pages on the real endpoints; add avatar upload; gate
  workspace settings by role/permission.

## G-011 — Account E2E only on Chromium

- **Severity:** Low
- **Status:** OPEN
- **Detail:** `account-settings.spec.ts` runs on chromium only; auth specs already run on Firefox + WebKit.
- **Next action:** extend the account journey run to Firefox + WebKit projects.

## G-012 — External request portal is stubbed (homepage CTAs live, portal pending)

- **Severity:** Medium
- **Status:** OPEN — Backend intake+tracking DONE & tested (commit 16d40f2); the DYNAMIC FORM UI, tracking UI, internal dashboard, SLA, comments UI and conversion are the remaining work
- **Detail:** `/requests/new` and `/requests/track` are real routes with honest placeholder pages so the
  homepage CTAs are never dead. The dynamic intake form, attachments, confirmation, secure token tracking,
  and the requests data model/backend are the next phase (External Request Portal → Tracking → Dashboard).
- **Progress:** intake form (f99a1ca), draft PII fix (57af700), secure uploads backend (546a6bc) done+tested.
- **Next action:** attachments UI wired to uploads, real tracking UI, internal dashboard, SLA, conversion.

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
  - **In-App Notifications — PARTIAL (still).** Present: AppNotification rows per event (unread status, action_url
    deep link, tenant-level fallback). MISSING: read/unread UI, dedup, preferences, delivery log, quiet-hours.
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

