# Regression audit — what the `/app/*` move changed, and what was never there

Run against `5266411` (the commit before the advertiser portal moved under `/app/*`) and the current
head. Method: diff the route table, diff the navigation, diff the settings surfaces, then sign in as
each account type and look.

## 1. Nothing was lost in the move itself

- **Routes**: 74 before, 90 now. Every path present before resolves now — either directly or through
  `legacyAppRedirects` / `legacyClientPortalRedirects`, which carry route parameters and query
  strings through. Verified by test (`legacyRedirects.test.tsx`, 22 cases) and live.
- **Navigation**: the advertiser rail has exactly the same fifteen entries, in the same order, with
  the same entitlement keys. Only the `to` values gained the `/app` prefix.
- **Settings sections**: all eight (`general`, `clients`, `projects`, `team`, `notifications`,
  `security`, `branding`, `disclaimer`) are still registered and still reachable.

Two real defects WERE found and fixed while auditing, both predating this pass:

| | Fix |
|---|---|
| `/app/clients` served a "this module is part of a later phase" placeholder, because a stub route was registered before the finished page for the same path | `98ddc18` |
| Fifteen pre-move root paths (`/integrations`, `/billing/invoices`, `/alerts`, …) were missing from the redirect list, so old bookmarks were dead links | `98ddc18` |

## 2. The real problem is not a regression — it is a layer that was never built

**The platform owner has nowhere to go.** `platform@campaignshub.io` has
`is_platform_admin = true`, `tenant_id = null` and no membership, so:

```
PortalResolver::landingPathFor()  →  '/onboarding'
```

The person who owns the system signs in and is asked to set up a workspace, like a brand-new
customer. There is no console for tenants, plans, payments, platform settings or audit.

The backend already assumes this layer exists — `ResolveMembership` puts a platform admin into
platform scope, `HasRoles` grants them every permission, `ClientScopeResolver` treats them as
unrestricted — but no route, layout or page was ever written for them to land in.

**Consequently, platform-owner functions ended up inside the ADVERTISER's workspace settings**, where
a tenant admin can reach them:

| Surface | Today | Belongs to |
|---|---|---|
| Public pages CMS (the marketing homepage) | `/app/settings/public-pages` | platform owner |
| Portal disclaimers / methodology notes | `/app/settings/portals` | platform owner |
| Taxonomy engine (definitions + options) | `/app/settings/taxonomies` | **both** — platform-shared rows (`tenant_id` null) are the owner's; tenant-private rows are the tenant's |

`PublicPageSetting` carries `BelongsToTenant`, so the marketing homepage is stored per tenant even
though there is one public homepage. Whichever tenant happens to be `is_default_portal` owns it by
accident.

`TaxonomyDefinition` already models the split correctly (`tenant_id` null = shared with every
tenant), so the engine does not need changing — only a platform-scoped surface to edit the shared
rows from, instead of editing them from inside one tenant's settings.

## 3. What this audit does NOT claim

The four existing portals were checked live — sign-in, rail, dashboard, links, permissions, data
isolation, back/refresh/direct-URL — on desktop and mobile 375, RTL and LTR, light and dark. E2E is
290/290 across chromium, firefox and webkit. That is the state the `/admin` work starts from, not a
claim that it is finished: `/admin` itself is unbuilt, and the menu simplification the structure calls
for has not been applied to any portal yet.
