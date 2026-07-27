# CampaignsHub — Final Delivery Package

A multi-tenant media-buying platform (agencies, in-house teams, brands, self-serve companies) to run clients,
projects, ad accounts, campaigns, content, tracking, reporting, alerts, and client requests from one place.

## Stack
- **Backend:** Laravel 12 (PHP 8.4), DDD (`app/Domains/*`), PostgreSQL, Sanctum SPA cookie auth, fail-closed
  multi-tenancy via global scopes.
- **Frontend:** React 19 + TypeScript (strict) + Vite, TanStack Query, react-router, zustand. Arabic-first
  (RTL), self-hosted fonts, Latin digits. Installable PWA with an offline app shell.

## What ships (verified)
- **Tenancy & access:** fail-closed tenant + project isolation; roles/permissions; suspended/disabled hard
  enforcement; workspace invitations (secure expiring token → join existing workspace).
- **Accounts & onboarding:** one account model (account_type + enabled_modules + subscription_plan), resumable
  onboarding, entitlement-driven navigation (personal full menu vs company simplified), enforced at the API.
- **Clients & projects:** Client Command Center (classification, analytics, reports, team access, files,
  activity, settings, portfolio), external Client Request Portal (OTP-verified, honest delivery states).
- **Campaigns & metrics:** unified campaigns, external campaign import/linking, normalized daily metrics,
  aggregations (totals/provider/campaign/timeseries/funnel/budget pacing).
- **Reports:** generation engine + Chromium PDF/XLSX/CSV export, audience guard (internal never sent to
  client), **scheduled reports** (daily/weekly/monthly/custom, timezone) with an honest delivery ledger.
- **Notifications & delivery:** dedup, quiet hours, per-user/category preferences, delivery ledger; **channel
  provider adapters** (email/whatsapp/sms) — honest by construction (`sent` only on a real provider ack).
- **Alerts:** rule-driven engine (budget risk, no results, ROAS drop, sync failure, token expiry) with
  cooldown / dedup / snooze / resolve / create-task, raised through the shared notification pipeline.
- **PWA:** installable, offline shell, honest caching (API never cached), in-app update flow.

## Honest status (not defects)
- **Email / WhatsApp / SMS delivery = Awaiting Provider Credentials.** Adapters are in place; bind a
  configured provider in `config/providers.php` to go live. Nothing is ever logged `sent` without a provider.
- **Google (social) login = Awaiting Credentials.**
- **Alerts management UI (React page) = not yet built** — the engine + API are complete and tested
  (OPEN_GAPS G-019).

## Quality gates
- **Backend:** 290 tests passing (1220 assertions); `phpstan` clean (level in `phpstan.neon`); `pint` clean.
- **E2E (Playwright):** full suite on **Chromium / Firefox / WebKit** — see FULL_E2E_RESULT below.
- **Acceptance flow** (backend + browser): suspended blocked → session revoked → invitation accepted → correct
  modules visible → forbidden module denied → scheduled report created → generated → delivery logged honestly
  → alert triggered → notification received → refresh & persistence.

## Run it
See `docs/PRODUCTION_RUNBOOK.md` (web + `schedule:run` cron + `queue:work` worker; provider wiring; env; /up
health) and `docs/SECURITY_AUDIT.md`. Governance: `docs/PROGRESS.md`, `docs/OPEN_GAPS.md`.

## FULL_E2E_RESULT
Final full regression (Chromium / Firefox / WebKit, clean single backend, CI retries):
**144 passed, 0 failed, 2 flaky (passed on retry), 16 skipped** (~6 min).

- The 2 "flaky" are the write-heavy `workspace-invitation` spec, which does a real signup → auto-login →
  dashboard round-trip; it passes reliably in isolation and in a targeted 3-browser run (21/21), and passes
  on retry in the full suite. The single-threaded `php artisan serve` dev backend saturates under a 6-minute
  3-browser load and intermittently drops a write (the flake migrates browser run-to-run) — an environment
  limit, not an app defect. Use php-fpm / Octane in real environments (see PRODUCTION_RUNBOOK).
- Skips are visual-snapshot specs not run in this environment.

Backend: **290 passed (1220 assertions)**, `phpstan` clean, `pint` clean.
