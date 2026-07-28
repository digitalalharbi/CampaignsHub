# Final Test Results — CampaignsHub (expanded delivery)

Branch `feat/three-experiences`. All root causes fixed at source; E2E `retries=0`.

## Backend (PHPUnit / Pest)
```
Tests:  371 passed (1813 assertions)
Failed: 0
```
`phpstan` — 0 errors. `pint` — clean.

## Frontend unit (Vitest)
```
Test Files 23 passed
Tests      112 passed
Failed:    0
```

## End-to-end (Playwright — Chromium / Firefox / WebKit)
```
Total:   188 passed
Failed:  0
Flaky:   0
Skipped: 0
Retries: 0
Chromium/Firefox/WebKit: Passed
```
Includes the expansion surfaces + the consolidation redirect assertions.

## Clean install (fresh worktree, dedicated DB, full lifecycle)
```
composer install → env → migrate → seed (incl. DemoAccountsSeeder) → npm ci → build →
backend 371 passed → smoke 8/8 → E2E 188 passed → REHEARSAL COMPLETE
```

## Stable baseline (unchanged)
Backend 294, Vitest 22, E2E 152/0/0/0; tagged `v1.0.0-baseline`; ZIP SHA-256
`b8329cf4d6ba63ab77a571e75c2305f1e1e764921be5667f3149dbb42bdd708b`.

## Consolidation (one module/name/route/engine)
Integrations canonical at `/app/integrations` (absorbs Connection Center + Drive connector); Branding inside
Settings; Finance one backend surfaced as المالية / الاشتراك / الفواتير; legacy routes redirect; connector
registries disambiguated (AdvertisingConnectorRegistry vs ConnectorCapabilityRegistry) — see DUPLICATION_AUDIT.
