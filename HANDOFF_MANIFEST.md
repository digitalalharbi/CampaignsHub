# HANDOFF MANIFEST — CampaignsHub

**The one page to read first.** It says what this repository is, how to run it, what is finished,
what is waiting on somebody outside this machine, and which document answers each further question.

Written 2026-08-11 for the developer taking this over.

Every claim here is either **built and tested on this machine** or **explicitly not**. Nothing in
this system is `LIVE_VERIFIED`, because no credential for any external provider exists on the machine
it was built on. That is a statement about credentials, not about completeness.

---

## 1. What it is

A multi-tenant SaaS for running paid media end to end. An agency or an advertiser connects their ad
accounts and their store; the product unifies what comes back into one set of figures behind
dashboards, analytics, creative analysis, a funnel, reports, shareable client links and billing.

Arabic-first with a complete English mirror (RTL/LTR), Latin digits everywhere, self-hosted fonts.

## 2. Architecture

```
backend/    Laravel 12 · PHP 8.4 · PostgreSQL 16 · Redis · Sanctum SPA cookie auth
            DDD: app/Domains/{Access,Accounts,Alerts,Audit,Billing,Campaigns,ClientWorkspaces,
                 Commerce,CRM,Identity,Integrations,Metrics,Notifications,Platform,Projects,
                 Reports,Requests,Subscriptions,Tenancy,…}
            All HTTP under /api/v1. Multi-tenancy is fail-closed through global scopes.

frontend/   React 19 · TypeScript strict · Vite · Tailwind v4 · TanStack Query · zustand
            One SPA serving all four portals; the URL says which portal you are in.

infrastructure/  docker/, nginx/, scripts/
docs/            architecture, decisions (adr/), runbooks, handoff
```

Two architectural decisions that explain most of the rest:

- **ADR 0001 — cookie sessions, not tokens, for the SPA.** Personal access tokens exist only for
  non-browser clients.
- **ADR 0002 — access comes from a MEMBERSHIP, not from a column on the user.** One person can hold
  several workspaces and portals; suspension follows the workspace. The platform admin belongs to no
  tenant at all.

## 3. The four portals, and one sign-in page

Everybody signs in at **`/login`**. The backend decides the destination from account state,
membership, role, permissions and portal — never from the address that was typed. There is no
per-portal login page, deliberately.

| Path | Who | Notes |
|---|---|---|
| `/admin` | Platform owner | Plans, providers, readiness panels, email operations, platform settings. No tenant. |
| `/app` | Advertiser | A company running its own campaigns. |
| `/agency` | Agency | The team running campaigns for other people; carries client workspaces. |
| `/portal` | Client | The agency's customer, read-mostly, scoped fail-closed. |

Public, unauthenticated: the marketing site, the legal documents, and `/r/{token}` — a shared client
report link, which needs no session by design and is tested for it (`PUBLIC-REPORT-NOAUTH`).

## 4. Canonical accounts

Development and demo only — `DemoPortalLoginsSeeder::shouldRun()` refuses in production, and none of
these is ever rendered in the product's own interface. Password `password`, **development only**.

| Portal | Email |
|---|---|
| `/admin` | `admin@campaignshub.io` |
| `/app` | `advertiser@campaignshub.io` |
| `/agency` | `agency@campaignshub.io` |
| `/portal` | `client@campaignshub.io` |

Provisioned in **every** environment by `DatabaseSeeder`, additively:

| Portal | Email | What it is |
|---|---|---|
| `/admin` | `platform@campaignshub.io` | The super-admin an installer provisions. Not a demo account, no published password. |

Details, and the supporting demo personas: [`docs/DEMO_ACCOUNTS.md`](docs/DEMO_ACCOUNTS.md).

## 5. Running it locally

```bash
createdb mediabuying          # development
createdb mediabuying_test     # the test suite's own database

cd backend
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve                       # http://127.0.0.1:8000

cd ../frontend
cp .env.example .env && npm install
npm run dev                             # http://127.0.0.1:5173
```

**PostgreSQL** holds everything durable. **Redis** holds sessions, cache and queues — sessions and
cache are disposable, queued jobs are not.

### Verifying

```bash
cd backend  && php artisan test                    # full backend suite
cd backend  && vendor/bin/pint                     # formatting
cd frontend && npm run typecheck                   # tsc -b
cd frontend && npm test                            # vitest
cd frontend && npm run lint                        # oxlint
cd frontend && npm run build                       # production bundle
cd frontend && npm run gate                        # Playwright: chromium, firefox, webkit
```

Three databases, and only one of them is your job. `mediabuying` is development. **`mediabuying_test`
is what `php artisan test` uses** (`backend/phpunit.xml` names it) and nothing creates it, so create
it once by hand. `mediabuying_e2e` is the gate's, and `php artisan e2e:prepare` creates and resets it
on every run — a gate that silently fell back to the development database is the failure that
command exists to prevent.

The gate runs each browser as its own isolated invocation with its own database reset,
`retries: 0`, `workers: 1`. **Capture its exit code on its own line, never through a pipe** —
`npm run gate 2>&1 | tail -60` gives you `tail`'s status, which is always 0, and that has produced a
false green here before:

```bash
npm run gate > gate.log 2>&1
REAL_GATE_EXIT=$?
```

### Workers and the scheduler

Neither is optional in a real deployment. Without workers nothing generates a report, sends a
message or syncs a platform; without the scheduler nothing renews, dispatches or digests.

```bash
php artisan horizon                                     # supervised
* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
php artisan schedule:list                               # confirm what is registered
```

Registered work includes `subscriptions:lifecycle` (daily 01:00), `reports:dispatch-scheduled`
(every 5 minutes), `notifications:send-digests` (hourly), `integrations:sync` and
`integrations:sync-structure` (`55 */6`), `fx:rates` (02:30, inert until configured) and the backup
command.

## 6. Migrations, seeding and upgrades

- `php artisan migrate --force` — ordered by filename, a no-op on an up-to-date database.
- **Two migrations REWRITE existing rows** (store money into the reporting currency; store
  timestamps re-anchored in the store's real timezone). Both are reversible and both were exercised
  up → down → up against a fully migrated database. Take a backup before crossing either.
  They are named in [`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md) §7.
- **Never seed a production database.** The demo seeders create demo tenants, demo logins and demo
  reports.
- `php artisan migrate:fresh --seed` is the supported reset. `php artisan db:seed` **again** on a
  populated database is also supported and asserted (`DEMO-RESEED-001`).
- On upgrade, `php artisan queue:restart` is not optional — a worker that is not restarted runs the
  previous release's code against the new schema.

Full sequence, rollback and backup/restore: `docs/DEPLOYMENT_CHECKLIST.md`.

## 7. Integration readiness — the honest table

Every adapter below is written, tested against recorded fixtures, and wired into the product. What
none of them has is a credential, and a credential is not something code can supply.

| # | Provider | State | What is needed |
|---|---|---|---|
| 1 | Meta Ads | `BLOCKED_EXTERNAL_CREDENTIALS` | App id + secret |
| 2 | Google Ads | `BLOCKED_EXTERNAL_CREDENTIALS` | Client id + secret + developer token + login customer id |
| 3 | TikTok Ads | `BLOCKED_EXTERNAL_CREDENTIALS` | App id + secret |
| 4 | Snapchat Ads | `BLOCKED_EXTERNAL_CREDENTIALS` | Client id + secret + organization id |
| 5 | X Ads | `BLOCKED_EXTERNAL_CREDENTIALS` | Client id + secret |
| 6 | LinkedIn Ads | `BLOCKED_EXTERNAL_CREDENTIALS` | Client id + secret + API version |
| 7 | Salla | `BLOCKED_EXTERNAL_CREDENTIALS` | Client id + secret + webhook secret |
| 8 | Zid | `BLOCKED_EXTERNAL_CREDENTIALS` | Client id + secret + webhook secret. **Set each client workspace's timezone first** — Zid publishes none |
| 9 | Moyasar | `READY_FOR_CREDENTIALS` | Publishable + secret key + webhook token. Sandbox keys first |
| 10 | Mail | `READY_FOR_CREDENTIALS` | SMTP, or Postmark/Resend key. Currently `MAIL_MAILER=log` — **no delivery is claimed** |
| 11 | FX rates | `READY_FOR_CONFIGURATION` | A source decision, not a credential. Unset on purpose |

Also blocked on credentials: Google sign-in, Apple sign-in, SMS/WhatsApp. **GA4 is not integrated at
all** — absent, not awaiting anything.

### URLs to register, byte for byte

| What | URL |
|---|---|
| Ad-platform redirect | `GET {AD_PLATFORM_REDIRECT_BASE or APP_URL}/api/v1/oauth/ads/{provider}/callback` |
| Ad-platform webhook | `POST {APP_URL}/api/v1/webhooks/ads/{provider}` (same path answers `GET` for the subscription handshake) |
| Commerce redirect | `GET {APP_URL}/api/v1/oauth/commerce/{provider}/callback` |
| Commerce webhook | `POST {APP_URL}/api/v1/webhooks/commerce/{provider}` |
| Moyasar webhook | `POST {APP_URL}/api/v1/payments/webhook/moyasar` |
| Moyasar return | `{FRONTEND_URL}/signup/status` |

These were compared against the routes the application actually registers, and they match. Per-provider
environment variables and the rules each one enforces:
[`docs/INTEGRATION_CREDENTIALS_CHECKLIST.md`](docs/INTEGRATION_CREDENTIALS_CHECKLIST.md).

### What `LIVE_VERIFIED` costs

Real credentials **and** a real auth round trip **and** account discovery **and** a first live sync
or payment **and** a real webhook **and** the result visible in the product. Nothing less, and it is
recorded in `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` with the evidence named.

## 8. Money — read before touching `Domains/Subscriptions` or `Domains/Billing`

- **Subscriptions and checkout are USD. Advertising reporting is SAR.** An agency's own invoices to
  its client keep their own currency.
- Starter $19/mo · Growth $49/mo (first 30 days at $9, then $49, **3-month minimum commitment**) ·
  Agency $99/mo · Enterprise internal only, refused at checkout even if the code is typed into a URL.
- **A payment is never marked paid from a checkout result or a redirect** — only from a webhook the
  adapter cryptographically verified, whose amount and currency are re-checked against our own record,
  after the payment has been re-read from the gateway.
- **No card data is stored.** No PAN, no CVC. Saved cards are provider tokens, encrypted and hidden
  from serialisation; a token arrives only from a payment the gateway settled, and there is
  deliberately no endpoint that adds a card.
- **Editing a plan never re-prices an existing subscriber.**

## 9. Production checklist, short form

1. `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` generated.
2. `APP_URL` and `FRONTEND_URL` https and not localhost; `SANCTUM_STATEFUL_DOMAINS` lists every host
   the SPA is served from (a missing entry returns 401 and reads as an auth defect).
3. `SESSION_DOMAIN` covers the host, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`.
4. `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SUBSCRIPTION_PROVIDER=moyasar` (**never sandbox**).
5. **`php artisan production:check` exits 0.** It reports the shape of a key, never its value, and
   fails a live key outside production as well as a test key inside it. Expect two warnings on a
   first deploy — no mail provider, no FX driver. Both are honest unfinished integrations.
6. Workers and cron running; `php artisan schedule:list` shows the jobs.
7. Register the URLs in §7, then smoke-test in the order given in the deployment checklist.

Full version, including rollback and backup/restore: `docs/DEPLOYMENT_CHECKLIST.md`.

## 10. Conventions that are load-bearing

Each of these exists because its opposite shipped once and was found.

- **Absent is never zero.** A metric a platform did not report is `null`, shown as «لم تُرسل».
- **A refusal is not a failure.** Permission, expired session, not-found and a server error each get
  their own message, and Retry is offered only where retrying can work.
- **Fail closed.** Every scope ceiling, permission and share link narrows and never widens.
- **Nothing claims delivery it cannot prove.** No message is recorded as sent without a provider
  acknowledgement; no integration reports Connected without a real round trip.
- **Convert money once, at ingest**, keeping `original_*` beside the converted figure with a dated
  rate and a named source. No rate means the figure is withheld, not guessed and not zero.
- **A merchant's day is not a UTC day.** Store timestamps are true instants; report windows are
  measured on the client's clock; every order keeps the calendar date its own merchant sold it on.

## 11. Where to look next

| Question | Document |
|---|---|
| What is built, what is not, and the money rules in full | [`docs/PRODUCTION_HANDOFF.md`](docs/PRODUCTION_HANDOFF.md) |
| How to deploy, upgrade, roll back, back up | [`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md) |
| Every provider, its env vars, its URLs, its state | [`docs/INTEGRATION_CREDENTIALS_CHECKLIST.md`](docs/INTEGRATION_CREDENTIALS_CHECKLIST.md) |
| Every requirement, with the commit and the tests | [`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`](docs/REQUIREMENTS_TRACEABILITY_MATRIX.md) |
| Sign-ins for local and demo | [`docs/DEMO_ACCOUNTS.md`](docs/DEMO_ACCOUNTS.md) |
| The live handoff and the exact next task | [`docs/RESUME_STATE.md`](docs/RESUME_STATE.md) |
| Why a thing is the way it is | [`docs/adr/`](docs/adr/) and [`docs/DECISIONS.md`](docs/DECISIONS.md) |
| The engineering rules the code was written under | [`CLAUDE.md`](CLAUDE.md) |
| What is deliberately incomplete | [`KNOWN_LIMITATIONS.md`](KNOWN_LIMITATIONS.md) |

## 12. Delivery state

Verification evidence for this handoff — the exact HEAD, the gate result per browser, the test
totals, and the list of external blockers — is recorded under **START HERE** in
[`docs/RESUME_STATE.md`](docs/RESUME_STATE.md), which is updated at every close and is the file to
trust over any number written elsewhere.

**No secret is committed to this repository.** Only `.env.example` files are tracked, holding
variable names with empty or placeholder values. Real credentials belong in the environment and
nowhere else.
