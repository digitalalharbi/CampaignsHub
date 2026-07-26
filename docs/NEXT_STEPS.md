# CampaignsHub — Resume Pointer

_Single source of truth for "where we are" so work survives context/session loss._

## Current state (2026-07-26)
- **Branch:** `feat/premium-ui` (integration branch) in worktree `~/Developer/CampaignsHub-UI`.
- **Last commits:** `be1dc16` (disclaimers), `14ebde1` (premium charts), `95ab5f9` (secure links).
- **Gates green:** backend 111 tests, pint + phpstan clean, tsc + lint + build ok, vitest 18.
- **Worktrees:** UI `feat/premium-ui` (active), C3 `feat/metrics-c3`, Preview (detached @37aa464).
  Never run two agents on one worktree. Do not touch `Desktop/MediaBying System` (dirty main).

## Run / preview
```bash
# backend (restart before verifying new routes — :8010 caches routes/opcache)
cd ~/Developer/CampaignsHub-UI/backend && php artisan serve --host=0.0.0.0 --port=8010
# frontend
cd ~/Developer/CampaignsHub-UI/frontend && npm run dev   # http://localhost:5173
# demo data (deterministic, no rand, is_demo flagged)
php artisan demo:reset
```
Demo login: `owner@demo-agency.local` / `password` (see docs/DEMO_ACCOUNTS.md).

## Completed phases (verified)
Foundation · RBAC/Audit · Campaigns C1 · Metrics C3.2/C3.5 · Dashboard+Analytics · Reports
(builder, queued gen, PDF/XLSX/CSV) · Interactive slide reports · Secure client links ·
**Premium chart design system** · **Central disclaimer & methodology**.

## Remaining roadmap (ordered)
1. **Settings (integrated)** — General, Clients, Projects, Team & Permissions, Notifications,
   Security, Branding, Disclaimer (management UI; API already built).
2. **Alerts & Notifications** — rules engine, notification center, dedup/cooldown/quiet-hours, email.
3. **Scheduled Reports & Email** — scheduler + queue jobs + delivery log.
4. **Campaign Tasks & Approvals** — lightweight, campaign/report-scoped.
5. **Connection Center** + Custom Connector Builder (Direct/Aggregator/Sheets/CSV/Webhook/DB/MCP).
6. **C3.3 Jobs & Horizon** — sync/reports/notifications/emails queues, idempotent + locks + backoff.
7. **MCP server** (read-only default) + external MCP connections.
8. **PWA** — manifest, service worker, offline shell, install prompt, mobile nav.
9. **Cross-browser** (Chromium/Firefox/WebKit) + responsive matrix.
10. **Production readiness** — README, ERD, API docs, Postman, security + deploy guides, delivery ZIP.

## External blockers (Awaiting Credentials — build code+tests+docs, don't stop)
Real platform APIs (Meta/Google/TikTok/Snapchat/X/LinkedIn/Microsoft/Pinterest/GA4/Salla/Zid/
Shopify/WooCommerce) + 3rd-party (PMA/Windsor/Dataslayer/Supermetrics/Funnel). Leave
`Awaiting Credentials` / `Pending Platform Approval` / `Not Production Verified`.

## Standing rules (do not violate)
No `rand()` · no static data as final · no scraping / no browser-automation login · no `git add .` ·
separate commit per phase · don't lower/delete tests to pass · Reach is per-platform (never unique/merged) ·
AI recommendations need user approval before client sees them · Demo must be removable · keep preview live.
