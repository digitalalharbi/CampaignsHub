# DEPENDENCIES — CampaignsHub

Single source of truth for external dependencies. Everything here is an external credential/account the system
cannot self-provision. None block local running or internal-flow testing; each has a safe Adapter/Sandbox/Mock.

| Dependency | Status | Adapter present | Effect while pending |
|---|---|---|---|
| Email provider (SMTP/API) | Awaiting External Dependency | `MessageProvider` (email) — Null adapter | Deliveries `awaiting_provider_credentials`, never `sent` |
| WhatsApp provider | Awaiting External Dependency | `MessageProvider` (whatsapp) — Null adapter | `awaiting_provider_credentials` |
| SMS provider | Awaiting External Dependency | `MessageProvider` (sms) — Null adapter | `awaiting_provider_credentials` |
| Google OAuth (social login) | Awaiting External Dependency | Login form works; Google button inert | No Google sign-in |
| Payment gateway (Moyasar/Tap/Stripe) | Awaiting External Dependency | `PaymentProvider`/`WebhookVerifier` (planned, Null) | No real charge; invoices stay unpaid, honest states |
| Meta / Google / TikTok / Snapchat / LinkedIn / X / Microsoft / Pinterest Ads | Awaiting External Dependency | Connector adapters; Sandbox path works | Live ad sync inert; Sandbox verified |
| GA4 / Google Tag Manager | Awaiting External Dependency | Connector adapter | No live analytics sync |
| Salla / Zid / Shopify / WooCommerce / CRM | Awaiting External Dependency | Connector adapter | No live store/CRM sync |
| Google Drive (content) | Awaiting External Dependency | Drive adapter (planned) | No live Drive browse; metadata mock in dev |

## Runtime services (must be provided by the host, not credentials)
| Service | Local | Production |
|---|---|---|
| PostgreSQL 14+ | required | required |
| Redis (cache/session/queue) | required (`redis-cli ping`) | required |
| Queue worker (`queue:work`) | required for report generation | required, supervised |
| Scheduler (`schedule:run`/`schedule:work`) | required for scheduled reports/alerts/SLA | cron every minute |
| Chromium (report PDF render) | bundled via renderer | required |

## Honesty guarantees (enforced in code)
- No delivery is `sent` and no integration is `connected`/`Production Verified` without a real provider ack.
- Dev-only test hatches hard-gated OFF in production.
- Rate limiting enforced in production/staging/testing; relaxed ONLY in local with the explicit
  `E2E_RELAX_RATE_LIMITS=true`.
