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

---

_Last updated: 2026-07-27_
