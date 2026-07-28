# ROUTE REDIRECT MAP — legacy/duplicate → canonical

Old links keep working (no dead links, no lost bookmarks). Redirects live in the SPA router.

| Old / duplicate route | → Canonical route | Reason |
|---|---|---|
| `/integrations` | `/app/integrations` | one Integrations surface |
| `/app/connections` | `/app/integrations` | Connection Center folded into Integrations |
| `/app/drive` | `/app/integrations` | Google Drive is a connector inside Integrations |
| `/app/branding` | `/settings/branding` | Branding lives inside Settings |
| `/projects/:id/integrations` | (kept — project-scoped detail under Integrations) | not a duplicate |

Canonical additions:
- `/app/integrations` → the rich Connection Center page (connectors, capabilities, honest states, sync, history).
- `/app/files` → unified staff Files (request uploads + Drive files by reference).
- `/settings/branding` → Branding Center inside Settings.

Notes:
- Drive folder/file browsing is reachable within Integrations (connector detail) and Files; no standalone engine.
- Finance is ONE backend; surface differs: Ops `/app/billing` (المالية), SaaS `/app/subscriptions` (الاشتراك),
  Client `/client/invoices` (الفواتير).
