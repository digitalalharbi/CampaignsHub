# Demo Accounts & Data

Seeded in **local/testing only** — `DemoPortalLoginsSeeder::shouldRun()` refuses in production, so a
deployed install has no account whose password is published in a seeder. All demo data is labelled
`Demo` / `Sandbox` / `Simulated`, and a demo connection is never reported as a live one.

## The canonical accounts — one per portal (`IDENTITY-ACCOUNTS-001`)

Password: `password`. **Development only.**

| Portal | Email | What it is |
|---|---|---|
| `/admin` | `admin@campaignshub.io` | Platform Admin — the owner's console, belongs to no tenant (ADR 0002) |
| `/app` | `advertiser@campaignshub.io` | Advertiser — a self-serve company workspace |
| `/agency` | `agency@campaignshub.io` | Agency — tenant owner, all permissions, carries the demo world |
| `/portal` | `client@campaignshub.io` | Client portal — the customer's own view |

Internal fallback, provisioned in **every** environment by `DatabaseSeeder`:

| Portal | Email | What it is |
|---|---|---|
| `/admin` | `platform@campaignshub.io` | The super-admin an installer provisions. Not a demo account. |

### One sign-in page, always

There is no per-portal login. Everybody signs in at **`/login`**, and the BACKEND decides where they
land from their account state, membership, role, permissions and portal — never the address they
typed. An account that spans two portals would make that decision ambiguous, which is why there is
exactly one canonical account per portal.

### Not shown in the product

These addresses and their password appear in seeders, tests and this document. **They are never
rendered in the application's own interface** — no demo-credential panel on `/login`, no hint text,
nothing a visitor can read. A demo login that advertises itself is a production credential leak
waiting for one environment variable to be wrong.

### The other demo personas keep their own addresses

`analyst@demo-agency.local`, `member@demo-company.local`, `manager@demo-agency.local`,
`viewer@demo-agency.local` and `talent@demo-agency.local` are supporting cast — they exist to prove
that permissions differ between people in one workspace, and none of them is a portal entry point.
They were deliberately left on their original addresses: the canonical set above is what a person
signs in with, and renaming the rest would churn a hundred call sites to no end.

## Seeded data (Demo Agency tenant)
- **CRM leads** (5): Acme Co, Nova Retail, Zahra Store, Falcon Media, Bright Foods (varied
  status/source/value).
- **Client workspaces** (3, one per mode): Acme (Managed), Nova (Collaborative), Zahra (Self-Service).
- **Projects** (3): one "Q3 Launch — Demo" per workspace, `setup_completion=70`.
- **Task** (1): "Prepare tracking — Demo" (in_progress, high).
- **Notification** (1): simulated integration alert (warning).
- **AI key** (1): OpenAI, scope tenant, secret `sk-DEMO-SANDBOX-0000` (encrypted at rest, shown
  masked `••••0000`).
- **Advertising connectors**: Sandbox (connected) + 6 platform stubs (awaiting_credentials).

## Reset

`php artisan migrate:fresh --seed` rebuilds everything, and is the supported way to reset.

`php artisan db:seed` on a database that already holds a demo world is safe too, and is asserted by
`DemoCreativesReseedTest` (`DEMO-RESEED-001`). It used to abort on a foreign key: the creatives
seeder passed `id` in its update payload, and because `db:seed` runs everything inside
`Model::unguarded()`, a second run genuinely re-keyed an existing creative while thirty days of its
metrics still pointed at the old key. `migrate:fresh --seed` never met it, because from an empty
schema every creative is a create.
