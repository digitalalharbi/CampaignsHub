# ADR 0001 — Sanctum cookie-based SPA authentication

## Status
Accepted (2026-07-21).

## Context
Phase 1 shipped a working auth flow that issued a Sanctum Personal Access Token and stored it in
memory on the client. That is XSS-safe (no localStorage) but loses the session on refresh and is not
the recommended pattern for a first-party SPA. The spec mandates cookie-based SPA auth for the React
app, with CSRF, `withCredentials`, and stateful domains.

## Decision
Use **Sanctum stateful (cookie/session) authentication** for the React SPA:
- The SPA calls `GET /sanctum/csrf-cookie` once, then `POST /api/v1/auth/login`.
- Login authenticates the **web (session) guard**; the session cookie (HttpOnly) carries auth.
- `auth:sanctum` on API routes resolves the user from that session via
  `EnsureFrontendRequestsAreStateful` (already enabled through `statefulApi()`).
- Axios sends the `XSRF-TOKEN` cookie back as the `X-XSRF-TOKEN` header automatically.
- On app load the SPA calls `/auth/me` to restore the session — refresh no longer logs the user out.

Personal Access Tokens remain available for **non-browser API clients** (mobile, integrations) via a
dedicated `POST /api/v1/auth/tokens` endpoint, so both models coexist without weakening either.

## Consequences
- No auth token is ever stored in JS-accessible storage.
- Requires CORS `supports_credentials=true` and the frontend origin allow-listed; in dev the Vite
  proxy keeps requests same-origin so cookies "just work".
- `SANCTUM_STATEFUL_DOMAINS` must list the SPA origins.
