# Production Runbook — CampaignsHub

Operational requirements to run CampaignsHub in production. Verified against the current codebase (Phase 10).

## 1. Processes (all three are required)

| Process | Command | Purpose |
|---|---|---|
| Web | `php artisan serve` behind a real web server (nginx/php-fpm) | Serves the API |
| SPA | nginx serving `frontend/dist` **with a `try_files … /index.html` fallback** — see `deploy/nginx-spa.conf` | Serves the app |

> **The SPA fallback is not optional.** Every route in this product is a client-side route, so the
> browser asks the server for `/admin/login` or `/agency/clients/acme` by name. A static server
> without the fallback answers 404 — and neither `npm run dev` nor `vite preview` shows it, because
> both have one built in. A deep link therefore works on every developer machine and fails the first
> time a customer opens one from an email. `deploy/nginx-spa.conf` is the working configuration for
> both origins, including the `SESSION_DOMAIN` leading dot that makes the shared cookie work.
| Scheduler | `* * * * * php artisan schedule:run` (system cron) | Runs the timed jobs below |
| Queue worker | `php artisan horizon` (supervised) — or `php artisan queue:work --queue=reports,default --tries=3 --timeout=900` | Report generation + async work |

**Without the queue worker**, scheduled reports are created and queued but stay `processing` (a report must be
`completed` before it can be exported/shared). **Without the scheduler**, no scheduled reports, SLA evaluation,
or alerts fire.

> **`--queue=reports,default` is not optional on the plain worker.** Report generation is dispatched
> onto its own `reports` queue. A worker started with no `--queue` consumes `default` only, so every
> report sits at «قيد المعالجة» forever while the worker reports itself perfectly healthy. Horizon's
> supervisor is configured with both queues (`config/horizon.php`), `reports` first.

### Horizon (PROD-001)

`php artisan horizon` replaces `queue:work` when `QUEUE_CONNECTION=redis`, which is what `.env.example`
sets. The dashboard is at `/horizon` and is gated on `users.is_platform_admin` — **not** on any tenant
role or permission. That is deliberate: Horizon lists job payloads, and a payload here carries tenant
ids, client names and store identifiers, so it is one screen showing every tenant's work at once.

### The worker timeout is not a preference — SNAP-STRUCTURE-RETRY-001

`--timeout=900` above matches `config/horizon.php`, and both must stay under the connection's
`retry_after` (1200) and at or above the longest job's own timeout (`SyncAccountStructureJob`, 900):

    longest job timeout  <=  worker timeout  <  retry_after
    900                  <=  900             <  1200

This is a contract, not three independent numbers. It shipped violated — `retry_after` at Laravel's
default of 90 against a job declaring 900 — and Redis handed the same still-running structure sweep to
a worker every ninety seconds until its attempts were spent, so no sweep on the live Snapchat account
ever finished and Ad Sets, Ads and Creatives stayed empty. Nothing threw; 2,287 tests passed.

Check it at any time, in the container you actually care about:

```bash
php artisan queue:contract
```

It prints the active connection, every `retry_after`, every worker timeout and every job's timeout,
and exits non-zero if the ordering is wrong. The deploy script runs it in both the `backend` and
`queue` containers before restarting Horizon.

Horizon's long-wait notifications are deliberately **not** routed. Every delivery channel in this
installation is `awaiting_provider_credentials` (§2), so wiring them would produce a queue monitor
that silently fails to alert — the exact failure a queue monitor exists to prevent. Point your own
monitoring at `/api/v1/admin/operational-status` instead (§5).

### Scheduled jobs (from `routes/console.php`, confirm with `php artisan schedule:list`)
- `ops-heartbeat` — every minute (scheduler + queue liveness; see §5)
- `requests:prune-uploads` — hourly (expired upload sessions + orphaned files)
- `requests:evaluate-sla` — every 10 min (request SLA warnings/breaches)
- `reports:dispatch-scheduled` — every 5 min (due schedules → snapshot + honest delivery ledger)
- `alerts:evaluate` — every 15 min (budget risk, no results, ROAS drop, sync failure, token expiry)
- `integrations:sync` — every 30 min (the ad-platform metrics sweep, re-asking the last 7 days)
- `integrations:sync-structure` — `55 */6 * * *` (campaigns → ad sets → ads → creatives; runs **before**
  the metrics sweep on purpose, because insights for an undiscovered campaign are dropped)
- `integrations:refresh-tokens` — hourly (refreshes ahead of expiry rather than on failure)
- `commerce:sync` — hourly at :20 (Salla/Zid products, customers, orders, abandoned carts)
- `integrations:prune-raw` — daily 03:30 (raw payload retention)
- `subscriptions:lifecycle` — daily 01:00 (trials, renewals, past due, grace, suspension)

## 2. Delivery providers (honest by default)

Email / WhatsApp / SMS are **adapters** resolved from `config/providers.php`. The shipped defaults are the
`Null*` adapters: `isConfigured() = false`, so every delivery is recorded as `awaiting_provider_credentials`
and **nothing is ever logged as `sent` without a real provider acknowledgement**. States:
`queued | awaiting_credentials | sending | sent | failed | retrying | suppressed`.

To go live on a channel, bind a configured `MessageProvider` implementation for that channel in
`config/providers.php` (`channels.<channel>`). No call-site changes are needed — `NotificationDispatcher`,
scheduled-report delivery, and alerts all read the channel status through the registry.

Until then, treat email/WhatsApp as **Awaiting Provider Credentials** (not a defect). Google (social) login is
likewise **Awaiting Credentials**.

## 3. Production safety gates (enforced in code)

- **Dev secrets are hard-gated off in production.** `ContactVerificationService::exposeDevSecrets()` returns
  `false` whenever `APP_ENV=production`, regardless of `requests.verification.expose_dev_code`. This suppresses
  the OTP `dev_code`, the portal `dev_token`, and the invitation `dev_link`. Locked by `ProductionHardeningTest`.
- **Suspended/disabled accounts** are denied on every authenticated API request (`EnsureAccountActive`), and
  cannot log in or mint tokens.
- **Multi-tenant isolation** is fail-closed via global scopes (`BelongsToTenant`/`TenantScope`); route-model
  binding 404s across tenants.

## 4. Environment

`config:cache` is safe (no closures in config). Required keys are present in `.env.example`. Set for production:
`APP_ENV=production`, `APP_DEBUG=false`, a strong `APP_KEY`, `QUEUE_CONNECTION=database` (or redis),
`SESSION_DRIVER`, `SANCTUM_STATEFUL_DOMAINS` + `SESSION_DOMAIN` matching the SPA origin, and DB/mail creds.

## 5. Health and monitoring (PROD-001)

Three endpoints, and they answer three different questions. Pointing the wrong monitor at the wrong
one is how a delayed report becomes an outage — or how a dead worker goes unnoticed for a week.

| Endpoint | Question | Auth | Point what at it |
|---|---|---|---|
| `GET /up` | Is the PHP process alive? | none | Container liveness probe |
| `GET /api/v1/ready` | Can **this node** serve a request? | none | Load balancer readiness |
| `GET /api/v1/admin/operational-status` | Is the **deployment** working? | platform admin | Your alerting/paging monitor |

**`/api/v1/ready` deliberately does not fail when a background process is dead.** A stopped worker is
a serious fault and not a reason to pull healthy web nodes out of rotation — doing that turns a
delayed report into an outage, at exactly the moment the operator most needs the product reachable in
order to diagnose it. It probes only the datastores this installation actually uses: a deployment on
the database queue and database sessions is never marked unready for a Redis it does not have.

**`/api/v1/admin/operational-status`** is the one that pages somebody. It returns **200** when healthy,
**503** when not, so an uptime check needs no JSON parsing at all, and the body names the failing part
and the command that fixes it. Verdicts:

| Verdict | HTTP | Meaning |
|---|---|---|
| `healthy` | 200 | Datastores up, both background processes beating |
| `unverified` | 200 | Heartbeats not seen **yet** — normal for a deployment <2 min old or a flushed cache |
| `degraded` | 503 | A background process has stopped: reports and syncs are silently not happening |
| `down` | 503 | A datastore is unreachable |

### How the background processes are checked

Both leave a heartbeat every minute (the `ops-heartbeat` scheduler entry). The scheduler stamps
itself; the queue's stamp is written **by `QueueHeartbeatJob` on the worker**, because dispatching a
job proves only that Redis accepted a push — a job that comes back out is the only thing that proves
somebody is consuming. A heartbeat older than 5 minutes (four missed beats) reads `down`.

This is the gap this section exists to close: before it, `/ready` answered `ready` because the
database was up, while the worker had been dead for a day and every report sat at «قيد المعالجة».

## 6. Backups (PROD-001)

```bash
php artisan ops:backup --path=/var/backups/campaignshub --keep=14
```

Writes `database.dump` (pg_dump custom format), `storage.tar.gz` (uploaded files) and a
`manifest.json` recording each artefact's size and SHA-256. Run it from cron and **check the exit
code** — every failure path (no `pg_dump`, unwritable target, non-zero dump exit, a dump too small to
be real) aborts with status 1 and writes no manifest. Nothing is ever reported as backed up that was
not: a nightly job logging «done» over a truncated dump turns «we have no backups» into «we believed
we had backups», and the difference is discovered on the single worst day.

**The artefacts are local and unencrypted.** Shipping them off-host and encrypting them is yours to
do with your own tooling — the command says so on every run rather than implying it has happened.
`manifest.json` records `"encrypted": false, "offsite": false` for the same reason.

### Restore drill — run it quarterly, and write down the date

The cheap half is automated:

```bash
php artisan ops:backup --path=/var/backups/campaignshub --verify
```

It re-hashes the newest backup against its manifest and exits 1 on `CORRUPT` or `MISSING`. A file on
disk is not a backup; a file you can prove is intact is.

The expensive half is a human step, on purpose — a command able to restore is a command that will one
day restore over something live:

```bash
createdb campaignshub_drill
pg_restore --no-owner --no-acl -d campaignshub_drill /var/backups/campaignshub/<stamp>/database.dump
# point a scratch APP_ENV at it, sign in, open a report, confirm figures match the day of the backup
dropdb campaignshub_drill
```

A backup nobody has restored is a hypothesis.

## 7. Clean install

```bash
# 1. Backend
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate      # then fill DB/Redis/SANCTUM/SESSION values
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force  # the permission catalogue is code, not data
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 2. Frontend
cd ../frontend && npm ci && npm run build             # serve dist/ per deploy/nginx-spa.conf

# 3. Processes
#    cron:       * * * * * php artisan schedule:run
#    supervisor: php artisan horizon

# 4. Verify — do not skip
curl -fsS https://<api>/api/v1/ready
php artisan schedule:list
```

`config:cache` is safe here (no closures in config). The first `operational-status` reads `unverified`
until the heartbeats land, which takes about two minutes; that is expected and is not a failure.

A fresh install has **no provider credentials**, so every ad and commerce platform reads
`Awaiting Credentials` until the platform operator configures them at `/admin` → Integrations →
Providers. That is the honest state, not a defect. The OAuth callback and webhook URLs to paste into
each provider's own console are shown on that screen, derived from `app.url` and never stored — see
`ProviderDefinition::redirectUri()`.

## 8. Safe upgrade

```bash
php artisan down --render=errors::503 --retry=60
php artisan ops:backup --path=/var/backups/campaignshub   # exit non-zero ⇒ STOP, do not proceed
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force
cd frontend && npm ci && npm run build && cd ..
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan horizon:terminate     # supervisor restarts it on the NEW code
php artisan up
curl -fsS https://<api>/api/v1/ready
```

- **`horizon:terminate` is required, not optional.** A long-running worker holds the old code in
  memory; without it, jobs dispatched by the new release are executed by the old one. The same applies
  to a plain `queue:work` — restart it.
- **Back up before migrating, and honour the exit code.** The backup command fails loudly precisely so
  this line can be a gate.
- **Migrations are additive.** The frozen tags `v1.0.0-baseline` and `v1.1.0-expanded-final` never
  move, and no migration in this repository drops a column carrying customer data.
- **Rolling back code is safe; rolling back a migration is not.** If a release must be reverted, revert
  the code and leave the schema forward — the additive rule is what makes that work.

## 9. Secrets

- **Provider client secrets** (the six ad platforms, Salla, Zid) live in `provider_configurations`,
  encrypted at rest, written only at `/admin` → Integrations → Providers. **There is no endpoint that
  reads a secret back** — the console shows whether a field is set, never its value. Rotation is a
  first-class action on that screen.
- **Payment gateway keys** are environment-only and are deliberately **not** writable from any console:
  a surface able to change one is a surface whose compromise redirects every customer payment.
- **OAuth access/refresh tokens** are encrypted per connection and only ever obtained through
  `TokenVault::fresh()`, which refreshes ahead of expiry and marks the connection `error` on failure.
  `integrations:refresh-tokens` runs hourly so a token is renewed before anything needs it.
- **`APP_KEY` decrypts all of the above.** Losing it loses every stored credential; rotating it without
  re-encrypting does the same. Keep it in your secret manager, not in the repository, and include it in
  whatever you back up the database with — a database dump without its key restores to nothing usable.
- **Card details are never stored.** The payment method lives with the provider; this system keeps a
  reference.
### Dependency advisories — release check

`composer audit` and `npm audit --omit=dev` are part of every release check. State as of this commit:

- **`composer audit`: clean.** It was not — `guzzlehttp/guzzle` carried CVE-2026-69246 (high,
  *noncanonical host can bypass host-based checks*) and CVE-2026-69245. This product makes outbound
  calls to eight external APIs through `PlatformHttp`, so a host-check bypass is squarely in its path.
  Patched by updating guzzle.
- **`npm audit --omit=dev`: two high advisories remain, both the same one**
  ([GHSA-qwww-vcr4-c8h2](https://github.com/advisories/GHSA-qwww-vcr4-c8h2), *React Router: RSC Mode
  CSRF bypass allows action execution before a 400 response*), reported against `react-router` and
  `react-router-dom`.

  **Not applicable to this application, and deliberately not "fixed".** The vulnerable path is React
  Router's **RSC server mode**. This frontend is a pure client-side SPA — `src/app/router.tsx` builds a
  `createBrowserRouter`, there is no React Router server runtime, no server actions and no RSC
  rendering anywhere in the build. There is no forward fix: the advisory range is `7.12.0 – 8.2.0`,
  the newest published release is `7.18.2`, and the only remediation npm offers is a **semver-major
  downgrade to 7.11.0** — replacing an unreachable advisory with a routing-library downgrade across
  every route in the product, which is the larger risk by a wide margin.

  **Re-check this at each release.** The moment a patched `7.18.x`/`7.19.x` ships, take it; and if this
  app ever adopts RSC or a React Router server runtime, the assessment above expires immediately and
  the advisory becomes live.
