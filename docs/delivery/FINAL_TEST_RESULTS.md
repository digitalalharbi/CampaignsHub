# Final Test Results — CampaignsHub (stable release)

Certified on the delivered commit. All root causes fixed at the source; no retry-masking (E2E `retries=0`).

## Backend (PHPUnit / Pest)
```
Tests:    294 passed (1225 assertions)
Failed:   0
```
Static analysis: `phpstan` — 0 errors. Style: `pint` — clean.

## Frontend unit (Vitest)
```
Test Files  6 passed
Tests       22 passed
Failed:     0
```

## End-to-end (Playwright — Chromium / Firefox / WebKit)
```
Total:    152 passed
Failed:   0
Flaky:    0
Skipped:  0
Retries:  0
Chromium: Passed
Firefox:  Passed
WebKit:   Passed
```
Notes:
- Visual-baseline specs are chromium-only by design and are not scheduled on Firefox/WebKit (project-level
  `grepInvert:/@visual/`), so they never appear as spurious skips. They DO run on Chromium.
- The E2E stack is self-contained: Playwright starts a concurrent backend (`PHP_CLI_SERVER_WORKERS=4
  --no-reload`) + a queue worker (`--queue=reports,default`) + the Vite dev server.

## Root causes fixed (not masked)
1. **429 under single-IP load** — per-IP inline throttles are correct for real traffic but spurious for the
   whole suite from 127.0.0.1. `ConditionalThrottle` enforces limits in production/staging/testing and relaxes
   ONLY in `local` with the explicit `E2E_RELAX_RATE_LIMITS=true`. Locked by `RateLimitProtectionTest`.
2. **Invitation accept (webkit)** — used the user returned by the accept response instead of a second
   `/auth/me` racing the regenerated session cookie; primes CSRF; submit no longer depends on a stale guard.
3. **PDF/XLSX export** — report generation runs on the `reports` queue; the E2E worker now drains it.
4. **Stale selectors** — campaigns page gained H3 summary cards; pinned to the H1 and the card testid.

## Acceptance flow (verified)
Suspended blocked → session revoked → invitation accepted → correct modules → forbidden module denied →
scheduled report created → generated → delivery logged honestly → alert triggered → notification received →
alerts UI (create/resolve/snooze/create-task) → refresh & persistence. PWA installable + offline shell verified.
