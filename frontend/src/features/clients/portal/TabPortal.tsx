import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Trash2 } from 'lucide-react'
import { updateSettings, type ClientDetail } from '../api'
import { invitePortalUser, listPortalUsers, resendPortalInvitation, revokePortalUser, withdrawPortalInvitation, type PortalUser } from './portalAdminApi'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Switch } from '@/components/ui/Switch'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { fmtDate } from '@/lib/datetime'

/**
 * CP-VISIBILITY-001, CP-WRITE-DENY-001, CP-USERS-001 — one client's portal, decided by the agency.
 *
 * Three questions on one screen: what may this client SEE (fifteen switches), what may its people DO
 * (writes stay denied unless one is switched on deliberately — seeing is never doing), and WHO can open it
 * (grant an existing account or invite a new address, with a role and, if wanted, a project ceiling).
 * The server enforces every one of these; this screen only edits them.
 */
const VISIBILITY = ['dashboard', 'projects', 'campaigns', 'content', 'reports', 'recommendations', 'spend', 'revenue', 'platform_evidence', 'requests', 'invoices', 'messages', 'files', 'campaign_names', 'ad_account_names', 'audience'] as const

/** Powers an agency may hand to a client. The rest (integrations, writes, pause, budget, notes, margin, identifiers) are not offered. */
const GRANTABLE = ['create_requests', 'approve_content', 'team_admin'] as const

const COPY = {
  ar: {
    see: 'ما الذي يراه العميل', seeHint: 'الرؤية منفصلة عن الصلاحية: عرض الحملات لا يمنح تعديلها.',
    do: 'ما الذي يستطيع فعله', doHint: 'التعديل على الحملات والميزانيات والربط يبقى للوكالة دائماً.',
    people: 'دعوة العميل لمتابعة مشاريعه', peopleHint: 'ادعُ العميل أو فريقه بالبريد وحدّد مشاريعه. ما يراه وما يقدر يفعله تحدده الخيارات أدناه فقط، ولا تمنحه الدعوة أي دخول لمساحة الوكالة.', lastLogin: 'آخر دخول', neverLogged: 'لم يدخل بعد', invite: 'دعوة', email: 'البريد الإلكتروني', role: 'الدور', projects: 'المشاريع', allProjects: 'كل المشاريع',
    save: 'حفظ', saved: 'تم الحفظ', none: 'لا أحد بعد.', granted: 'مُنح الوصول لحساب موجود.', invited: 'أُرسلت الدعوة.',
    roles: { client_viewer: 'مطّلع', client_approver: 'معتمِد', client_admin: 'مسؤول العميل' } as Record<string, string>,
    states: { active: 'نشط', invited: 'مدعو' } as Record<string, string>,
    v: { dashboard: 'لوحة الأداء', projects: 'المشاريع', campaigns: 'الحملات', content: 'المحتوى', reports: 'التقارير', recommendations: 'التوصيات المعتمدة', spend: 'الإنفاق', revenue: 'الإيرادات', platform_evidence: 'لقطات المنصات', requests: 'الطلبات', invoices: 'الفواتير', messages: 'الرسائل', files: 'الملفات', campaign_names: 'أسماء الحملات', ad_account_names: 'أسماء الحسابات الإعلانية', audience: 'الجمهور (ضمن الأداء)' } as Record<string, string>,
    c: { create_requests: 'فتح طلبات جديدة', approve_content: 'اعتماد المحتوى', team_admin: 'إدارة من يدخل البوابة' } as Record<string, string>,
    revoke: 'إزالة', edit: 'تعديل', resend: 'إعادة الإرسال', withdraw: 'سحب الدعوة', resent: 'أُعيد إرسال الدعوة.', cancel: 'إلغاء', noneMeansAll: 'بدون اختيار = كل المشاريع',
  },
  en: {
    see: 'What the client sees', seeHint: 'Seeing is separate from doing: showing campaigns grants no power over them.',
    do: 'What the client may do', doHint: 'Changing campaigns, budgets and connections always stays with the agency.',
    people: 'Invite the client to follow their projects', peopleHint: 'Invite the client or their team by email and choose their projects. What they see and may do is set only by the switches below; this never gives them access to the agency workspace.', lastLogin: 'Last sign-in', neverLogged: 'Never signed in', invite: 'Invite', email: 'Email', role: 'Role', projects: 'Projects', allProjects: 'All projects',
    save: 'Save', saved: 'Saved', none: 'Nobody yet.', granted: 'Access granted to an existing account.', invited: 'Invitation sent.',
    roles: { client_viewer: 'Viewer', client_approver: 'Approver', client_admin: 'Client admin' } as Record<string, string>,
    states: { active: 'Active', invited: 'Invited' } as Record<string, string>,
    v: { dashboard: 'Performance dashboard', projects: 'Projects', campaigns: 'Campaigns', content: 'Content', reports: 'Reports', recommendations: 'Approved recommendations', spend: 'Spend', revenue: 'Revenue', platform_evidence: 'Platform screenshots', requests: 'Requests', invoices: 'Invoices', messages: 'Messages', files: 'Files', campaign_names: 'Campaign names', ad_account_names: 'Ad account names', audience: 'Audience (under performance)' } as Record<string, string>,
    c: { create_requests: 'Open new requests', approve_content: 'Approve content', team_admin: 'Manage who can open the portal' } as Record<string, string>,
    revoke: 'Remove', edit: 'Edit', resend: 'Resend', withdraw: 'Withdraw', resent: 'Invitation resent.', cancel: 'Cancel', noneMeansAll: 'None chosen = all projects',
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
      {d.can.manage_team && (
        <section>
          <h3 className="font-bold text-text-primary">{t.people}</h3>
          <p className="mb-3 text-xs text-text-muted">{t.peopleHint}</p>
          <Invite clientId={d.id} projects={d.projects} t={t} onDone={() => void users.refetch()} canAdmin={caps.team_admin === true} />
          {users.isError ? (
            <QueryFailure error={users.error} ar={ar} testId="portal-users-failure" onRetry={() => void users.refetch()} fallbackTitle={ar ? 'تعذّر تحميل المستخدمين.' : 'Could not load the people.'} />
          ) : (
            <ul data-testid="portal-users" className="mt-3 space-y-2">
              {(users.data ?? []).length === 0 && !users.isLoading && <li className="text-text-muted">{t.none}</li>}
              {(users.data ?? []).map((u) => (
                <PersonRow key={u.membership_id ?? u.invitation_id} u={u} d={d} t={t} canAdmin={caps.team_admin === true} onDone={() => void users.refetch()} />
              ))}
            </ul>
          )}
        </section>
      )}
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

    </div>
  )
}

/**
 * CLIENT-INVITE-CONTROL-001 — Owner 2026-11-20: full control after the invitation. An active person's projects and admin power are
 * changed in place (the same grant, which REPLACES the ceiling); a pending invitation is resent or withdrawn; anyone is removed.
 * CLIENT-ONE-PROJECT-001 — a client with one project names it instead of «كل المشاريع».
 */
function PersonRow({ u, d, t, canAdmin, onDone }: { u: PortalUser; d: ClientDetail; t: typeof COPY.ar; canAdmin: boolean; onDone: () => void }) {
  const [editing, setEditing] = useState(false)
  const [chosen, setChosen] = useState<string[]>(u.projects ?? [])
  const [admin, setAdmin] = useState(u.role === 'client_admin')
  const [msg, setMsg] = useState('')
  const scope = u.projects === null
    ? (d.projects.length === 1 ? d.projects[0]!.name : t.allProjects)
    : u.projects.map((id) => d.projects.find((p) => p.id === id)?.name ?? id).join('، ')
  const save = useMutation({
    mutationFn: () => invitePortalUser(d.id, { email: u.email ?? '', role: admin && canAdmin ? 'client_admin' : 'client_approver', ...(chosen.length > 0 ? { project_ids: chosen } : {}) }),
    onSuccess: () => { setEditing(false); setMsg(t.saved); onDone() },
    onError: (e) => setMsg(toApiError(e).message),
  })
  const resend = useMutation({ mutationFn: () => resendPortalInvitation(u.invitation_id ?? ''), onSuccess: () => setMsg(t.resent), onError: (e) => setMsg(toApiError(e).message) })
  const withdraw = useMutation({ mutationFn: () => withdrawPortalInvitation(u.invitation_id ?? ''), onSuccess: onDone, onError: (e) => setMsg(toApiError(e).message) })
  const btn = 'rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50'

  return (
    <li data-testid="portal-person" className="grid gap-2 rounded-xl border border-border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="min-w-0">
          <span className="block truncate font-semibold text-text-primary" dir="ltr">{u.email}</span>
          <span className="text-xs text-text-muted">{u.name ? `${u.name} · ` : ''}{u.role === 'client_admin' ? `${t.roles.client_admin} · ` : ''}{scope}</span>
        </span>
        <span className="flex flex-wrap items-center gap-2">
          {u.state === 'active' && (
            <span data-testid="portal-user-login" className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${u.last_login_at ? 'bg-[var(--positive-background)] text-success' : 'bg-[var(--warning-background)] text-warning'}`}>
              {u.last_login_at ? `${t.lastLogin} ${fmtDate(u.last_login_at)}` : t.neverLogged}
            </span>
          )}
          <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary">{t.states[u.state]}</span>
          {u.state === 'active' && <button type="button" className={btn} onClick={() => setEditing((v) => !v)} data-testid="portal-person-edit">{t.edit}</button>}
          {u.state === 'invited' && u.invitation_id && (
            <>
              <button type="button" className={btn} disabled={resend.isPending} onClick={() => resend.mutate()} data-testid="portal-person-resend">{t.resend}</button>
              <button type="button" className={btn} disabled={withdraw.isPending} onClick={() => withdraw.mutate()} data-testid="portal-person-withdraw">{t.withdraw}</button>
            </>
          )}
          {u.membership_id && <Revoke clientId={d.id} membershipId={u.membership_id} label={t.revoke} onDone={onDone} />}
        </span>
      </div>
      {editing && (
        <div className="grid gap-2 rounded-lg bg-surface-secondary p-2.5" data-testid="portal-person-editor">
          {d.projects.length > 1 && (
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              <span className="text-xs text-text-muted">{t.projects}:</span>
              {d.projects.map((p) => (
                <label key={p.id} className="flex items-center gap-1.5 text-xs text-text-secondary">
                  <input type="checkbox" checked={chosen.includes(p.id)} onChange={(e) => setChosen((c) => (e.target.checked ? [...c, p.id] : c.filter((x) => x !== p.id)))} /> {p.name}
                </label>
              ))}
              <span className="text-[11px] text-text-muted">{t.noneMeansAll}</span>
            </div>
          )}
          {canAdmin && (
            <label className="flex items-center gap-2 text-xs text-text-secondary">
              <input type="checkbox" checked={admin} onChange={(e) => setAdmin(e.target.checked)} /> {t.c.team_admin}
            </label>
          )}
          <div className="flex justify-end gap-2">
            <button type="button" className={btn} onClick={() => setEditing(false)}>{t.cancel}</button>
            <button type="button" disabled={save.isPending} onClick={() => save.mutate()} className="rounded-lg bg-brand-primary px-3 py-1 text-xs font-semibold text-white disabled:opacity-50" data-testid="portal-person-save">{t.save}</button>
          </div>
        </div>
      )}
      {msg && <p role="status" className="text-xs text-text-secondary">{msg}</p>}
    </li>
  )
}

function Revoke({ clientId, membershipId, label, onDone }: { clientId: string; membershipId: string; label: string; onDone: () => void }) {
  const revoke = useMutation({ mutationFn: () => revokePortalUser(clientId, membershipId), onSuccess: onDone })

  return <button type="button" aria-label={label} onClick={() => revoke.mutate()} disabled={revoke.isPending} className="rounded p-1 text-text-muted hover:text-danger"><Trash2 size={14} /></button>
}

function Invite({ clientId, projects, t, onDone, canAdmin }: { clientId: string; projects: { id: string; name: string }[]; t: typeof COPY.ar; onDone: () => void; canAdmin: boolean }) {
  const [email, setEmail] = useState('')
  // CLIENT-INVITE-NO-ROLE-001 — Owner 2026-11-20: no «مطّلع / معتمِد» choice. What the client sees and does is the switches below;
  // the invitation carries the full client role, and only «يدير من يدخل البوابة» (when the agency enabled it) adds the admin role.
  const [admin, setAdmin] = useState(false)
  const role = admin && canAdmin ? 'client_admin' : 'client_approver'
  const [chosen, setChosen] = useState<string[]>([])
  const [result, setResult] = useState<'granted' | 'invited' | null>(null)
  const send = useMutation({
    mutationFn: () => invitePortalUser(clientId, { email, role, ...(chosen.length > 0 ? { project_ids: chosen } : {}) }),
    onSuccess: (r) => { setResult(r.state); setEmail(''); onDone() },
  })

  return (
    <form data-testid="portal-invite" onSubmit={(e) => { e.preventDefault(); send.mutate() }} className="grid gap-2 rounded-xl border border-border p-3 sm:grid-cols-[1fr_auto_auto]">
      <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t.email} dir="ltr" className="h-10 rounded-lg border border-border bg-surface px-3 text-sm" aria-label={t.email} />
      {canAdmin ? (
        <label className="flex h-10 items-center gap-2 text-xs text-text-secondary">
          <input type="checkbox" checked={admin} onChange={(e) => setAdmin(e.target.checked)} /> {t.c.team_admin}
        </label>
      ) : <span aria-hidden />}
      <button type="submit" disabled={send.isPending || email === ''} className="h-10 rounded-lg bg-brand-primary px-4 text-xs font-semibold text-white disabled:opacity-50">{t.invite}</button>
      {projects.length === 1 && <p className="text-xs text-text-muted sm:col-span-3">{t.projects}: {projects[0]!.name}</p>}
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
