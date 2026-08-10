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

**Not built, and required before renewals can charge unattended:** tokenization and a saved payment
method. `chargeDueRenewals` opens a real renewal charge through the same verified path, but with no
stored token the customer must return to a checkout to complete it.

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

All six are **`BLOCKED_EXTERNAL_CREDENTIALS`**. Each adapter is complete and read-only: semantic
metric mapping, pagination, absent-is-never-zero, idempotent sync, fixtures. **No OAuth round trip
has ever been made for any of them.**

| Provider | Env |
|---|---|
| Meta Ads | `META_ADS_APP_ID` · `META_ADS_APP_SECRET` |
| Google Ads | `GOOGLE_ADS_CLIENT_ID` · `GOOGLE_ADS_CLIENT_SECRET` · `GOOGLE_ADS_DEVELOPER_TOKEN` · `GOOGLE_ADS_LOGIN_CUSTOMER_ID` |
| TikTok Ads | `TIKTOK_ADS_APP_ID` · `TIKTOK_ADS_APP_SECRET` |
| Snapchat Ads | `SNAPCHAT_ADS_CLIENT_ID` · `SNAPCHAT_ADS_CLIENT_SECRET` · `SNAPCHAT_ADS_ORGANIZATION_ID` |
| X Ads | `X_ADS_CLIENT_ID` · `X_ADS_CLIENT_SECRET` |
| LinkedIn Ads | `LINKEDIN_ADS_CLIENT_ID` · `LINKEDIN_ADS_CLIENT_SECRET` · `LINKEDIN_ADS_VERSION` |

**Redirect URL to register with each platform:**
`GET {AD_PLATFORM_REDIRECT_BASE or APP_URL}/api/v1/oauth/ads/{provider}/callback`

**Webhook URL (advertising family):**
`POST {APP_URL}/api/v1/webhooks/ads/{provider}` — the same path answers `GET` for the
subscription-verification handshake several platforms perform before they will deliver anything.

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
| Zid | `ZID_CLIENT_ID` · `ZID_CLIENT_SECRET` · `ZID_WEBHOOK_SECRET` | `POST {APP_URL}/api/v1/webhooks/commerce/zid` |

**Redirect URL for both:** `GET {APP_URL}/api/v1/oauth/commerce/{provider}/callback`

The advertising and commerce webhook families are deliberately on separate paths (`webhooks/ads/…`
and `webhooks/commerce/…`). They share the verification machinery and nothing after it — one
discovers ad accounts, the other stores — and one endpoint branching on the provider is how two sets
of rules drift into each other.

**Zid publishes no abandoned-cart endpoint.** Its connector REFUSES rather than returning an empty
list, so the run reads `partial` and the UI says «لا توفّرها المنصة». That is deliberate: an empty
list and «the platform does not offer this» are different facts, and only one of them is true.

---

## 4. Identity, mail and analytics

| Provider | State | Env |
|---|---|---|
| Google sign-in | `BLOCKED_EXTERNAL_CREDENTIALS` | `GOOGLE_CLIENT_ID` · `GOOGLE_CLIENT_SECRET` · `GOOGLE_REDIRECT_URI` — redirect `{APP_URL}/api/v1/auth/oauth/google/callback` |
| Apple sign-in | `BLOCKED_EXTERNAL_CREDENTIALS` | `APPLE_CLIENT_ID` · `APPLE_TEAM_ID` · `APPLE_KEY_ID` · `APPLE_PRIVATE_KEY` · `APPLE_REDIRECT_URI` |
| Email delivery | `BLOCKED_EXTERNAL_CREDENTIALS` | SMTP, or `POSTMARK_API_KEY` / `RESEND_API_KEY` |
| SMS / WhatsApp | `BLOCKED_EXTERNAL_CREDENTIALS` | Per `config/providers.php`; `Null*` adapters are the default |
| GA4 | **Not integrated** | — |

**Email is the one to read carefully.** The whole notification system — digests, alerts, report-ready
messages, invitations, account mail — is built, scheduled, deduplicated by database constraint and
rendered in both languages. With no provider the delivery state is `awaiting_credentials` and
`sent_at` stays null. **Nothing is ever recorded as «sent» without a provider acknowledgement**, and
that is enforced by tests, not by convention.

GA4 appears in no config and has no adapter. It is not «awaiting credentials»; it does not exist.

---

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
