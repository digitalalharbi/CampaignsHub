import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, getEnvelope, patchData, postData, putData } from '@/lib/api/client'
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

// ---- Recipients and subscriptions (builder Q: /settings/workspace/notifications/external, Q-notes.md) ----------

const EXT = `${BASE}/external`

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

/** What the wizard edits: one recipient and the ONE subscription it creates or changes. */
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

/** The server's own remarks on a save: «archived projects excluded», «CC moved to BCC», a role-mailbox hint… */
export interface SaveRemarks { notes: string[]; hints: string[] }

export function useRecipients() {
  return useQuery({ queryKey: KEYS.recipients, queryFn: () => getData<RecipientRow[]>(`${EXT}/recipients`) })
}

const recipientFields = (d: RecipientInput) => ({
  email: d.email, name: d.name || null, salutation: d.salutation || null, role_key: d.role_key, language: d.language,
  display_currency: d.display_currency ?? null, is_role_mailbox: d.is_role_mailbox,
})

export function useRecipientActions() {
  const qc = useQueryClient()
  const inv = () => qc.invalidateQueries({ queryKey: ['nv2'] })

  return {
    create: useMutation({
      mutationFn: async (d: RecipientInput): Promise<SaveRemarks> => {
        const r = await postData<{ notes?: string[]; hints?: string[] }>(`${EXT}/recipients`, { ...recipientFields(d), subscriptions: [d.subscription] })

        return { notes: r.notes ?? [], hints: r.hints ?? [] }
      },
      onSuccess: inv,
    }),
    // Editing changes the recipient's own fields and the subscription being edited (two calls; the server owns each rule).
    update: useMutation({
      mutationFn: async (b: { id: string; subscriptionId: string | null; draft: RecipientInput }): Promise<SaveRemarks> => {
        const { email: _email, ...fields } = recipientFields(b.draft)
        void _email
        await patchData<unknown>(`${EXT}/recipients/${b.id}`, fields)
        const r = b.subscriptionId
          ? await patchData<{ notes?: string[] }>(`${EXT}/subscriptions/${b.subscriptionId}`, b.draft.subscription)
          : await postData<{ notes?: string[] }>(`${EXT}/recipients/${b.id}/subscriptions`, b.draft.subscription)

        return { notes: r.notes ?? [], hints: [] }
      },
      onSuccess: inv,
    }),
    pause: useMutation({ mutationFn: (subscriptionId: string) => postData<unknown>(`${EXT}/subscriptions/${subscriptionId}/pause`), onSuccess: inv }),
    resume: useMutation({ mutationFn: (subscriptionId: string) => postData<unknown>(`${EXT}/subscriptions/${subscriptionId}/resume`), onSuccess: inv }),
    duplicate: useMutation({ mutationFn: (subscriptionId: string) => postData<unknown>(`${EXT}/subscriptions/${subscriptionId}/duplicate`), onSuccess: inv }),
    // «delete» is a confirmed cancel: the row is kept (the immutable log points at it), nothing is sent to it again.
    remove: useMutation({ mutationFn: (subscriptionId: string) => postData<unknown>(`${EXT}/subscriptions/${subscriptionId}/cancel`, { confirm: true }), onSuccess: inv }),
  }
}

/** builder Q's external general settings (sender signature and reply-to, disclaimer, extra forbidden words, «طلب تواصل», subject templates, …). */
export interface ExternalSettingsData {
  sender: { reply_to: string | null; signature: string | null }
  external: {
    disclaimer: { ar: string | null; en: string | null }
    forbidden_words: string[]
    contact_link: string | null
    subject_templates: Record<string, string>
    continuity_days: number
    improvement_pct: number
    copy_delivery: 'all' | 'events' | 'digest'
    review_window_hours: number
    display_currency: string | null
  }
}

export function useExternalSettings() {
  return useQuery({ queryKey: ['nv2', 'external-settings'], queryFn: () => getData<ExternalSettingsData>(`${EXT}/settings`) })
}

export function useSaveExternalSettings() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (b: { sender?: Partial<ExternalSettingsData['sender']>; external?: Partial<ExternalSettingsData['external']> }) => putData<ExternalSettingsData>(`${EXT}/settings`, b),
    onSuccess: (d) => { qc.setQueryData(['nv2', 'external-settings'], d) },
  })
}

/** Q's KPI strip for the external section — «held for review» counts its review queue. Falls back to ours while Q is not deployed. */
export function useExternalKpis() {
  return useQuery({ queryKey: ['nv2', 'external-kpis'], queryFn: () => getData<Kpis['external']>(`${EXT}/kpis`), retry: false, refetchInterval: 60_000 })
}

export interface PreviewResult { subject: string; sender_name: string; html: string }

/**
 * RELEASE-INTEGRATION — builder Q's preview and send endpoints. A saved subscription is previewed by its id; the wizard's
 * unsaved draft is sent as `draft` (Q renders it with the same composer, outside the engine and the log).
 */
type PreviewTarget = { draft: RecipientInput; subscriptionId?: string | null; project_id: string }
const target = ({ draft, subscriptionId, project_id }: PreviewTarget) =>
  subscriptionId ? { subscription_id: subscriptionId, project_id } : { draft, project_id }

export function usePreviewMessage() {
  return useMutation({
    mutationFn: (b: PreviewTarget & { locale: 'ar' | 'en'; theme: 'light' | 'dark' }) =>
      postData<PreviewResult>(`${EXT}/preview`, { ...target(b), locale: b.locale, theme: b.theme }),
  })
}

/** «أرسل نسخة لي» — to the signed-in user only, marked «[تجربة]», outside the log. */
export function useTestSend() {
  return useMutation({
    mutationFn: (b: PreviewTarget) => postData<{ sent_to: string }>(`${EXT}/test-send`, target(b)),
  })
}

/** «أرسل للمستلم الآن» — a SAVED subscription only, through the engine (checks, review, log). */
export function useSendNow() {
  return useMutation({
    mutationFn: (b: { subscriptionId: string; project_id: string }) =>
      postData<{ queued: number }>(`${EXT}/send-now`, { subscription_id: b.subscriptionId, project_id: b.project_id, confirm: true }),
  })
}

export function useBindableProjects() {
  return useQuery({
    queryKey: ['nv2', 'projects'],
    queryFn: () => getData<{ id: string; name: string }[]>('/settings/notifications/external-recipients/projects'),
  })
}
