# Notifications v2 — shared engine contracts (builder M)

Binding for every builder of `feature/notifications-v2` (Owner spec `OWNER_SPEC_2026-11-15.md` §0, §1, §3, §4).
Branch `feature/notifications-v2-m`. Change a contract only by editing this file in the same commit as the code.

## 0. Where the contracts live

| Contract | File |
|---|---|
| DB schema | `backend/database/migrations/2026_11_18_100000_notifications_v2_shared_engine.php` |
| Interfaces | `backend/app/Domains/Notifications/Engine/Contracts/` — `EventRegistry`, `EntitlementGate`, `UsageCounter`, `Router`, `Scheduler`, `Sender`, `DeliveryLog`, `SubscriptionLog`, `QuietHoursPolicy`, `WorkspaceTypes` |
| DTOs | `backend/app/Domains/Notifications/Engine/Data/` — `EventDefinition`, `DeliveryIntent`, `Delivery`, `Recipient`, `RoutingDecision`, `SendResult`, `Subscription`, `EntitlementState`, `WorkspaceTypeProfile` |
| Enums | `backend/app/Domains/Notifications/Engine/Enums/` — `Scope`, `Priority`, `Channel`, `DeliveryStatus`, `SubscriptionStatus`, `SubscriptionLogAction`, `EntitlementKey`, `EntitlementMode`, `RecipientKind` |
| Exception | `backend/app/Domains/Notifications/Engine/Exceptions/EntitlementBlocked.php` |
| Event catalog (defaults) | `backend/config/notification_events.php` |
| Workspace-type profiles | `backend/config/notification_workspace_types.php` |
| Engine settings, entitlement defaults, limits | `backend/config/notifications_v2.php` |
| Strings (AR / EN) | `backend/lang/{ar,en}/notifications_v2.php` |
| TS mirror | `frontend/src/lib/notifications/` — `types.ts`, `entitlements.ts`, `index.ts` |

Implementations (step 2) live beside them under `backend/app/Domains/Notifications/Engine/` and are bound in
`Engine/NotificationsEngineServiceProvider.php` (registered in `bootstrap/providers.php`). Nothing is under `/agency`:
the engine is workspace-level.

| Implementation | File (under `Engine/`) |
|---|---|
| EventRegistry | `Catalog/ConfigEventRegistry.php` |
| WorkspaceTypes, settings | `Workspace/ConfigWorkspaceTypes.php`, `Workspace/WorkspaceSettingsRepository.php`, `Workspace/WorkspaceSettings.php` |
| EntitlementGate, heads-up | `Entitlements/PlanEntitlementGate.php`, `Entitlements/GraceHeadsUp.php`, middleware `Http/Middleware/EnsurePlanFeature.php` (alias `plan.feature:<key>`) |
| UsageCounter | `Usage/MonthlyUsageCounter.php` |
| Router, QuietHoursPolicy | `Routing/EngineRouter.php`, `Routing/WorkspaceQuietHours.php` |
| Engine entry point | `NotificationEngine.php` — `raise(DeliveryIntent)` |
| Scheduler | `Scheduling/SubscriptionScheduler.php`, `Scheduling/DigestPeriods.php`, `Scheduling/DigestRunner.php` |
| Senders + pipeline | `Sending/SenderPipeline.php`, `Sending/InAppSender.php`, `Sending/MailSender.php`, `Sending/PushSender.php`, `Sending/EngineMail.php` |
| Extension points | `Contracts/MessageComposer.php` (default `Content/CatalogMessageComposer.php`), `Contracts/PreSendCheck.php` (container tag `notifications.presend`), senders tag `notifications.senders` |
| Logs | `Log/ImmutableDeliveryLog.php`, `Log/ImmutableSubscriptionLog.php`; API `Http/Controllers/DeliveryLogController.php` |
| Consent, migration | `Consent/ConfirmationRequests.php`, `Http/Controllers/ConsentController.php`, `Migration/LegacyNotificationImporter.php` |
| Models | `Models/ExternalRecipient.php`, `Models/NotificationSubscription.php` |
| Mail fix | `Mail/AlignEnvelopeSender.php`, `Mail/MailDnsReport.php`, `Mail/DnsResolver.php`, `Mail/SystemDnsResolver.php` |
| Commands | `notifications:v2-send` (1 min), `notifications:v2-digests` (5 min), `notifications:v2-consent-sweep` (daily), `notifications:v2-grace-heads-up` (daily), `notifications:v2-import-legacy [--dry-run]` (by hand), `mail:dns-check` (by hand) |

API (all under `/api/v1/settings/`, tenant from the membership): `GET workspace/notifications/entitlements`,
`GET …/usage`, `GET …/log`, `GET …/log/export` (CSV), `GET …/subscription-log`, `GET …/confirmation-requests`
(preview), `POST …/confirmation-requests {expected}` (owner only), `POST …/recipients/{id}/approve-internally`.
Public: `GET|POST /n/confirm/{token}` (web).

## 1. Tenant isolation («RLS»)

The repository isolates tenants in the application layer — not with Postgres RLS policies, which exist nowhere in
the schema. Every tenant-owned table carries `tenant_id` (FK to `tenants`, cascade), its model uses
`BelongsToTenant` (global `TenantScope`, auto-filled from `TenantContext`), queries from console code name the tenant
explicitly (`withoutGlobalScopes()->where('tenant_id', …)`), and isolation tests prove that nothing crosses a
workspace through the API. Every new table follows that rule. Exceptions, on purpose:

- `notification_plan_features`, `notification_event_catalog` — platform tables, no tenant data, admin-only writes.
- `notification_delivery_log`, `notification_subscription_log` — carry `tenant_id` and are read only through the
  tenant-scoped endpoint, but have NO foreign keys (a cascade would be a delete; the proof outlives the tenant,
  project and recipient; names are snapshotted).

## 2. Schema

| Table | Level | Purpose |
|---|---|---|
| `notification_plan_features` | platform | (key, plan_code?, workspace_type?) → enabled, limits, grace_days, keep_existing, paid_from. Unique on the coalesced triple |
| `notification_event_catalog` | platform | admin overrides of a catalog event (partial definition, enabled, version) |
| `workspace_entitlement_states` | workspace | a key's lifecycle state when the plan no longer includes it: `grace` (until `grace_ends_at`), `grandfathered`, `view_only` |
| `workspace_notification_settings` | workspace | timezone, work_days, quiet_hours, holidays, org_domains, routing (event → roles/channels/enabled), settings (the rest of §0 layer 2) |
| `notification_external_recipients` | workspace | §9 recipient: email, name, salutation, role_key, language, display_currency, is_role_mailbox, status, consent, confirmation token hash, approved_by/at, legacy_source/id |
| `notification_subscriptions` | workspace | §9/§10 subscription: status, template, digest_types, event_keys, period_key, frequency, weekdays, month_day, send_time, timezone, subject, cc, bcc, review_mode, reviews_remaining, overrides, next_run_at |
| `notification_subscription_projects` | workspace | subscription ↔ project (same tenant) |
| `notification_outbox` | workspace | mutable work state of ONE delivery: reference, dedup_key (unique), group_key/group_count, payload, state pending/claimed/done, attempts, not_before, deferred_reason |
| `notification_delivery_log` | workspace | **immutable** (trigger). One row per outcome of a delivery; terminal statuses once; `deferred` may precede them |
| `notification_subscription_log` | workspace | **immutable** (trigger). Confirmation, internal approval + who, unsubscribe, reduce, pause, no confirmation in 7 days, migration |
| `usage_counters` (existing, reused) | workspace | metrics `notif.external_messages`, `notif.external_recipients`, `notif.push_notifications`, `notif.charts_generated`, period `YYYY-MM` |

Status vocabulary — `DeliveryStatus`: `sent` (only after provider acceptance) · `failed` (+reason) · `deferred` ·
`skipped` (+reason) · `held_for_review` · `held_for_privacy` · `blocked_by_plan`.
`SubscriptionStatus`: `pending` · `active` · `paused` · `cancelled` — nothing is sent unless `active`.

## 3. Event catalog format

`config/notification_events.php` → `events.<event_key>`:

| Field | Type | Meaning |
|---|---|---|
| `scope` | `internal\|external\|both` | where it may be delivered |
| `priority` | `info\|important\|critical` | internal only; only `critical` bypasses quiet hours/holidays, and only internally |
| `default_roles` | `{workspace_type: [role_key]}` | who receives it by default, per profile; editable per workspace (`routing`) |
| `channels` | `[in_app\|push\|email]` | allowed channels; external = email only |
| `entitlement` | EntitlementKey | key the delivery requires (checked at route AND send time) |
| `creates_task` | bool | §6 follow-up task |
| `internal_only` | bool | sensitive operational event: never external, never to a profile with `sees_sensitive_events: false` |
| `preference_type` | MessageCatalogue type | the existing personal switch that also governs it (reuse of `notification_preferences`) |
| `required_capability` | ProjectCapability | e.g. `money.view` for budget figures |
| `groupable` | bool | same-type events in the grouping window collapse into one delivery |

Strings: `notifications_v2.events.<event_key with . → _>.<internal|external>.<title|body>` (`EventDefinition::textKey`),
placeholders `:project`, `:workspace`, `:recipient`, `:date`, `:period`, `:actor`. Every message carries project and
workspace names (payload `project_name`, `workspace_name`, snapshotted on the log row).

Registry: `EventRegistry` = config defaults merged with `notification_event_catalog.overrides` (enabled, version).
An override can never move an `internal_only` event to the external scope.

## 4. Workspace-type abstraction

`WorkspaceTypes::profileFor(tenantId, ?userId)` → `WorkspaceTypeProfile` from `config/notification_workspace_types.php`:
`roles`, `terminology` (translation keys: `notifications_v2.terms.<type>.{client,project}`), `responsible_role`,
`client_role` (separate message per project, review on, simplified language), `work_days`, `member_roles`
(tenant role slug → profile role), `sees_sensitive_events`. Resolution is data too: membership portal (`portal` →
`client_portal`), then `tenants.account_type` (`brand`, `self_serve_company` → `business`), else `agency`.
Code never compares a type key to a literal.

## 5. Entitlements (§1)

Keys (`EntitlementKey`): `notifications.internal`, `notifications.push`, `notifications.sound`, `notifications.external`,
`notifications.external.charts`, `notifications.external.cc_bcc`, `notifications.external.custom_subject`,
`notifications.external.review_mode`, `followup.morning_brief`, `followup.tasks`, `branding.remove_platform_footprint`.
Default: all ON for every plan and type except `branding.remove_platform_footprint` (OFF).

Limits (null = unlimited), default → plan row `limits` → workspace `tenant_limit_overrides` metric `notif.<limit>`:
`external_recipients` 500 · `monthly_messages` 20000 · `cc_per_message` 10 · `devices_per_user` 10 ·
`monthly_push` 50000 · `monthly_charts` 50000.

`EntitlementGate::state()` resolution: `workspace_entitlement_states` row → plan row (plan+type → plan → type → any)
→ config default. `EntitlementMode`: `full`, `grace`, `grandfathered` (all usable), `view_only` (data + settings
readable, nothing sent, edits refused, «ترقية»), `off`. API: middleware `entitlement:<key>` answers 403
`{errors:{entitlement:[key]}, meta:{upgrade:true}}`; scheduled jobs and the sender call `allows()` themselves.

## 6. Engine flow

```
domain → NotificationEngine::raise(DeliveryIntent)
       → Router::route()  → RoutingDecision[] (deliver | skipped | blocked_by_plan, notBefore for quiet hours)
       → outbox rows (dedup_key = event|object|threshold|day|recipient|channel; grouping by group_key in the window)
cron   → SenderPipeline::run()  (batch, per-tenant cap, claim, re-check entitlement + monthly limit, Sender::send)
       → DeliveryLog::record()  (sent only on SendResult::accepted; 3 attempts, back-off 5 → 30 min; then failed)
cron   → Scheduler::due() → DeliveryIntent per subscription with the complete-days period (PeriodResolver, K)
```

Channel senders implement `Sender`: `in_app` (writes `app_notifications`), `email` (Laravel mail through the
configured provider; `accepted` only when the transport returned), `push` (adapter to builder L's `PushDispatcher`).

## 7. Absorbing builder L (`feature/notifications-pwa-l`, merged)

L's tables are reused, not duplicated: `notification_reads`, `notification_alert_settings` (sound, volume,
push_types), `push_subscriptions`, `push_deferred`, `push_ledger`, and the `kind`/`repeat_count`/`last_seen_at`
columns of `app_notifications`. Done in this batch:

- the `push` channel `Sender` (`PushSender`) calls `PushDispatcher::deliver` (made public) — L's device handling,
  404/410 cleanup and SSRF checks; the engine logs the outcome. `PushSubscriptions::register` reads the plan's
  `devices_per_user`; `PushDispatcher::wants` and `SoundPreferences::forReader` check `notifications.push` / `.sound`;
- `AlertQuietHours` (personal 22:00–08:00 default for sound/push) is the personal layer under `QuietHoursPolicy`;
- `SoundPreferences` / `PushTypes` stay the personal-preference layer; the engine adds the plan check
  (`notifications.sound`, `notifications.push`);
- `BellKind` keeps naming legacy rows; the in-app `Sender` writes `app_notifications` with `kind` = the event key,
  so L's bell, unread count, read-all and filters serve engine rows unchanged.
