# RESUME STATE — CampaignsHub (silent autonomous execution)

Precise state for automatic continuation. Update after each merge.

## Repo
- Branch: `feat/three-experiences` (expansion). Baseline tag: `v1.0.0-baseline` (stable, delivered).
- Stable ZIP: `~/Desktop/CampaignsHub-Final-Delivery.zip` (6,240,127 bytes) SHA-256
  `b8329cf4d6ba63ab77a571e75c2305f1e1e764921be5667f3149dbb42bdd708b`.
- Scripts (scratchpad): `clean_install_rehearsal.sh`, `smoke.mjs`, `package.sh`, `package_expanded.sh`.

## Delivered stable baseline (do NOT redo)
Backend 294, frontend 22 vitest, E2E 152/0/0/0 (Chromium/Firefox/WebKit), clean-install verified, packaged+SHA-256, tagged.

## Expansion — committed & tested
- Backend domains (all phpstan clean, integrated; full backend suite 351 passed at commit 22b8835):
  Billing daf9c8e · Messaging 12d6dbd · Branding ee0322c · Request Journey fb1547d · Connection Center e4dced5 ·
  Client-portal endpoints 1815dd6 · Drive 00f655c. Route wiring in routes/api.php: billing, messaging, branding,
  connections, drive.
- Frontend: Client Service Portal e20155b (dashboard/quotes/invoices/messages/profile/journey; files/campaigns/
  reports are honest "not yet available" pending backend). Build clean, 37 vitest, browser-verified.

## In-flight (3 agents; integrate their commits when they land)
1. Backend: Subscriptions+Plans+UsageLimits (app/Domains/Subscriptions, routes/api/subscriptions.php UNWIRED) +
   Client-portal Files/Campaigns/Reports endpoints (ClientPortalController + routes/api/requests.php).
2. Frontend internal UIs: src/features/billing, messaging, requestJourney — each exports `<feature>Routes.tsx`
   (UNWIRED into router).
3. Frontend internal UIs: src/features/branding, connections, drive — each exports `<feature>Routes.tsx` (UNWIRED).

## Integration steps remaining (do in order; regress after each)
1. Wire `routes/api/subscriptions.php` into routes/api.php; migrate; seed plans; `php artisan test` (expect ≥351+new, 0 fail).
2. Import each frontend `<feature>Routes.tsx` into `src/app/router.tsx` under the authed AppShell children; add
   nav entries in `src/layouts/AppShell.tsx` (Operations Console = full menu; SaaS Workspace = simplified,
   entitlement-gated by account workspace_kind/plan). `npm run build` (0 TS errors) + `npx vitest run`.
3. Operations Console surface: internal full menu — Tenants, Clients, Requests, Projects, Campaigns, Analytics,
   Reports, Payments, Subscriptions, Integrations/Connections, Branding, Messages, Alerts, Team, Audit, Platform Settings.
4. SaaS Workspace surface: subscriber simplified menu — Dashboard, Projects, Campaigns, Analytics, Reports,
   Connections, Alerts, Team(by plan), Billing, Branding, Settings — with tenant isolation, plan limits, module
   entitlements, white-label, usage tracking, subscription status.
5. Client portal Files/Campaigns/Reports pages → real data from the new client endpoints (replace ComingSoon).
6. Expanded E2E specs (billing/messaging/journey/branding/connections/drive/subscriptions/portal) → run 3 browsers
   (CI=1), retries=0, target 0 failed/0 flaky/0 skipped. Backend runs via playwright webServer (workers=4 +
   queue:work --queue=reports,default); E2E_RELAX_RATE_LIMITS=true in local .env only.
7. Full backend + frontend regression.
8. Expanded clean install: `bash scratchpad/clean_install_rehearsal.sh` (fresh worktree, dedicated DB, seed,
   build, backend tests, smoke, E2E) → 0 failed.
9. `bash scratchpad/package_expanded.sh` → CampaignsHub-Expanded-Delivery.zip + extract-verify + SHA-256.
10. Final audit: no placeholders/dead buttons; permissions + tenant/client/project isolation; console/network clean;
    RTL/LTR + light/dark + mobile; update MASTER_REQUIREMENTS/IMPLEMENTATION_MATRIX/OPEN_GAPS; tree clean.

## Run / demo
Backend `:8000` (E2E_RELAX_RATE_LIMITS=true local), queue worker `--queue=reports,default`, scheduler; frontend `:5173`.
Demo: owner@demo-agency.local / analyst@… / viewer@… — password `password`.

## Honest external deps (Awaiting External Dependency; never claim real)
Email/WhatsApp/SMS/Google OAuth/Payment gateway/Ad platforms + Google Drive — Null/Sandbox adapters delivered.

## CONSOLIDATION PASS (after in-flight completion agent commits) — per docs/CANONICAL_MODULES.md
1. AppShell operationalNav → canonical only: dashboard, requests, clients, projects, campaigns, analytics,
   reports, alerts, messaging, billing(المالية), integrations(→/app/integrations); add subscriptions(الاشتراك)
   for SaaS via ent. Remove nav items: connections_center, drive, branding (dupes). Relabel billing=المالية,
   subscriptions=الاشتراك.
2. Router: /app/integrations = ConnectionCenterPage (canonical). Add <Navigate> redirects: /integrations,
   /app/connections, /app/drive → /app/integrations; /app/branding → /settings/branding. Add /settings/branding
   = BrandingCenterPage under SettingsLayout children.
3. Entitlements (AccountEntitlements): PERSONAL_NAV = dashboard,requests,clients,projects,campaigns,analytics,
   reports,alerts,messaging,billing,connections,team,settings (drop subscriptions,branding,drive,messaging?keep).
   COMPANY_NAV = dashboard,projects,campaigns,analytics,reports,connections,alerts,team,subscriptions,settings
   (drop billing,branding). Update RegistrationOnboardingTest accordingly.
4. Rebuild + vitest + backend test; browser-verify the 3 menus; then expanded E2E, clean install, ZIP, audit.

## PHASE: Taxonomy & UX (branch feat/taxonomy-ux, off v1.1.0-expanded-final — do NOT touch tag/packages)
Goal: central Taxonomy & Option engine + unified searchable/manageable form controls + adopt across
requests/clients/campaigns (dependent selects) + Integrations redesign (tabs/grid/drawer, full width, Drive under
Files) + homepage shorten/rebalance + forms (steppers/draft/validation) + safe migration (no data loss) + E2E 3
browsers/RTL/LTR/light/dark/mobile. Specs: docs/OPTION_MANAGEMENT_SPEC, CLASSIFICATION_MATRIX, FORM_CONTROLS_AUDIT,
UX_SYSTEM_AUDIT (committed 1980ee2). Tasks #39–#42.
In flight: (a) backend Taxonomy engine — app/Domains/Taxonomy/**, migration, routes/api/taxonomy.php (UNWIRED),
taxonomies.*/options.* perms, TaxonomyEngineSeeder, tests. (b) frontend form controls — src/components/forms/** +
src/features/taxonomy/taxonomyApi.ts (UNWIRED into pages/router).
Next after both land: wire routes/api/taxonomy.php; build Settings→Taxonomies&Options page (Option Manager) +
route; adopt controls in requests/clients/campaigns/projects/onboarding/alerts (replace ~19 hardcoded lists) with
dependent selects + objective-driven KPIs; Integrations redesign; homepage redesign; safe value migration; full
regression (backend/frontend/E2E) + clean tree. Preview stays running (scripts/dev-up.sh).
