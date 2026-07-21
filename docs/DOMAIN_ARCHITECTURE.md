# Domain & Subdomain Architecture

The app never binds business logic to a single domain, so white-label + custom domains work later.

| Host | Purpose | Status |
|---|---|---|
| `campaignshub.io` | Marketing site, register, plans, public content | marketing page built (`/welcome`) |
| `app.campaignshub.io` | Operational SPA | app built (served at SPA origin) |
| `api.campaignshub.io` | Laravel API (`/api/v1`) | built |
| `docs.campaignshub.io` | Public docs / API reference | planned |
| `status.campaignshub.io` | Service status | planned |
| `agency-name.campaignshub.io` | Per-agency subdomain | supported by design (tenant resolved from auth/session/domain) |
| `campaigns.client-domain.com` | Custom domain (Enterprise) | `client_workspaces.custom_domain` column exists; routing wiring pending |

**Isolation boundaries**: Platform → Tenant/Agency → Client Workspace → Projects → Users/Integrations/
Reports/AI. Tenant is resolved server-side (never trusted from the client) and enforced by the global
`TenantScope` (fail-closed). See `docs/tenancy` concepts in `CLAUDE.md`.

**Backend domains** (`app/Domains/*`): Identity, Tenancy, Access, Audit, CRM, Integrations,
ClientWorkspaces, Projects, AI, Notifications, Tasks. (Remaining brief domains — MediaPlanning,
Content, Approvals, Advertising sync, Tracking, Attribution, Ecommerce, Analytics, Optimization,
Automation, Reports, Billing, MCP — are planned; see `KNOWN_LIMITATIONS.md`.)
