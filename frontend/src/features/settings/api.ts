import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, postData, putData, deleteData } from '@/lib/api/client'
import type { ResolvedDisclaimer } from '@/features/disclaimers/api'

// ---- General (organization) ---------------------------------------------------------------------

export interface OrgGeneral {
  account_type: string
  logo_url: string | null
  contact_email: string | null
  contact_phone: string | null
  country: string
  default_locale: 'ar' | 'en'
  default_currency: string
  timezone: string
  date_format: string
  number_format: string
  fiscal_year_start_month: number
  demo_mode: boolean
}
export interface OrgSettings {
  name: string
  slug: string
  general: OrgGeneral
  options: { account_types: string[]; date_formats: string[]; number_formats: string[] }
}

export function useOrgSettings() {
  return useQuery({ queryKey: ['settings', 'organization'], queryFn: () => getData<OrgSettings>('/settings/organization') })
}

export function useUpdateOrgSettings() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: { name: string; general: OrgGeneral }) => putData<OrgSettings>('/settings/organization', body),
    onSuccess: (data) => qc.setQueryData(['settings', 'organization'], data),
  })
}

// ---- Disclaimers management ----------------------------------------------------------------------

export type DisclaimerScope = 'organization' | 'client' | 'project'
export interface DisclaimerOverride {
  id: string
  scope: DisclaimerScope
  scope_id: string | null
  payload: Partial<ResolvedDisclaimer>
  version: number
  is_active: boolean
  effective_at: string | null
  updated_at: string
}
export interface DisclaimerSettings {
  defaults: ResolvedDisclaimer
  overrides: DisclaimerOverride[]
}

export function useDisclaimerSettings() {
  return useQuery({ queryKey: ['settings', 'disclaimers'], queryFn: () => getData<DisclaimerSettings>('/settings/disclaimers') })
}

export function useSaveDisclaimer() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: {
      scope: DisclaimerScope
      scope_id?: string | null
      payload: Record<string, unknown>
      is_active?: boolean
      effective_at?: string | null
    }) => putData<DisclaimerOverride>('/settings/disclaimers', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'disclaimers'] })
      qc.invalidateQueries({ queryKey: ['disclaimer'] })
    },
  })
}

// ---- Team ----------------------------------------------------------------------------------------

export interface TeamMember {
  id: string
  name: string
  email: string
  roles: { slug: string; name: string }[]
  is_owner: boolean
  disabled: boolean
  last_login_at: string | null
  two_factor_enabled: boolean
}
/** Somebody invited who has not accepted yet — they hold no account and no membership. */
export interface TeamInvitation {
  id: string
  email: string
  role_slug: string
  delivery_status: string
  expires_at: string
  expired: boolean
}
export interface TeamData {
  members: TeamMember[]
  roles: { slug: string; name: string }[]
  invitations: TeamInvitation[]
}

export function useTeam() {
  return useQuery({ queryKey: ['settings', 'team'], queryFn: () => getData<TeamData>('/settings/team') })
}
export function useTeamActions() {
  const qc = useQueryClient()
  const inv = () => qc.invalidateQueries({ queryKey: ['settings', 'team'] })
  return {
    // No name — TEAM-INVITE-001. The invited person names themselves at acceptance.
    invite: useMutation({ mutationFn: (b: { email: string; role: string }) => postData('/settings/team', b), onSuccess: inv }),
    revokeInvitation: useMutation({ mutationFn: (id: string) => deleteData(`/settings/team/invitations/${id}`), onSuccess: inv }),
    setRole: useMutation({ mutationFn: (b: { id: string; role: string }) => putData(`/settings/team/${b.id}/role`, { role: b.role }), onSuccess: inv }),
    toggle: useMutation({ mutationFn: (id: string) => postData(`/settings/team/${id}/toggle`), onSuccess: inv }),
    remove: useMutation({ mutationFn: (id: string) => deleteData(`/settings/team/${id}`), onSuccess: inv }),
  }
}

// ---- Notification preferences --------------------------------------------------------------------

export type Rhythm = 'immediate' | 'daily' | 'weekly'

/** One message this product can send, as the server describes it — MAIL-011. */
export interface CatalogueType {
  key: string
  mandatory: boolean
  /** Empty for the digests, which ARE a rhythm. `['immediate']` for events nothing batches. */
  rhythms: Rhythm[]
  /** `daily` or `weekly` when this row is switched through the digest opt-ins instead of `types`. */
  digest_switch: 'daily' | 'weekly' | null
  sent_by: string
}

export interface NotifPrefs {
  channels: { in_app: boolean; email: boolean }
  categories: Record<string, { in_app: boolean; email: boolean }>
  /**
   * EFFECTIVE per-type settings — what will actually happen, after mandatory types, the master
   * channel switch, the person's own choices and the catalogue defaults have been applied. The
   * screen renders these directly rather than re-deriving them, so there is one resolution order in
   * the product and it lives in PHP.
   */
  types: Record<string, { email: boolean; in_app: boolean; rhythm: Rhythm }>
  catalogue: { key: string; types: CatalogueType[] }[]
  quiet_hours: { enabled: boolean; start: string; end: string }
  frequency: 'realtime' | 'hourly' | 'daily'
  project_ids: string[] | null
  projects: { id: string; name: string; client_name: string | null }[]
  digests: { daily: boolean; weekly: boolean; alerts: boolean }
  available_digests: string[]
  timezone: string
  locale: 'ar' | 'en'
  digest_hour: number
  available_timezones: string[]
  available_categories: string[]
}

/**
 * What may be written back.
 *
 * Every key is optional and the server writes only what it receives. That is not a convenience: the
 * old client PUT a fixed body without `digests`, `timezone`, `locale` or `digest_hour`, and the old
 * server wrote them anyway from defaults — so saving a checkbox silently cleared somebody's digest.
 */
export type NotifPrefsInput = Partial<
  Pick<NotifPrefs, 'channels' | 'categories' | 'types' | 'quiet_hours' | 'frequency' | 'project_ids' | 'digests' | 'timezone' | 'locale' | 'digest_hour'>
>

export function useNotifPrefs() {
  return useQuery({ queryKey: ['settings', 'notifications'], queryFn: () => getData<NotifPrefs>('/settings/notifications') })
}
export function useSaveNotifPrefs() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (b: NotifPrefsInput) => putData<NotifPrefs>('/settings/notifications', b),
    onSuccess: (d) => qc.setQueryData(['settings', 'notifications'], d),
  })
}

// ---- Security ------------------------------------------------------------------------------------

export interface SecurityActivity {
  history: { action: string; ip_address: string | null; user_agent: string | null; at: string | null }[]
  devices: { ip_address: string | null; user_agent: string | null; last_seen: string | null }[]
  two_factor_enabled: boolean
}
export function useSecurityActivity() {
  return useQuery({ queryKey: ['settings', 'security', 'activity'], queryFn: () => getData<SecurityActivity>('/settings/security/activity') })
}
export interface SecurityPolicy { session_timeout_minutes: number; alert_new_device: boolean; alert_failed_logins: boolean }
export function useSecurityPolicy() {
  return useQuery({ queryKey: ['settings', 'security', 'policy'], queryFn: () => getData<{ policy: SecurityPolicy }>('/settings/security/policy') })
}
export function useSecurityActions() {
  const qc = useQueryClient()
  const inv = () => { qc.invalidateQueries({ queryKey: ['settings', 'security'] }) }
  return {
    changePassword: useMutation({ mutationFn: (b: { current_password: string; password: string; password_confirmation: string }) => postData('/settings/security/password', b) }),
    savePolicy: useMutation({ mutationFn: (policy: SecurityPolicy) => putData('/settings/security/policy', { policy }), onSuccess: inv }),
    mfaSetup: useMutation({ mutationFn: () => postData<{ secret: string; otpauth_uri: string }>('/settings/security/mfa/setup') }),
    mfaConfirm: useMutation({ mutationFn: (code: string) => postData('/settings/security/mfa/confirm', { code }), onSuccess: inv }),
    mfaDisable: useMutation({ mutationFn: (password: string) => postData('/settings/security/mfa/disable', { password }), onSuccess: inv }),
  }
}

// ---- Branding ------------------------------------------------------------------------------------

export interface Branding {
  logo_url: string | null
  client_logo_url: string | null
  primary_color: string
  report_accent: string
  portal_name: string
  report_footer: string | null
}
export function useBranding() {
  return useQuery({ queryKey: ['settings', 'branding'], queryFn: () => getData<{ branding: Branding }>('/settings/branding') })
}
export function useSaveBranding() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (branding: Branding) => putData<{ branding: Branding }>('/settings/branding', { branding }),
    onSuccess: (d) => qc.setQueryData(['settings', 'branding'], d),
  })
}

// ---- Clients (workspaces) ------------------------------------------------------------------------

export interface ClientRow { id: string; name: string; mode: string; projects_count?: number }
export function useClients() {
  return useQuery({ queryKey: ['settings', 'clients'], queryFn: () => getData<ClientRow[]>('/client-workspaces') })
}
export function useClientActions() {
  const qc = useQueryClient()
  const inv = () => qc.invalidateQueries({ queryKey: ['settings', 'clients'] })
  return {
    create: useMutation({ mutationFn: (b: { name: string; mode: string }) => postData('/client-workspaces', b), onSuccess: inv }),
    update: useMutation({ mutationFn: (b: { id: string; name?: string; mode?: string }) => putData(`/client-workspaces/${b.id}`, b), onSuccess: inv }),
    archive: useMutation({ mutationFn: (id: string) => deleteData(`/client-workspaces/${id}`), onSuccess: inv }),
  }
}

// ---- Notification recipients (MAIL-010) -----------------------------------------------------------

/**
 * Who a manager has arranged to be told, and whether it is doing anything.
 *
 * `status` is resolved server-side on every read, against the person's LIVE access. A row whose
 * recipient has since been moved off the client comes back `eligible: false` — the screen must show
 * that, because a list that looks correct and mails nobody is worse than no list.
 */
export interface NotifRecipient {
  id: string
  user_id: number
  name: string | null
  email: string | null
  project_id: string | null
  project_name: string | null
  category: string | null
  status: { eligible: boolean; reason: string | null }
}

export interface AssignableRecipients {
  people: { user_id: number; name: string; email: string; project_ids: string[] }[]
  projects: { id: string; name: string; client_name: string | null }[]
  available_categories: string[]
}

export function useNotifRecipients() {
  return useQuery({
    queryKey: ['settings', 'notif-recipients'],
    queryFn: () => getData<{ recipients: NotifRecipient[]; available_categories: string[] }>('/settings/notifications/recipients'),
  })
}

export function useAssignableRecipients() {
  return useQuery({
    queryKey: ['settings', 'notif-assignable'],
    queryFn: () => getData<AssignableRecipients>('/settings/notifications/recipients/assignable'),
  })
}

export function useNotifRecipientActions() {
  const qc = useQueryClient()
  const inv = () => qc.invalidateQueries({ queryKey: ['settings', 'notif-recipients'] })
  return {
    add: useMutation({
      mutationFn: (b: { user_id: number; project_id: string | null; category: string | null }) =>
        postData('/settings/notifications/recipients', b),
      onSuccess: inv,
    }),
    remove: useMutation({
      mutationFn: (id: string) => deleteData(`/settings/notifications/recipients/${id}`),
      onSuccess: inv,
    }),
  }
}

// ---- Team notification status (MAIL-012) ---------------------------------------------------------

export interface TeamNotificationPerson {
  user_id: number
  name: string
  email: string | null
  roles: string[]
  /** `agency` · `client` · `advertiser` — a client contact receives notifications too. */
  portal: string
  /** Client-qualified, and only the projects the reader can reach themselves. */
  projects: string[]
  categories: string[]
  rhythms: { daily: boolean; weekly: boolean; alerts: boolean }
  arranged_by_manager: boolean
  last_message: { at: string | null; kind: string; status: string; source: string } | null
  /** `silent` · `never_sent` · `sent` · `awaiting_credentials` · `failed` · `sandbox` */
  state: string
}

export interface TeamNotifications {
  people: TeamNotificationPerson[]
  email_provider_configured: boolean
  available_categories: string[]
}

export function useTeamNotifications() {
  return useQuery({
    queryKey: ['settings', 'notif-team'],
    queryFn: () => getData<TeamNotifications>('/settings/notifications/team'),
  })
}
