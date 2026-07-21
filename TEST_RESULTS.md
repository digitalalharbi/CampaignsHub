# Test Results

_Captured 2026-07-21 on the dev machine (PHP 8.4, PostgreSQL 16, Node 24)._

## Backend (Laravel 12, PostgreSQL test DB `mediabuying_test`)
- **`php artisan test` → 41 passed (151 assertions)**, ~1.9s.
- **Laravel Pint** (`--test`) → passed (no style violations).
- **Larastan / PHPStan level 5** (`--memory-limit=512M`) → **No errors**.

### Coverage by area
| Suite | Tests | What it proves |
|---|---|---|
| HealthTest | 2 | Envelope + request-id header; 404 error envelope |
| AuthTest | 6 | Cookie-session register/login/me/logout + PAT endpoint; login audited |
| TenantIsolationTest | 5 | Autofill tenant_id, cross-tenant reads blocked, **fail-closed**, platform scope |
| CrmLeadTest | 7 | Lead CRUD, route-binding under tenant scope, convert→opportunity, idempotency, isolation, authz |
| ConnectorContractTest | 15 | All 7 connectors satisfy the contract; awaiting connectors never fabricate; sandbox marked |
| IntegrationApiTest | 4 | Connector list/status, awaiting-credentials refusal, sandbox connect+sync, health |
| Example (framework) | 2 | Scaffolding sanity |

> Tests run on **PostgreSQL**, not SQLite — tenant isolation, JSONB, and constraints are exercised
> against the real engine, per the brief.

## Frontend (React 19 + TypeScript strict + Vite)
- **`tsc -b` (typecheck)** → PASS.
- **oxlint** → PASS (exit 0).
- **Vitest** → **4 passed** (auth store permission/guest/clear logic).
- **`vite build`** (production) → PASS.

## Live verification (in-browser, evidence captured during build)
- System status dashboard reads `/health` + `/ready` (db/redis up), Arabic RTL + English dark LTR.
- Auth: guest → `/login`; login (csrf+session) → app; **session survives reload** (`/auth/me` 200); logout.
- CRM Leads: list (200), create modal, **convert → 201** updates row to "converted".
- Integrations: 7 connector cards — Sandbox "connected", 6 platforms "awaiting credentials".
- **Zero console errors** across the above.

## Not yet covered
No Playwright E2E, no visual-regression, no accessibility-automation suites yet (planned, Phase 10).
Frontend component/RTL/a11y unit coverage is minimal so far (one store test) — see
`KNOWN_LIMITATIONS.md`.
