import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, getEnvelope, patchData, postData, putData, deleteData } from '@/lib/api/client'
import type {
  Channel, DeliveryLogRow, EntitlementState, ExternalRecipient, NotificationSubscription, Priority, Scope,
  SubscriptionLogAction, SubscriptionStatus, UsageMonth, WorkspaceTypeProfile,
} from '@/lib/notifications'

/**
 * NOTIF-V2-SETTINGS-UI-001 — the data layer of the one workspace notification settings page.
 * Paths under `/settings/workspace/notifications` are builder M's (entitlements, usage, log, consent) and this
 * builder's (settings, kpis). The recipients / subscriptions / preview / test-send paths are the CONTRACT the external
 * pipeline (builder Q) serves; they are kept in this one block so adapting them is one edit (see R-notes.md).
 */
const BASE = '/settings/workspace/notifications'

export interface Bilingual { ar: string; en: string }

export interface WorkspaceEvent {
  key: string
  scope: Scope
  priority: Priority
  channels: Channel[]
  internal_only: boolean
  creates_task: boolean
  enabled: boolean
  default_roles: string[]
  title: { internal: Bilingual | null; external: Bilingual | null }
  route: { roles: string[]; channels: Channel[]; enabled: boolean; customised: boolean }
}

export interface WorkspaceSettingsData {
  workspace: {
    name: string
    type: WorkspaceTypeProfile & { role_labels: Record<string, Bilingual>; term_labels: Record<string, Bilingual> }
  }
  settings: {
    timezone: string
    work_days: number[]
    quiet_hours: { enabled: boolean; start: string; end: string }
    holidays: string[]
    org_domains: string[]
    routing: Record<string, { roles?: string[]; channels?: Channel[]; enabled?: boolean }>
    settings: Record<string, unknown>
    version: number
  }
  events: WorkspaceEvent[]
  first_run: boolean
}

export type SettingsPatch = Partial<Pick<WorkspaceSettingsData['settings'], 'timezone' | 'work_days' | 'quiet_hours' | 'holidays' | 'org_domains' | 'routing'>> & {
  settings?: Record<string, unknown>
}

const KEYS = {
  settings: ['nv2', 'settings'],
  kpis: ['nv2', 'kpis'],
  entitlements: ['nv2', 'entitlements'],
  usage: ['nv2', 'usage'],
  recipients: ['nv2', 'recipients'],
  log: ['nv2', 'log'],
} as const

export function useWorkspaceSettings() {
  return useQuery({ queryKey: KEYS.settings, queryFn: () => getData<WorkspaceSettingsData>(`${BASE}/settings`) })
}

export function useSaveWorkspaceSettings() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (patch: SettingsPatch) => putData<WorkspaceSettingsData & { refused?: string[] }>(`${BASE}/settings`, patch),
    onSuccess: (data) => { qc.setQueryData(KEYS.settings, data) },
  })
}

export interface Kpis {
  internal: { team_unread: number; open_followup_tasks: number | null; push_failures: number }
  external: {
    held_for_review: number; held_for_privacy: number; sent_this_week: number
    failed: number; awaiting_confirmation: number; unsubscribed_this_month: number
  }
}

export function useKpis() {
  return useQuery({ queryKey: KEYS.kpis, queryFn: () => getData<Kpis>(`${BASE}/kpis`), refetchInterval: 60_000 })
}

export interface EntitlementsData { workspace_type: WorkspaceTypeProfile; entitlements: EntitlementState[] }

export function useEntitlements() {
  return useQuery({ queryKey: KEYS.entitlements, queryFn: () => getData<EntitlementsData>(`${BASE}/entitlements`) })
}

export function useUsage() {
  return useQuery({ queryKey: KEYS.usage, queryFn: () => getData<UsageMonth>(`${BASE}/usage`) })
}

// ---- Log (builder M) -------------------------------------------------------------------------------

export interface LogPage<T> { rows: T[]; total: number; lastPage: number }

export function useDeliveryLogPage(page: number, perPage: number, status: string) {
  return useQuery({
    queryKey: [...KEYS.log, 'delivery', page, perPage, status],
    queryFn: async (): Promise<LogPage<DeliveryLogRow>> => {
      const qs = new URLSearchParams({ scope: 'external', page: String(page), per_page: String(perPage) })
      if (status) qs.set('status', status)
      const env = await getEnvelope<DeliveryLogRow[]>(`${BASE}/log?${qs}`)
      const meta = (env.meta ?? {}) as { total?: number; last_page?: number }

      return { rows: env.data, total: meta.total ?? env.data.length, lastPage: meta.last_page ?? 1 }
    },
  })
}

export interface SubscriptionLogRow {
  id: string
  recipient_id: string | null
  subscription_id: string | null
  action: SubscriptionLogAction
  detail: Record<string, unknown> | null
  actor_label: string | null
  created_at: string
}

export function useSubscriptionLogPage(page: number) {
  return useQuery({
    queryKey: [...KEYS.log, 'subscription', page],
    queryFn: async (): Promise<LogPage<SubscriptionLogRow>> => {
      const env = await getEnvelope<SubscriptionLogRow[]>(`${BASE}/subscription-log?page=${page}`)
      const meta = (env.meta ?? {}) as { total?: number; last_page?: number }

      return { rows: env.data, total: meta.total ?? env.data.length, lastPage: meta.last_page ?? 1 }
    },
  })
}

/** The CSV export is a same-origin download behind the session cookie. */
export const LOG_EXPORT_URL = `/api/v1${BASE}/log/export?scope=external`

// ---- Consent (builder M) ---------------------------------------------------------------------------

export interface ConfirmationPreview { recipients: number; subscriptions: number; excluded_archived_projects: number }

export function useConfirmationPreview(enabled: boolean) {
  return useQuery({ queryKey: ['nv2', 'confirmation-preview'], enabled, queryFn: () => getData<ConfirmationPreview>(`${BASE}/confirmation-requests`) })
}

export function useSendConfirmations() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (expected: number) => postData<unknown>(`${BASE}/confirmation-requests`, { expected }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['nv2'] }) },
  })
}

export function useApproveInternally() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (recipientId: string) => postData<unknown>(`${BASE}/recipients/${recipientId}/approve-internally`),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['nv2'] }) },
  })
}

// ---- Recipients and subscriptions (CONTRACT served by builder Q) ------------------------------------

export interface SubscriptionRow extends Omit<NotificationSubscription, 'status'> {
  status: SubscriptionStatus
  template_key: string | null
  subject_template: string | null
  cc: string[]
  bcc: string[]
  next_run_at: string | null
}

export interface RecipientRow extends ExternalRecipient {
  subscriptions: SubscriptionRow[]
}

export interface RecipientInput {
  email: string
  name?: string | null
  salutation?: string | null
  role_key: string
  language: 'ar' | 'en'
  display_currency?: string | null
  is_role_mailbox: boolean
  subscription: {
    template_key: string | null
    project_ids: string[]
    event_keys: string[]
    digest_types: string[]
    period_key: string | null
    frequency: 'daily' | 'weekly' | 'monthly' | null
    weekdays: number[]
    month_day: number | null
    send_time: string
    subject_template: string | null
    cc: string[]
    bcc: string[]
  }
}

export function useRecipients() {
  return useQuery({ queryKey: KEYS.recipients, queryFn: () => getData<RecipientRow[]>(`${BASE}/recipients`) })
}

export function useRecipientActions() {
  const qc = useQueryClient()
  const inv = () => qc.invalidateQueries({ queryKey: ['nv2'] })

  return {
    create: useMutation({ mutationFn: (b: RecipientInput) => postData<RecipientRow>(`${BASE}/recipients`, b), onSuccess: inv }),
    update: useMutation({ mutationFn: (b: { id: string; body: Partial<RecipientInput> }) => patchData<RecipientRow>(`${BASE}/recipients/${b.id}`, b.body), onSuccess: inv }),
    pause: useMutation({ mutationFn: (subscriptionId: string) => postData<unknown>(`${BASE}/subscriptions/${subscriptionId}/pause`), onSuccess: inv }),
    resume: useMutation({ mutationFn: (subscriptionId: string) => postData<unknown>(`${BASE}/subscriptions/${subscriptionId}/resume`), onSuccess: inv }),
    duplicate: useMutation({ mutationFn: (subscriptionId: string) => postData<unknown>(`${BASE}/subscriptions/${subscriptionId}/duplicate`), onSuccess: inv }),
    remove: useMutation({ mutationFn: (subscriptionId: string) => deleteData<unknown>(`${BASE}/subscriptions/${subscriptionId}`), onSuccess: inv }),
  }
}

export interface PreviewResult { subject: string; sender_name: string; html: string }

export function usePreviewMessage() {
  return useMutation({
    mutationFn: (b: { draft: RecipientInput; project_id: string; locale: 'ar' | 'en'; theme: 'light' | 'dark' }) =>
      postData<PreviewResult>(`${BASE}/preview`, b),
  })
}

export function useTestSend() {
  return useMutation({
    mutationFn: (b: { draft: RecipientInput; project_id: string; to: 'self' | 'recipient' }) => postData<{ state: string }>(`${BASE}/test-send`, b),
  })
}

export function useBindableProjects() {
  return useQuery({
    queryKey: ['nv2', 'projects'],
    queryFn: () => getData<{ id: string; name: string }[]>('/settings/notifications/external-recipients/projects'),
  })
}
