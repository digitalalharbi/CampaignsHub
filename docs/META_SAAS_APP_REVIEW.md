# Meta SaaS app — configuration, isolation and App Review evidence (META-SAAS-APP-001)

CampaignsHub runs **three** Meta app profiles. None replaces another.

| Profile | Row / env | Issues customer connections | Purpose |
|---|---|---|---|
| `live` | `meta` / `META_APP_ID…` | yes — every connection made before this, and new ones by default | the production integration |
| `candidate` | `meta.candidate` / `META_CANDIDATE_*` | **no** (admin test round trip only) | proving a Facebook Login for Business setup; its Promote replaces Live and is **not** used for the SaaS app |
| `saas` | `meta.saas` / `META_SAAS_*` | yes — only once an admin chose it after a passed test, or when an operator names it for one connection | the SaaS app under App Review |

## Isolation guarantees (held by `MetaSaasAppIsolationTest`)

- The SaaS app's App ID, App Secret (encrypted), Configuration ID and scopes live in `provider_configurations.meta.saas`; nothing reads or writes the Live or Candidate rows.
- `provider_connections.app_profile` records the app that issued a connection (`NULL` = Live, unchanged for every existing row). Token exchange, refresh, sync calls and the OAuth callback all use **that** app (`PlatformCredentials::forConnection`).
- A SaaS login opens its **own** connection; a Live connection's token, scopes and accounts are never overwritten.
- New connections use the SaaS app only after `PUT /admin/settings/integrations/meta-saas/routing {default: saas}`, refused (409) unless the latest SaaS test passed with the credentials configured now; changing the credentials withdraws it automatically.
- Existing accounts move only explicitly: `POST /api/v1/accounts/{account}/move-to-connection {connection_id, confirm}` — allowed only when the target app's discovery found the account; the SAME account row moves (bindings and history keep their id), the old connection stays connected and keeps its token, and moving back is the same request.
- Rollback = choose `live` for new connections, and move any moved account back. No token is revoked anywhere.

## Owner steps (credentials — never in git, chat or logs)

1. developers.facebook.com → the new app → Facebook Login for Business → Valid OAuth Redirect URIs: `https://api.campaignshub.io/api/v1/oauth/ads/meta/callback` (same URL; the app travels in the signed state). App Domains: `campaignshub.io`, `api.campaignshub.io`.
2. Facebook Login for Business → Configurations → token type **User access token**, assets **Ad accounts**, permission **ads_read** → copy the Configuration ID.
3. `/admin/settings/integrations/meta-saas` → App ID, App Secret, Configuration ID → Save → «Run connection test» → consent with the **test business / test ad account** → the page shows each step (OAuth start, consent, token exchange, `me/adaccounts`, one `ads_read` insights read).
4. Only after a passed test: «Use the SaaS app for new connections».

## Permissions — request only what the product actually exercises

| Permission | Used by CampaignsHub today | Evidence to record for App Review |
|---|---|---|
| `ads_read` | **Yes** — ad-account discovery (`me/adaccounts`), campaign/ad-set/ad/creative structure, daily insights | the SaaS test run (step list + `fbtrace_id`), a screen recording of connect → accounts → a report showing the account's spend/impressions |
| `ads_management` | **No** — the Meta connector performs no write | do **not** request it for this app until a write feature ships |
| `business_management` | **No** — accounts are discovered through `me/adaccounts`, not through Business Manager edges | do **not** request it |

## Blockers (external)

- **BLOCKED_EXTERNAL_CREDENTIALS** — the SaaS app's App ID, App Secret and Configuration ID, entered by the Owner on the admin page.
- **BLOCKED_OPERATIONAL_EVIDENCE** — a reviewer/test business with an ad account that has spend, to record the `ads_read` screencast for App Review.
