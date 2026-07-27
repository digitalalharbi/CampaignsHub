# Deployment Checklist — CampaignsHub

## Pre-deploy
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY` set.
- [ ] `DB_*` point at the production Postgres; connection verified.
- [ ] `SANCTUM_STATEFUL_DOMAINS` + `SESSION_DOMAIN` match the SPA origin (exact host).
- [ ] `QUEUE_CONNECTION=database` (or redis); queue table migrated.
- [ ] `config/providers.php` channels: keep `Null*` (Awaiting Credentials) until real provider keys exist.
- [ ] Frontend built: `npm run build` → serve `frontend/dist` behind the web server / CDN.

## Processes (all required)
- [ ] Web: nginx + php-fpm (or Octane) serving the API — NOT `artisan serve`.
- [ ] Queue worker supervised: `php artisan queue:work --tries=3 --timeout=120` (systemd / Supervisor).
- [ ] Scheduler cron: `* * * * * php artisan schedule:run` → drives reports:dispatch-scheduled (5m),
      alerts:evaluate (15m), requests:evaluate-sla (10m), requests:prune-uploads (hourly).

## Security (verified — see docs/SECURITY_AUDIT.md)
- [ ] Dev secrets hard-gated off in production (ProductionHardeningTest) — verified `APP_ENV=production`.
- [ ] Multi-tenant isolation fail-closed (global scopes); cross-tenant 404.
- [ ] CORS scoped to api/sanctum/login/logout, `supports_credentials=true`, explicit allowed origins (no `*`).
- [ ] HTTPS enforced; secure + same-site cookies.

## Post-deploy smoke
- [ ] `GET /up` returns 200 (point the load balancer health check here).
- [ ] Login works; `/auth/me` returns the account payload.
- [ ] Generate a report → queued → worker completes it.
- [ ] Submit an external request → appears in the inbox.
- [ ] Trigger an alert (or wait for the scheduler) → notification received; delivery logged honestly.

## Honest external status (not blockers)
```
Email Provider    — Awaiting Credentials
WhatsApp Provider — Awaiting Credentials
SMS Provider      — Awaiting Credentials
Google OAuth      — Awaiting Credentials
```
