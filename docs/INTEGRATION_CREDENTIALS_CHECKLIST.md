# INTEGRATION CREDENTIALS CHECKLIST — CampaignsHub

**What this is:** every external provider the product actually integrates with, the exact environment
variables it reads, the redirect and webhook URLs to register with that provider, and the honest
state of each one today.

**What this is not:** a list of things that are broken. Every adapter below is written, tested
against recorded fixtures and wired into the product. What none of them has is a credential — and a
credential is not something code can supply.

## The vocabulary, used exactly

| state | meaning |
|---|---|
| `VERIFIED` | Built, tested, and proven working here. |
| `READY_FOR_CREDENTIALS` | Code, config, storage, states and tests are complete. Supply the credential and it runs. |
| `BLOCKED_EXTERNAL_CREDENTIALS` | Waiting on a credential only the operator can obtain. |
| `BLOCKED_OPERATIONAL_EVIDENCE` | Code is ready; what is missing is evidence from a real environment. |
| `LIVE_VERIFIED` | **Real credentials + a real auth round trip + account discovery + a first live sync or payment + a real webhook + the result visible in the product.** Nothing less. |

**No provider in this document is `LIVE_VERIFIED`, and none may be marked so from this machine.**
There are no credentials on this install, so no live round trip has ever been made.

---

## 1. Payments

### Moyasar — the primary gateway

| | |
|---|---|
| State | `READY_FOR_CREDENTIALS` |
| Env | `MOYASAR_PUBLISHABLE_KEY` · `MOYASAR_SECRET_KEY` · `MOYASAR_WEBHOOK_TOKEN` |
| Webhook URL | `POST {APP_URL}/api/v1/payments/webhook/moyasar` |
| Callback | `{FRONTEND_URL}/signup/status` |
| Currency | **USD** — subscriptions are sold in USD (`SUBSCRIPTION_CURRENCY`) |

Rules the code enforces, and which the credential setup must respect:

- The **publishable** key is the browser's and is the only one that may reach the frontend. The
  **secret** key is the server's alone and must never appear in a bundle, a log or an API response.
- **Both** the secret key and the webhook token are required before `isConfigured()` returns true. A
  secret key without a webhook token would open checkouts that nothing could ever confirm.
- Test and live keys must **match each other**. `sk_live_…` beside `pk_test_…` is the mismatch that
  produces «it succeeded in the browser and never existed on the server». `production:check` fails on it.
- Moyasar's webhook authenticates with a **shared secret in the body** (`secret_token`), not an HMAC.
  That cannot prove the body is unmodified, so the comparison is constant-time AND the amount and
  currency are re-checked against our own record before anything settles.
- Moyasar publishes **no card fingerprint**, so `paymentMethodFingerprint()` returns null rather than
  brand+last4 — thousands of cards share those, and using them would block innocent customers.

#### Automatic renewal — a MERCHANT-ENABLEMENT question, not a credential (`PAY-TOKEN-003`)

Updated 2026-08-11. The saved-card path is now built end to end: a verified, gateway-re-read payment
that carries a reusable token files the card (`subscription_payment_methods`, token encrypted and
hidden from serialisation), and the next renewal is DEBITED instead of being sent as an invoice.

There is **no environment variable for this**, and nothing to ask for beyond the keys above. Whether
it engages depends on whether Moyasar issues a reusable token with the payments this merchant account
settles:

- **It does** → the card is filed on first payment and renewals are taken unattended.
- **It does not** → every renewal arrives as an invoice the customer visits and pays. That is a
  working way to be paid; what makes it worth knowing is the shape of the failure when they miss one
  — the account goes past due and then suspended, which reads like dunning working correctly.

`/admin/settings` reports which state this install is in, as two separate numbers: whether the
gateway CAN charge a saved card, and how many cards actually exist. On a fresh install that is
ready-and-zero, which is the honest reading.

**What to verify on the first sandbox payment:** that the settled payment's `source` carries a
`token`, and that `subscription_payment_methods` gains a row. Until a real payload has been seen, the
capture half is `READY_FOR_CREDENTIALS` — the refusal half (no token → no card → no charge) is
verified. There is deliberately **no endpoint that adds a card**: a token arrives only from a payment
the gateway settled.

#### Test and live keys, in both directions

`production:check` fails a **test** key in production (customers charged nothing while the product
reports them paid) **and** a **live** key outside production (`PAY-ENV-001`) — a laptop, staging box
or CI run holding `sk_live_…` charges real cards against a database thrown away nightly, and copying
a working production `.env` is how most staging environments start.

### Stripe — supported by the same port

| | |
|---|---|
| State | `READY_FOR_CREDENTIALS` |
| Env | `STRIPE_PUBLISHABLE_KEY` · `STRIPE_SECRET_KEY` · `STRIPE_WEBHOOK_SECRET` |
| Webhook URL | `POST {APP_URL}/api/v1/payments/webhook/stripe` |

Same rules. Stripe *does* publish a stable payment-method fingerprint, which is what makes
«one introductory month per payment method» enforceable without the system ever seeing a card.

### Sandbox — for installs with no credentials

Signs and verifies a **real** webhook over a local secret (`SUBSCRIPTION_SANDBOX_SECRET`) so the
whole payment path can be walked. **Inert in production**, and `production:check` fails a production
install that still names it as `SUBSCRIPTION_PROVIDER`.

---

## 2. Advertising platforms

Each adapter is complete and read-only: semantic metric mapping, pagination, absent-is-never-zero,
idempotent sync, fixtures.

**The blanket sentence that used to stand here — «No OAuth round trip has ever been made for any of
them» — is no longer true, and leaving it would have been the worse error.** Per
`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, **Meta** and **Snapchat** have both completed a real
authorisation, account discovery, a binding and live syncs on production, with run ids and row
counts recorded there (Meta, 2026-09-06: structure `success records=58`, metrics `stored 317`;
Snapchat: 3,420 rows). That evidence is the matrix's, cited here rather than re-claimed.

The rest are **`BLOCKED_EXTERNAL_CREDENTIALS`**, and which of them is configured on THIS install is
a question only the install can answer: `/admin` → provider readiness reports it per provider. Do
not read a state for your install out of this table.

| Provider | Env |
|---|---|
| Meta Ads | `META_ADS_APP_ID` · `META_ADS_APP_SECRET` · optional `META_ADS_CONFIG_ID` (unset = dialog asks for `scope` as before) |
| Meta **Candidate** app (META-CANDIDATE-001) | `META_CANDIDATE_APP_ID` · `META_CANDIDATE_APP_SECRET` · `META_CANDIDATE_CONFIG_ID` · optional `META_CANDIDATE_SCOPES` (comma list, default `ads_read`) — see below |
| Google Ads | `GOOGLE_ADS_CLIENT_ID` · `GOOGLE_ADS_CLIENT_SECRET` · `GOOGLE_ADS_DEVELOPER_TOKEN` — **no manager (MCC) account id** (GADS-MCC-001) |
| TikTok Ads | `TIKTOK_ADS_APP_ID` · `TIKTOK_ADS_APP_SECRET` |
| Snapchat Ads | `SNAPCHAT_ADS_CLIENT_ID` · `SNAPCHAT_ADS_CLIENT_SECRET` — **no organisation id** (SNAP-ORG-001) |
| X Ads | `X_ADS_CLIENT_ID` · `X_ADS_CLIENT_SECRET` |
| LinkedIn Ads | `LINKEDIN_ADS_CLIENT_ID` · `LINKEDIN_ADS_CLIENT_SECRET` · `LINKEDIN_ADS_VERSION` |
| ChatGPT Ads (OpenAI Advertiser API) | **Nothing.** Optional `OPENAI_ADS_API_BASE` only, which overrides the documented host — see below |

### ChatGPT Ads has no install-level credential, and that is not an omission

Every other row above is an app we register once: a client id and secret identifying CampaignsHub to
the platform, shared by every tenant, with each customer's own access arriving afterwards through
consent. OpenAI publishes no such app for advertising. The only credential is the advertiser's own
bearer key, created in their OpenAI Ads console and **scoped to one ad account**.

So there is nothing for a platform operator to enter, the admin console shows no fields for it, and
`isConfigured()` is true with nothing set. A field here would be a platform-wide store for a
per-tenant secret: an operator who filled it would hand every tenant on the install the same key —
one customer's ad account reported inside every other customer's workspace.

The tenant enters the key at connect (`POST /api/v1/integrations/openai_ads/api-key/connect`). It is
encrypted at rest on `integration_credentials` with `credential_type = 'api_key'` and no expiry,
never returned after save, never logged, and shown back only as its last four characters. Rotation
is the same endpoint: it re-credentials the existing connection in place.

**Status: `AWAITING_CREDENTIALS`.** The adapter, the connect flow, the storage and the guards exist.
No real key has been through the chain, so nothing here may be called VERIFIED, and availability of
ChatGPT Ads itself is limited by OpenAI — not something this product can state for a market or an
advertiser.

**Redirect URL to register with each platform (OAuth providers only — ChatGPT Ads never redirects a
browser and publishes no redirect URI):**
`GET {AD_PLATFORM_REDIRECT_BASE or APP_URL}/api/v1/oauth/ads/{provider}/callback`

**Webhook URL (advertising family):**
`POST {APP_URL}/api/v1/webhooks/ads/{provider}` — the same path answers `GET` for the
subscription-verification handshake several platforms perform before they will deliver anything.

### Meta Candidate app (META-CANDIDATE-001)

One isolated second Meta app, used only to prove a Facebook Login for Business round trip before it
replaces the Live app. It never touches the Live app's credentials, customer connections, accounts or
data.

**Where the Owner sets it (no values in git, chat or logs):** `/admin/settings/integrations/meta-candidate`
→ App ID, App Secret, Configuration ID (stored encrypted in `provider_configurations`, row
`meta.candidate`). The environment keys above are the fallback, exactly like the Live keys; none has a
default and none falls back to a Live value.

**In the new Meta app (developers.facebook.com):**
- Facebook Login for Business → Settings → Valid OAuth Redirect URIs:
  `https://api.campaignshub.io/api/v1/oauth/ads/meta/callback` (the same URL as Live; the profile
  travels in the signed state).
- Settings → Basic → App Domains: `campaignshub.io` (and `api.campaignshub.io`).
- Facebook Login for Business → Configurations → create one with token type **User access token**,
  asset type **Ad accounts**, permission **ads_read**. Its ID is the Configuration ID.
- No webhooks are needed.

**Running the test:** `/admin/settings/integrations/meta-candidate` → «Run connection test» → consent
at Meta → back on the same page with the step checklist (OAuth start, consent, token exchange,
`me/adaccounts`, one `ads_read` insights read for the last 7 days). The same result is printed, read-only,
by `php artisan integrations:meta-candidate`, and by the **Production diagnostics** workflow when it
is dispatched with `provider: meta` (no new input).

**Promote / roll back (prepared, never automatic):** the same page offers «Promote candidate to Live»
only when the latest round trip succeeded with the credentials configured now, and «Roll back» only
after a promotion. Promotion switches the Live row's App ID, App Secret, Configuration ID and scopes
to the candidate's and keeps the previous values in `meta.previous_live`. It does not touch customer
connections or tokens and forces no reconnect. Meta tokens are app-scoped: existing connections keep
working while the old app stays valid, and a connection whose token nears expiry is refreshed with
the new app's credentials. Meta refuses that, so the connection asks for a reconnect at that point.

Set `AD_PLATFORM_REDIRECT_BASE` explicitly in a split deployment — several providers refuse to
register a redirect that does not match byte for byte.

Tokens are stored through `TokenVault` (encrypted at rest) with refresh and revocation handled by
`OAuthTokens`. Sync history, last-sync time, error states and reconnect are per tenant and fail
closed.

**Two semantic decisions to confirm against real data on the first live sync:**

- **LinkedIn** deliberately reports **no revenue and no purchases**. `conversionValueInLocalCurrency`
  is the value the advertiser *assigned* to a conversion — on a B2B platform usually an internal
  worth put on a lead — so reporting it as revenue would put a ROAS on a client's report built from
  money nobody had taken. If a LinkedIn account genuinely measures sales, revisit this.
- **TikTok**'s `initiate_checkout` spelling could not be verified verbatim against the developer
  portal. It is mapped anyway because a wrong spelling **fails safe** — no key, nothing stored, and
  the funnel reads «لم تُرسل» rather than a zero.

---

## 3. Commerce platforms

Both are **`BLOCKED_EXTERNAL_CREDENTIALS`**; adapters, syncers, order attribution and webhook
handling are complete.

| Provider | Env | Webhook URL |
|---|---|---|
| Salla | `SALLA_CLIENT_ID` · `SALLA_CLIENT_SECRET` · `SALLA_WEBHOOK_SECRET` | `POST {APP_URL}/api/v1/webhooks/commerce/salla` |
| Zid | `ZID_CLIENT_ID` · `ZID_CLIENT_SECRET` · `ZID_WEBHOOK_USERNAME` · `ZID_WEBHOOK_PASSWORD` — **no signing secret** (ZID-WEBHOOK-001) | `POST {APP_URL}/api/v1/webhooks/commerce/zid` |

**Redirect URL for both:** `GET {APP_URL}/api/v1/oauth/commerce/{provider}/callback`

The advertising and commerce webhook families are deliberately on separate paths (`webhooks/ads/…`
and `webhooks/commerce/…`). They share the verification machinery and nothing after it — one
discovers ad accounts, the other stores — and one endpoint branching on the provider is how two sets
of rules drift into each other.

**Zid publishes no abandoned-cart endpoint.** Its connector REFUSES rather than returning an empty
list, so the run reads `partial` and the UI says «لا توفّرها المنصة». That is deliberate: an empty
list and «the platform does not offer this» are different facts, and only one of them is true.

**Set the client's timezone before the first sync** (`COMMERCE-TZ-001`, added 2026-08-11). Every
store timestamp is anchored through an explicit chain — the payload's own zone, then the store's,
then the **client workspace's**, then UTC as a recorded assumption:

- **Salla** states the zone on each date (`{date, timezone}`) and on the store, so it needs nothing.
- **Zid** publishes no timezone in the shape this connector reads. Without a timezone on the client
  workspace, its orders fall to `assumed_utc` — they are KEPT and counted, and every surface says how
  many had their zone assumed, but any of them may sit on the day either side of where it is shown.

Report windows are measured on the CLIENT's clock while each order keeps `placed_on`, the calendar
date its own merchant sold it on. Both facts are stored; neither overwrites the other.

---

## 4. Identity, mail and analytics

| Provider | State | Env |
|---|---|---|
| Google sign-in | `BLOCKED_EXTERNAL_CREDENTIALS` | `GOOGLE_CLIENT_ID` · `GOOGLE_CLIENT_SECRET` · `GOOGLE_REDIRECT_URI` — redirect `{APP_URL}/api/v1/auth/oauth/google/callback` |
| Apple sign-in | `BLOCKED_EXTERNAL_CREDENTIALS` | `APPLE_CLIENT_ID` · `APPLE_TEAM_ID` · `APPLE_KEY_ID` · `APPLE_PRIVATE_KEY` · `APPLE_REDIRECT_URI` |
| Email delivery | `BLOCKED_EXTERNAL_CREDENTIALS` | SMTP, or `POSTMARK_API_KEY` / `RESEND_API_KEY` |
| SMS / WhatsApp | `BLOCKED_EXTERNAL_CREDENTIALS` | Per `config/providers.php`; `Null*` adapters are the default |
| Google Analytics 4 | `AWAITING_CREDENTIALS` | `GA4_CLIENT_ID` · `GA4_CLIENT_SECRET` — **its own OAuth client, not Google Ads'** · see §4a |

**Email is the one to read carefully.** The whole notification system — digests, alerts, report-ready
messages, invitations, account mail — is built, scheduled, deduplicated by database constraint and
rendered in both languages. With no provider the delivery state is `awaiting_credentials` and
`sent_at` stays null. **Nothing is ever recorded as «sent» without a provider acknowledgement**, and
that is enforced by tests, not by convention.

GA4 is now integrated as a **measurement** source and has its own section below — §4a. The line
that used to stand here («GA4 appears in no config and has no adapter… it does not exist») was true
when it was written and is not true any more; it is replaced rather than left for somebody to act on.

---

## 4a. Google Analytics 4 — a MEASUREMENT source, not an advertising platform (`GA4-INTEGRATION-001`)

Added 2026-10-09. **State: `AWAITING_CREDENTIALS`.** The catalogue entry, the consent journey, property
discovery, property selection, the Data API read, the four-hourly sweep, the Connection Hub section and
the client-report section all exist and are tested. No OAuth client exists for this install, so no live
property has ever been discovered, read or reported — and nothing here may be called verified until one
has.

### What GA4 is NOT, in this product

It is a third `ProviderKind`, and the separation is load-bearing rather than tidy:

- It is **not** in `AdvertisingConnectorRegistry` and not among `ProviderCatalogue::ofKind(Advertising)`.
- It does **not** appear on the Connection Hub, which is the paid-media board. It has its own section
  on `/app/integrations`, below paid media and below commerce.
- Its figures are **never** added to, divided by, or shown as one number with an ad platform's. GA4
  measures the client's own site under GA4's attribution; an ad platform reports what IT believes its
  ads caused. A blended return from the two is a number that is true of neither.
- It is **not** counted as a connected advertising platform in any «which platforms are connected»
  answer, and it consumes no ad-account quota.

### The two credentials, and why they are GA4's own

| | |
|---|---|
| Env | `GA4_CLIENT_ID` · `GA4_CLIENT_SECRET` |
| Config | `config/measurement_platforms.php` (its own file, like `commerce_platforms.php`) |
| Scope requested | `https://www.googleapis.com/auth/analytics.readonly` — **this one only** |
| Redirect URI | `{APP_URL}/api/v1/oauth/measurement/ga4/callback` — on production, `https://api.campaignshub.io/api/v1/oauth/measurement/ga4/callback` |
| Webhook | **None.** GA4 publishes no webhook; it is polled (`WebhookSupport::PollingOnly`) |

**Do not reuse the Google Ads client.** They are different consent screens asking for different
scopes: a customer connecting Analytics would be asked for advertising access, and revoking one would
silently break the other.

### What the Owner must do, in order

1. **Google Cloud project** — use an existing one or create one. Enable **both**:
   - Google Analytics **Admin** API (property discovery)
   - Google Analytics **Data** API (the reporting read)

   Enabling only the Data API is the common half-step. Discovery then fails with «Admin API has not
   been used», which the product reports as a refusal — never as «no properties found».

2. **OAuth consent screen** — publish it, requesting **only** `analytics.readonly`. Google reviews
   this scope; a wider request (`analytics.edit`, `analytics.manage.users`) both delays review and puts
   a claim in front of your customers that this product may change their Analytics configuration. It
   cannot and must not ask to.

3. **OAuth client** — type **Web application**. Add the redirect URI above **byte for byte**. Copy the
   client id and secret.

4. **Put them in the environment** on the API host: `GA4_CLIENT_ID`, `GA4_CLIENT_SECRET`. Never in the
   repository. Or store them through `/admin` → provider configuration, which encrypts them at rest and
   takes precedence over the environment.

5. **Connect, as a tenant:** `/app/integrations` → **التحليلات والقياس** → «ربط أناليتكس». Google's own
   consent screen appears; the returning browser lands back on the integrations page with the number of
   properties discovered.

6. **Select one property per project.** Discovery selects NOTHING — an agency's Google account commonly
   reaches dozens of clients' properties, and syncing what it can see would pull one client's web
   analytics into another client's project. Choose the project in the section's selector, then press
   «اختر لهذا المشروع».

7. **Press «اقرأ الآن»** on the selected property. The first read is also what teaches the product the
   property's **timezone** and **currency** — discovery deliberately does not ask, so a never-read
   property honestly says «يُعرف عند أول قراءة» rather than being shown a guessed UTC.

8. **Turn the report section on** where you want it. `site_measurement` is **off by default** on
   client-facing reports: an operator who shows a client their site measurement beside the campaigns'
   figures is choosing to explain the difference, and that choice is deliberately theirs.

### What proves it, and what does not

A completed consent is not proof. For `GA4-INTEGRATION-001` to move past
`IMPLEMENTED_NOT_VERIFIED` the whole chain has to be observed on a real property: consent →
properties discovered → one selected → a read that returns days → those days on a client report, in
the property's own timezone, with the property's own currency on its revenue and with the ad
platforms' KPI revenue **unchanged** by it.

### Two things to expect, so they are not read as faults

- **A refresh token arrives only on the FIRST consent.** Google issues it when the authorise URL asks
  for `access_type=offline` and `prompt=consent` together — which it does — and omits it from every
  later refresh response. The stored one is kept for that reason.
- **The Data API meters in TOKENS per property per day, not requests.** A wide report costs more than
  a narrow one, and exhaustion arrives as `RESOURCE_EXHAUSTED`. That is why the sweep is four-hourly
  rather than half-hourly, why the manual read is throttled, and why a backfill is capped at 365 days
  per request. The product reports the refusal; it never writes the exhausted day down as zeros.

---

## 4b. Exchange rates — a CONFIGURATION decision, not a credential (`FX-FEED-001`)

Added 2026-08-11. This one does not belong with the credentials above, and putting it there would ask
the wrong question of the wrong person.

| | |
|---|---|
| State | `READY_FOR_CONFIGURATION` |
| Env | `FX_RATE_DRIVER` — **unset, deliberately** |
| Console | `/admin/settings/currency-rates` |
| Command | `fx:rates` (daily 02:30; writes nothing and exits 0 when unconfigured) |

**No publisher is chosen in this repository, on purpose.** Which source a deployment trusts for
exchange rates is a commercial decision with a contract behind it; a default here would make it
silently, and every converted figure in the product would carry a provenance nobody picked.

Until one is chosen, money in a currency other than a client's reporting currency is **withheld** —
reported as withheld on the funnel, the dashboard and the client's own link, never guessed and never
counted as zero. The product tells the truth without a rate source; what it cannot do is convert.

Two ways to satisfy it, and both are legitimate:

1. Point `FX_RATE_DRIVER` at a class implementing `CurrencyRateSource`, once somebody has chosen and
   contracted with a publisher.
2. Record rates by hand at `/admin/settings/currency-rates`. They are stored as `manual:<email>` and
   audited, because an operator is a real source and a conversion has to lead back to a person.

The console lists the pairs the absence has ALREADY cost, worst first, derived from the figures
actually withheld in both pipelines — so a currency nobody thought to list appears the moment it
costs somebody a number.

## 4c. The compliance URLs every platform review asks for (`LEGAL-DELETE-001`)

Before any of the six advertising platforms will approve an app, its console asks for these. They are
public, bilingual, need no session and never redirect to `/login`.

| Field in the provider console | URL |
|---|---|
| Privacy Policy URL | `{FRONTEND_URL}/privacy` |
| Terms of Service URL | `{FRONTEND_URL}/terms` |
| User Data Deletion URL | `{FRONTEND_URL}/data-deletion` |
| Data Deletion Callback URL — **Meta only** | `POST {APP_URL}/api/v1/webhooks/data-deletion/meta` |

Read them from `/admin` → integration readiness rather than typing them: they are derived from the
configured URLs, so a copy cannot go stale the day the domain changes — and a stale one is a rejected
review with no obvious cause.

**The callback is not a formality.** It verifies Meta's `signed_request` against the app secret with
a constant-time HMAC comparison, and **answers 503 while that secret is absent** rather than opening
a deletion for anyone who finds the URL. A signed request opens one verified request, idempotently,
and answers `{url, confirmation_code}` in the shape Meta expects. Only Meta asks for a callback
today; the endpoint exists for any provider that adds the requirement.

## 5. What to do with a credential once you have it

1. Put it in the environment. Never in the repository.
2. Run `php artisan production:check`. It fails on a test key in production, a mixed test/live pair,
   and a gateway secret with no webhook secret — and it reports the *shape* of a key, never its value.
3. Register the redirect and webhook URLs above with the provider, byte for byte.
4. Open `/admin` → the provider's readiness panel and run its **Test configuration** action. It makes
   a real, safe call and refuses before making one if the provider is not fully configured.
5. Only after a real auth round trip, account discovery, a first sync or payment and a real webhook
   whose result is visible in the product may that provider be recorded as `LIVE_VERIFIED` — in
   `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, with the evidence named.
