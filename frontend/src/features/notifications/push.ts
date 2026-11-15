import { api } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'
import { isIos, isStandalone } from '@/app/pwaInstall'

/**
 * NOTIF-PUSH-001 — mobile notifications from the browser's side.
 *
 * The permission prompt is only ever raised by `enablePush()`, which only the «فعّل إشعارات الجوال» button calls. Nothing here runs
 * on page load, on sign-in or on a timer. On an iPhone Web Push exists only for an app installed to the Home Screen (iOS 16.4+),
 * so there the answer is «install first», not a prompt that would be silently ignored.
 */
export type PushType = 'critical' | 'budget_90' | 'budget_100' | 'sync_failed' | 'approval'

export interface PushDevice { id: string; label: string; created_at: string | null; last_used_at: string | null; failing: boolean }

export interface PushConfig { configured: boolean; public_key: string | null; devices: PushDevice[]; types: PushType[] }

export interface AlertSettings {
  sound_enabled: boolean
  sound_volume: number
  push_types: Record<PushType, boolean>
  quiet_hours: { enabled: boolean; start: string; end: string; is_default: boolean; timezone: string }
}

export async function getPushConfig(): Promise<PushConfig> {
  const res = await api.get<ApiEnvelope<PushConfig>>('/push')

  return res.data.data as PushConfig
}

export async function getAlertSettings(): Promise<AlertSettings> {
  const res = await api.get<ApiEnvelope<AlertSettings>>('/notifications/alert-settings')

  return res.data.data as AlertSettings
}

export async function saveAlertSettings(patch: Partial<Pick<AlertSettings, 'sound_enabled' | 'sound_volume'>> & { push_types?: Partial<Record<PushType, boolean>> }): Promise<AlertSettings> {
  const res = await api.put<ApiEnvelope<AlertSettings>>('/notifications/alert-settings', patch)

  return res.data.data as AlertSettings
}

export const removeDevice = (id: string) => api.delete(`/push/subscriptions/${id}`)
export const testDevice = (id: string) => api.post<ApiEnvelope<{ delivered: boolean }>>(`/push/subscriptions/${id}/test`)

/** Why mobile notifications cannot be switched on here, or null when they can be asked for. */
export type PushBlock = 'unsupported' | 'ios_install' | 'denied' | 'not_configured' | null

export function pushSupported(): boolean {
  return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

export function pushBlock(configured: boolean, perm: NotificationPermission | 'unsupported' = typeof Notification === 'undefined' ? 'unsupported' : Notification.permission): PushBlock {
  if (!configured) return 'not_configured'
  // On iPhone the page in Safari has no PushManager at all until it runs as an installed app.
  if (isIos() && !isStandalone()) return 'ios_install'
  if (!pushSupported()) return 'unsupported'
  if (perm === 'denied') return 'denied'

  return null
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
  const pad = '='.repeat((4 - (base64Url.length % 4)) % 4)
  const raw = atob((base64Url + pad).replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i += 1) out[i] = raw.charCodeAt(i)

  return out
}

async function registration(): Promise<ServiceWorkerRegistration> {
  // `registerServiceWorker()` only runs in production builds; asking for push must work wherever the worker file is served.
  return (await navigator.serviceWorker.getRegistration()) ?? (await navigator.serviceWorker.register('/sw.js'))
}

/**
 * Ask for permission (this is the ONLY place that does), subscribe this browser, and tell the server.
 *
 * @throws Error with `.code` of `permission_denied` when the person says no — never swallowed, so the page can say so.
 */
export async function enablePush(publicKey: string): Promise<void> {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw Object.assign(new Error('permission'), { code: 'permission_denied' })

  const reg = await registration()
  await navigator.serviceWorker.ready
  const existing = await reg.pushManager.getSubscription()
  const subscription = existing ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) }))
  const json = subscription.toJSON() as { endpoint?: string; keys?: { p256dh?: string; auth?: string } }

  await api.post('/push/subscriptions', { endpoint: json.endpoint, keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth } })
}

/** This browser's subscription endpoint, if it has one. */
export async function currentEndpoint(): Promise<string | null> {
  if (!pushSupported()) return null
  const reg = await navigator.serviceWorker.getRegistration()
  const sub = await reg?.pushManager.getSubscription()

  return sub?.endpoint ?? null
}

/**
 * Forget THIS browser: tell the server, then drop the subscription locally.
 *
 * Called at sign-out, so a phone handed to somebody else is not still ringing for the person who left. Best-effort and short: a
 * sign-out must never wait on a slow network, and the server also drops a device the push service reports as gone.
 */
export async function forgetThisDevice(): Promise<void> {
  try {
    if (!pushSupported()) return
    const reg = await navigator.serviceWorker.getRegistration()
    const sub = await reg?.pushManager.getSubscription()
    if (!sub) return
    await api.post('/push/forget', { endpoint: sub.endpoint }, { timeout: 2500 }).catch(() => undefined)
    await sub.unsubscribe().catch(() => undefined)
  } catch { /* nothing to forget, or the browser refused — signing out goes on */ }
}
