# Task Management

Backend: `app/Domains/Tasks`. Table: `tasks` (tenant-scoped; optional `client_workspace_id`/
`project_id`). Fields: title, description, `status`, `priority` (low/normal/high/urgent),
`assignee_id`, `created_by`, `start_date`/`due_date`, `checklist` (JSONB), `meta`. `isOverdue()`
helper.

## Statuses
`backlog → todo → in_progress → waiting_client → blocked → review → completed → cancelled`.

## API (`/api/v1`)
- `GET tasks` (filters: status, project_id, client_workspace_id, `mine`).
- `POST tasks`, `PUT/PATCH tasks/{id}`.
Permissions: `tasks.view/create/update/delete`. Tenant-isolated.

## Tested
`NotificationTaskTest`: create + **cross-tenant isolation** (green).

## Planned
Subtasks/dependencies/recurrence, watchers, time tracking, escalation, activity history; views
(List/Kanban/Calendar/My/Team/Overdue/Waiting-for-Client); automated task templates (onboarding,
tracking setup, launch, weekly optimization, monthly report, closing, renewal, invoice follow-up);
links to automation + notifications. See `KNOWN_LIMITATIONS.md`.
