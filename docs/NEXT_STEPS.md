# CampaignsHub — Resume Pointer

_Single source of truth for "where we are" so work survives context/session loss._

## Current state (2026-07-26)
- **Branch:** `feat/premium-ui` (integration branch) — reports-rebuild MERGED (`44613ca`).
- **Last commits:** `9328efa` (settings complete), `866e66b` (settings shell), `be1dc16` (disclaimers),
  `14ebde1` (premium charts).
- **Gates green:** backend **121 tests**, pint + phpstan clean, migrations reversible, tsc + lint +
  build ok, vitest 18.
- **NEXT PHASE (mandatory, separate clean worktree):** Reports/PDF rebuild — see "Reports rebuild" below.
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

## Status
**Reports: Completed** (Core + Hardening, merged to `feat/premium-ui` @ `3a071dc`). Hardening delivered: automated PDF visual-regression (`npm run test:pdf-visual` + committed baselines), persisted recommendation approval workflow (report_annotations, Draft→Reviewed→Approved, reviewed_by/approved_by, + approval panel UI), `client_display_name` DB fields (unified_campaigns/projects).

## Completed phases (verified)
Foundation · RBAC/Audit · Campaigns C1 · Metrics C3.2/C3.5 · Dashboard+Analytics · Reports
(builder, queued gen, PDF/XLSX/CSV) · Interactive slide reports · Secure client links ·
**Premium chart design system** · **Central disclaimer & methodology**.

## Reports rebuild (NEXT — do in a clean worktree `feat/reports-rebuild`, NOT mixed with settings)
Root cause list to close: Arabic reversed/disjointed in PDF, near-empty pages, huge whitespace,
repeated footers/notes, missing charts in PDF, big gap between interactive link and PDF quality,
contradictory data (results>0 with spend=revenue=0 must BLOCK export).
Plan (each its own step): Canonical Report Snapshot (single source for all formats, with checksum +
data_version in metadata) → ReportDataValidator/ConsistencyChecker/ExportReadinessGate (blocks export
on any inconsistency, never zero-fills) → replace Dompdf for creative reports with **headless
Chromium (Playwright) printing a signed `/reports/{id}/print` React route** (wait for fonts/charts/
images/`__REPORT_*_READY__`) → embed IBM Plex Sans Arabic + Inter, `dir`/`lang` + `<bdi>` for mixed
values, no manual reshaping → Presentation (16:9/A4 landscape, 1 slide/page) + Document (A4 portrait)
PDF types → ReportPrintLayoutEngine (no empty/footer-only pages, no split cards/charts) → redesigned
client link (less text, more charts, accordion/appendix) → balanced Notes|Recommendations two-column →
unified charts → export consistency (same renderer/snapshot) → XLSX sheets + CSV zip (UTF-8 BOM) →
visual-regression (PDF page→PNG vs baseline) + **manual page-by-page audit** → 3 Arabic sample PDFs
(weekly/monthly/comparison) + 1 English → Reports commit → merge after green → resume roadmap.

## Remaining roadmap (ordered, after Reports rebuild)
1. **Alerts & Notifications** — rules engine, notification center, dedup/cooldown/quiet-hours, email.
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
