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

## 2026-10-29 — test environment, journey evidence and what is still needed

### Test environment (independent of Live and Candidate)

- **Separate stores.** Credentials: `provider_configurations.meta.saas` / `META_SAAS_*`. Connections: `provider_connections.app_profile = 'saas'`.
- **No shared config.** Nothing in this environment reads or writes `meta` (Live) or `meta.candidate`.
- **Redirect URI:** `https://api.campaignshub.io/api/v1/oauth/ads/meta/callback`. The URI is shared, but the app travels in the signed `state`, and the callback exchanges with that app's own secret.
- **Data deletion callback (this app's own):** `https://api.campaignshub.io/api/v1/webhooks/data-deletion/meta-saas`. It is verified with the SaaS App Secret only.
- **Webhooks:** not required. The product only reads (`ads_read`) and subscribes to no Meta webhook. A **Deauthorize callback** is optional for Facebook Login for Business and is not configured.
- **Ad account:** use a test ad account with **no active campaigns and no spend**, owned by a test business the Owner controls. Use a Marketing API sandbox ad account where the App Dashboard offers one. Nothing is written to any account: the connector performs no write, and `ads_management` is not requested.

### Journey evidence (automated, Meta faked) — `MetaSaasAppIsolationTest`

All 8 tests pass, 111 assertions.

- **Login and token:**
  - SaaS login → consent → callback → token (exchanged with the SaaS secret only).
  - Discovery puts `act_900` on the SaaS connection and nothing on Live.
- **Sync and reports:**
  - Binding → sync: every Graph call carries the SaaS token, and the SaaS `appsecret_proof` when signed. The Live token and secret never appear.
  - Report visibility goes through `BoundAccountVisibility`.
- **Reconnect:** the same SaaS connection takes the new token. No third connection is created and the Live token is unchanged.
- **Another tenant:** it lists neither the account nor the connection, and its revoke attempt gets a 404.
- **Disconnect:**
  - Live stays connected with its token.
  - No account row or figure is lost.
- **Data-deletion callback:**
  - SaaS-signed bodies are accepted only on `/meta-saas`.
  - Live-signed bodies are refused there, and SaaS-signed bodies are refused on `/meta` (`DataDeletionFlowTest`).

### What the Owner must provide (nothing is assumed)

| # | Item | Where |
|---|---|---|
| 1 | A **Business**-type Meta app for SaaS, separate from the Live and Candidate apps | https://developers.facebook.com/apps/ |
| 2 | **Facebook Login for Business** configuration:<br>• token type: User<br>• assets: Ad accounts<br>• permission: `ads_read`<br>Copy the **Configuration ID**. | App Dashboard → Facebook Login for Business → Configurations |
| 3 | Valid OAuth Redirect URI and App Domains, as listed above | App Dashboard → Facebook Login for Business → Settings |
| 4 | Basic settings:<br>• Privacy Policy `https://campaignshub.io/privacy`<br>• Terms `https://campaignshub.io/terms`<br>• Data Deletion Callback `https://api.campaignshub.io/api/v1/webhooks/data-deletion/meta-saas`<br>• app icon, category, contact email | App Dashboard → App settings → Basic |
| 5 | Enter the App ID, App Secret and Configuration ID on `/admin/settings/integrations/meta-saas`. They are never sent by chat or git. | CampaignsHub admin |
| 6 | A test business with an ad account (no spend needed for the round trip). The tester must hold an app role while the app is in Development mode. | https://business.facebook.com/settings · App Dashboard → App roles |
| 7 | **Business Verification** of the business that owns the app (needed for Advanced Access) | https://business.facebook.com/settings/security |
| 8 | **Marketing API access tier**, then **App Review** for `ads_read` Advanced Access:<br>• screencast connect → accounts → report<br>• reviewer test credentials | https://developers.facebook.com/docs/marketing-api/get-started/authorization · https://developers.facebook.com/docs/app-review |

### Status

| Item | Status |
|---|---|
| Code: separate app profile, routing, per-connection exchange, refresh and sync, move/rollback, SaaS deletion callback | IMPLEMENTED_NOT_VERIFIED (automated journey passes; no real Meta round trip yet) |
| Real OAuth round trip with the SaaS app | BLOCKED_EXTERNAL_CREDENTIALS (items 1–5) |
| Discovery and sync on a real test ad account | BLOCKED_EXTERNAL_CREDENTIALS (item 6) |
| Business Verification, Advanced Access, App Review | BLOCKED_OPERATIONAL_EVIDENCE (items 7–8, Owner-side Meta processes) |
