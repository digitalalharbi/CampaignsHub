# FINAL ACCEPTANCE CHECKLIST — CampaignsHub

Binding gates. An item is checked only with real, tested evidence (no `Completed` without a test).

## Stable release (baseline)
- [ ] Backend tests: Failed = 0
- [ ] Frontend unit tests: Failed = 0
- [ ] E2E: Failed = 0, Flaky = 0, retries = 0
- [ ] Skipped = external dependencies only (0 spurious)
- [ ] Chromium / Firefox / WebKit: all passed
- [ ] Clean install from fresh worktree/clone passed
- [ ] Final ZIP extracts + verifies (files + checksums)
- [ ] Working tree clean; no secrets / runtime artifacts / node_modules / vendor / .env
- [ ] SHA-256 generated
- [ ] Documentation complete

## Per-experience acceptance (expansion)
### Client Service Portal
- [ ] Client Login → Complete Request → Approve Quote → Pay Invoice → Upload Files → Receive Notifications → Track Execution → View Campaign → Download Report

### SaaS Workspace (subscribers)
- [ ] Subscriber Registration → Choose Plan → Create Workspace → Connect Advertising Platform → Sync Real Data (Sandbox) → View Campaigns → Receive Alert → Generate Report

### Operations Console (internal)
- [ ] Admin → Manage Tenants → Manage Requests → Manage Payments → Manage Branding → Manage Integrations → Audit All Operations

## Cross-cutting (every module)
- [ ] Tenant isolation · Client isolation · Project isolation (fail-closed)
- [ ] Payment webhooks (idempotent; no `Paid` before verified webhook)
- [ ] File security · Google Drive permissions
- [ ] Connector failure states honest (Available/Awaiting/Sandbox/Production/Permission/Token/Sync-Failed)
- [ ] RTL/LTR · Light/Dark · Mobile · Chromium/Firefox/WebKit
- [ ] Loading/Empty/Error states · Console clean · Network clean
- [ ] No placeholder · No dead button · No fake data as real · No unverified integration claim
- [ ] No internal data leaked to clients · No retry masking · No flaky · No unexplained skips

## Definition of done (per unit)
Database · Backend · Frontend · Real API (or documented Awaiting External Dependency + Adapter/Sandbox) ·
Validation · Permissions · Isolation · Loading/Empty/Error · Responsive · RTL/LTR · Light/Dark · Accessibility ·
Feature tests · E2E · Live browser review · Console clean · Network clean · Documentation · Commit ·
Regression passed.
