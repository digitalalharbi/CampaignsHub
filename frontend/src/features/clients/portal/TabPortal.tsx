import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Trash2 } from 'lucide-react'
import { updateSettings, type ClientDetail } from '../api'
import { PORTAL_ROLES, invitePortalUser, listPortalUsers, revokePortalUser } from './portalAdminApi'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Switch } from '@/components/ui/Switch'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * CP-VISIBILITY-001, CP-WRITE-DENY-001, CP-USERS-001 — one client's portal, decided by the agency.
 *
 * Three questions on one screen: what may this client SEE (fifteen switches), what may its people DO
 * (writes stay denied unless one is switched on deliberately — seeing is never doing), and WHO can open it
 * (grant an existing account or invite a new address, with a role and, if wanted, a project ceiling).
 * The server enforces every one of these; this screen only edits them.
 */
const VISIBILITY = ['dashboard', 'projects', 'campaigns', 'content', 'reports', 'recommendations', 'spend', 'revenue', 'platform_evidence', 'requests', 'invoices', 'messages', 'files', 'campaign_names', 'ad_account_names'] as const

/** Powers an agency may hand to a client. The rest (integrations, writes, pause, budget, notes, margin, identifiers) are not offered. */
const GRANTABLE = ['create_requests', 'approve_content', 'team_admin'] as const

const COPY = {
  ar: {
    see: 'ما الذي يراه العميل', seeHint: 'الرؤية منفصلة عن الصلاحية: عرض الحملات لا يمنح تعديلها.',
    do: 'ما الذي يستطيع فعله', doHint: 'التعديل على الحملات والميزانيات والربط يبقى للوكالة دائماً.',
    people: 'من يدخل البوابة', invite: 'دعوة', email: 'البريد الإلكتروني', role: 'الدور', projects: 'المشاريع', allProjects: 'كل المشاريع',
    save: 'حفظ', saved: 'تم الحفظ', none: 'لا أحد بعد.', granted: 'مُنح الوصول لحساب موجود.', invited: 'أُرسلت الدعوة.',
    roles: { client_viewer: 'مطّلع', client_approver: 'معتمِد', client_admin: 'مسؤول العميل' } as Record<string, string>,
    states: { active: 'نشط', invited: 'مدعو' } as Record<string, string>,
    v: { dashboard: 'لوحة الأداء', projects: 'المشاريع', campaigns: 'الحملات', content: 'المحتوى', reports: 'التقارير', recommendations: 'التوصيات المعتمدة', spend: 'الإنفاق', revenue: 'الإيرادات', platform_evidence: 'لقطات المنصات', requests: 'الطلبات', invoices: 'الفواتير', messages: 'الرسائل', files: 'الملفات', campaign_names: 'أسماء الحملات', ad_account_names: 'أسماء الحسابات الإعلانية' } as Record<string, string>,
    c: { create_requests: 'فتح طلبات جديدة', approve_content: 'اعتماد المحتوى', team_admin: 'إدارة من يدخل البوابة' } as Record<string, string>,
    revoke: 'إزالة',
  },
  en: {
    see: 'What the client sees', seeHint: 'Seeing is separate from doing: showing campaigns grants no power over them.',
    do: 'What the client may do', doHint: 'Changing campaigns, budgets and connections always stays with the agency.',
    people: 'Who can open the portal', invite: 'Invite', email: 'Email', role: 'Role', projects: 'Projects', allProjects: 'All projects',
    save: 'Save', saved: 'Saved', none: 'Nobody yet.', granted: 'Access granted to an existing account.', invited: 'Invitation sent.',
    roles: { client_viewer: 'Viewer', client_approver: 'Approver', client_admin: 'Client admin' } as Record<string, string>,
    states: { active: 'Active', invited: 'Invited' } as Record<string, string>,
    v: { dashboard: 'Performance dashboard', projects: 'Projects', campaigns: 'Campaigns', content: 'Content', reports: 'Reports', recommendations: 'Approved recommendations', spend: 'Spend', revenue: 'Revenue', platform_evidence: 'Platform screenshots', requests: 'Requests', invoices: 'Invoices', messages: 'Messages', files: 'Files', campaign_names: 'Campaign names', ad_account_names: 'Ad account names' } as Record<string, string>,
    c: { create_requests: 'Open new requests', approve_content: 'Approve content', team_admin: 'Manage who can open the portal' } as Record<string, string>,
    revoke: 'Remove',
  },
}

export function TabPortal({ d }: { d: ClientDetail }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const portal = d.portal ?? { mode: 'managed', visibility: {}, capabilities: {} }
  const [vis, setVis] = useState<Record<string, boolean>>({ ...portal.visibility })
  const [caps, setCaps] = useState<Record<string, boolean>>({ ...portal.capabilities })
  const [saved, setSaved] = useState(false)

  const save = useMutation({
    mutationFn: () => updateSettings(d.id, { settings: { portal: { visibility: vis, capabilities: Object.fromEntries(GRANTABLE.map((k) => [k, caps[k] === true])) } } }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['app', 'client', d.id] }); setSaved(true); setTimeout(() => setSaved(false), 2500) },
  })

  const users = useQuery({ queryKey: ['app', 'client', d.id, 'portal-users'], queryFn: () => listPortalUsers(d.id), enabled: d.can.manage_team })

  return (
    <div data-testid="portal-tab" className="grid gap-6 text-sm">
      <section>
        <h3 className="font-bold text-text-primary">{t.see}</h3>
        <p className="mb-3 text-xs text-text-muted">{t.seeHint}</p>
        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {VISIBILITY.map((k) => (
            <li key={k} className="rounded-xl border border-border px-3 py-2">
              <Switch checked={vis[k] === true} onCheckedChange={(on) => setVis((v) => ({ ...v, [k]: on }))} label={t.v[k]} testId={`vis-${k}`} disabled={!d.can.manage_settings} />
            </li>
          ))}
        </ul>
      </section>

      <section>
        <h3 className="font-bold text-text-primary">{t.do}</h3>
        <p className="mb-3 text-xs text-text-muted">{t.doHint}</p>
        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {GRANTABLE.map((k) => (
            <li key={k} className="rounded-xl border border-border px-3 py-2">
              <Switch checked={caps[k] === true} onCheckedChange={(on) => setCaps((c) => ({ ...c, [k]: on }))} label={t.c[k]} testId={`cap-${k}`} disabled={!d.can.manage_settings} />
            </li>
          ))}
        </ul>
        {d.can.manage_settings && (
          <div className="mt-3 flex items-center gap-3">
            <button type="button" data-testid="portal-save" onClick={() => save.mutate()} disabled={save.isPending} className="flex items-center gap-1.5 rounded-lg bg-brand-primary px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">
              {save.isPending && <Loader2 size={13} className="animate-spin" />} {t.save}
            </button>
            {saved && <span role="status" className="text-xs text-success">{t.saved}</span>}
            {save.isError && <span role="alert" className="text-xs text-danger">{toApiError(save.error).message}</span>}
          </div>
        )}
      </section>

      {d.can.manage_team && (
        <section>
          <h3 className="mb-2 font-bold text-text-primary">{t.people}</h3>
          <Invite clientId={d.id} projects={d.projects} t={t} onDone={() => void users.refetch()} canAdmin={caps.team_admin === true} />
          {users.isError ? (
            <QueryFailure error={users.error} ar={ar} testId="portal-users-failure" onRetry={() => void users.refetch()} fallbackTitle={ar ? 'تعذّر تحميل المستخدمين.' : 'Could not load the people.'} />
          ) : (
            <ul data-testid="portal-users" className="mt-3 space-y-2">
              {(users.data ?? []).length === 0 && !users.isLoading && <li className="text-text-muted">{t.none}</li>}
              {(users.data ?? []).map((u) => (
                <li key={u.membership_id ?? u.invitation_id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-3">
                  <span className="min-w-0">
                    <span className="block truncate font-semibold text-text-primary" dir="ltr">{u.email}</span>
                    <span className="text-xs text-text-muted">{u.name ? `${u.name} · ` : ''}{t.roles[u.role] ?? u.role} · {u.projects === null ? t.allProjects : u.projects.map((id) => d.projects.find((p) => p.id === id)?.name ?? id).join('، ')}</span>
                  </span>
                  <span className="flex items-center gap-2">
                    <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary">{t.states[u.state]}</span>
                    {u.membership_id && <Revoke clientId={d.id} membershipId={u.membership_id} label={t.revoke} onDone={() => void users.refetch()} />}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </div>
  )
}

function Revoke({ clientId, membershipId, label, onDone }: { clientId: string; membershipId: string; label: string; onDone: () => void }) {
  const revoke = useMutation({ mutationFn: () => revokePortalUser(clientId, membershipId), onSuccess: onDone })

  return <button type="button" aria-label={label} onClick={() => revoke.mutate()} disabled={revoke.isPending} className="rounded p-1 text-text-muted hover:text-danger"><Trash2 size={14} /></button>
}

function Invite({ clientId, projects, t, onDone, canAdmin }: { clientId: string; projects: { id: string; name: string }[]; t: typeof COPY.ar; onDone: () => void; canAdmin: boolean }) {
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<string>('client_viewer')
  const [chosen, setChosen] = useState<string[]>([])
  const [result, setResult] = useState<'granted' | 'invited' | null>(null)
  const send = useMutation({
    mutationFn: () => invitePortalUser(clientId, { email, role, ...(chosen.length > 0 ? { project_ids: chosen } : {}) }),
    onSuccess: (r) => { setResult(r.state); setEmail(''); onDone() },
  })

  return (
    <form data-testid="portal-invite" onSubmit={(e) => { e.preventDefault(); send.mutate() }} className="grid gap-2 rounded-xl border border-border p-3 sm:grid-cols-[1fr_9rem_auto]">
      <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t.email} dir="ltr" className="h-10 rounded-lg border border-border bg-surface px-3 text-sm" aria-label={t.email} />
      <select value={role} onChange={(e) => setRole(e.target.value)} aria-label={t.role} className="h-10 rounded-lg border border-border bg-surface px-2 text-sm">
        {PORTAL_ROLES.filter((r) => r !== 'client_admin' || canAdmin).map((r) => <option key={r} value={r}>{t.roles[r]}</option>)}
      </select>
      <button type="submit" disabled={send.isPending || email === ''} className="h-10 rounded-lg bg-brand-primary px-4 text-xs font-semibold text-white disabled:opacity-50">{t.invite}</button>
      {projects.length > 1 && (
        <fieldset className="sm:col-span-3">
          <legend className="mb-1 text-xs text-text-muted">{t.projects}</legend>
          <div className="flex flex-wrap gap-x-4 gap-y-1">
            {projects.map((p) => (
              <label key={p.id} className="flex items-center gap-1.5 text-xs text-text-secondary">
                <input type="checkbox" checked={chosen.includes(p.id)} onChange={(e) => setChosen((c) => (e.target.checked ? [...c, p.id] : c.filter((x) => x !== p.id)))} /> {p.name}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      {result && <p role="status" className="text-xs text-success sm:col-span-3">{result === 'granted' ? t.granted : t.invited}</p>}
      {send.isError && <p role="alert" className="text-xs text-danger sm:col-span-3">{toApiError(send.error).message}</p>}
    </form>
  )
}
