# Database ERD — CampaignsHub (core domain)

PostgreSQL, 74 tables. Everything is tenant-scoped: `tenant_id` on domain tables is enforced fail-closed by a
global scope. Below are the core entities and their real relationships (support/pivot/audit tables omitted for
readability).

```mermaid
erDiagram
  tenants ||--o{ users : tenant_id
  tenants ||--o{ roles : tenant_id
  roles ||--o{ role_user : role_id
  users ||--o{ role_user : user_id
  roles ||--o{ permission_role : role_id
  permissions ||--o{ permission_role : permission_id

  tenants ||--o{ client_workspaces : tenant_id
  client_workspaces ||--o{ projects : client_workspace_id
  tenants ||--o{ projects : tenant_id
  projects ||--o{ project_memberships : project_id
  users ||--o{ project_memberships : user_id

  tenants ||--o{ integration_credentials : tenant_id
  integration_credentials ||--o{ provider_connections : credential_id
  tenants ||--o{ provider_connections : tenant_id

  projects ||--o{ unified_campaigns : project_id
  unified_campaigns ||--o{ external_campaigns : unified_campaign_id
  projects ||--o{ daily_metrics : project_id
  unified_campaigns ||--o{ daily_metrics : unified_campaign_id
  metric_sync_runs ||--o{ daily_metrics : sync_run_id
  projects ||--o{ metric_sync_runs : project_id

  projects ||--o{ reports : project_id
  projects ||--o{ report_schedules : project_id
  report_schedules ||--o{ report_deliveries : schedule_id
  reports ||--o{ report_deliveries : report_id

  tenants ||--o{ alert_rules : tenant_id
  alert_rules ||--o{ alert_events : rule_id
  tenants ||--o{ notifications : tenant_id
  notifications ||--o{ notification_deliveries : notification_id

  tenants ||--o{ tasks : tenant_id
  tenants ||--o{ requests : tenant_id
  tenants ||--o{ workspace_invitations : tenant_id

  tenants {
    ulid id PK
    string account_type
    jsonb enabled_modules
    string subscription_plan
    string status
    string onboarding_step
  }
  users {
    bigint id PK
    ulid tenant_id FK
    string email
    timestamptz email_verified_at
    timestamptz disabled_at
  }
  alert_rules {
    uuid id PK
    ulid tenant_id FK
    string type
    jsonb threshold
    int cooldown_minutes
    jsonb channels
    bool create_task
  }
  alert_events {
    uuid id PK
    uuid rule_id FK
    string status
    string severity
    jsonb context
    uuid task_id
    timestamptz last_triggered_at
  }
  report_deliveries {
    uuid id PK
    uuid schedule_id FK
    string channel
    string status
    string audience
  }
```

Notes:
- `status`/`disabled_at` on `users` drive suspended-account enforcement (`EnsureAccountActive`).
- Delivery tables (`report_deliveries`, `notification_deliveries`) carry an honest status: never `sent` without
  a real provider acknowledgement (defaults `awaiting_provider_credentials` / `awaiting_credentials`).
- `alert_events` is the firing ledger (cooldown/dedup/snooze/resolve); `alert_rules` is the config.
