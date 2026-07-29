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
