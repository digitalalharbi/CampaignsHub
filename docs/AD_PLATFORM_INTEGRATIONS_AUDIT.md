# Ad-platform integrations audit (ADAUDIT-001)

**Audited 2026-07-29 against the code at HEAD, not against previous reports.** Every claim below was
read out of the source or observed in the running system; nothing is inferred from a plan document.

The purpose of this file is to answer one question honestly: *for each of the six platforms, what is
already built, and what is genuinely missing?* The short answer is that the **shared machinery is
complete and exercised**, and what every platform is missing is the **same single thing — provisioned
API credentials**. No platform has a half-written adapter that would need rewriting once keys arrive.

---

## 1. What exists regardless of credentials

| Layer | Where | State |
|---|---|---|
| Connector contract | `app/Domains/Integrations/Contracts/AdvertisingConnector.php` | ✅ `key`, `label`, `status`, `authorizationUrl`, `handleCallback`, `healthCheck`, `listAdAccounts`, `syncCampaigns`, `syncInsights` |
| Honest base for un-credentialed platforms | `Contracts/AwaitingCredentialsConnector.php` | ✅ reports `AwaitingCredentials`; `syncCampaigns`/`syncInsights` return `SyncResult::failed`, `healthCheck` returns down, `authorizationUrl` throws. **It never fabricates data.** |
| Deterministic fake for dev/tests | `Sandbox/SandboxAdvertisingConnector.php` | ✅ returns clearly-labelled sandbox rows, never touches the network |
| Registry | `Registry/AdvertisingConnectorRegistry.php` | ✅ all six + sandbox, with a `google → google_ads` alias (see §4) |
| Credential storage | `Models/IntegrationCredential` (encrypted payload) | ✅ payload is never logged or returned by an API |
| Connection + account model | `ProviderConnection`, `ExternalAccount` | ✅ status, health-check timestamp, last successful sync, last error |
| Campaign discovery model | `ExternalCampaign` | ✅ linked to a unified campaign, carries provider ids |
| **Ad-set / ad model** | `ExternalAdSet`, `ExternalAds` tables | ✅ goal, bid strategy, budgets, targeting, review status, `source_type` |
| Metrics normalization | `Domains/Metrics` (`NormalizedMetric`, `UpsertDailyMetrics`, `MetricsAggregator`) | ✅ currency conversion, idempotent upsert, derived KPIs from sums |
| **Sync pipeline** | `Metrics/Services/AccountMetricsSyncer` + `Jobs/SyncAccountMetricsJob` | ✅ queued per account, records a `MetricSyncRun` for every attempt |
| Sync log + trigger API | `Metrics/Http/Controllers/SyncRunController` | ✅ `GET`/`POST projects/{project}/sync-runs` |
| Per-platform UI | `PlatformOverviewController` + `PlatformIntegrationsPanel` | ✅ status, accounts, discovery counts, last run, errors, capabilities |

**Consequence:** adding a credential does not require new architecture. It requires implementing the
OAuth exchange and the two sync calls inside one class per platform.

---

## 2. Per-platform status

All six extend `AwaitingCredentialsConnector`, so they share exactly the same honest behaviour. The
differences are only in what each platform's real implementation will have to do.

| Platform | Connector | Registry key | OAuth | Accounts | Campaigns | Ad sets / ads | Insights | Blocking item |
|---|---|---|---|---|---|---|---|---|
| Meta | `MetaConnector` | `meta` | ⬜ awaiting app review + token | ⬜ | ⬜ | ⬜ | ⬜ | App ID/secret, `ads_read`, system-user token |
| Google Ads | `GoogleAdsConnector` | `google_ads` (+ alias `google`) | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | OAuth client + **developer token** (separate approval) |
| TikTok | `TikTokConnector` | `tiktok` | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | App approval + advertiser authorisation |
| Snapchat | `SnapchatConnector` | `snapchat` | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | OAuth client + organisation access |
| X (Twitter) | `XConnector` | `x` | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | Ads API access tier (not granted by default) |
| LinkedIn | `LinkedInConnector` | `linkedin` | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | Marketing Developer Platform approval |

⬜ = implemented as the honest awaiting-credentials path; lights up when credentials are provisioned.
**No cell here is marked done on the strength of a mock.**

### What "awaiting credentials" actually does, verified live
Triggering a sync for the demo Meta account produced a real `MetricSyncRun`:

```
status  : awaiting_credentials
error   : No credentials for Meta Marketing API — nothing was fetched.
          Add credentials to enable this sync.
upserted: 0
```

That is the whole point: the trigger, the queue, the connector check, the run record and the log are
all real. Only the secret is absent — and the system says so instead of failing vaguely or, worse,
inventing numbers.

---

## 3. Demo mode — and why it cannot be mistaken for production

`DemoIntegrationsSeeder` builds the full chain for Meta, Google, TikTok and Snapchat so the integration
surfaces can be reviewed without credentials. Every artefact is labelled:

- connections are named **«— بيانات تجريبية»**
- ad accounts carry `metadata.is_demo = true`
- ad sets and ads carry `source_type = 'demo'` and `is_demo = true`
- sync runs carry `is_demo = true` and render a «تجريبية» badge
- the stored credential payload is literally `DEMO-PLACEHOLDER-NOT-A-REAL-TOKEN`

A demo row can therefore never be read as a live platform pull, in the UI or in the database.

---

## 4. Defects this audit found and fixed

1. **Provider-key drift.** `GoogleAdsConnector::key()` returns `google_ads`, but every stored row —
   `daily_metrics`, `external_accounts`, `external_campaigns` — uses `google`. A Google account
   therefore resolved to **no connector at all**, and its sync was recorded as *"no connector is
   registered"*: a misleading failure instead of the truthful awaiting-credentials state. Fixed with an
   explicit alias in the registry, plus a regression test.
2. **A permission that does not exist.** The sync trigger was gated on `integrations.manage`, which this
   system never defines (only `view`, `connect`, `disconnect`). The gate could never pass, so the button
   never rendered for anyone, including an owner. Fixed to `integrations.connect`.

---

## 5. Definition of done per platform (for when credentials arrive)

A platform may only be marked done when **all** of these are true — a passing mock is not one of them:

1. OAuth authorize + callback exchange real tokens, with refresh handled and tokens stored encrypted.
2. `listAdAccounts()` returns the real accounts of the authorised identity.
3. `syncCampaigns()` discovers real campaigns and links them to unified campaigns.
4. Ad sets and ads are pulled into `external_ad_sets` / `external_ads` with `source_type = 'api'`.
5. `syncInsights()` returns real rows that survive normalization (currency, timezone, attribution).
6. Rate limiting, pagination, retry and idempotency are exercised against the live API.
7. A `MetricSyncRun` proves it: real window, non-zero upserts, and a real failure reproduced at least once.
8. Live review in the browser on Chromium, Firefox and WebKit.

---

## 6. Honest bottom line

- **Not blocked on engineering:** the contract, registry, credential vault, connection/account models,
  campaign + ad-set + ad schema, sync pipeline, sync log, error surfacing and per-platform UI are built
  and tested.
- **Blocked on access:** six sets of platform credentials, each behind that platform's own approval
  process. Nothing in this repository can shorten those approvals.
- **Not claimed:** no platform is described as connected, synced or live anywhere in the product.

---

## 7. SNAP-ORG-001 / SNAP-TOKEN-001 / PROBE-CLAIM-001 — corrections, 2026-08-16

**This section supersedes anything above it that disagrees with it.** §2's table describes the state
before the six read-only adapters were written; the adapters exist. What follows was re-verified
against the providers' CURRENT published documentation, not against this file and not against the
code's own comments.

### SNAP-ORG-001 — a system-level organisation id could not be right for more than one customer

`SnapchatConnector::fetchAdAccounts()` read `organization_id` from the platform's `/admin`
configuration and requested `organizations/{that id}/adaccounts`.

CampaignsHub is multi-tenant. Every customer authorises with their own Business Manager member and
receives their own token, carrying access to their own organisation. One organisation id in one
system row pointed **every** customer's token at the operator's organisation — so a tenant saw
accounts that were not theirs, or, far more often, none at all, because their token has no access
there and the call is refused. It was a tenancy defect wearing a configuration field's clothes.

**Verified from the current documentation:** `GET /v1/me/organizations?with_ad_accounts=true` returns
the organisations the authenticated member can reach, each with its ad accounts nested and the
member's role on them. The endpoint existed all along; the field stood in for a call nobody made.

The field is removed from the catalogue. `SnapchatOrganisationDiscoveryTest` proves two tenants with
two tokens discover two different organisations and never each other's, and that an organisation id
already stored in production is preserved rather than destroyed.

### SNAP-TOKEN-001 — the stated token lifetime was half the real one

The interface said «نحو 30 دقيقة». The current authentication documentation states the access token
expires after **3600 seconds (60 minutes)**. Corrected in both languages. An operator plans refresh
windows, alert thresholds and support answers around that number.

### PROBE-CLAIM-001 — «جاهز للإنتاج» was claimed from a credentials probe

`ProviderSetupState::ProductionReady` was reached whenever the key set was complete, the environment
was `production`, and the configuration probe passed. The interface rendered it green, as
«جاهز للإنتاج».

The probe sends a deliberately impossible authorisation code and reads the refusal. A refusal that
names the GRANT proves the provider recognised our client id and secret — **and that is all it
proves.** Not app review, not granted scopes, not a developer token, not anybody's consent, not one
reachable ad account. Several of these platforms additionally require an external approval that no
request from this server can detect.

The case is now `CredentialsVerified` — **the stored value `production_ready` is unchanged**, so live
rows and saved filters keep working — the badge is no longer green, and the label reads
«تم التحقق من التطبيق — جاهز لبدء الربط». `isLiveVerified()` returns false for every state in the
enum, because none of them observes a consent, a discovery or a sync. `LIVE_VERIFIED` in `CLAUDE.md`
terms remains unearned for every provider here.

---

## 8. OAUTH-WS-001 / TIKTOK-AUTH-001 / TIKTOK-SCOPE-001 — corrections, 2026-08-16

**This section supersedes anything above it that disagrees with it.** Read from TikTok's *Marketing
API Authorization* and *Marketing API Authentication* pages as published today — the portal renders
nothing to a fetcher, so both were opened in a browser and read.

### OAUTH-WS-001 — the client workspace a connection is filed under was never checked to be ours

Both OAuth entry points — `AdPlatformOAuthController::start` (the six ad platforms) and
`StoreOAuthController::start` (Salla, Zid) — validated the caller's `client_workspace_id` as
`['sometimes','nullable','uuid']`. That checks the shape of a string and nothing else. The value went
into the single-use state and, on the callback, onto the `ProviderConnection` and every discovered
`ExternalAccount`.

So an authenticated operator of tenant A, legitimately holding `integrations.connect` in their OWN
tenant, could post tenant B's client-workspace id and file a real, live platform credential — and
everything that then syncs through it — under another company's client. `client_workspace_id` is what
the agency surfaces, the client portals and the per-client report scopes read.

`ClientWorkspace` is `BelongsToTenant`, but a global scope defends *queries*, and this code ran none:
it moved a bare string from a request body to a database column. The fix is the rule this repository
already uses for this same field in `TaskController`, `ProjectController` and `AICredentialController`
— `Rule::exists('client_workspaces','id')->where('tenant_id', …)` — narrowed with `deleted_at is
null`, because the table is soft-deleted and a live credential filed against a removed client appears
on no surface at all.

It deliberately does **not** add a per-user client-workspace membership rule: no controller here has
one, an agency owner is expected to connect for any of their own clients, and inventing that inside a
security fix would break a legitimate flow while wearing the same commit.

### TIKTOK-AUTH-001 — TikTok returns two codes and we exchanged the wrong one

TikTok's documented redirect carries **both**, with **different values**:

```
…?state=…&code=3c6dc21d2db289199737bcb8c006c23aaf000a1e
        &auth_code=1234c21d2db289199737bcb8c006c23aaf000a1e&id=1701890905779201
```

and the authorization page states, of that exact example, that the code to extract is the `auth_code`
one. The authentication reference agrees: `auth_code` is the code generated by the advertiser
authorization URL, valid for one hour, usable once.

`AdPlatformOAuthController::callback` read `code` for every provider and posted it to TikTok as
`auth_code`. **Every TikTok connection attempt failed at the first exchange** — not intermittently,
and not for some advertisers. It survived because no fixture ever drove the callback with the pair of
parameters a real TikTok redirect carries; one that sends a single `code` cannot tell the two apart.

Which parameter carries the code is now the provider's decision
(`PlatformOAuth::callbackCodeParameter()`), beside the other TikTok bends. There is no fallback to
`code`: falling back would post the value now known to be wrong and report TikTok's refusal to the
customer as a platform outage. The documented body is exactly `{app_id, secret, auth_code}`, so the
undocumented `grant_type` is gone from both the exchange and the configuration probe.

### TIKTOK-SCOPE-001 — a granted scope is not always a string

`PlatformOAuth::tokensFrom()` did `(string) $body['scope']`, which is right for the five OAuth
providers. TikTok's token response documents `scope` as `number[]` and its own example returns
`"scope": [4]`. Casting an array to string raises «Array to string conversion» in PHP 8, inside the
token exchange — so the callback ended in `outcome=failed` with that message shown to the customer.
The scope is now joined rather than dropped, because TikTok grants what the ADVERTISER approved,
which need not be everything the app asked for.

### What the TikTok audit CONFIRMED rather than changed

- **The access token does not expire.** «A long-term access token does not expire, but it'll become
  invalid if the advertiser cancels the authorization.» `supportsRefresh: false` is correct, and a
  dead TikTok connection means re-authorisation, not a token rotation.
- **Scopes belong to the app, not the authorise URL.** The advertiser approves the permissions chosen
  at app creation, and the granted scope comes back on the token response.
- **`/oauth2/advertiser/get/` is the documented list** of every advertiser a token can reach — which
  is what the connector already calls, with the customer's own token. No advertiser id, Business
  Centre id or store id is a system credential, for the same reason SNAP-ORG-001 gave.
- **No PKCE.** TikTok's flow publishes no code challenge or verifier.
- **Polling, not webhooks.** TikTok publishes no delivery webhook for ad reporting; `PollingOnly` is
  accurate rather than a placeholder.
- **The sandbox is an advertiser account, not a host.** TikTok's sandbox uses the same endpoints with
  a whitelisted advertiser, so ENV-FAKE-001's verdict stands: there is no environment switch to make
  honest here, because there is no second host to switch to.

**Not claimed:** no TikTok credential exists on this install and no live round trip has been made.
TikTok remains `BLOCKED_EXTERNAL_CREDENTIALS`, and the two defects above are the reason the first
real attempt would have failed — they are fixed ahead of that attempt, not verified by one.

## §9 — Phase 2, second provider: Meta (2026-08-16)

Audited against the current *Ad Account Insights*, *Ads Action Stats* and *Platform Versioning*
references, and against Meta's own Graph/Marketing API versions table. Two defects, both proven
fail-first. One of them is Meta's; the other is the whole metrics pipeline's, and Meta is where it
became provable.

### META-ATTRIB-001 — the attribution window was never asked for, never known, and reported as checked

`daily_metrics.attribution_window` exists so that two numbers measured over different windows are
never added together without a word to the reader. `NormalizedMetric` defaults it to the string
`'default'`; **nothing in the application ever passed anything else** — `grep -rn "attributionWindow:"
app/` returned zero results — so every row from every provider carried the same literal.

Three things read that column, and all three were reading a constant:

- `MetricsController::normalization` groups on it and returns `attribution_windows` to the
  «أساس الأرقام» panel;
- the panel warns «أكثر من نافذة إسناد في الفترة نفسها» when it receives more than one;
- `AttributionTransparency::platformRows()` groups per provider on it.

A grouping over a column with one value returns one bucket, always. So the warning could not fire,
and the single row it displayed instead read as a clean bill of health: **a panel built to catch a
comparability defect reporting a uniformity it had never verified.**

What makes this concrete rather than theoretical is Meta's own wording. On the Ad Account Insights
reference, `action_attribution_windows` has «القيمة الافتراضية: default», and then:
«يشير الخيار `default` إلى `["7d_click","1d_view"]`». The default is not «unset» or «neutral» — it is
a **specific window**: seven days after a click, one day after a view. Snapchat, TikTok, Google, X
and LinkedIn each have their own, different, unstated defaults. Storing the same word for all six
stated that they agreed.

The fix has two halves:

- `MetaConnector` **asks** for `["7d_click","1d_view"]` — Meta's own default, stated rather than
  inherited — and every row it maps carries `attribution_window: 7d_click,1d_view`. The value is
  deliberately Meta's default and not something shorter or longer: a different window would be a
  reporting policy nobody here has chosen, applied retroactively to every client's history. This
  changes what we KNOW about the numbers, not what the numbers are.
- `InsightRowNormaliser` reads that key off the row — exactly as it already reads the row's
  `currency` — and passes it to `NormalizedMetric` instead of letting the constructor default stand.

Sent as **JSON**, like the `time_range` beside it: Graph reads `list<enum>` parameters as JSON, and a
PHP array would be serialised into `action_attribution_windows[0]=…`, which Graph does not parse. It
would have been ignored, and an ignored parameter is indistinguishable from one never sent.

A connector that names no window still gets `default`, and that stays the truthful word — «this
provider's own unstated default», not «the same window as everybody else». Because that is now a
DIFFERENT string from Meta's, the mixed-window warning can finally fire. **No frontend change was
needed**: the panel was already written to warn on more than one window; the backend had simply never
given it a second value.

### META-VERSION-001 — pinned to a Marketing API version that expired eleven months ago

The Marketing API keeps its **own** version table, separate from the Graph API's. On it:

| Marketing API | Released | Expires |
|---|---|---|
| v21.0 | 2 Oct 2024 | **9 Sep 2025** |
| v22.0 | 21 Jan 2025 | 19 Feb 2026 |
| v23.0 | 29 May 2025 | 9 Jun 2026 |
| v24.0 | 8 Oct 2025 | 6 Oct 2026 |
| v25.0 | 18 Feb 2026 | — (current stable) |

All three Meta URLs — `authorize_url`, `token_url`, `api_base` — were pinned to **v21.0**, which
expired on 9 September 2025.

This never surfaced as a failure, and could not have. Meta's platform versioning guide states that
once a version is no longer usable, calls to it **default to the next-oldest usable version**. There
is no error, no header and no log line: the figures kept arriving, from a version nobody chose.

All three are moved to **v25.0**. The test asserts the version as a NUMBER with a floor, not as a
literal string, so a later upgrade passes and only a downgrade — or standing still until v25.0 is
itself retired — fails.

### What the Meta audit CONFIRMED rather than changed

- **`me/adaccounts` is the right discovery call**, made with the customer's own token. No ad account
  id, Business Manager id or pixel id is a system credential — the same rule as SNAP-ORG-001.
- **The system credential set is exactly `client_id` + `client_secret`.** Nothing customer-owned
  leaks into platform configuration.
- **`paging.next` is followed to the end**, cursor URL entire, with a ceiling — unchanged and correct.
- **No PKCE.** Meta's authorization-code flow publishes no code challenge or verifier.
- **The action-type priority list stays a priority list, never a sum** (META-001). Nothing in this
  audit disturbed it.

**Not claimed:** no Meta credential exists on this install and no live round trip has been made. Meta
remains `BLOCKED_EXTERNAL_CREDENTIALS`. **Nothing here is `LIVE_VERIFIED`** — an expired version and
an unstated window are both fixed ahead of the first real connection, not verified by one.

## §10 — Phase 2, third provider: Google Ads (2026-08-16)

Audited against the current sunset table, *Listing accessible customers*, *Get the account hierarchy*,
the `customer_client` field reference and *API call structure*. Three defects, all proven fail-first
(8 of 9 new tests failed before the fix). The first of them means the other two had never yet had a
chance to matter.

### GADS-VERSION-001 — v18 is not merely old, it is gone

`api_base` was `https://googleads.googleapis.com/v18`. Google's sunset table lists the **released**
versions as:

| Google Ads API | Released | Sunset |
|---|---|---|
| v21 | 6 Aug 2025 | Aug 2026 (tentative) |
| v22 | 15 Oct 2025 | Oct 2026 (tentative) |
| v23 | 28 Jan 2026 | Feb 2027 |
| v24 | 22 Apr 2026 | May 2027 |
| **v25** | **22 Jul 2026** | **Aug 2027** |

v18 is on none of them. And Google does not degrade quietly the way Meta does (META-VERSION-001): its
own definition is that a sunset version can no longer be used and requests to it **fail** on or after
the sunset date. **Every Google Ads call this platform made was refused, for every customer, always.**

Moved to **v25**, guarded by a numeric floor rather than a literal so an upgrade passes and only
standing still fails.

### GADS-HIERARCHY-001 — `listAccessibleCustomers` is not a list of ad accounts

The connector treated every resource name it returned as an ad account. Google's page says what it
actually returns: the accounts **the authenticated user can act on directly**. That includes MANAGER
accounts, and excludes the customers underneath them. Its worked example is exactly the agency shape
this product serves — a user with admin rights on manager M1 and account C3 can reach M1, C1, C2 and
C3, but the call returns **only M1 and C3**.

So for any agency working through an MCC we recorded the MANAGER as an ad account, and the accounts
that hold the campaigns and the spend were never discovered at all. A manager holds no campaigns, so
the single "account" we did create would sync cleanly and report nothing — forever. That reads as a
quiet month, not as a broken integration, which is the hardest kind of failure to notice.

The documented answer is `customer_client`, queried under each accessible customer. It returns every
direct and indirect client of a manager, plus the entry point itself at `level = 0`, and carries
`descriptive_name`, `currency_code`, `time_zone`, `status`, `level` and `manager`. That last flag is
what separates an advertiser from a folder.

Three things fall out of using it:

- the manager is skipped and its clients are discovered;
- name, currency, timezone and status now come from the platform instead of being `null` or a blanket
  `active` — a client Google reports as `CANCELED` is no longer badged live;
- the extra per-customer `SELECT customer.descriptive_name` round trip is gone, because the hierarchy
  already carries the name.

Accounts are keyed by id, so one reachable through two entry points — an operator with rights on both
the MCC and one of its clients — is discovered once rather than twice with its spend doubled.

### GADS-MCC-001 — the manager account id was a SYSTEM credential

`login_customer_id` sat in the platform's own `/admin` configuration and was sent as
`login-customer-id` on **every call, for every tenant**. Google documents it as the customer id of the
manager account through which the caller reaches **that particular client account** — so it belongs
to each customer's hierarchy, not to this platform. One value in one system row was wrong for every
tenant but at most one.

It was also written into `parent_external_id` on every discovered account, making one operator's MCC
id the recorded parent of every client's accounts on every surface that shows a hierarchy.

This is SNAP-ORG-001 again: same shape, same layer, same cause.

The header is now asked of the connector (`ApiAdvertisingConnector::loginCustomerId()`, null for every
other provider) rather than read from credentials. While walking a hierarchy it is the entry point
being walked; for a per-customer GAQL query it is the parent recorded for that account at discovery.
An account held directly sends **no** header, which is correct — Google then defaults it to the
operating customer.

The `/admin` field is removed as well, and that matters as much as the header. A field on that form is
an instruction: leaving it after the value stopped being read would invite an operator to paste one
customer's manager id into a platform-wide setting and believe they had configured something.
`GOOGLE_ADS_LOGIN_CUSTOMER_ID` is deliberately not read anywhere — an environment variable that is
silently ignored is worse than one that is absent.

### What the Google Ads audit CONFIRMED rather than changed

- **The developer token IS a system credential.** It identifies this application to Google, is
  approved separately from the OAuth client, and every call is refused without it. It stays required.
- **`customers:listAccessibleCustomers` is still the right entry point** — and Google states it
  ignores any `login-customer-id` supplied, so the old system value never affected it either way.
- **`searchStream` returns a JSON ARRAY of chunks**, and reading `$body['results']` finds nothing;
  `stream()` flattens it. Unchanged and correct.
- **The PURCHASE-category second query stays** (GADS-001). Google publishes no purchase metric, and
  `metrics.conversions` counts whatever the account counts.
- **Customer ids are sent without dashes.** Unchanged.
- **No PKCE**, and the refresh token is issued only on first consent with `access_type=offline` plus
  `prompt=consent` — both already handled.

**Not claimed:** no Google Ads credential exists on this install and no live round trip has been made.
Google Ads remains `BLOCKED_EXTERNAL_CREDENTIALS`. **Nothing here is `LIVE_VERIFIED`.**

## §11 — Phase 2, fourth provider: X (2026-08-16)

Audited against X's current *OAuth 2.0 Authorization Code Flow with PKCE* reference and the X Ads API
*Versions* table. One defect, proven fail-first.

### X-PKCE-001 — PKCE was declared mandatory in three places and implemented in none

`ProviderCatalogue` said so, repeatedly and correctly:

- the class header: «X — PKCE is mandatory, so `code_verifier` must survive the whole round trip»;
- the X definition: `usesPkce: true`;
- its `tokenNote`: «the authorise call carries a `code_challenge` and the token call must present the
  matching `code_verifier`».

`grep -rn "code_challenge\|code_verifier" app/Domains/Integrations/` returned **only those three
comments**. Not one line of code. The only PKCE in this repository was in
`Identity/Services/OAuthFlow` — the unrelated staff sign-in flow, which implements it correctly and
was the reason a reader could find PKCE in the codebase and assume it applied here.

And X is not a platform where PKCE is a hardening option. Its own words: «We only provide
authorization code with PKCE and refresh token as the supported grant types.» It is the **only**
authorization-code flow X offers. So **every X connection attempt would have been refused at the
authorise step**, for every customer, always — while the console described the integration as ready
and the catalogue described the mechanism in detail.

That combination is why this carries its own defect id rather than being filed as missing work.
Documentation describing a mechanism nobody wrote is worse than no documentation: it is the thing a
reviewer checks INSTEAD of the code, which is precisely what happened for as long as this stood.

**The fix.** `PlatformOAuth::codeVerifier()` mints a 96-character verifier — the shape
`Identity/Services/OAuthFlow` already uses, comfortably inside RFC 7636's 43–128 unreserved
characters — and `codeChallenge()` derives the S256 challenge. Both controllers mint the verifier at
`start`, carry it in the authorisation state's `extra`, and present it at the exchange.

**Where the verifier lives is the deliberate part.** Not the session: the integrations callback is a
PUBLIC route, and nothing of the session survives the platform's redirect — which is the entire
reason `AuthorizationState` exists. Riding in that record gives the verifier the same properties for
free: single use (`Cache::pull`), short lived, and bound to the tenant, user and provider that minted
it. It is read from the RECORD and never from the query string, the same rule the tenant and the
client workspace already follow.

**The catalogue is now what decides.** `usesPkce` was a field nothing read. It now drives the
behaviour, and a test asserts that the set of providers sending a challenge is exactly the set
declaring the flag — walked from the catalogue rather than listed — so a provider that adopts PKCE
later cannot be declared without being implemented. That drift is the defect itself.

**And it fails closed.** A PKCE provider reaching the exchange with no verifier is REFUSED. Exchanging
anyway would send X a request it is obliged to reject, and the customer would be shown X's refusal as
though the platform were at fault — an error message naming the wrong culprit, which is the failure
mode this whole audit exists to remove.

`StoreOAuthController` is threaded identically even though neither Salla nor Zid publishes PKCE
today. The mechanism belongs to the flow, not to one provider, and a second entry point that could
not honour the catalogue's declaration would recreate exactly the defect being fixed.

### What the X audit CONFIRMED rather than changed

- **`ads-api.x.com/12` is current.** X's Versions table: 12.0 introduced 27 October 2022, Deprecated
  **TBD**, End of Life **TBD** — the newest published version, with no sunset announced. Unlike Meta
  and Google Ads, X has no version defect. Checked rather than assumed, and left alone.
- **`offline.access` is already in the scopes**, and it is what makes a refresh token exist at all:
  without it an X access token lasts two hours and cannot be renewed.
- **The token call uses Basic authentication.** X authenticates the request itself for confidential
  clients rather than taking the pair in the body; `standardToken()` already does this, and
  `ProviderProbe` matches it.
- **Discovery is `GET accounts`**, documented as returning the ad accounts the currently authorised
  user has access to — the customer's own token, no system account id. Same rule as SNAP-ORG-001.
- **Polling, not webhooks.** `PollingOnly` is accurate.

**Not claimed:** no X credential exists on this install and no live round trip has been made. X
remains `BLOCKED_EXTERNAL_CREDENTIALS`. **Nothing here is `LIVE_VERIFIED`** — PKCE is fixed ahead of
the first real connection, not verified by one.

## §12 — Phase 2, fifth provider: LinkedIn (2026-08-16)

Audited against LinkedIn's current *Marketing API Versioning* page, the *Pagination* concept page and
the *Ad Accounts* reference. Two defects, both proven fail-first (5 of 8 new tests failed).

### LINKEDIN-PAGE-001 — ten. Every list this connector read stopped at ten

`LinkedInConnector` made four calls — `adAccounts`, `adAccounts/{id}/adCampaigns`,
`adAccounts/{id}/creatives` and `adAnalytics` — and **not one of them passed `count` or `start`, or
looked at `paging`**. Each read `$body['elements']` once and returned.

LinkedIn's pagination page gives the default `count` as **10**.

So an agency saw at most ten ad accounts. Within each of those, at most ten campaigns, ten creatives,
and ten rows of analytics. Nothing errored, nothing was logged, and every total on every surface —
spend, impressions, the funnel, the client's own shared report — was short by whatever the eleventh
campaign onward did. A truncated account does not look broken; it looks smaller.

The analytics call is where this was worst. One row is one campaign on one day, so a handful of
campaigns over a month exceeds ten rows immediately — the first page alone was reported as the whole
of the spend.

`readAll()` walks `start`/`count` with a page of 100, and terminates on LinkedIn's own documented
rule: «You have reached the end of the dataset when your response contains fewer elements … than your
count parameter request». That rather than paging until an empty page, which would spend an extra
round trip on every sync of every account on an API that throttles per application. A `MAX_PAGES`
ceiling bounds the walk, exactly as `MetaConnector` already does.

This is the same defect Meta had and fixed, with one difference that matters: Meta's page size was
500, so it needed a genuinely large account to bite. LinkedIn's is ten, which is a small one.

### LINKEDIN-VERSION-001 — pinned to a version older than one LinkedIn has already retired

`LINKEDIN_ADS_VERSION` defaulted to **202411** (November 2024). From LinkedIn's versioning page:

- the latest version is **202607** (July 2026);
- versions are supported for a **minimum of one year**;
- and every page of the marketing documentation currently carries the banner «The Marketing Version
  **202507** (Marketing July 2025) **has been sunset**».

202411 is eight months older than the version LinkedIn names as already sunset. The header is not
advisory — this connector's own comment says an unpinned call is rejected outright — so every
LinkedIn call was made against a version that no longer exists.

Moved to **202607**, asserted as a number with a floor so a later monthly bump passes and only
standing still fails.

That is the third provider in a row on a dead API version: `v18` for Google Ads, `v21.0` for Meta,
`202411` here. Only X, of the four checked, was current.

### What the LinkedIn audit CONFIRMED rather than changed

- **`r_ads` and `r_ads_reporting` are the right scopes.** The Ad Accounts reference names `r_ads`
  (read-only) and `rw_ads` (read/write) as the ad account permissions; this platform reads only.
- **`adAccounts?q=search` returns the accounts the authorised member can reach** — the customer's own
  token, no system account id. Same rule as SNAP-ORG-001.
- **The `LinkedIn-Version` and `X-Restli-Protocol-Version: 2.0.0` headers are both already sent**, and
  a test now pins the first to the configured value so a silent drift is caught.
- **LinkedIn has no ad-set level**, so `fetchAdSets` returning `[]` is accurate rather than unfinished.
- **`purchases` stays absent** (LINKEDIN-001): LinkedIn publishes no purchase metric, and
  `externalWebsiteConversions` counts every conversion the account defined.
- **Polling, not webhooks.**

**Not claimed:** no LinkedIn credential exists on this install and no live round trip has been made.
LinkedIn remains `BLOCKED_EXTERNAL_CREDENTIALS`. **Nothing here is `LIVE_VERIFIED`.**

## §13 — Phase 2, sixth and seventh providers: Salla and Zid (2026-08-16)

Audited against Salla's *Webhooks* page (Security Strategies) and Zid's *Authorization* and *Create
Webhook* references. **Salla needed no change.** Zid had one defect, proven fail-first.

### Salla — audited and left alone, which is the finding

Salla's documented scheme, and ours, line up exactly:

| Salla documents | This platform does |
|---|---|
| header `X-Salla-Signature` | `webhookSignatureHeader: 'x-salla-signature'` |
| a 64-character SHA-256 hash of the request body, using the Secret | `hash_hmac('sha256', $rawBody, $secret)` |
| bare hex, no prefix | no prefix stripped for Salla |
| «using a timing-safe equality function, compare» | `hash_equals` |
| base endpoint `https://api.salla.dev/admin/v2` | the configured `api_base`, character for character |

One difference is worth recording, because it runs the other way. Salla's own Node sample computes the
HMAC over `JSON.stringify(req.body)` — the re-encoding this class's first rule exists to forbid, since
re-encoding reorders keys and re-escapes unicode and then fails for deliveries that were perfectly
valid. **We hash the RAW body, which is more correct than the vendor's published example.** Nothing
was changed on that basis; it is noted so a future reader who compares the two does not "fix" ours
towards theirs.

Salla also offers a second strategy, **Token**, selected per subscription and announced in
`X-Salla-Security-Strategy`. Signature is what Salla assigns by default to every Partner App and to
any webhook registered without a strategy, and it is what this platform implements. A subscription
deliberately created with Token would be refused rather than accepted — the fail-closed direction —
and that is left as it is rather than widened speculatively.

### ZID-WEBHOOK-001 — the signature scheme was invented; Zid authenticates with Basic

`ProviderCatalogue` declared `webhookSignatureHeader: 'x-zid-signature'`. `WebhookSignature` computed
`hash_hmac('sha256', $rawBody, $secret)` and compared it against that header. The `/admin` form asked
the operator for a «Webhook secret … used to sign event deliveries».

**Zid publishes no HMAC signature scheme of any kind.** Its Create Webhook reference documents the
only authentication it offers:

> If username and password are provided when creating a webhook, Zid will include a **Basic
> Authentication** header when sending webhook requests. … `Authorization: Basic dXNlcjpzZWNyZXQ=` …
> This allows partners to verify that the webhook request is coming from Zid.

So `x-zid-signature` was a header Zid never sends. Every genuine Zid delivery was refused with «The
delivery carried no signature», and the operator was being asked to configure a secret for a
mechanism that does not exist — one they could never obtain, because Zid has nothing to issue.

It fails CLOSED, so this was never a security hole. It is the same shape as X-PKCE-001: a mechanism
described in detail, in the place a reviewer looks, implemented against a provider that does not have
it. That is worse than an omission, because the description is what gets checked instead of the
behaviour.

**The fix.** Zid is verified against the documented credential pair. `webhook_secret` becomes
`webhook_username` + `webhook_password`, on the form and in configuration, and the catalogue names
`Authorization` — the header Zid really sends.

The whole header is compared in **one** `hash_equals` rather than the username and password
separately: two comparisons leak which half was wrong through their timing, and the username is
usually the easier half to guess.

The three rules at the top of `WebhookSignature` are unchanged by the different scheme. A missing
credential still refuses; the comparison is still timing-safe; and the raw body is still what Salla's
HMAC is computed over.

`WebhookSupport::RequiresConfirmation` **stays**, so the poll remains authoritative — but it now
means something different. It used to encode "we do not know what Zid sends". It now encodes a
documented fact: Zid's deliveries carry a shared credential and no per-message integrity, so a poll
is the stronger statement about what actually happened in the store.

### What the Zid audit CONFIRMED rather than changed

- **`https://api.zid.sa/v1` and `https://oauth.zid.sa` are both current.** Zid's Authorization page
  states «the latest version of our API is v1. Older versions are deprecated and should not be used».
  No version defect — checked rather than assumed, like X.
- **The two-credential requirement is already handled and already fails closed.** Zid returns an
  `access_token` for `Authorization: Bearer` **and** a separate `authorization` value for
  `X-Manager-Token`; a call carrying only the first is refused by every endpoint. `PlatformOAuth`
  raises at the exchange when the second is missing, rather than opening a connection that would
  exchange cleanly and then read nothing (COMMERCE-001).
- **Webhook idempotency is real.** `WebhookIngest` writes the row before acting on it, behind a unique
  index on the event id, so a redelivery is a duplicate-key no-op rather than a second order.
- **No PKCE.** Zid uses the authorization-code grant for confidential clients and publishes no code
  challenge.

**Not claimed:** no Salla or Zid credential exists on this install and no live round trip has been
made against either. Both remain `BLOCKED_EXTERNAL_CREDENTIALS`. **Nothing here is `LIVE_VERIFIED`.**

---

## §14 — The production create-project failure, and what it was standing on (2026-08-17)

The first live Snapchat authorisation succeeded, discovery catalogued 309 ad accounts, and the
customer reached the connection wizard with no projects. Pressing **«إنشاء مشروع ومتابعة الربط»**
answered **«حدث خطأ غير متوقع.»** and created nothing.

Nothing crashed. `POST /projects` was never called. Two defects were stacked, and the second is why
the first had no symptom anybody could act on.

### PROJECT-CREATE-WORKSPACE-001 — an advertiser had no container

```ts
const workspaceId = workspaces.data?.[0]?.id
if (!workspaceId) throw new Error('لا توجد مساحة عميل.')
```

A `client_workspaces` row is an **agency's client**. `projects.client_workspace_id` is `NOT NULL`, so
every project needs one — including the projects of somebody running their own campaigns, who has no
clients and never will. `[0]` was standing in for a decision the domain had never made: it is nothing
at all for an advertiser, and for an agency it silently files a new project under whichever client
sorted first.

The rule now, in `CanonicalWorkspace`, and it never picks among several:

| Account type | Answer |
|---|---|
| `personal` — freelancer, agency, in-house team | `null`. Ask. One client today is not a rule about tomorrow |
| `company` — brand, self-serve | The canonical container: the only existing workspace adopted, or one created under the tenant lock |
| not yet answered (onboarding abandoned) | Decided by shape, and only where the shape leaves exactly one possible answer — zero or one workspace. Two or more is agency-shaped whatever the column says, and gets asked |

`client_workspaces.is_canonical` is additive with a partial unique index per tenant. Nothing is
backfilled: adoption happens the first time a single-client tenant resolves its container, in code
that can see the account type, rather than by guessing for every tenant at once.

### ERROR-HONESTY-001 — the message was written and then discarded

`toApiError` reads `message` off an axios **envelope**. A locally thrown `Error` has no response, no
status and no envelope, so it fell through to the `unexpected` branch and its own precise message —
«لا توجد مساحة عميل.» — was replaced by the generic string. The wizard knew exactly why it could not
proceed and the customer was told nothing.

A `Refusal` class now carries interface-decided refusals through with their own words. Deliberately
*not* «trust every `Error.message`»: most thrown errors are ours, and «Cannot read properties of
undefined» is not a sentence to put in front of a customer.

### And why the organisation list reads as UUIDs

Discovery lived as a **private method on the OAuth callback**, so the only way to refresh a catalogue
was to authorise again. The 309 accounts were catalogued before this product recorded `parent_name`
at all; the hierarchy endpoint then fell back to `'name' => $external_id`, which is how a column of
raw Snapchat organisation UUIDs came to be rendered as though the provider had named them that.

`AccountDiscovery` is the same code, reachable: `POST /connections/{id}/refresh` re-asks with the
token already held. No consent screen, because the authorisation never lapsed — re-consenting to
repair our own missing column is not a fix, it is a bill. The endpoint upserts on the table's own
unique key, moves no external id, and **marks** an account that stops coming back rather than deleting
it: a provider having a bad minute must not be able to erase a customer's inventory, and a bound
account may be holding a year of history.

The hierarchy now returns `name: null` and the interface says «الاسم غير متاح» beside the button that
fetches it. An identifier shown as a name claims the provider called it that.

**Not claimed:** none of the above changes the live Snapchat readiness. Selection, assignment and the
first real scoped sync still require an authenticated production session, and Snapchat remains
`BLOCKED_OPERATIONAL_EVIDENCE` — **not** `LIVE_VERIFIED`.

---

## §15 — The other copy of the same rule (2026-08-17)

`ASSIGN-PROJECT-001` was fixed in `AccountStructureSyncer`. The metrics side has its own copy of that
method, and it was not.

```php
// AccountMetricsSyncer::projectIdFor(), unchanged since before the fix
return Project::withoutGlobalScopes()
    ->where('tenant_id', $account->tenant_id)
    ->orderBy('created_at')
    ->value('id');
```

`MetricSyncRun` carries `BelongsToProject`; `SyncRunController` reads runs project-scoped. So a
metrics run for an account assigned to **client B** — assigned correctly, doing exactly what it was
told — was filed under **client A's oldest project** for as long as it had no campaigns yet, which is
precisely the window a FIRST sync runs in. Client A's operator then read sync history naming client
B's provider, account and row counts, and `DataFreshnessService` computed A's freshness from B's runs.

Two copies of one rule, one of them corrected. That is what let it survive a review of the fix, and
it is why the answer now comes from `AccountAssignment` — the single place that knows — in both.

The `NOT NULL` on `metric_sync_runs.project_id` was not incidental to this; it was the **reason** for
the fallback. The column's own comment said so: «an account that feeds nothing yet still needs a
project to file the run under (the column is NOT NULL and a run with no home would be unreadable)».
A schema rule was forcing a claim the data could not support. It is nullable now, and an unassigned
account is refused with its own status — `awaiting_assignment` — before any provider call.

### What one timestamp could not say

`last_synced_at` alone had to stand in for three different facts:

| The truth | What the column showed |
|---|---|
| Nobody has ever tried | null |
| We tried an hour ago and the provider refused | whatever it was before — stale |
| We succeeded at 03:00, due again at 03:30 | a timestamp that also looks stale when a sweep is late |

Three additive columns now answer one each: `last_sync_attempt_at`, `last_sync_error_category`,
`next_sync_at`. Nothing is backfilled — null means «never recorded», which is the truth for every row
that predates them.

Two smaller faults fell out of writing them. The success stamp lived inside `retainRaw()`, a method
about keeping the provider's raw bodies, so a connector that is not an `ApiAdvertisingConnector`
synced successfully and its account still read «never synced»; and the stamp was written *before*
`finish()` decided the status, so a run ending «the provider returned no insight rows» had already
claimed a sync. A checkpoint belongs to the outcome, and is now written where the outcome is decided.

### Health is per account

`AccountHealth` derives seven states in «most actionable first» order, each naming a different
person's next move. `DELAYED` is deliberately distinct from `FAILED`: nothing errored, the data is
merely older than the schedule allows, and reporting a late sweep as an error sends somebody to
reconnect an authorisation that is working.

A connection SUMMARISES its accounts — «10 مربوطة · 9 سليمة · 1 يحتاج انتباه» — rather than claiming
one state for all of them. Ten accounts behind one authorisation with one whose access was withdrawn
used to render as a single green «متصل», and that one account is the only fact on the card anybody
needed.

### One thing that needed no fix, recorded so nobody adds it

§38 asks for cache invalidation after a successful sync. There is **no read-path cache** to
invalidate: dashboard, analytics, campaigns, reports and the client portal all query live. The only
caches in the application are the OAuth state store, the PDF render handoff, the request-label
lookup and the scheduler heartbeats — none of which carries a figure. Client-side, TanStack Query is
invalidated at the confirmation. Adding a server cache in order to have something to invalidate would
be inventing the staleness this section exists to prevent.

---

## §16 — The third copy of the ownership rule, and the commerce side (2026-08-17)

`projectIdFor()` existed three times, each ending in a variation of «and otherwise, the tenant's
oldest project»:

| Where | Fixed in | How it presented |
|---|---|---|
| `AccountStructureSyncer` | `fdff1fc` | 309 discovered accounts' campaigns into one project nobody chose |
| `AccountMetricsSyncer` | this programme | one agency client's sync history visible in another's |
| `StoreSyncer` | this programme | one client's Salla/Zid revenue in another client's funnel |

Commerce was the worst of the three because it had no assignment concept to fall back *from*.
`ProjectStores` said so in its own comment: «which project such a store will land in is not knowable
until the first sweep files it». A store is now assigned through the same `ProjectIntegrationBinding`
an ad account uses, `SyncStoresCommand` sweeps assigned stores only, and `SyncStoreJob` re-proves its
scope at run time exactly as the ad-platform workers do.

Both ad-platform syncers also kept an «existing campaign wins» fallback, justified as «a re-sync
never re-files work already placed». That reasoning is about DISPLAY and was being applied to WRITES:
it could only fire for an account the worker had already refused, while leaving a second route by
which data could enter a project nobody assigned it to. Removed. Nothing already filed is moved or
deleted — it stops receiving new writes, which is what detaching means.

`isActivelyAssigned()` now re-proves the whole chain rather than its first link: the binding, the
project it names, that project's tenant, the client-workspace fence, and the connection's status. A
queued job can outlive the project it points at, and a binding row whose project is gone is a
leftover rather than an authorisation.

---

## §18 — The live Snapchat «validation error», root-caused (2026-08-17)

The first real Snapchat metrics sync, on a live connection with a real assigned account, returned
**0 metrics** and:

> Request cannot be processed due to validation error

Structure synced. Metrics did not. That asymmetry is the clue: structure never calls `/stats`.

### SNAP-WINDOW-001 — the range was UTC midnight for every account on the platform

```php
'start_time' => $from.'T00:00:00.000-00:00',
'end_time'   => $to.'T00:00:00.000-00:00',
```

Snapchat's measurement reference states the rule outright:

> **time must be of day boundary**, start_time and end_time must be both specified, or neither

and its own DAY responses carry the ad account's offset — the documented example is
`"start_time": "2016-08-05T22:00:00.000-07:00"`. For an account in `Asia/Riyadh` (UTC+3), UTC
midnight is **03:00 local**. Not a day boundary. Every DAY request this product made for a non-UTC
account was refused before a figure was read.

`ReportingWindow` now places the window in the **account's own timezone**, recorded at discovery, and
encodes it with that offset. An account whose timezone was never captured **fails with a message
naming the fix** rather than defaulting: defaulting to UTC is what broke this, and defaulting to
`Asia/Riyadh` would be the same mistake wearing a different constant.

`end` is exclusive, which is also what makes a single day expressible — a naive «from = to = today»
produces a zero-length range no provider accepts.

### SNAP-PAGING-001 — the first page was taken for the whole answer

The same reference gives the contract: `limit` up to 200, and `paging.next_link` for the rest. The
connector read one response and returned. An account with 201 campaigns reported 200 and lost the
rest in silence. Identical in shape to `LINKEDIN-PAGE-001` — found there first only because
LinkedIn's default page size is 10 and so bites on a small account, where Snapchat's bites on a large
one.

### SNAP-CHUNK-001 — a month asked for in one range

A first sync asks for thirty days at once. A provider that caps a DAY range refuses the **whole**
request rather than truncating it, so the customer's very first sync would be the one that fails.
The reference states **no** hard cap for DAY granularity — the one-day rule is TOTAL granularity, and
the thirty-day rule is Lead Gen — so an assumption either way would be a guess. Requests are
therefore split on a configured ceiling, deliberately conservative: each chunk upserts idempotently
on `(account, campaign, date, metric)`, so splitting costs round trips and nothing else, and a cap we
have not been told about cannot break a first impression.

### Finalisation, recorded rather than guessed

> **IMPORTANT: Metrics are finalized 48 hours after the end of the day in the timezone set by the Ad
> Account.**

`integrations.incremental.provisional_hours` records that number so the seven-day restate window has
a stated reason instead of «seven felt safe»: seven days covers a 48-hour finalisation plus a weekend
of missed sweeps, and older days are final and are not re-fetched on every run.

**Not claimed:** this is the fix for the error the live sync returned. Whether the live sync then
succeeds is `BLOCKED_OPERATIONAL_EVIDENCE` until it is run against the real account after deploy.
Snapchat remains **not** `LIVE_VERIFIED`.
