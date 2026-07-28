# DUPLICATION AUDIT — CampaignsHub (post-expansion)

Inventory of overlapping surfaces introduced during the expansion, and the consolidation applied. No data or
functionality is deleted — duplicates are merged into the canonical module and old routes redirect.

## Findings
| Area | Surfaces found | Verdict | Action |
|---|---|---|---|
| Integrations | `/integrations` (legacy IntegrationsPage) + `/app/connections` (Connection Center) | **Duplicated** | Canonical `/app/integrations` = Connection Center; redirect both old routes; single nav "التكاملات" |
| Google Drive | `/app/drive` standalone nav + page | **Overlapping** with Integrations + Files | Drive = a connector inside Integrations; its files show in Files; remove standalone nav; redirect |
| Branding | `/app/branding` standalone Branding Center | **Overlapping** with Settings | Move under Settings → الهوية (`/settings/branding`); remove standalone nav; redirect |
| Finance | `/app/billing` (Finance) + `/app/subscriptions` + `/client/invoices` | **Canonical (one backend)** | Keep ONE finance backend (Billing+Subscriptions); surface per experience: Ops=المالية, SaaS=الاشتراك, Client=الفواتير. Not a duplication — remove Subscriptions from the Ops nav only |
| Messaging | `/app/messages` + embedded threads | **Canonical** | One Messaging engine surfaced in context; no action beyond confirming single engine |
| Alerts vs Notifications | `/app/alerts` + notification center (bell) | **Distinct (not duplicated)** | Alerts = rules/risk/resolve/snooze; Notifications = inbox/channels/delivery-log; an alert raises a notification |
| Files | (new) unified Files vs per-entity file tabs | **Canonical** | Unified `/app/files` aggregates by source/entity/visibility; per-entity tabs remain contextual views of the same data |

## Query keys / APIs / permissions
- Integrations: reuse `integrations.*` permissions + `provider_connections`/`metric_sync_runs`/`daily_metrics`
  tables; Connectors framework is additive over the same data — no parallel tables.
- Finance: `billing.*` + `subscriptions.*` permissions; `quotes`/`invoices`/`payments` + `subscription_plans`/
  `subscriptions`/`usage_counters` — one schema, surfaced three ways.
- Drive: `drive.*` + `drive_links`/`drive_files` referenced by Files (by id, no byte copy).

## Result
Navigation carries one entry per canonical module; legacy/duplicate routes redirect; no two engines for
integrations, files, messaging, or finance; no two names for one module in any menu.
