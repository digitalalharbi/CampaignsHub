# Client Portal

The client-facing side of a Client Workspace. Backend foundations exist (client workspaces,
membership + client roles, projects, isolation); the portal **UI is not built yet**.

## Intended scope (per brief)
A client (per their mode + permissions) sees ONLY their own: projects, campaigns, permitted spend,
revenue, ROAS, content awaiting approval, tasks for them, recent reports, notifications, integration
status/last sync, recent files, their AI usage, team invites, subscription (if Self-Service).
Never agency-internal data, margin, or other clients' data.

## Client roles
`client_admin` (invite team, create projects, connect sources, manage AI keys, request campaigns,
approve content, create tasks, upload files — subject to plan/permissions), `client_approver`,
`client_viewer`. Stored on `client_workspace_user.client_role`.

## Isolation guarantees (implemented at the data layer)
Tenant global scope (fail-closed) + workspace/project scoping + server-side permissions. A client
route like `/clients/{clientWorkspaceId}` will resolve the workspace within the tenant scope and
enforce membership + role before returning anything.

## Not yet built
Portal pages (overview/projects/users/integrations/AI/reports/tasks/notifications/files/billing/
usage/branding/security/audit), invite flow, branding application, per-client dashboard. See
`KNOWN_LIMITATIONS.md`.
