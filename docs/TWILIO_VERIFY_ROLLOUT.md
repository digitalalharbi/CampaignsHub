# Twilio Verify OTP rollout

## Architecture and scope

CampaignsHub now uses the existing Twilio Verify Service for SMS challenges whenever the complete
server-side credential set is configured. The integration covers platform phone sign-in,
authenticated phone confirmation, registration mobile verification, public request contact
verification, and client-portal SMS login through the existing Laravel services and routes.

Twilio creates and retains the OTP. CampaignsHub stores only challenge metadata, local attempt and
expiry controls, an initiator-binding hash where an account/session exists, and consumption state.
Only a Twilio `approved` VerificationCheck can mark a number verified. The browser never receives a
Twilio credential and cannot mark a number verified.

When Twilio is not configured, the existing non-production hashed-code fallback remains available so
local development is usable. Production never exposes `dev_code`.

## Environment checklist

Set these in the backend deployment secret store, not in the frontend or a committed file:

```ini
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_VERIFY_SERVICE_SID=
TWILIO_VERIFY_CHANNEL=sms
```

Run `php artisan production:check` after configuration. It reports absent or partial Twilio
configuration without printing values.

## Saudi delivery and compliance readiness

Code readiness is not verified live delivery. Before enabling production traffic:

1. Confirm that the existing Verify Service is approved and enabled for Saudi Arabia (`+966`).
2. Confirm Twilio Geo Permissions allow Saudi destinations.
3. Confirm the service's message template/sender complies with current Saudi CST and carrier rules.
4. Enable and review Twilio Verify Fraud Guard/service rate limits in the Twilio console.
5. Test a controlled Saudi handset in staging after explicit approval; no live SMS was sent during
   implementation.
6. Monitor Verify delivery/error metrics and application redacted warnings during rollout.

Carrier filtering, sender registration, account funding, and destination permissions are external
blockers that code and mocked tests cannot prove.

## Google readiness (PR #793 preserved)

Google Login and Google Ads remain separate OAuth clients and no credential or stored connection was
changed. The merged hardening retains Google ID-token signature/issuer/audience/azp/expiry/nonce
checks, explicit authenticated account linking, replay protection, encrypted token storage,
tenant/workspace ownership, refresh-token preservation, and Google Ads account discovery.

The checked-out environment has none of the Google or Twilio secret variables set. Production still
requires operator verification of:

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and the exact Login callback;
- `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, optional legacy developer token, and the Ads
  callback base;
- Google consent-screen publication, domain verification, requested-scope approval, staging Login
  round trip, and staging Ads account discovery.

Do not overwrite credentials or reconnect existing Google Ads accounts as part of this rollout.

## Rollout

1. Deploy code and run migrations with Twilio variables still absent; existing production behavior
   remains fail-closed (`awaiting_provider_credentials`).
2. Add all four Twilio variables atomically in the secret store and rebuild Laravel's config cache.
3. Run `production:check` and the smoke suite.
4. With explicit approval, send one staging OTP to an allowlisted Saudi handset and verify delivery,
   incorrect/expired-code handling, resend cooldown, and one-time consumption.
5. Enable production traffic gradually and monitor redacted provider failures, 429s, Fraud Guard,
   conversion, and delivery rates.

## Rollback

Remove the Twilio variables atomically and rebuild the config cache. SMS flows immediately return to
the prior fail-closed providerless state; existing email/password and Google authentication continue
unchanged. The additive `owner_key_hash` column may remain safely in place. Do not roll back the
migration while new application code is running, and do not delete verification rows during a
rollback.
