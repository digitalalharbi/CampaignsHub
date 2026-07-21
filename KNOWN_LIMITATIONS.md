# Known Limitations

An honest list of what is incomplete or constrained as of 2026-07-21. Nothing here is hidden.

## Scope not yet implemented
- **Phases 4–10** of the brief are not built: Media Planning, Content & Approvals, Campaign Launch
  workflow, Tracking/Attribution (GA4/GTM/pixels/CAPI), Salla/Zid, live ad-platform sync,
  Analytics/Reports engine, Optimization rules + AI insights, Automation builder, AI gateway/MCP,
  Billing (Tap/Moyasar), Client Portal, Proposals/Contracts/Onboarding UI, Reverb realtime.
- **CRM**: only the Leads vertical has a UI. Companies, Contacts, Opportunities (kanban), Pipelines
  management, Proposals, Onboarding, and the unified-timeline UI are backend-partial or not built.

## Integrations
- All advertising/ecommerce/payment connectors are **`Awaiting Credentials`** — interfaces + sandbox
  + stubs only. No real external call has been made or tested with live credentials.
- Webhook handlers, incremental sync, backfill, reconciliation jobs, and token-refresh scheduling
  are **not** implemented yet (planned with each live connector).

## Auth / security
- Email is a **global** unique login id (not tenant-scoped). Intentional; revisit if multi-brand
  users must share an email across tenants.
- 2FA, email verification enforcement, device/session management UI, and impersonation are not built.
- No rate-limit tuning beyond login/token throttles; no automated dependency scanning wired yet.

## Frontend
- Automated test coverage is minimal (one store test). No Playwright E2E, visual-regression, or
  accessibility-automation suites yet. Accessibility was followed by construction (focus states,
  aria, RTL, tokens) but not machine-verified.
- Design-system components still to add: Drawer, Toast provider, DatePicker/DateRangePicker,
  Tooltip, CommandPalette (⌘K), FileUploader, FilterBar, mobile card fallback for tables.
- Auth is cookie-session; on API errors there is no global 401 interceptor→logout yet.

## Infrastructure
- **Docker is not installed on the build machine**, so `docker-compose.yml` and the Dockerfiles are
  authored but **not run/verified locally**. Validate before relying on them.
- No CDN/queue-worker/Horizon/Reverb processes are running locally; Horizon/Reverb are configured in
  intent but not exercised.
- CI workflow is authored (`.github/workflows/ci.yml`) but has not run on a real GitHub runner.

## Data
- Seed data (demo tenant, leads) is for local/testing only and is environment-gated.
- No Materialized Views, partitioning, or pgvector yet (planned for analytics/AI phases).
