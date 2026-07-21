# Security Review

Scope: the code built so far (Identity, Tenancy, Access, Audit, CRM, Integrations). This is an
internal self-review, not a third-party penetration test.

## Strengths (implemented)
- **Tenant isolation is fail-closed.** The global `TenantScope` returns nothing when no tenant is
  resolved (`whereRaw('1=0')`) rather than leaking cross-tenant rows. Verified by tests. Middleware
  priority guarantees the tenant is resolved *before* route-model binding (a real bug found via live
  testing and fixed + regression-tested).
- **Server-side authorization.** Permissions are checked in Form Requests / controllers
  (`hasPermission`), never by hiding UI. Platform admins are explicit.
- **Auth.** Sanctum cookie-session for the SPA (HttpOnly session cookie, CSRF via XSRF-TOKEN, stateful
  domains). No token in JS-accessible storage. PATs only for non-browser clients, with abilities.
- **Secrets.** None in git. `.env.example` only. Integration `credentials` column is a binary blob
  reserved for an **encrypted** payload; the model hides it from serialization. A hook
  (`prevent-secret-access.sh`) blocks accidental `.env` reads.
- **Transport/headers.** nginx sets `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `server_tokens off`; the API does not leak framework/server versions.
- **Input validation** via Form Requests; parameterised queries (Eloquent); output via API Resources.
- **Audit trail** is append-only (no `updated_at`), capturing actor/tenant/IP/UA/correlation id.
- **Rate limiting** on login and token issuance (`throttle:6,1`).

## Gaps / TODO (tracked)
- No 2FA, email-verification enforcement, or session/device management UI.
- CORS origins are dev-oriented; set exact production origins and keep `supports_credentials` true
  without wildcards.
- Encryption-at-rest for the integration credentials blob must be wired when real tokens land
  (use Laravel `Crypt`/`encrypted` cast or a KMS); until then it stays null.
- No automated dependency scanning (`composer audit` / `npm audit`) in CI yet; add it.
- Webhook signature verification and idempotency keys are designed but unimplemented (no live
  webhooks yet).
- No CSP header yet for the SPA; add a strict CSP at the edge.
- Broadcasting/Reverb channel authorization not implemented (feature not built).

## Recommendations before launch
1. Add `composer audit` + `npm audit` (or Snyk/Dependabot) to CI.
2. Implement 2FA + email verification; add a global 401 interceptor on the SPA.
3. Add a strict CSP and HSTS at the reverse proxy.
4. Encrypt integration credentials on write; rotate/refresh tokens via scheduler.
5. Commission an external penetration test after Phases 4–9 land.
