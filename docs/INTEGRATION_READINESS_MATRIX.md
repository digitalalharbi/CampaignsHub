# Integration readiness matrix — INTEG-READINESS-001

One question decides every cell in this document:

> When a credential arrives, does connecting require **writing code**, or only **entering it**?

Anything that needs code is a readiness gap and is named as such. Anything that needs only a secret
is `BLOCKED_EXTERNAL_CREDENTIALS` — complete on our side, waiting on someone else's.

Nothing here is marked from a mock, a test, or a plan. Each cell cites the file that makes it true.

---

## 0. What this document corrects

`AD_PLATFORM_INTEGRATIONS_AUDIT.md` §2 states that all six ad connectors extend
`AwaitingCredentialsConnector` and that every capability cell is empty. **That is no longer true and
has not been for some time.** `AwaitingCredentialsConnector` does not exist in the tree; every
connector extends a real API base:

```
GoogleAdsConnector  LinkedInConnector  MetaConnector
SnapchatConnector   TikTokConnector    XConnector      → ApiAdvertisingConnector
SallaConnector      ZidConnector                       → ApiCommerceConnector
```

Reading that section today would produce a plan to build what is already built. §2 of that document
is superseded by this matrix; the rest of it — the per-provider corrections in §7–§10 — remains
accurate and is where the reasoning behind several of these cells lives.

---

## 1. Legend

| state | meaning |
|---|---|
| `VERIFIED` | real code, exercised against the real provider, evidence recorded |
| `IMPLEMENTED_NOT_VERIFIED` | real code, complete, never yet run against the live provider |
| `BLOCKED_EXTERNAL_CREDENTIALS` | our side is done; a secret we do not hold is the only thing missing |
| `UNSUPPORTED` | the provider does not offer it — not a gap |
| `NOT_STARTED` | genuinely absent, and a real readiness gap |

`IMPLEMENTED_NOT_VERIFIED` is the honest resting state for almost everything below. It is **not** a
softer way of saying done: it means the code path has never met the provider, and the first real
connection is where the remaining defects will surface.

---

## 2. Foundation — shared by every provider

| capability | where | state |
|---|---|---|
| Ad provider contract | `Integrations/Contracts/AdvertisingConnector.php` | `VERIFIED` — `authorizationUrl`, `handleCallback`, `healthCheck`, `listAdAccounts`, `syncCampaigns`, `syncAdSets`, `syncAds`, `syncInsights` |
| Store provider contract | `Commerce/Contracts/CommerceConnector.php` | `VERIFIED` — `fetchStores`, `syncProducts`, `syncOrders`, `syncCustomers`, `syncAbandonedCarts`, `healthCheck` |
| OAuth + **PKCE** | `Integrations/OAuth/PlatformOAuth.php` | `VERIFIED` — `codeVerifier`/`codeChallenge`, per-provider callback code parameter |
| Token refresh | `PlatformOAuth::refresh`, `RefreshAdPlatformTokensCommand` | `VERIFIED` — refresh with a configurable skew (`refresh_skew_minutes`) |
| Encrypted credential storage | `Models/IntegrationCredential`, `OAuth/TokenVault` | `VERIFIED` — encrypted payload, never logged, never returned by an API |
| OAuth state | `OAuth/AuthorizationState` | `VERIFIED` — TTL 15 min (`state_ttl_minutes`) |
| Webhook signature | `Webhooks/WebhookSignature` | `VERIFIED` — HMAC-SHA256 compared with `hash_equals`, not `===`; Zid's basic-auth header compared whole |
| Webhook ledger + **idempotency** | `Models/IntegrationWebhookEvent`, `Webhooks/WebhookIngest` | `VERIFIED` — unique index on `(provider, fingerprint)`, insert-before-work, savepoint-scoped duplicate recovery returning the existing row |
| Rate limits / retry / backoff | `Support/PlatformHttp` (+14 files) | `IMPLEMENTED_NOT_VERIFIED` |
| Pagination / cursors | `Reporting/ReportingWindow` + per-connector (`MAX_PAGES`) | `IMPLEMENTED_NOT_VERIFIED` |
| Idempotent upsert | `Metrics/UpsertDailyMetrics` (+24 files) | `VERIFIED` — exercised continuously by Snapchat |
| Connection wizard | `Services/ConnectionWizardState`, `ConnectionWizardController`, `frontend/features/integrations/ConnectionWizard.tsx` | `VERIFIED` — resume-safe **by construction**: each step is derived from a database fact, not a cookie, so a wizard abandoned in a closed browser resumes exactly where it stopped |
| Integration Center UI | `frontend/src/features/integrations/` | `IMPLEMENTED_NOT_VERIFIED` |
| Provider catalogue | `Catalogue/ProviderCatalogue` | `VERIFIED` — all 8 providers, credential fields declared per provider |

**Consequence: no provider below is blocked on architecture.** Every one has its endpoints, scopes
and credential fields already declared in `config/ad_platforms.php` or `config/commerce_platforms.php`,
read from environment variables. Connecting is entering a secret, not shipping a release.

---

## 3. Per-provider readiness

`Auth · Accounts · Entities · Metrics · Media · Currency · Attribution · Sync · Backfill · Webhooks · Health · UI`

### Ad platforms

| provider | API version | auth | accounts | entities | metrics | media | currency | attribution | sync | backfill | webhooks | health | UI |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Snapchat** | v1 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | `UNSUPPORTED` (polling) | ✅ | ✅ |
| **Meta** | v25.0 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | ✅ |
| **TikTok** | v1.3 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | `UNSUPPORTED` (polling) | 🔑 | ✅ |
| **Google Ads** | v25 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | `UNSUPPORTED` (polling) | 🔑 | ✅ |
| **X** | — | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | `UNSUPPORTED` (polling) | 🔑 | ✅ |
| **LinkedIn** | configurable | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | `UNSUPPORTED` (polling) | 🔑 | ✅ |

### Stores

| provider | API | auth | store | orders | products | refunds | customers | currency | UTMs | webhooks | sync | backfill | health | UI |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Salla** | admin/v2 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 signed | 🔑 | 🔑 | 🔑 | ✅ |
| **Zid** | v1 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 | 🔑 basic-auth | 🔑 | 🔑 | 🔑 | ✅ |

✅ `VERIFIED` — evidence recorded · 🔑 `BLOCKED_EXTERNAL_CREDENTIALS` — code complete, secret absent

**Only Snapchat has ever met its provider.** Every other ✅ in the ad table is Snapchat's alone, and
nothing about its success may be read across to the others: a connector that compiles and a
connector that survives a real account are different claims. The 🔑 cells are not estimates of
difficulty — they are the assertion that the code is written and the secret is not held.

### Snapchat, the one with evidence

| fact | value |
|---|---|
| accounts discovered | 309 |
| active bindings | 1 |
| campaigns | 89 (sweep maps 88 — `sbx-cmp-1` is a sandbox fixture the API refuses) |
| creative-grain insights | implements `ReportsCreativeInsights` — **alone among the six** |
| money | spend withheld, original currency preserved, never coalesced to 0 |

Creative-grain metrics being Snapchat's alone is a property of the providers, not a gap: no other
connector implements `ReportsCreativeInsights` because no other provider returns that grain. Ad
figures are never projected downward onto creatives to fill it.

---

## 4. Real readiness gaps

Everything in §2–§3 is either done or waiting on a secret. These are the items that would genuinely
require code, and they are the actual backlog.

**Two candidates were withdrawn after checking the code rather than a grep.** They are recorded here
because a false gap is more expensive than a missing one: it sends someone to build a second
implementation of something that already works.

| id | gap | why it matters |
|---|---|---|
| `READY-2` | **The Video objective headlines `cpm`, the price of an impression, not of a view.** `cost_per_view` already exists end-to-end — `EntityMetricsAggregator` computes it as spend ÷ `video_views`, `analytics/api.ts` types it, `content/metrics.ts` labels it «تكلفة المشاهدة» and counts it as money. It is simply not in `metricCatalog.ts`'s video layout. | A video campaign is bought for views, and is currently priced by impressions on the one screen meant to judge it by its own objective. The metric is there; the layout does not reach for it. |
| `READY-3` | **`ObjectiveTab` in `AnalyticsPage.tsx` keeps a private objective→KPI map.** `metricCatalog.ts` already owns the canonical one as `OBJECTIVE_LAYOUTS`/`layoutFor`; `AnalyticsPage` imports only `SPECS, dashboardMetrics` and hardcodes its own eight families, omitting `cpl`, `cpa`, `cpe`, `cpi`, `aov`, `landing_page_views` and `registrations`. | This is the "no raw metric logic inside the interfaces" rule being broken in the one screen the rule exists for. Two maps drift, and the weaker one is the one on screen. |
| `READY-4` | **`ObjectiveTab` asserts `money_original_currencies: 1`** while summing withheld spend across a family, takes the currency from the *first* withheld campaign, and on a partial family discards the converted half and prints the withheld half as the family total. | The same defect class as `PARTIAL-WITHHELD-001`: a total that omits part of its scope, plus a fabricated claim that only one currency is involved. |

### Withdrawn, and why

| withdrawn | what the code actually does |
|---|---|
| ~~webhook replay / idempotency missing~~ | **Fully implemented, and enforced by the database.** `integration_webhook_events` has a unique index on `(provider, fingerprint)`; `WebhookIngest::record` inserts *before* doing any work, catches `UniqueConstraintViolationException` inside a savepoint, and returns `duplicate: true` with the existing row. The fingerprint is the provider's own event id when present — what a retry preserves — and a SHA-256 of the raw body when not. The migration's own comment states it: «The unique index IS the idempotency guarantee.» |
| ~~CPV does not exist~~ | Narrowed to `READY-2`. The metric exists and is computed; only the analytics video layout omits it. |

Both were produced by grepping for the words `replay`, `idempot` and `cpv`. The code says
`duplicate`, `fingerprint` and `cost_per_view`. **Absence of the word is not absence of the
behaviour** — the same failure this project has already recorded three times in `RESUME_STATE.md` §5,
here committed by the audit instrument itself.

`READY-3` and `READY-4` sit in `AnalyticsPage.tsx`, which PRs in flight are changing. They are
recorded here and will be fixed on fresh main after those land, rather than opened as a competing
implementation.

## 5. What "ready" will mean when credentials arrive

For each provider the sequence is intended to be, with no code written:

1. Enter client id / secret (and Google's developer token) into the Integration Center.
2. `Connect` → OAuth, with PKCE where the provider supports it.
3. Choose account or store, choose project.
4. Confirm currency and attribution window.
5. Choose sync range; backfill runs.
6. Verify the first sync's result.

**If any step requires a code change, that is a readiness defect and belongs in §4.** The purpose of
this matrix is to make that claim falsifiable: the first real connection either walks this path or
proves the matrix wrong, and the matrix is then corrected rather than the failure explained away.
