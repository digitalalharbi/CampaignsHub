# Production Runbook — CampaignsHub

Operational requirements to run CampaignsHub in production. Verified against the current codebase (Phase 10).

## 1. Processes (all three are required)

| Process | Command | Purpose |
|---|---|---|
| Web | `php artisan serve` behind a real web server (nginx/php-fpm) | Serves the API |
| Scheduler | `* * * * * php artisan schedule:run` (system cron) | Runs the timed jobs below |
| Queue worker | `php artisan queue:work --tries=3 --timeout=120` (supervised) | Report generation + async work |

**Without the queue worker**, scheduled reports are created and queued but stay `processing` (a report must be
`completed` before it can be exported/shared). **Without the scheduler**, no scheduled reports, SLA evaluation,
or alerts fire.

### Scheduled jobs (from `routes/console.php`, confirm with `php artisan schedule:list`)
- `requests:prune-uploads` — hourly (expired upload sessions + orphaned files)
- `requests:evaluate-sla` — every 10 min (request SLA warnings/breaches)
- `reports:dispatch-scheduled` — every 5 min (due schedules → snapshot + honest delivery ledger)
- `alerts:evaluate` — every 15 min (budget risk, no results, ROAS drop, sync failure, token expiry)

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

## 5. Health

`GET /up` is the framework health endpoint. Point the load balancer / uptime check at it.
