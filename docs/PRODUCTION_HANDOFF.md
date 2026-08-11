# PRODUCTION HANDOFF — CampaignsHub

**Written 2026-08-10.** For the developer taking this over. It says what exists, what does not, and
which of the two every claim in it is.

Companion documents, and the order to read them in:

1. This file — what the system is and what state each part is in.
2. `DEPLOYMENT_CHECKLIST.md` — the commands and the questions, top to bottom.
3. `INTEGRATION_CREDENTIALS_CHECKLIST.md` — every provider, its env vars, its URLs, its state.
4. `REQUIREMENTS_TRACEABILITY_MATRIX.md` — every requirement, with the commit and the tests.
5. `RESUME_STATE.md` — the live handoff, with the exact next task under **START HERE**.

---

## 1. What it is

A multi-tenant media-buying platform. An agency or an advertiser connects their advertising
accounts and their store, and the product unifies what comes back into one set of figures behind
dashboards, analytics, creative analysis, a funnel, reports and shareable client links.

**Backend** — Laravel 12, PHP 8.4, PostgreSQL, Redis, DDD under `app/Domains/*`, Sanctum SPA cookie
auth, fail-closed multi-tenancy through global scopes. API under `/api/v1/`.

**Frontend** — React 19, TypeScript (strict), Vite, Tailwind v4, TanStack Query, zustand. Arabic-first
RTL with a complete English mirror, self-hosted fonts, Latin digits throughout.

**Four portals**, and the URL says which one you are in: `/app` (advertiser), `/agency` (agency),
`/portal` (the agency's client), `/admin` (the platform operator). Shared pages resolve their links
against the current portal rather than hard-coding `/app`.

## 2. State of the system, stated exactly

| vocabulary | meaning |
|---|---|
| `VERIFIED` | Built, tested and proven here. |
| `READY_FOR_CREDENTIALS` | Complete. Supply the credential and it runs. |
| `BLOCKED_EXTERNAL_CREDENTIALS` | Waiting on a credential only the operator can obtain. |
| `BLOCKED_OPERATIONAL_EVIDENCE` | Code ready; missing evidence from a real environment. |
| `LIVE_VERIFIED` | Real credentials + real auth round trip + account discovery + a first live sync or payment + a real webhook + the result visible in the product. |

**Nothing in this system is `LIVE_VERIFIED`.** There are no credentials on the machine it was built
on, so no live round trip has ever been made — for any provider, including the payment gateway.
That is a statement about credentials, not about completeness.

### Built and verified

Registration and onboarding (both journeys, walked end to end through a real webhook-driven
provisioning), the four portals, clients, projects, campaigns, creative analysis, the funnel and
store analytics, reports (executive summary and full detail, scheduling, PDF/CSV/XLSX export),
shareable client links with fail-closed scope ceilings, the notification and email system, alerts,
plan entitlements enforced server-side, subscriptions and billing, `/admin` platform operations, the
public site, consent and legal documents, and the PWA.

Backend **2009 tests**. Frontend **1017 tests**. Playwright across Chromium, Firefox and WebKit
(299 · 291 · 291), 0 failed, 0 flaky, retries 0. Counts as of 2026-08-11.

### Not built — say so rather than discovering it

> **Corrected 2026-08-11.** Four items that stood here have since shipped, and a developer reading
> the old list would have rebuilt working code. They are recorded below under «shipped since» with
> the reference to look up, because knowing something exists is as load-bearing as knowing it does not.

- **GA4.** Not integrated at all — it is absent, not awaiting credentials.
- **The alerts management page.** The engine and the API are complete; the operator screen is not.
- **The influencer/UGC module** is behind `FEATURE_INFLUENCERS_UGC=false`. The models, controllers
  and tests remain; what is withdrawn is the offer.
- **Passkeys.** Deliberately not started; explicitly optional.
- **A platform→objective mapper for Google Ads specifically.** Every other provider maps
  (`PlatformObjectiveMap`, six providers). Google's campaign resource does not expose a marketing
  objective in the API this connector reads — it reports `advertisingChannelType`, which is where an
  ad runs and not what it is for — so Google campaigns arrive unclassified with the platform's raw
  word recorded for a person to correct (`REPORT-OBJECTIVE-002-E`). **Do not map channel type onto
  an objective**: SEARCH serves lead campaigns and shopping campaigns alike.

### Shipped since this document was first written — do not rebuild

| Was listed as missing | Now | Reference |
|---|---|---|
| Payment tokenization and saved payment methods | Built end to end. A verified, gateway-re-read payment carrying a reusable token files the card (encrypted, hidden from serialisation) and the next renewal is debited rather than invoiced. Whether Moyasar issues that token for a given merchant account is the remaining unknown, and needs a key to answer | `PAY-TOKEN-001…003` |
| A backend-to-backend re-fetch of a payment | Built. Moyasar's `paid` webhook is re-read from `GET /v1/payments/{id}` before anything settles, and an unreachable gateway settles nothing rather than falling back to the body | `PAY-CONFIRM-001` |
| FX / reporting currency | Built for both pipelines. Original amount + original currency + dated rate + source on every monetary row; no rate means the figure is WITHHELD and reported as withheld, never guessed and never zero. No vendor is chosen in this repository — that is a configuration decision, not a credential | `FX-001`, `COMMERCE-FX-001`, `FX-FEED-001` |
| — (not previously known) | Store timestamps are true instants; report windows are measured on the client's clock; each order keeps the calendar date its own merchant sold it on | `COMMERCE-TZ-001` |

## 3. Money — read this before touching anything in `Domains/Subscriptions` or `Domains/Billing`

**Commercial terms, as sold:** Starter $19/mo or $190/yr · Growth $49/mo or $490/yr, recommended,
first 30 days at $9, then $49, with a **3-month minimum commitment** · Agency $99/mo or $990/yr ·
Enterprise by conversation, internal only (`is_public: false`, refused at checkout even if the code
is typed into a URL).

**Two currencies, one decision.** Subscriptions, checkout and CampaignsHub's own billing are **USD**.
Advertising dashboards, analytics and reports are **SAR**. An agency's invoices to its own client
keep their own currency and do not follow the subscription currency.

Rules that are load-bearing and have tests behind them:

- **A payment is never marked paid from a checkout result or a redirect.** Only a webhook the adapter
  cryptographically verified may settle one. A `?status=paid` in a query string means nothing.
- **The amount AND the currency are re-checked** against our own record before settling. A verified
  event proves the gateway sent it, not that it charged what we asked for.
- **Idempotent by event id**, and a paid payment ignores a second `paid`.
- **An unverified event still gets a row**, under a hash of its body, so it can never occupy the id
  of a real event and make the genuine delivery look like a duplicate.
- **Editing a plan never re-prices an existing subscriber.** `assignPlan()` captures the amount AND
  the currency onto the subscription row at the moment of purchase. The same protection covers the
  minimum commitment.
- **No card data is ever stored.** No PAN, no CVC. Only what the provider publishes and permits.
- **Campaigns are deliberately not metered.** The metered axes are connected ad accounts, projects,
  team members, client workspaces and monthly reports — each one counted from the real rows, enforced
  by middleware, editable in `/admin`, and returning `used` / `limit` / `upgrade_path` on refusal.

## 4. Running it

Install, deploy, workers, scheduler, smoke tests, upgrade and rollback: `DEPLOYMENT_CHECKLIST.md`.

The one command to remember:

```bash
php artisan production:check
```

It fails a production install on `APP_DEBUG`, a localhost or plain-http public URL, a session cookie
domain that cannot hold the cookie, a `sync` queue, the sandbox gateway, a **test key in
production**, a **test key mixed with a live one**, and a gateway secret with no webhook secret. It
reports the *shape* of a key and never its value. Run it in the pipeline before traffic moves.

## 5. Development

```bash
# backend
cd backend && php artisan serve            # :8000
php artisan test                           # 1820
vendor/bin/pint

# frontend
cd frontend && npm run dev                 # :5173, proxies /api to :8000
npm test                                   # 959
npm run gate                               # Chromium + Firefox + WebKit, isolated per browser
```

**The gate's exit code must be captured on its own line, never through a pipe.**
`npm run gate 2>&1 | tail -60` then `$?` gives you `tail`'s status, which is always 0. That mistake
has produced a false green in this repository before:

```bash
npm run gate > gate.log 2>&1
REAL_GATE_EXIT=$?
```

Demo logins (local, testing and demo only — hard-gated off in production):
`admin@campaignshub.io` · `advertiser@campaignshub.io` · `agency@campaignshub.io` ·
`client@campaignshub.io`, password `password`.

## 6. Conventions worth knowing before you change something

- **Absent is never zero.** A metric a platform did not report is `null` and is shown as «لم تُرسل»,
  never as `0`. A zero is a fact about money; a null is the absence of one, and a reader cannot tell
  them apart once they are the same character.
- **A refusal is not a failure.** Permission, expired session, not-found and a genuine server error
  each get their own message, and Retry is offered only where retrying can work.
- **Fail closed.** Every scope ceiling, every permission and every share link narrows and never
  widens. A client filter on a shared report can only narrow what the link already permits.
- **Nothing claims delivery it cannot prove.** No message is recorded as sent without a provider
  acknowledgement, no integration reports Connected without a real round trip, and no account
  activates without verified money.

These are not style preferences. Each one exists because its opposite shipped once and was found.
