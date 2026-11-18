/**
 * NOTIF-V2-ENGINE-001 — TypeScript mirror of the shared notifications engine contracts.
 * Source of truth: backend/app/Domains/Notifications/Engine (PHP) and docs/notifications-v2/CONTRACTS.md.
 * Keep the unions in the same order as the PHP enums.
 */

export const SCOPES = ['internal', 'external', 'both'] as const
export type Scope = (typeof SCOPES)[number]
export type DeliveryScope = Exclude<Scope, 'both'>

export const PRIORITIES = ['info', 'important', 'critical'] as const
export type Priority = (typeof PRIORITIES)[number]

export const CHANNELS = ['in_app', 'push', 'email'] as const
export type Channel = (typeof CHANNELS)[number]

export const DELIVERY_STATUSES = [
  'sent',
  'failed',
  'deferred',
  'skipped',
  'held_for_review',
  'held_for_privacy',
  'blocked_by_plan',
] as const
export type DeliveryStatus = (typeof DELIVERY_STATUSES)[number]

export const SUBSCRIPTION_STATUSES = ['pending', 'active', 'paused', 'cancelled'] as const
export type SubscriptionStatus = (typeof SUBSCRIPTION_STATUSES)[number]

export const SUBSCRIPTION_LOG_ACTIONS = [
  'created',
  'migrated',
  'confirmation_requested',
  'confirmed',
  'approved_internally',
  'unsubscribed',
  'reduced',
  'paused',
  'resumed',
  'cancelled',
  'no_confirmation_7d',
] as const
export type SubscriptionLogAction = (typeof SUBSCRIPTION_LOG_ACTIONS)[number]

export const ENTITLEMENT_MODES = ['full', 'grace', 'grandfathered', 'view_only', 'off'] as const
export type EntitlementMode = (typeof ENTITLEMENT_MODES)[number]

export type RecipientKind = 'user' | 'external'

/** One catalog entry, as `GET` endpoints return it (EventDefinition::toArray). */
export interface EventDefinition {
  key: string
  scope: Scope
  priority: Priority
  /** workspace type key => role keys */
  default_roles: Record<string, string[]>
  channels: Channel[]
  entitlement: string | null
  creates_task: boolean
  internal_only: boolean
  preference_type: string | null
  required_capability: string | null
  groupable: boolean
  enabled: boolean
  version: number
  text: {
    internal: { title: string; body: string } | null
    external: { title: string; body: string } | null
  }
}

/** EntitlementState::toArray */
export interface EntitlementState {
  key: string
  mode: EntitlementMode
  allowed: boolean
  can_edit: boolean
  upgrade: boolean
  grace_ends_at: string | null
  limits: Record<string, number | null>
}

/** WorkspaceTypeProfile::toArray */
export interface WorkspaceTypeProfile {
  key: string
  roles: { key: string; label_key: string }[]
  terminology: Record<string, string>
  responsible_role: string
  client_role: string | null
  work_days: number[]
  sees_sensitive_events: boolean
}

/** One immutable delivery-log row (GET /settings/workspace/notifications/log). */
export interface DeliveryLogRow {
  id: string
  delivery_id: string
  reference: string
  event_key: string
  scope: DeliveryScope
  channel: Channel
  recipient_kind: RecipientKind
  recipient_address: string | null
  project_id: string | null
  project_name: string | null
  workspace_name: string | null
  status: DeliveryStatus
  reason: string | null
  content_version: number
  attempt: number
  provider_message_id: string | null
  provider_accepted_at: string | null
  created_at: string
}

export interface ExternalRecipient {
  id: string
  email: string
  name: string | null
  salutation: string | null
  role_key: string
  language: 'ar' | 'en'
  display_currency: string | null
  is_role_mailbox: boolean
  status: SubscriptionStatus
  consent: 'none' | 'double_opt_in' | 'approved_internally'
}

export interface NotificationSubscription {
  id: string
  recipient_id: string
  status: SubscriptionStatus
  project_ids: string[]
  event_keys: string[]
  digest_types: string[]
  period_key: string | null
  frequency: 'daily' | 'weekly' | 'monthly' | null
  /** 0 = Sunday */
  weekdays: number[]
  month_day: number | null
  send_time: string
  timezone: string
  review_mode: boolean | null
  reviews_remaining: number
}

/** POST /settings/workspace/notifications/confirmation-requests/preview */
export interface ConfirmationRequestPreview {
  recipients: number
  subscriptions: number
  excluded_archived_projects: number
}

export type UsageMetric =
  | 'notif.external_messages'
  | 'notif.external_recipients'
  | 'notif.push_notifications'
  | 'notif.charts_generated'

export interface UsageMonth {
  period: string
  counts: Record<UsageMetric, number>
  limits: Record<string, number | null>
}
