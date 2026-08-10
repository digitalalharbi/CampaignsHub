# DEPLOYMENT CHECKLIST — CampaignsHub

Run top to bottom. Every step is either a command or a question with one right answer.
`php artisan production:check` mechanises most of section 2 — run it, and fix what it names.

---

## 1. Before the first deploy

- [ ] PostgreSQL 15+ reachable, with an empty database and a role that owns it.
- [ ] Redis reachable — sessions, cache and queues all live there.
- [ ] PHP 8.4 with the extensions Laravel 12 requires, plus `bcmath` (money is compared with
      `bccomp`, never with float equality) and `zip`/`gd` for the xlsx and PDF exports.
- [ ] Node 20+ **on the build machine only**. The server serves a built bundle; it does not need Node.
- [ ] A headless Chromium for PDF export, or `REPORTS_CHROMIUM_ENABLED=false` and the honest
      «PDF export unavailable» state.
- [ ] TLS terminating in front of the app, and the proxy's real-IP headers trusted (`TrustProxies`).

## 2. Environment

Start from `backend/.env.example` — it is complete and carries **no secrets**.

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` generated (`php artisan key:generate`).
- [ ] `APP_URL` and `FRONTEND_URL` are **https** and are **not** localhost.
- [ ] `SANCTUM_STATEFUL_DOMAINS` lists every host the SPA is served from. A missing entry does not
      error — it returns 401, which reads as an authentication defect and is not one.
- [ ] `SESSION_DOMAIN` covers the app's host (a leading dot shares it across subdomains),
      `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`. `none` would let any site send the
      cookie; `strict` breaks return-from-payment.
- [ ] `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`.
- [ ] `SUBSCRIPTION_PROVIDER=moyasar` — **never `sandbox` in production.**
- [ ] Gateway keys are a matched pair (both live), and the webhook secret is present.
- [ ] **`php artisan production:check` exits 0.** It fails on every one of the above and reports the
      *shape* of a key, never its value.

## 3. Install

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan storage:link
```

Front end, on the build machine:

```bash
npm ci && npm run build          # emits frontend/dist
```

**Do not seed a production database.** The demo seeders create demo tenants, demo logins and demo
reports. They are for local, testing and demo environments and nothing else.

## 4. Workers and the scheduler

- [ ] Queue workers running (Horizon: `php artisan horizon`, supervised). Without them nothing
      generates a report, sends a message or syncs a platform.
- [ ] Cron running the scheduler every minute:
      `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
- [ ] Confirm the schedule is registered: `php artisan schedule:list`. It must include
      `subscriptions:lifecycle` (daily 01:00), `reports:dispatch-scheduled` (every 5 min),
      `notifications:send-digests` (hourly) and `integrations:sync-structure` (`55 */6`).
- [ ] Failed jobs are being retained and are visible (`php artisan queue:failed`).

## 5. Register the external URLs

From `docs/INTEGRATION_CREDENTIALS_CHECKLIST.md`, byte for byte:

- [ ] Moyasar webhook → `{APP_URL}/api/v1/payments/webhook/moyasar`
- [ ] Ad-platform redirects → `{APP_URL}/api/v1/oauth/ads/{provider}/callback`
- [ ] Ad-platform webhooks → `{APP_URL}/api/v1/webhooks/ads/{provider}`
- [ ] Commerce redirects → `{APP_URL}/api/v1/oauth/commerce/{provider}/callback`
- [ ] Commerce webhooks → `{APP_URL}/api/v1/webhooks/commerce/{provider}`

## 6. Smoke tests, in this order

1. [ ] `GET {APP_URL}/api/v1/health` answers 200.
2. [ ] The SPA loads over https with no console errors and no mixed content.
3. [ ] Sign in as the platform admin. **If sign-in appears to do nothing, it is `SESSION_DOMAIN`** —
       the browser is discarding the cookie. It is almost never a credentials problem.
4. [ ] `/admin` → the readiness panels render, and every unconfigured provider says so honestly.
5. [ ] Signup, both paths: «لحملاتي وأعمالي» must offer Starter and Growth and land in `/app`;
       «لعملائي» must offer Agency alone and land in `/agency`. Enterprise must not appear.
6. [ ] A real payment through the live gateway, at the introductory price, from a card you own —
       then confirm the webhook arrived, the subscription is active and the workspace exists.
       **Nothing else proves the payment path.** Until this is done, billing is
       `READY_FOR_CREDENTIALS` and not `LIVE_VERIFIED`.
7. [ ] Refund that payment through the gateway and confirm the product reflects it.

## 7. Upgrading an existing install

```bash
php artisan down
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan queue:restart          # workers hold the OLD code until told otherwise
php artisan horizon:terminate      # if using Horizon
php artisan up
```

`queue:restart` is not optional. A worker that is not restarted keeps running the previous
release's code against the new database — which is how a migration that passed produces failures
nobody can reproduce.

Migrations are additive and ordered by filename; there is no destructive migration in the history.
Re-running `migrate` on an up-to-date database is a no-op.

## 8. Rollback

1. Deploy the previous release's code.
2. **Do not roll migrations back.** Every migration in this repository is additive, so the previous
   release runs against the newer schema. `migrate:rollback` on a production database that has taken
   writes is how data is lost.
3. `php artisan queue:restart` again, so workers pick up the old code.
4. If a migration itself is the problem, restore from backup — see below — rather than reversing it.

## 9. Backup and restore

- [ ] `pg_dump` on a schedule, retained off-host, and **restored into a scratch database at least
      once** — an untested backup is a belief, not a backup.
- [ ] Storage (`storage/app`) backed up alongside it: report exports, uploaded files and branding
      assets live there and are not reconstructible from the database.
- [ ] Redis needs no backup. Sessions and cache are disposable by design; queued jobs are not, so
      drain the queue before a planned migration rather than relying on Redis persistence.
- [ ] `APP_KEY` stored with the secrets and **never rotated casually** — provider tokens are
      encrypted with it, and a rotation without re-encryption makes every stored integration token
      unreadable.
