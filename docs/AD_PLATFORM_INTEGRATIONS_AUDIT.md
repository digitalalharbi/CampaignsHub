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
