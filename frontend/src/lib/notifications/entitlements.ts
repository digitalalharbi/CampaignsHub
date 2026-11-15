/**
 * NOTIF-V2-ENGINE-001 — the entitlement keys of Owner spec §1 and their defaults.
 * Mirrors backend EntitlementKey + config/notifications_v2.php (entitlements.defaults / limits).
 * The server decides; the UI only shows a lock and «ترقية» when `allowed` is false.
 */
import type { EntitlementState } from './types'

export const ENTITLEMENT_KEYS = [
  'notifications.internal',
  'notifications.push',
  'notifications.sound',
  'notifications.external',
  'notifications.external.charts',
  'notifications.external.cc_bcc',
  'notifications.external.custom_subject',
  'notifications.external.review_mode',
  'followup.morning_brief',
  'followup.tasks',
  'branding.remove_platform_footprint',
  'projects.trash_retention_days',
] as const
export type EntitlementKey = (typeof ENTITLEMENT_KEYS)[number]

/** Every key on for every plan and workspace type, except removing the platform footprint. */
export const ENTITLEMENT_DEFAULTS: Record<EntitlementKey, boolean> = {
  'notifications.internal': true,
  'notifications.push': true,
  'notifications.sound': true,
  'notifications.external': true,
  'notifications.external.charts': true,
  'notifications.external.cc_bcc': true,
  'notifications.external.custom_subject': true,
  'notifications.external.review_mode': true,
  'followup.morning_brief': true,
  'followup.tasks': true,
  'branding.remove_platform_footprint': false,
  'projects.trash_retention_days': true,
}

export const LIMIT_KEYS = [
  'external_recipients',
  'monthly_messages',
  'cc_per_message',
  'devices_per_user',
  'monthly_push',
  'monthly_charts',
  'trash_retention_days',
] as const
export type LimitKey = (typeof LIMIT_KEYS)[number]

/** Settings of a key are editable only while it is usable; view-only keeps them visible behind «ترقية». */
export function isLocked(state: EntitlementState | undefined): boolean {
  return state !== undefined && !state.allowed
}

export function showsUpgrade(state: EntitlementState | undefined): boolean {
  return state !== undefined && state.upgrade
}
