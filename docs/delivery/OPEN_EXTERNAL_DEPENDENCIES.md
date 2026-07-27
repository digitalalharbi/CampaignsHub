# Open External Dependencies — CampaignsHub

These are the ONLY items pending, and every one is an external credential/account that the system cannot
self-provision. None of them block running the system locally or exercising internal flows — the app runs
end-to-end without them, and nothing is ever logged as `sent`/`connected` before a real provider answers.

| Dependency | Status | Effect while pending | How to enable |
|---|---|---|---|
| Email provider | Awaiting Credentials | Notifications/reports recorded `awaiting_provider_credentials`, never `sent` | Bind a configured `MessageProvider` for `email` in `config/providers.php` |
| WhatsApp provider | Awaiting Credentials | WhatsApp deliveries `awaiting_provider_credentials` | Bind a configured provider for `whatsapp` |
| SMS provider | Awaiting Credentials | SMS/OTP-by-SMS `awaiting_provider_credentials` | Bind a configured provider for `sms` |
| Google OAuth | Awaiting Credentials | "Continue with Google" inert; email+password works fully | Set Google OAuth client id/secret |
| Advertising platform credentials (Meta/Google/TikTok/…) | Awaiting Credentials | Live ad-account sync inert; Sandbox path works end-to-end | Add each platform's API credentials to the connection |

Honesty guarantees enforced in code:
- No message/delivery is ever marked `sent` without a documented provider acknowledgement (provider adapter
  layer; delivery ledgers default to `awaiting_provider_credentials`).
- Dev-only test hatches (OTP dev code, portal dev token, invite dev link) are hard-gated OFF in production.
