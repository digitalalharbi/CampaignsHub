# Client Workspaces

A rentable, isolated space an agency (tenant) provisions per client. Backend:
`app/Domains/ClientWorkspaces` + `app/Domains/Projects`. Tables: `client_workspaces`,
`client_workspace_user` (membership + client role), `projects`.

## Modes (configurable per workspace — no code changes)
- **Managed** — agency runs everything; client views + approves.
- **Collaborative** — agency + client work together, gated by permissions.
- **Self-Service** — client runs own projects/sources/team under their subscription.

Enum: `WorkspaceMode` (`managed|collaborative|self_service`).

## What a workspace holds
Branding (logo/brand name/colors via `branding` JSONB), per-workspace `limits`, optional
`custom_domain`, members with client roles (`client_admin|client_approver|client_viewer`), and
**projects**. Each project (`projects`) has status (`setup|active|paused|closed`),
`setup_completion` (0–100), and an account manager; it owns its own sources/campaigns/reports as
those domains attach bindings.

## API (`/api/v1`)
- `GET/POST client-workspaces`, `GET client-workspaces/{id}` (with projects_count).
- `GET/POST projects` (filter by `client_workspace_id`).
Permissions: `workspaces.*`, `projects.*`. All tenant-scoped (fail-closed).

## Tests
`ClientWorkspaceTest`: create in all 3 modes, add project, mode validation, **cross-tenant
isolation** (green).

## Not yet built
Client-facing portal UI, invite flow UI, membership management UI, per-workspace usage enforcement,
custom-domain routing. See `KNOWN_LIMITATIONS.md`.
