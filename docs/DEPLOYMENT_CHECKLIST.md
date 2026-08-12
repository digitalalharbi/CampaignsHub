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
- [ ] `SUBSCRIPTION_PROVIDER=moyasar` — **never `sandbox` in production.** The example file ships
      `sandbox`, because it carries no keys and a fresh clone has to pass `production:check` before
      anybody has credentials. Changing it here is therefore a step, not a check — and
      `production:check` fails a production install that skipped it.
- [ ] Gateway keys are a matched pair (both live), and the webhook secret is present.
- [ ] Live keys are in **production only**. `production:check` also fails a live secret in any other
      environment (`PAY-ENV-001`) — copying a working production `.env` to staging is how a box whose
      database is wiped nightly ends up charging real cards.
- [ ] Each client workspace that will connect a **Zid** store has its `timezone` set. Zid publishes
      none, so without it that store's orders fall back to an assumed UTC — kept and counted, and
      flagged as assumed on every surface, but placed on a day that may be off by the real offset
      (`COMMERCE-TZ-001`).
- [ ] **`php artisan production:check` exits 0.** It fails on every one of the above and reports the
      *shape* of a key, never its value. Expect **two warnings** on a first deploy — no mail provider
      and no `FX_RATE_DRIVER`. Both are honest unfinished integrations, not failures: the product
      never records a message as sent without the first, and withholds rather than guesses money
      without the second. Neither blocks the deploy; both should be on somebody's list.

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

Migrations are ordered by filename and re-running `migrate` on an up-to-date database is a no-op.
**Two of them REWRITE existing rows** — they are reversible, but "additive" would be the wrong word
and an operator upgrading a populated database should know which:

| Migration | What it rewrites | Why it is safe |
|---|---|---|
| `2026_08_12_120000_a_store_selling_in_dollars_is_not_holding_riyals` | Store money into the reporting currency (`COMMERCE-FX-001`). Rows already in that currency are stamped at par; rows in any other currency have their converted amount set NULL until a dated rate exists | The provider's own figures are copied into `original_*` FIRST, so nothing is lost. `down()` restores the amounts from them |
| `2026_08_13_120000_a_merchants_day_is_not_a_utc_day` | `placed_at` / `abandoned_at`, re-anchoring each stored wall clock in the store's real timezone (`COMMERCE-TZ-001`) | The stored value IS the merchant's wall clock, so re-anchoring recovers the instant that was thrown away — exact, not estimated. `down()` puts the wall clocks back |

Both were exercised up → down → up against a fully migrated database before release.

**Take a backup before upgrading past either of them** (§9). That is ordinary practice for any
release; it matters more for these two because a restore, not a rollback, is the fastest way back if
something about your data surprises them.

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
