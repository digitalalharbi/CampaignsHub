# MediaBuying Platform — Engineering Guide (CLAUDE.md)

> منصة SaaS متعددة العملاء لإدارة وأتمتة رحلة الميديا باينج بالكامل.
> Multi-tenant SaaS to run the full media-buying lifecycle: lead → client → proposal →
> onboarding → media plan → content → launch → tracking → attribution → optimization →
> reporting → billing.

This file is the source of truth for how the codebase is structured and how work must be done.
Read it before writing any code. It **overrides** default assumptions.

---

## 1. Architecture (non-negotiable)

- **Backend**: Laravel 12, **API-only**. PHP 8.3+ (dev machine runs 8.4). REST under `/api/v1`.
- **Frontend**: React + TypeScript + Vite, **decoupled** SPA. No Blade, no Inertia in the app.
- **Database**: PostgreSQL 16. UUIDs for API-exposed entities, `NUMERIC` money, `TIMESTAMPTZ`, `JSONB` for raw payloads.
- **Cache/Queue**: Redis + Laravel Queues + Horizon.
- **Style**: Modular Monolith + Domain-Driven Design. Split into `app/Domains/*`.

### Layering rules
1. Controllers are thin — they delegate, they do not contain business logic.
2. Validation lives in Form Requests.
3. Use cases live in **Actions**; complex orchestration in **Services**.
4. Data crosses layers as **DTOs**; rule-bearing values as **Value Objects**.
5. JSON output goes through **API Resources** — never return Eloquent models directly.
6. External SDKs are hidden behind **Contracts (interfaces) + Adapters**. Controllers never call an SDK.
7. Multi-step writes run inside DB transactions; queue side effects with `afterCommit`.

### Domain folder shape
```
app/Domains/<Domain>/
  Actions/ Contracts/ DTOs/ Enums/ Events/ Exceptions/
  Jobs/ Listeners/ Models/ Policies/ Queries/ Repositories/
  Resources/ Services/ ValueObjects/
```
Domains: Identity, Tenancy, CRM, Clients, Onboarding, Proposals, Contracts, Campaigns,
MediaPlanning, Content, Approvals, Advertising, Integrations, Tracking, Attribution,
Ecommerce, Analytics, Optimization, Automation, Tasks, Notifications, Reports, Billing,
AI, MCP, Audit.

---

## 2. API contract

Every response uses the envelope:
```json
{ "success": true, "message": "...", "data": {}, "meta": {}, "errors": null }
```
Errors: `success:false`, `data:null`, `errors:{field:[...]}`, `meta.request_id` present.
Use correct status codes (200/201/202/204/400/401/403/404/409/422/429/500/503).
Standardize pagination, filtering, sorting, search, allowed includes. Every request carries a
`request_id` (and honors an `Idempotency-Key` on unsafe writes where declared).

---

## 3. Multi-tenancy (safety-critical)

- Every operational row has `tenant_id`.
- **Never trust a tenant id coming from the frontend.** Resolve tenant from the authenticated
  token/session (or domain), set it on a request-scoped context, and apply it via a global scope.
- All important unique constraints include `tenant_id`. Cache keys, storage paths, and broadcast
  channels are tenant-scoped. Tenant-isolation tests are mandatory and must stay green.

## 4. Security & authz
- Sanctum by default (SPA cookie auth + PATs). Passport only if real external OAuth2 is needed.
- Authorization enforced server-side via Policies/Gates — never by hiding buttons in React.
- AI/automation may **never** launch a campaign, change budget, or pause an ad without an
  explicit, permissioned, audited human approval.

## 5. Integrations
- One `Connector` interface per capability (advertising, ecommerce, payments, AI).
- When credentials/permissions are missing: build the full connector + OAuth flow + settings pages
  + a Sandbox/Fake connector + contract tests, and expose state `Awaiting Credentials`.
- Never fake a successful external call. Never commit secrets. `.env.example` only.

## 6. Frontend rules
- Design tokens only (see `docs/design-tokens.md`); no ad-hoc colors.
- Every page/component supports: loading, skeleton, empty, error, no-permission, stale, syncing.
- RTL for Arabic, LTR for English. Latin digits for numbers/dates/ids. Dark mode complete.
- Data-bearing UI shows Data Source + Last Updated. No fabricated data.

---

## 7. Definition of Done (quality gates)
No task/phase is "done" until, for the touched code:
- `composer test` green, `pint --test` clean, Larastan (PHPStan) clean, migrations reversible.
- Frontend: `tsc --noEmit` clean, ESLint clean, Vitest green.
- No secrets in git, no critical TODOs, no dead buttons, no fake integrations, no console errors.
- Evidence captured (command output / screenshot).

## 8. Environment
- Local: PostgreSQL 16 + Redis 8 (Homebrew), PHP 8.4, Node 24. Docker files exist but Docker is
  not installed on this machine — Docker-based steps are authored, not locally verified.

## 9. Progress
See `docs/PROGRESS.md` for the phase log and what is verified vs. pending.
