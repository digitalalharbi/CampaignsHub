# LIVE DASHBOARDS & DEMO ACCESS — CampaignsHub

Deterministic demo accounts seeded by `DemoAccountsSeeder` (local/testing/demo only — never production).
All staff passwords: `password`. Bring the environment up with `scripts/dev-up.sh`.

Preview: **http://localhost:5173** · Dev status: **http://localhost:5173/dev/status** · Backend: **http://127.0.0.1:8000**

| # | Experience | URL | Login | Auth | Role | Workspace | Plan | Pages |
|---|---|---|---|---|---|---|---|---|
| 1 | Operations Console (internal) | `/login` → `/dashboard` | agency@campaignshub.io | password | owner | personal (agency) | trial | Dashboard, Requests, Clients, Projects, Campaigns, Analytics, Reports, Integrations, Alerts, Messages, Finance, Team, Settings(+Branding) |
| 2 | Operations Console (analyst) | `/login` → `/dashboard` | analyst@demo-agency.local | password | analyst | personal (agency) | trial | Dashboard, Projects, Campaigns, Analytics, Reports (read-oriented) |
| 3 | SaaS Workspace (company owner) | `/login` → `/dashboard` | advertiser@campaignshub.io | password | owner | company (self_serve_company) | growth | Dashboard, Projects, Campaigns, Analytics, Reports, Integrations, Alerts, Subscription, Team, Settings |
| 4 | SaaS Workspace (team member) | `/login` → `/dashboard` | member@demo-company.local | password | member | company | growth | same SaaS menu (read subset) |
| 5 | Client Service Portal | `/client/login` → `/client` | customer@demo-client.local / +966500000009 | OTP dev code (non-prod) or header `X-Client-Token: demo-client-portal-token` | client contact | client (Demo Client) | — | Home, Requests, Invoices, Messages, Files, Campaigns, Reports, Profile |
| 6 | Public Website | `/` | — | public | — | — | — | Homepage, `/login`, `/register`, `/requests/new`, `/requests/track` |
| 7 | Dev Status | `/dev/status` | — | dev-only | — | — | — | Live service health (blocked in production) |

## Menu differences (verified)
- **Operations Console** (personal nav): full agency menu incl. Clients, Requests, Finance (المالية), Messages.
- **SaaS Workspace** (company nav): subscriber menu with Subscription (الاشتراك) instead of Finance; NO Clients/
  Requests/Messages inbox.
- **Client Portal**: own nav (Requests, Invoices, Messages, Files, Campaigns, Reports, Profile).

## Client Portal demo data (customer@demo-client.local)
One in-progress request (REQ-DEMO-CLIENT-0001), a sent quote + an approved quote whose issued invoice is pending
(pay → honest `awaiting_provider_credentials`, no fake receipt), a 3-message thread, a request file + a Drive
file reference, a linked campaign with metrics, and a client-audience report with an active share.

## Honest states
Email/WhatsApp/SMS, Google OAuth, payment gateway, and ad-platform/Drive live sync are **Awaiting External
Dependency** — Null/Sandbox adapters deliver the flows without claiming real delivery.

## Client Portal OTP (local review)
On `/client/login`, after "Send code", a **DEV OTP** banner + **Copy** button appears in local/dev only — the
backend returns `dev_code` exclusively in non-production (`ContactVerificationService::exposeDevSecrets()` returns
false in production), so the OTP and dev token are fully hidden in Production. No manual `X-Client-Token` needed.
