# Notification Architecture

Backend: `app/Domains/Notifications`. Table: `app_notifications` (distinct from Laravel's own
`notifications`). Every row carries `tenant_id`, optional `client_workspace_id`/`project_id`, and a
`user_id` recipient. **Double-scoped**: tenant global scope + explicit `user_id` filter, so a user
only ever sees their own notifications within their tenant.

Fields: `type`, `severity` (info/success/warning/critical), `title`, `message`, `source`,
`entity_type`/`entity_id`, `action_url` (deep link), `status` (unread/read/snoozed/resolved),
`read_at`/`snoozed_until`/`resolved_at`. Append-only `created_at` (no `updated_at`).

## API (`/api/v1`)
- `GET notifications` (filters: status, project_id; `meta.unread` count).
- `POST notifications/{id}/read`, `POST notifications/read-all`.

## Tested
`NotificationTaskTest`: per-recipient scoping + unread count, mark read (green).

## Planned (channels/features)
Email, browser push, webhooks, Slack, Teams, WhatsApp (official providers); quiet hours, digest mode,
deduplication, real-time delivery via Reverb, assignment, escalation. Types listed in the brief map
to the `type` field as producers are built (campaign not spending, budget nearing, CPA/ROAS, content
approval, token expiry, webhook/sync failures, payment failed, trial ending, etc.).
