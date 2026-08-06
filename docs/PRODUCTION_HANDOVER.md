# Production Handover — CampaignsHub

**For the developer taking this codebase over.** It answers what the system is, how to become
productive in it, which decisions are load-bearing, and — the part most handovers skip — what is
deliberately absent and why.

It does **not** repeat operations. Running, upgrading, backing up, monitoring and rotating secrets are
in [`PRODUCTION_RUNBOOK.md`](PRODUCTION_RUNBOOK.md), which is the authority for all of it. Where this
document mentions those, it points there.

| Question | Document |
|---|---|
| How do I run, upgrade, back up, monitor it? | [`PRODUCTION_RUNBOOK.md`](PRODUCTION_RUNBOOK.md) |
| What was required, what was built, what is proven? | [`REQUIREMENTS_TRACEABILITY_MATRIX.md`](REQUIREMENTS_TRACEABILITY_MATRIX.md) |
| Where did the last session stop, and what is next? | [`RESUME_STATE.md`](RESUME_STATE.md) |
| Why is it built this way? | [`DOMAIN_ARCHITECTURE.md`](DOMAIN_ARCHITECTURE.md), [`DECISIONS.md`](DECISIONS.md), [`adr/`](adr/) |
| What is knowingly incomplete? | [`OPEN_GAPS.md`](OPEN_GAPS.md) and the matrix's own status column |

---

## 1. What the product is

A multi-tenant SaaS for media buying: an agency (or an in-house team) connects its ad platforms and
its store, and the system unifies the numbers, produces client reports, and runs the request and task
workflow around them.

**Five portals, one deployment.** Each is a distinct product for a distinct reader, not a role filter
over one screen:

| Portal | Path | Reader |
|---|---|---|
| Platform admin | `/admin` | Whoever operates CampaignsHub itself — tenants, plans, provider credentials |
| Advertiser | `/app` | A brand running its own media |
| Agency | `/agency` | An agency running media for several clients |
| Client | `/portal` | An agency's client, reading what the agency shares |
| Influencers | `/influencers` | **Off.** See §6 |

`app/Domains/Tenancy/Enums/Portal.php` is the enum; `ADR 0002` is why a section lives under one portal
rather than being conditionally hidden in another.

**Stack.** Laravel 12 / PHP 8.4, PostgreSQL, Redis, Sanctum cookie auth on the backend
(`backend/`, ~519 PHP classes in `app/`, organised as domains under `app/Domains/*`). React 19 + TypeScript + Vite on the frontend
(`frontend/`), TanStack Query for server state, zustand for session and UI state, Tailwind.

**Where the tests are.** 161 backend feature-test classes (`backend/tests/`) and 108 frontend test
files (`frontend/src/**/*.test.tsx`), plus Playwright end-to-end specs. Current counts and how to run
each are in `RESUME_STATE.md`, which is updated every session.

---

## 2. Getting productive in a day

Follow the clean-install steps in `PRODUCTION_RUNBOOK.md` §7, then — for a **development** machine
only — seed the demo data:

```bash
php artisan db:seed --force
```

That populates one agency tenant with real rows through the real tables: connected-looking ad
accounts, 90 days of metrics, a Salla store with 657 orders, creatives, reports and share links. Demo
accounts and their passwords are in [`DEMO_ACCOUNTS.md`](DEMO_ACCOUNTS.md). **The demo password is a
development convenience and must never exist in a production database** — a fresh production install
runs `PermissionSeeder` only, which is the permission catalogue and contains no users.

Every demo row carries `is_demo = true` and every demo connection is named «بيانات تجريبية». Nothing
seeded is presented as a real platform connection.

### The four files to read first

1. **`app/Domains/Metrics/Services/MetricsAggregator.php`** — every figure in the product comes from
   here. Base metrics are summed once with conditional aggregation; derived KPIs (ROAS, CPA, CTR, CPC,
   CPM) are computed *from* those sums and never summed themselves, because summing thirty daily CPCs
   does not give you a month's CPC.
2. **`app/Domains/Tenancy/Services/ClientScopeResolver.php`** — who can see which client. A membership that names
   clients is a **ceiling** that outranks the `clients.view_all` permission; `null` means unrestricted
   and `[]` means nothing. Every list in the product passes through it.
3. **`app/Domains/Reports/Services/SharedCreativeView.php`** — how a public client link decides what a
   reader without an account may see. It owns the ceiling and computes nothing.
4. **`frontend/src/app/router.tsx`** — the five portal trees, and which guard each sits behind.

---

## 3. The decisions that are load-bearing

These are the ones that will bite you if you work against them. Each has a longer entry in
`DECISIONS.md` or an ADR.

### One pipeline, one set of numbers

Every surface — dashboard, analytics, campaign pages, creative analysis, the funnel, the executive
report, the detailed report and the public client link — reads through the same services. **A page
that computes its own figure from its own query is an architectural defect here, not an optimisation.**
The rule exists because the first time two surfaces disagree about «كم بعنا؟», nobody can say which is
right, and the product's whole value is that the numbers agree.

Practical consequence: when you need a figure, find the service that already produces it. If you are
about to write a second `SUM()` for something, stop.

### Honest status vocabulary

The product has a fixed vocabulary and it is enforced in review, in copy and in the matrix:

`VERIFIED` · `PARTIAL` · `IMPLEMENTED_NOT_VERIFIED` · `Demo` · `Awaiting Credentials` ·
`BLOCKED_EXTERNAL_CREDENTIALS` · `NOT_STARTED`

**Nothing is ever called Connected, Synced or Live without a real OAuth round trip, account discovery
and an actual sync having happened.** A `200 OK` is not completion. Sandbox, Awaiting Credentials and
Live are three different states and are never collapsed.

### Null is not zero

A metric a platform did not report is `null`. Any ratio with a zero or unavailable denominator is
`null`. This is not pedantry: a ROAS of `0.00×` says «this money returned nothing», and printing it
for an awareness campaign that was never meant to sell anything is a false statement about a client's
money. `«لا يوجد مصدر يقيس هذه المرحلة»` and `0` are different sentences and the UI says which.

### What may be added up, and what may not

Read `app/Domains/Metrics/Services/AttributionTransparency.php` in full before touching anything that
totals conversions. In short: each ad platform reports the conversions it believes its ads caused, and
those claims **overlap** — one sale clicked from two platforms is reported in full by both. There is no
shared key that would prove two conversions are one sale, so per-platform figures are shown separately
and labelled `Platform-Reported`, and **no unified platform order total is ever produced.** The store's
own ledger *is* totallable, because an order id is a real key.

### Fail closed

A missing scope key degrades to «nothing», never to «everything». An empty campaign list on a share
link means the link shows no campaign data — a visibly broken link someone reports — rather than the
whole project. Read the defensive normalisation in `LiveReportService::ceiling()` for the pattern.

### Arabic first, Latin digits

The interface is Arabic-first and RTL, with English available. **Digits are always Latin** (`1,169`,
never `١١٦٩`) — a deliberate product decision for this market. Copy carries both languages from the
API where the sentence explains data, so two surfaces cannot word the same fact differently.

### Additive migrations, frozen tags

No migration in this repository drops a column carrying customer data. The tags `v1.0.0-baseline`
(`47ce364`) and `v1.1.0-expanded-final` (`e9b99f2`) **never move.** That is what makes «roll back the
code, leave the schema forward» a safe recovery (`PRODUCTION_RUNBOOK.md` §8).

---

## 4. The honest state of every integration

**No credential for any external provider exists in this repository or in any install of it.** This is
the single most important sentence in this document for anyone estimating remaining work.

| Integration | State | What is built | What is missing |
|---|---|---|---|
| Snapchat, TikTok, Meta, Google Ads, X, LinkedIn | `BLOCKED_EXTERNAL_CREDENTIALS` | Connector interfaces, the credential → connection → account → campaign chain, the sync pipeline, token refresh, webhook handling, the admin console that configures each | A real app registration per platform, then a real OAuth round trip, account discovery and one actual sync before any of them may be called Connected |
| Salla, Zid (commerce) | `Awaiting Credentials` | Both connectors written against each API's documented shapes, with fixtures; orders, products, customers, abandoned carts; attribution resolution | Merchant app credentials. Zid publishes no abandoned-cart endpoint, and its connector **refuses** rather than returning an empty list, so the run reads `partial` and the UI says «لا توفّرها المنصة» |
| Email, WhatsApp, SMS | `awaiting_provider_credentials` | Adapters resolved from `config/providers.php`; the shipped defaults report `isConfigured() = false` | Provider accounts. Nothing is ever recorded as `sent` without a real provider acknowledgement — see `PRODUCTION_RUNBOOK.md` §2 |
| Payments | environment-only keys | Webhook signature verification, idempotency, no duplicate charging, no card data stored | Live gateway keys, deliberately not writable from any console |

The OAuth callback and webhook URLs to paste into each provider's console are shown at `/admin` →
Integrations → Providers, derived from `app.url` and never stored.

**Per-provider review checklists** — what each platform's app review actually asks for — are in the
admin console, and `INTEGRATION_CREDENTIALS_CHECKLIST.md` at the repository root lists what each
platform's app review asks for.

---

## 5. Security and privacy, as implemented

- **Secrets.** Provider client secrets live encrypted in `provider_configurations`, written only from
  `/admin`. **There is no endpoint that reads a secret back**; the console shows whether a field is set,
  never its value. Rotation is a first-class action. `APP_KEY` decrypts all of it — a database dump
  without its key restores to nothing usable. Full detail in `PRODUCTION_RUNBOOK.md` §9.
- **No secret, password or webmail credential is committed to this repository**, and none appears in
  any document in `docs/`.
- **Tenant isolation** is enforced by global scopes on the models plus the client ceiling described in
  §2. The audit log is deliberately *not* globally scoped — it is filtered explicitly at each read, so
  an append-only record cannot be silently narrowed.
- **No personal data of individuals is fetched or stored from ad platforms.** Sales are never inferred
  or distributed demographically.
- **Analytics and marketing cookies are fully disabled**, and there is no Google Analytics, pixel or
  tracking script anywhere in the frontend. A consent mechanism exists and must be approved before any
  of that changes.
- **Client data is never deleted on suspension.** Suspension removes access, not records.
- **System logic and internal keys are never shown to a client**, and the two layers — the platform's
  own settings and a tenant's — are never mixed on one screen.

---

## 6. Deliberately absent — do not treat these as bugs

- **The influencers portal is off.** `FEATURE_INFLUENCERS_UGC=false` and
  `brand.features.influencers_ugc_enabled=false`. The code is present and the portal is not on sale.
  `Portal.php` explains why the enum case is kept rather than deleted. Leave both false.
- **Product and Tag filters on the creative library** do not exist, because there is no data model
  behind them yet. Recorded in the matrix rather than stubbed — a filter that returns everything is
  worse than no filter.
- **Legal and company data that nobody has supplied is unset**, visibly, and is never invented.
- **The attribution panel is on the operator's analytics tab, not on the client's link.** The client's
  link does carry `conversions_basis`, so its «conversions» figure states what it is; the full
  Platform-Reported / Store-Confirmed section needs its own visibility switch on the link builder
  first, because a new client-visible block without an operator toggle shows figures nobody chose to
  share.

`OPEN_GAPS.md` and the matrix's status column are the complete list. **A row marked `PARTIAL` or
`NOT_STARTED` there is the honest state, not an oversight** — the discipline of this codebase is that
nothing is marked `VERIFIED` without live evidence and acceptance tests behind it.

---

## 7. Working on it

### The loop

1. Read `RESUME_STATE.md` for where the last session stopped and what is next.
2. Build the unit — backend and frontend both, never one without the other.
3. Write the acceptance tests as claims about behaviour, not about implementation.
4. **Review it live in a browser**, in Arabic and in English, light and dark, at 375px and 1280px.
   This is mandatory and it is where most real defects in this codebase have been found — a green
   suite has repeatedly agreed with a screen that was wrong.
5. Run the gates (below). All of them.
6. One commit per concern. Never combine unrelated changes.
7. Update `REQUIREMENTS_TRACEABILITY_MATRIX.md` and `RESUME_STATE.md` in a separate `docs:` commit.

### The gates

```bash
# backend
cd backend && ./vendor/bin/pint && php artisan test

# frontend
cd frontend && npm run typecheck && npx vitest run && npx oxlint src/ && npm run build

# end to end — three browsers
npx playwright test --project=chromium --project=firefox --project=webkit
# (a `setup` project runs first as a dependency and authenticates; do not skip it)
```

Two rules about the gates that exist because they were broken before:

- **`npm run typecheck` (`tsc -b`), never `tsc --noEmit`.** `frontend/tsconfig.json` is a solution
  file; `--noEmit` silently checks nothing.
- **Take Playwright's own exit code.** Never pipe it through `tail` or anything else that can hide a
  failure, and never change a file or the database while the gate is running.

Repeated restarts are not a fix, and retries must never be used to hide an intermittent failure.

---

## 8. Clean install and upgrade — the drill, and what it proved

Both paths are in `PRODUCTION_RUNBOOK.md` §7 and §8. They were **run**, not just written, on
2026-08-07 at `0437c38`, against scratch databases:

**Clean install** — `migrate --force` then `db:seed --class=PermissionSeeder --force`, which is
exactly what production runs:

| | |
|---|---|
| Tables created | 129 |
| Permissions seeded | 111 |
| Users · tenants | **0 · 0** |
| Provider configurations · integration credentials | **0 · 0** |

A fresh install has no users and no credentials of any kind. Every ad and commerce platform reads
`Awaiting Credentials` until the platform operator configures it at `/admin` → Integrations →
Providers. **That is the honest state of a new install, not a defect.**

**Upgrade path** — a database migrated at the frozen tag `v1.0.0-baseline` (`47ce364`, 74 tables), then
brought forward with HEAD's migrations:

- Every migration applied in order, no errors, 74 → 129 tables.
- The upgraded schema was then compared column by column against the clean install:
  **1,898 columns, identical — same names, same types, same nullability.** An install that upgraded and
  an install created today are the same product, which is the property that makes the two paths
  interchangeable.
- No migration between the baseline and HEAD drops a column. That is what makes «roll back the code,
  leave the schema forward» a safe recovery.

Re-run the drill against a scratch database before any release; it costs two minutes and it is the
only thing that catches a migration that works on your machine because your machine already has the
column.

---

## 9. Production identity

| | |
|---|---|
| Domain | `campaignshub.io` |
| App URL | `https://campaignshub.io` |
| Contact | `info@campaignshub.io` |

The shareable client link is built server-side from `brand.frontend_url` — never from
`window.location.origin`, which is right in production and quietly wrong on staging, on a preview
deployment and on localhost.
