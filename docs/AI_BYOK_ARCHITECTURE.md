# AI BYOK Architecture

Bring-Your-Own-Key AI, isolated per scope. Backend: `app/Domains/AI`. Table:
`ai_provider_credentials`. Providers: OpenAI, Anthropic, Gemini (`AIProvider` enum).

## Key scopes
`platform` (platform-managed key, metered by plan) · `tenant` (agency BYOK) · `client` (client
BYOK inside their workspace) · `project` (project-specific key/config). Every row is tenant-scoped;
`client_workspace_id` / `project_id` narrow it further.

## Security (implemented + tested)
- Secret stored **encrypted at rest** (`encrypted` cast via APP_KEY); column is not plaintext.
- Secret **never returned** by the API — `AICredentialResource` exposes only `masked_key`
  (`••••1234`). `encrypted_secret` is in `$hidden`.
- `revealSecret()` decrypts **server-side only** for real calls.
- Strict tenant isolation — a tenant never sees another tenant's key.
- `ai.manage` required to store; `ai.view` to list; audit records metadata only (never the secret).
- Fields for `monthly_budget`, `monthly_token_limit`, `allowed_models`, `allowed_features`,
  `organization_id`, health-check timestamp.

`AIBYOKTest` (green): encryption at rest, no-secret-in-response, masked list, cross-tenant
isolation, permission gate.

## Not yet built
Live provider calls, usage/cost metering enforcement, per-request model/feature gating, key
rotation scheduler, sensitive-data redaction before send, health check via real provider ping. These
are the activation steps (see `INTEGRATION_CREDENTIALS_CHECKLIST.md`). The platform must never use
one client's key for another, and never train on one client's content for another — enforced by
scope + isolation.
