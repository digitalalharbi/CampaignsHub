# Integration Credentials Checklist

Every external integration below is built as an **interface + adapter + sandbox** and currently sits
in **`Awaiting Credentials`**. None is live. To activate one, provision the credentials, add them to
`backend/.env` (never commit them), implement the real SDK calls in the connector, and run its
contract test with real access.

Legend: ⏳ awaiting credentials · 🧪 sandbox available · ✅ live (none yet).

## Advertising platforms (`app/Domains/Integrations/Providers/*Connector.php`)
| Platform | Status | Required credentials (→ `.env`) |
|---|---|---|
| Meta Marketing API | ⏳ 🧪 | `META_APP_ID`, `META_APP_SECRET`, `META_REDIRECT_URI`, system-user token |
| TikTok Marketing API | ⏳ 🧪 | `TIKTOK_APP_ID`, `TIKTOK_APP_SECRET`, `TIKTOK_REDIRECT_URI` |
| Snapchat Marketing API | ⏳ 🧪 | `SNAPCHAT_CLIENT_ID`, `SNAPCHAT_CLIENT_SECRET`, `SNAPCHAT_REDIRECT_URI` |
| X Ads API | ⏳ 🧪 | `X_API_KEY`, `X_API_SECRET`, `X_ACCESS_TOKEN`, `X_ACCESS_SECRET` (+ dev account approval) |
| LinkedIn Marketing API | ⏳ 🧪 | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, `LINKEDIN_REDIRECT_URI` |
| Google Ads API | ⏳ 🧪 | `GOOGLE_ADS_DEVELOPER_TOKEN`, OAuth client id/secret — **no manager (MCC) account id** (GADS-MCC-001: it is the customer's, discovered from their own hierarchy) |

Sandbox connector (`sandbox`) needs no credentials and is available in non-production only.

## Tracking & analytics (not yet implemented — interfaces planned)
GA4 Data API, GA4 Admin API, Measurement Protocol, Google Tag Manager, Meta Pixel/CAPI,
Snapchat/TikTok/LinkedIn pixels + events APIs, X conversion tracking, Google Ads conversions.
Env keys to be defined when built (Phase 5).

## Ecommerce (not yet implemented — interfaces planned, Phase 5)
- **Salla**: `SALLA_CLIENT_ID`, `SALLA_CLIENT_SECRET`, `SALLA_WEBHOOK_SECRET`.
- **Zid**: `ZID_CLIENT_ID`, `ZID_CLIENT_SECRET`, `ZID_WEBHOOK_USERNAME`, `ZID_WEBHOOK_PASSWORD` — **no signing secret** (ZID-WEBHOOK-001: Zid authenticates webhooks with HTTP Basic and publishes no signature scheme).

## Payments (not yet implemented — interface planned, Phase 9)
- **Tap**: `TAP_SECRET_KEY`, `TAP_PUBLISHABLE_KEY`, `TAP_WEBHOOK_SECRET`.
- **Moyasar**: `MOYASAR_SECRET_KEY`, `MOYASAR_PUBLISHABLE_KEY`.

## AI providers (not yet implemented — gateway planned, Phase 8; BYOK per tenant)
OpenAI (`OPENAI_API_KEY`), Anthropic (`ANTHROPIC_API_KEY`), Google Gemini (`GEMINI_API_KEY`).
Keys are per-tenant, encrypted at rest — never stored in source.

## Activation procedure (per connector)
1. Provision credentials with the provider (app, OAuth redirect, approvals).
2. Add keys to `backend/.env` (and secret manager in prod).
3. Implement `authorizationUrl()`, `handleCallback()`, `sync*()` with the real SDK.
4. Run the connector's contract test against a real sandbox/account.
5. Flip status to `connected` only after a successful authenticated call. Never before.
