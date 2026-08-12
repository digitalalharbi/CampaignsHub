# ENGINEERING GUIDE — CampaignsHub

How this codebase is built and how work must be done. Imported by `CLAUDE.md`, so it applies to
every session and every contributor, human or agent.

These are not style preferences. Each rule below exists because its opposite shipped once and was
found. Where a rule and a habit disagree, the rule wins.

---

## 1. Architecture

- **Backend** — Laravel 12, API-only, PHP 8.4. REST under `/api/v1`.
- **Frontend** — React 19 + TypeScript (strict) + Vite, a decoupled SPA. No Blade or Inertia in the
  application (Blade renders email and print/PDF templates only).
- **Database** — PostgreSQL 16. UUIDs for API-exposed entities, `NUMERIC` for money, `TIMESTAMPTZ`
  for instants, `JSONB` for raw provider payloads.
- **Cache / queue / sessions** — Redis, Laravel queues, Horizon.
- **Shape** — modular monolith with domain-driven packages under `app/Domains/*`.

### Layering

1. Controllers are thin; they delegate and hold no business logic.
2. Validation lives in Form Requests.
3. Use cases live in **Actions**; multi-step orchestration in **Services**.
4. Data crosses layers as **DTOs**; rule-bearing values as **Value Objects**.
5. JSON leaves through **API Resources** — never return an Eloquent model directly.
6. External SDKs sit behind **Contracts + Adapters**. A controller never calls an SDK.
7. Multi-step writes run in a transaction; side effects queue with `afterCommit`.

### Domain folder shape

```
app/Domains/<Domain>/
  Actions/ Console/ Contracts/ DTOs/ Enums/ Events/ Exceptions/ Http/
  Jobs/ Listeners/ Models/ Policies/ Providers/ Queries/ Repositories/
  Resources/ Services/ Support/ ValueObjects/
```

The domains that exist are the authority on the list — read `app/Domains/` rather than a table in a
document that will drift.

## 2. API contract

Every response uses one envelope:

```json
{ "success": true, "message": "…", "data": {}, "meta": {}, "errors": null }
```

Errors carry `success:false`, `data:null`, `errors:{field:[…]}`, and always `meta.request_id`. Use
the status code that is true: 200/201/202/204/400/401/403/404/409/422/429/500/503. Pagination,
filtering, sorting, search and allowed includes are standardised. Unsafe writes honour an
`Idempotency-Key` where they declare one.

## 3. Multi-tenancy — safety-critical

- Every operational row carries `tenant_id`.
- **Never trust a tenant id from the client.** Resolve it from the authenticated session or token,
  put it on a request-scoped context, and enforce it through a global scope.
- Unique constraints that matter include `tenant_id`. Cache keys, storage paths and broadcast
  channels are tenant-scoped.
- Tenant-isolation tests are mandatory and stay green. A leak here is the worst defect this product
  can have.

## 4. Security and authorisation

- Sanctum: SPA cookie sessions for browsers (ADR 0001), personal access tokens for non-browser
  clients only.
- Authorisation is enforced server-side through Policies and Gates — never by hiding a button.
- Access derives from a **membership**, not from a column on the user (ADR 0002).
- AI and automation may **never** launch a campaign, change a budget or pause an ad without an
  explicit, permissioned, audited human approval.
- A signed-out session id is revoked, not merely destroyed (`ACCESS-EXIT-003`).

## 5. Integrations

- One `Connector`/adapter interface per capability: advertising, commerce, payments, mail, AI.
- With no credentials: build the whole connector, the OAuth flow, the settings page, a sandbox or
  fake adapter and contract tests — and report the honest state (`READY_FOR_CREDENTIALS` /
  `BLOCKED_EXTERNAL_CREDENTIALS`).
- **Never fake a successful external call.** Never commit a secret. `.env.example` carries variable
  names and placeholders only.
- A demo connection is never reported as a live one.

## 6. Frontend

- Design tokens only (`docs/design-tokens.md`); no ad-hoc colours.
- Every screen supports: loading, skeleton, empty, error, no-permission, stale and syncing.
- Arabic RTL and English LTR are both complete. **Latin digits** everywhere — numbers, dates, ids.
  Dark mode is complete, not partial.
- Data-bearing UI shows its source and when it was last updated. Nothing is fabricated.

## 7. Conventions that carry weight

- **Absent is never zero.** A metric nobody reported is `null` and reads «لم تُرسل», never `0`.
- **A refusal is not a failure.** Permission, expired session, not-found and a server error each get
  their own message, and Retry appears only where retrying can work.
- **Fail closed.** Every scope ceiling, permission and share link narrows and never widens.
- **Nothing claims delivery it cannot prove.** No message is recorded as sent without a provider
  acknowledgement; no integration says Connected without a real round trip.
- **Convert money once, at ingest**, keeping `original_*` beside the converted figure with a dated
  rate and a named source. No rate means the figure is withheld — never guessed, never zero.
- **A merchant's day is not a UTC day.** Store timestamps are true instants, report windows are
  measured on the client's clock, and every order keeps the calendar date its own merchant sold it on.

## 8. Definition of done

For the code a change touches:

- `php artisan test` green · `vendor/bin/pint --test` clean · migrations reversible (or a `down()`
  that states honestly why it does nothing).
- `npm run typecheck` clean · `npm run lint` clean · `npm test` green · `npm run build` succeeds.
- The three-browser gate green when the change is reachable from the browser:
  `npm run gate` with its exit code captured on its own line, never through a pipe.
- No secret in git, no dead button, no fake integration, no console error.
- Evidence captured — the command output, not a claim about it.

**Passing tests are not proof of completeness, and documentation is not a substitute for code.**
Do not report a task complete that has not been run.

## 9. Environment

PHP 8.4, Composer, Node 20+, PostgreSQL 16, Redis. Docker files exist and are authored, not
routinely exercised on a developer machine. Setup and the three databases: `README.md` and
`HANDOFF_MANIFEST.md`.
