# Meta SaaS app — Submit-for-Review package (META-SAAS-APP-001)

## Binding correction (Owner, 2026-10-30)

The SaaS app is a **new, fully independent Meta app**. It is not an extension of the Live or Candidate app, and it reuses none of their settings, secrets, tokens, test users or ad accounts.

- **Goal:** create, prepare, test and have Meta **officially approve** this app for public use in CampaignsHub SaaS.
- **Not allowed:**
  - changing Live or Candidate;
  - moving a customer connection;
  - a promote or rollback;
  - replacing any current app.
- **Replacing the current apps** with this one is a separate, future phase. It needs Owner approval, a migration plan, tests and no service interruption.
- **Nothing is VERIFIED** until a real round trip with an authorised test account and Meta's approval are both done.

## Separation inside CampaignsHub (already in production, #871)

| Concern | SaaS app | Live / Candidate |
|---|---|---|
| Settings and secrets | `provider_configurations.meta.saas`, entered only at `/admin/settings/integrations/meta-saas` | `meta`, `meta.candidate` |
| Connections | `provider_connections.app_profile = 'saas'`, bound to this app for life | `app_profile` NULL |
| OAuth | Its own App ID and Configuration ID; the signed `state` carries the profile; the token is exchanged with the SaaS secret only | Their own |
| Data-deletion callback | `/api/v1/webhooks/data-deletion/meta-saas`, verified with the SaaS secret only | `/meta` |
| Test round trip | Its own run (`profile = saas`) | Their own |
| Customer routing | Closed until credentials, a passing test **and** a recorded Meta approval all exist; stays Live in Production | Unchanged |
| Promote / rollback | Not offered for this app | Guarded (META-APP-SWITCH-GUARD-001) |

## Submit-for-Review checklist (state on 2026-10-30)

Meta's dashboards are not readable from CampaignsHub. Every «Owner» row is unconfirmed until the Owner does it, and nothing below assumes it is done.

| Requirement | Required value | State | Set up at |
|---|---|---|---|
| Permission | `ads_read`, Advanced Access only (no `ads_management`, no `business_management`) | Prepared: use case and screencast script below | App Review → Permissions and features |
| Business Verification | Business that owns the new app: Verified | Owner (Meta process) | https://business.facebook.com/settings/security |
| Access Verification | Requested by Meta for apps serving other businesses | Owner, if Meta prompts | https://developers.facebook.com/docs/development/release/access-verification |
| App Mode | **Development** until approval; switch to **Live** only after Advanced Access is granted | Owner | App Dashboard → top bar «App Mode» |
| OAuth (Facebook Login for Business) | Redirect URI `https://api.campaignshub.io/api/v1/oauth/ads/meta/callback`; configuration with `ads_read` on Ad accounts | CampaignsHub side ready (route live; `GET` without state → 302 `invalid_state`). Meta side: Owner | App Dashboard → Facebook Login for Business |
| Marketing API product | Added to the app | Owner | App Dashboard → Add product |
| Credentials in CampaignsHub | App ID, App Secret, Configuration ID of the **new** app | Empty in Production (checked 2026-10-30): no value reused from Live or Candidate | `/admin/settings/integrations/meta-saas` |
| Real round trip | Passed on the SaaS page with a test business ad account | Not run (needs the row above) | `/admin/settings/integrations/meta-saas` → «تشغيل اختبار الربط» |
| Reviewer access | CampaignsHub review workspace + Meta test user if asked | CampaignsHub side: provisioning needs the Owner's go-ahead | Admin console → review accounts |
| Screencast | 2-minute walk-through (script below) | Recorded after the real round trip | Uploaded in App Review |
| Privacy policy | `https://campaignshub.io/privacy` | Live: 200 (2026-10-30) | App settings → Basic |
| Terms | `https://campaignshub.io/terms` | Live: 200 | App settings → Basic |
| Data deletion | Callback `https://api.campaignshub.io/api/v1/webhooks/data-deletion/meta-saas`; page `https://campaignshub.io/data-deletion` | Route live. Answers 503 until the SaaS secret is entered, then verifies signatures with that secret only | App settings → Basic → Data deletion |
| Data handling questions | Answered in the App Review form: data is used only for the connecting business's reporting, not sold, deletable on request | Owner, using this document | App Review submission form |

### The test environment uses the new app only (verified in code and Production)

- `PlatformCredentials::forMeta(Saas)` takes App ID, Secret, Configuration ID and scopes **only** from `MetaSaasCredentials`: the `meta.saas` row, else `META_SAAS_*`. Only Meta's public endpoint URLs come from shared config.
- The SaaS test round trip records `profile = saas` and its credential fingerprint. A Live or Candidate run never counts for it (`MetaSaasAppIsolationTest`).
- In Production on 2026-10-30, the SaaS page reports all three values *not set* and no SaaS test run. No Live or Candidate value, token or account has been used for the new app.

## What the Owner must do in Meta (nothing here is assumed done)

| # | Step | Where | Evidence CampaignsHub reads |
|---|---|---|---|
| 1 | Create a **new** app, type **Business**, owned by the business that will be verified. Never reuse the Live or Candidate App ID. | https://developers.facebook.com/apps/creation/ | — |
| 2 | **Basic settings:**<br>• App Domains `campaignshub.io`<br>• Privacy `https://campaignshub.io/privacy`<br>• Terms `https://campaignshub.io/terms`<br>• Data deletion callback `https://api.campaignshub.io/api/v1/webhooks/data-deletion/meta-saas`<br>• 1024×1024 icon, category, contact email<br>• Website platform `https://campaignshub.io` | App Dashboard → App settings → Basic | Readiness «legal pages» is already ready |
| 3 | Add the **Facebook Login for Business** product:<br>• Valid OAuth Redirect URI `https://api.campaignshub.io/api/v1/oauth/ads/meta/callback`<br>• a **configuration** with token type *User*, assets *Ad accounts*, permission `ads_read`<br>• copy its **Configuration ID** | App Dashboard → Facebook Login for Business → Settings / Configurations · https://developers.facebook.com/docs/facebook-login/facebook-login-for-business | Proven by step 6 |
| 4 | Add the **Marketing API** product | App Dashboard → Add product → Marketing API · https://developers.facebook.com/docs/marketing-api/overview/authorization | — |
| 5 | Enter the **App ID, App Secret and Configuration ID** yourself. They never go through chat, email or git. | `https://campaignshub.io/admin/settings/integrations/meta-saas` | Readiness «credentials» |
| 6 | **Real test:**<br>• give a test Facebook user an app role (Development mode)<br>• in a business that user administers, use an ad account with no active campaigns<br>• on the SaaS page press «تشغيل اختبار الربط» | https://business.facebook.com/settings · App Dashboard → App roles | Readiness «OAuth round trip», «Meta dashboard settings» and «account discovery» turn ready from the run itself |
| 7 | **Business Verification** of the business that owns the app | https://business.facebook.com/settings/security · https://www.facebook.com/business/help/2058515294227817 | Owner records it on the SaaS page |
| 8 | **Access Verification**, if Meta asks: an app that accesses *other* businesses' data acts as a tech provider | https://developers.facebook.com/docs/development/release/access-verification | — |
| 9 | **App Review:** request **Advanced Access** for `ads_read` using the package below | App Dashboard → App Review → Permissions and features · https://developers.facebook.com/docs/app-review | Owner records approval → readiness «App Review» |

Only `ads_read` is requested. The product reads reporting data and never writes to an ad account, so `ads_management` and `business_management` are not requested.

## App Review package (ready to paste)

### Use case — `ads_read` (Advanced Access)

> CampaignsHub is a reporting workspace for agencies and advertisers. After a business connects its Meta ad accounts through Facebook Login for Business, CampaignsHub reads campaign, ad set, ad and insights data (spend, impressions, clicks, results) for the ad accounts that business selected. It shows that data in the business's own dashboards and in client reports it chooses to share. CampaignsHub never creates, edits, pauses or spends on any campaign; it only reads. Each business sees only its own connected accounts. A business can disconnect at any time from Integrations, and can request deletion of its data at https://campaignshub.io/data-deletion. Meta's data deletion callback for this app is https://api.campaignshub.io/api/v1/webhooks/data-deletion/meta-saas.

### Screencast script (about 2 minutes, English UI, one continuous take)

1. Sign in to CampaignsHub with the reviewer account. Show the URL `campaignshub.io`.
2. Integrations → Connect **Meta** → choose the platform app (SaaS) → Facebook Login for Business dialog. Show the app name and the `ads_read` permission. Approve one ad account.
3. Back in CampaignsHub: the discovered ad account is listed by name. Bind it to a project.
4. Run the first sync. Open Analytics and show spend, impressions, clicks and results read from that ad account.
5. Open Reports and show the same figures in a report.
6. Integrations → Disconnect. The connection is revoked and nothing is written to the ad account.
7. Open `/privacy` and `/data-deletion`.

### Reviewer access

- **CampaignsHub login:** a dedicated review workspace, isolated from every customer. Created only after you approve it, with a password you choose and enter when submitting, never in chat. The existing review-workspace mechanism (`ReviewAccess`, revoke and extend from the admin console) provides expiry and one-click revocation. A Meta-specific review workspace still has to be provisioned (see the readiness gap below).
- **Meta side:** the reviewer uses their own Facebook account. If Meta asks for test credentials, add a test user with an app role in step 6.

### Policies

- Privacy: https://campaignshub.io/privacy
- Terms: https://campaignshub.io/terms
- Data deletion: https://campaignshub.io/data-deletion. Callback: `/api/v1/webhooks/data-deletion/meta-saas`, signed with the SaaS App Secret, idempotent per Meta user, and answering `{url, confirmation_code}`.

## Readiness (2026-10-30)

| Item | Status |
|---|---|
| Separation from Live and Candidate (settings, OAuth, connections, deletion callback, test runs) | IMPLEMENTED_NOT_VERIFIED: automated isolation suites pass and it is deployed; no real round trip yet |
| Readiness screen and approval-gated routing | IMPLEMENTED_NOT_VERIFIED: deployed |
| Steps 1–6 (app, settings, FLfB, Marketing API, credentials, real test) | BLOCKED_EXTERNAL_CREDENTIALS: Owner in Meta Developers |
| Steps 7–9 (Business Verification, Access Verification, App Review) | BLOCKED_OPERATIONAL_EVIDENCE: Meta processes |
| Reviewer workspace for Meta | Not built: needs the Owner's go-ahead before provisioning an account |
| Screencast | Recorded after step 6, using the script above |
| Replacing Live with this app | Future phase. Not started; needs Owner approval and a migration plan |

## Real test — the journey inside CampaignsHub (2026-10-30)

The new app (App ID `2604968763258530`, Configuration ID `1138211818567426`, permissions `ads_read` and `ads_management`) was supplied by the Owner. The App Secret is rotated and entered by the Owner only, at `/admin/settings/integrations/meta-saas`. The secret that appeared in chat is never used.

| Step | Where | What it proves | What is read from rows |
|---|---|---|---|
| 1. Enter App ID, rotated App Secret, Configuration ID, scopes `ads_read, ads_management` | `/admin/settings/integrations/meta-saas` | Readiness «credentials» turns ready | `meta.saas` row |
| 2. «تشغيل اختبار الربط» | same page | OAuth with the Configuration ID; token exchange with the SaaS secret; `GET /me/permissions` records what Meta **granted**; discovery; one `ads_read` insights read. GETs only after the exchange | `meta_candidate_connections` run `profile = saas` |
| 3. Create the test workspace (new login address) | same page → «مساحة اختبار التطبيق الجديد» | An isolated tenant; the set-password link is shown once (mail is failing in Production) | `tenants.settings.meta_saas_test` |
| 4. Sign in to the test workspace → Integrations → Connect Meta | `/login`, then `/app/integrations` | The workspace connects through the SaaS app only. Discovery lists the test ad accounts | `provider_connections.app_profile = saas`, `issuing_app_id = 2604968763258530` |
| 5. Bind one test ad account to «Meta SaaS Test — Project», wait for the sync | Integrations → accounts | Campaigns and metrics arrive (ads_read) | `external_accounts.last_synced_at`, `daily_metrics` |
| 6. Create a report in the test project | Reports | The report reads the synced figures | `reports` |
| 7. Connect again, then disconnect | Integrations | Reconnect keeps the same connection; disconnect revokes it, and nothing is written to the ad account | `provider_connections.status = revoked` |

**`ads_management` is tested as a grant only.** Step 2 records whether Meta granted it. CampaignsHub performs no write on any ad account, so no campaign is created, edited, paused or published. A write test would need the Owner's separate, explicit approval and a test ad account, and is not built.

**Before and after, the Live side is unchanged.** The modes panel's «صحة Live الحالية» shows:
- Live connections, connected count and ad accounts;
- accounts synced in the last 24 h;
- the oldest successful sync.

Read it before step 1 and after step 7. The candidate page's rollback guard keeps showing `affected = 2`.
