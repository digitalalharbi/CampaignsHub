# Security Audit — CampaignsHub (Phase 11, 2026-07-27)

Scope: multi-tenant isolation, authorization, secrets, transport/session, injection surfaces. Findings below;
no unresolved high-severity issues.

## Multi-tenant isolation — PASS
- Domain models are tenant-scoped via the `BelongsToTenant` global scope (`TenantScope`, fail-closed): a
  query outside a tenant context returns nothing; route-model binding 404s across tenants.
- Models with `tenant_id` but **no** global scope were reviewed individually:
  - `Role` — every read is explicitly constrained to `tenant_id = current OR NULL (system role)`
    (TeamController, InvitationService, HasRoles::assignRole). No cross-tenant role resolution.
  - `AuditLog` — reads are user-scoped (SecurityController: `user_id = current user`) or keyed by the
    `entity_id` of an already tenant-verified model (CampaignActivityController resolves the campaign via the
    tenant-scoped model first; entity ids are UUIDs, globally unique). No cross-tenant audit leak.
- Locked with tests: `AlertsIsolationTest` (new surface), plus existing `ProjectScopingTest` and the
  entitlement/command-center isolation suites.

## Authorization — PASS
- Every `/api/v1/*` app route is behind `auth:sanctum` + `tenant`; mutations check `hasPermission(...)`
  (`abort_unless`). Company (brand/self-serve) workspaces are additionally fail-closed on client-management
  APIs via `EnsureEntitlement` (`EntitlementMatrixTest`).
- Suspended/disabled accounts are denied on every authenticated request and blocked at login/token issuance
  (`EnsureAccountActive`, `SuspendedAccountTest`).
- Alerts management requires `alerts.view` (read) / `alerts.manage` (write); a limited member is denied
  (verified in-browser across 3 engines).

## Secrets & dev hatches — PASS
- No `.env` tracked; `.env` git-ignored. No hardcoded secrets in `app/`.
- Dev-only testability secrets (OTP `dev_code`, portal `dev_token`, invitation `dev_link`) are **hard-gated
  off in production** regardless of config (`ProductionHardeningTest`).
- Delivery is honest: no channel is ever logged `sent` without a real provider acknowledgement; defaults are
  `awaiting_provider_credentials`.

## Transport / session — PASS
- Sanctum SPA cookie auth; CORS scoped to `api/*`, `sanctum/csrf-cookie`, `login`, `logout` with
  `supports_credentials=true` and an explicit (non-wildcard) allowed-origins list from env.
- Standard exception envelope; `APP_DEBUG=false` in production hides internals (500 → generic message).

## Injection / uploads — PASS (reviewed)
- Queries use Eloquent/bindings; the raw metric aggregations use static SQL with no user-interpolated values.
- Uploads are tenant/project scoped; downloads never expose absolute filesystem paths (client Files tab).

## Residual / accepted
- Email / WhatsApp / SMS and Google login are **Awaiting Provider Credentials** — not vulnerabilities; wiring
  a provider is a credentials task.
- Alerts management has no frontend page yet (API-complete) — see OPEN_GAPS G-019.
