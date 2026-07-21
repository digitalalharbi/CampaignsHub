# Demo Accounts & Data

Seeded by `DatabaseSeeder` in **local/testing only** (never production). All demo data is labelled
`Demo` / `Sandbox` / `Simulated`.

## Logins (password: `password`)
| Role | Email | Notes |
|---|---|---|
| Platform super-admin | `platform@mediabuying.local` | cross-tenant, `is_platform_admin` |
| Demo agency owner | `owner@demo-agency.local` | Tenant Owner, all permissions |

> Emails use the seed's original local domain; the brand is CampaignsHub. Rename in a later pass if
> desired — logins are data, not brand surfaces.

## Seeded data (Demo Agency tenant)
- **CRM leads** (5): Acme Co, Nova Retail, Zahra Store, Falcon Media, Bright Foods (varied
  status/source/value).
- **Client workspaces** (3, one per mode): Acme (Managed), Nova (Collaborative), Zahra (Self-Service).
- **Projects** (3): one "Q3 Launch — Demo" per workspace, `setup_completion=70`.
- **Task** (1): "Prepare tracking — Demo" (in_progress, high).
- **Notification** (1): simulated integration alert (warning).
- **AI key** (1): OpenAI, scope tenant, secret `sk-DEMO-SANDBOX-0000` (encrypted at rest, shown
  masked `••••0000`).
- **Advertising connectors**: Sandbox (connected) + 6 platform stubs (awaiting_credentials).

## Reset
`php artisan migrate:fresh --seed` rebuilds everything.
