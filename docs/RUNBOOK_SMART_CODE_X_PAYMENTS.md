# Runbook — Smart Code agency, X Ads and the payment gateways

Written 2026-10-20 from what Production actually showed. Everything below that says «Owner» needs something only the Owner holds
(a credential, an account, a mailbox). Nothing here asks anyone to bypass email verification, tenant isolation or branch protection.

## 1. Smart Code agency (AGENCY-SMART-CODE-001)

**State on Production.** Tenant `5a72eddd-e0c6-4e4b-95e9-919de37d17ae` (slug `smart-code`) exists, empty: no client, project, integration or member.
The owner invitation for `digital@smartcode.sa` was issued on 2026-10-20 02:19 UTC and its delivery **failed** (see 2).

**Recovery, in order.**
1. Owner: fix the SMTP login (section 2).
2. Run the workflow **Provision Smart Code Agency** (`provision-smart-code-agency.yml`). It is idempotent. It finds the tenant, sees the owner has no
   access, and issues a new invitation only when none is pending and unexpired. An undelivered invitation that is still within its 72 hours blocks
   re-issue; after it expires the next run replaces it.
3. Run **Production Mail Diagnose** (`production-mail-diagnose.yml`): the newest `invitation` row to `d***@smartcode.sa` must read `sent`.
4. The holder of `digital@smartcode.sa` opens the emailed link. A new address sets a password (the emailed link proves the address); an address that
   already has an account signs in as that verified address and presses «Accept and open the agency». The server compares the signed-in, verified
   email with the invited one; the link alone grants nothing.
5. After acceptance: `GET /api/v1/auth/memberships` for that user lists two agencies if they own another, and the portal switcher moves between them.

**Isolation checks after acceptance (authenticated, by the new owner).** Each must show only Smart Code's own rows: client workspaces, projects, ad
accounts and integrations, stores, creatives, reports and shared links, automation rules, budgets and spend limits, team, audit log. Reaching an
object of the other agency by id must answer 404. The same person's permissions in each agency are that agency's role only (a limited role in one
does not inherit another's ownership: `AgencyProvisioningTest`).

**If mail cannot be fixed soon.** A platform-admin «show the claim link once» path would deliver the link out of band. It is weaker proof that the
holder controls the address, so it is **not built** and needs the Owner's decision.

## 2. Mail (MAIL-DIAGNOSE-001, MAIL-HEALTH-001)

Production diagnosis (workflow run 39370084561): default mailer `smtp`, host `smtp.hostinger.com`, port 465, username and password set, from
`no-reply@campaignshub.io`. Every `invitation` and `email_verification` since 2026-08-30 failed with
`Failed to authenticate on SMTP server with username "info@campaignshub.io" … 535 5.7.8`. The server refuses the login.

Owner: reset or re-enter the password of the `info@campaignshub.io` mailbox in the Production environment file, restart the stack, and make sure the
from address belongs to the authenticated mailbox (Hostinger normally requires that). Then re-run Production Mail Diagnose. Once #779 is deployed the
platform operational status (`GET /api/v1/admin/operational-status`) carries `mail.state` = `failing` until a real send succeeds.

## 3. X Ads (OAUTH1-X-001, X-ADS-READY-001)

X's Ads API documentation requires **OAuth 1.0a** (HMAC-SHA1) on every call; the connection now runs the three-legged flow and signs each request.

Owner, in this order:
1. X developer console (console.x.com): a developer account and an app.
2. Submit the **Ads API Access Form** for that app and wait for approval (per app).
3. Register the callback URL shown on the platform's X integration page as one of the app's callback URLs.
4. Put the app's **API Key** in `X_ADS_CLIENT_ID` and **API Key Secret** in `X_ADS_CLIENT_SECRET`, restart.
5. Platform settings → X → **Test**: it asks X for a signed request token and says which half is wrong (invalid key/secret, or callback not registered).
   A pass proves the key, the secret and the callback only — not Ads API approval.
6. An advertiser connects X Ads (authorise on X). Accounts list through `GET accounts`; bind one and sync.

Not provable without the above: that the approved app can read the Ads API at all, rate limits in practice, and whether tokens ever need re-authorising.

## 4. Payment gateways (PAY-TAP-001, MOYASAR-REDIRECT-001, PAY-INVOICE-001)

All three paths (platform subscriptions, plan changes, agency→client invoices) go through one port and settle only from a verified webhook that the
gateway then confirms backend-to-backend for the quoted amount, currency and reference. No real charge is made by anything in this repository's tests.

**Moyasar (primary).** `MOYASAR_SECRET_KEY`, `MOYASAR_PUBLISHABLE_KEY`, `MOYASAR_WEBHOOK_TOKEN` (sandbox `sk_test_…` first). In the Moyasar dashboard set the
webhook URL to `<api>/api/v1/payments/webhook/moyasar` with the same token. Invoices redirect the payer by `success_url` / `back_url`.

**Tap (alternative).** `TAP_SECRET_KEY` (sandbox `sk_test_…` first; it also signs webhooks), optional `TAP_PUBLISHABLE_KEY`. In Tap set the webhook to
`<api>/api/v1/payments/webhook/tap`. A charge needs a named customer with an email; a portal session with no signed-in user is refused, not given an invented address.

**Sandbox acceptance sequence (per gateway).** 1. Platform settings → Payments shows the gateway `available`, environment `sandbox`. 2. Press **Test**:
reachable, nothing charged. 3. Start a registration or a portal invoice and pay with the gateway's published test card. 4. The webhook arrives; the payment
becomes `paid` only after the gateway confirms; the account activates / the invoice settles once. 5. Replay the webhook: nothing changes. 6. Pay a
deliberately wrong amount with the gateway's tools: the payment is recorded failed, nothing activates.

**Live keys.** A live key outside production is refused by the readiness check; a test key in production is a failure. Rotation is an environment change,
never a console action.

**Other Saudi gateways.** PayTabs, HyperPay, Tabby and Tamara are **not implemented**. PayTabs' and HyperPay's request and signature details could not be
retrieved from their public documentation in this session, and a guessed adapter would be fabrication. Tabby and Tamara are buy-now-pay-later retail
products (order items, shipping address, buyer history, a capture step after authorisation) and do not fit subscription or B2B invoice collection. Add a
gateway when its documentation and a sandbox are in hand: the port needs `createSession`, `verifyWebhook`, `fetchPayment`, `confirmsPayloadIntegrity`.
