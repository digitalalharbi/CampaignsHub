# Brand Guidelines — CampaignsHub

- **Name**: CampaignsHub (one word, capital C and H). Never a temporary/previous name in final UI.
- **Domain**: campaignshub.io. Subdomains: `app.` (application), `api.` (Laravel API),
  `docs.`, `status.`. Agencies may later get `agency-name.campaignshub.io` and custom domains.
- **Source of truth**: backend `config/brand.php` (env-driven) + `GET /api/v1/brand`; frontend
  `src/lib/brand.ts`. **Never hard-code the name** in components — import it.
- **Tagline**: "Run every client, project, and campaign from one place." / Arabic:
  "أدر كل عميل ومشروع وحملة من مكان واحد."
- **Colors/typography**: the design tokens (`docs/design-tokens.md`) — brand green, IBM Plex Sans
  Arabic / Plus Jakarta Sans / JetBrains Mono. Light + dark, RTL + LTR.
- **Applied surfaces**: page `<title>`, meta description, Open Graph, schema.org, PWA manifest,
  theme-color, login, marketing site, app shell, i18n `app_name`. (Emails/invoices/PDF/report share
  links will read from the same central config when those features are built.)
- **White-label default**: CampaignsHub; per client-workspace `branding` (logo, brand name, colors)
  can override in-app.
