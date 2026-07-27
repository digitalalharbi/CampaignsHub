import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, UserPlus } from 'lucide-react'
import { grantClientAccess, listAssignableUsers, listClientTeam, removeClientAccess, type ClientDetail } from './api'
import { ACCESS_ROLE_LABELS, labelOf } from './labels'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

const ROLES = ['client_owner', 'media_buyer', 'analyst', 'reporter', 'viewer', 'custom']

export function TabTeam({ d }: { d: ClientDetail }) {
  const t = useT()
  const lang = useUi((s) => s.locale)
  const qc = useQueryClient()
  const team = useQuery({ queryKey: ['app', 'client', d.id, 'team'], queryFn: () => listClientTeam(d.id) })
  const assignable = useQuery({ queryKey: ['app', 'client', d.id, 'assignable'], queryFn: () => listAssignableUsers(d.id), enabled: d.can.manage_team })
  const [userId, setUserId] = useState<number | ''>('')
  const [role, setRole] = useState('media_buyer')

  const grant = useMutation({
    mutationFn: () => grantClientAccess(d.id, { user_id: Number(userId), access_role: role }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['app', 'client', d.id, 'team'] }); qc.invalidateQueries({ queryKey: ['app', 'client', d.id, 'assignable'] }); setUserId('') },
  })
  const remove = useMutation({
    mutationFn: (uid: number) => removeClientAccess(d.id, uid),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['app', 'client', d.id, 'team'] }); qc.invalidateQueries({ queryKey: ['app', 'client', d.id, 'assignable'] }) },
  })

  const members = team.data?.members ?? []
  const field = 'h-10 rounded-lg border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500'

  return (
    <div className="grid gap-4">
      {d.can.manage_team && (
        <form onSubmit={(e) => { e.preventDefault(); if (userId) grant.mutate() }} className="flex flex-wrap items-end gap-2 rounded-xl border border-border bg-surface-secondary p-3">
          <select className={field} value={userId} onChange={(e) => setUserId(e.target.value ? Number(e.target.value) : '')}>
            <option value="">{t('tm_member')}…</option>
            {(assignable.data?.assignable ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
          <select className={field} value={role} onChange={(e) => setRole(e.target.value)}>
            {ROLES.map((r) => <option key={r} value={r}>{labelOf(ACCESS_ROLE_LABELS, r, lang)}</option>)}
          </select>
          <button type="submit" disabled={!userId || grant.isPending} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60"><UserPlus size={15} /> {t('tm_add')}</button>
          {grant.isError && <span className="text-xs text-danger">{t('error_generic')}</span>}
        </form>
      )}

      {members.length === 0 ? (
        <p className="rounded-xl border border-border bg-surface p-8 text-center text-sm text-text-muted">{t('tm_empty')}</p>
      ) : (
        <ul className="space-y-2">
          {members.map((m) => (
            <li key={m.user_id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-4 py-3 text-sm">
              <div>
                <div className="font-medium text-text-primary">{m.name}</div>
                <div className="text-[11px] text-text-muted" dir="ltr">{m.email}</div>
              </div>
              <div className="flex items-center gap-2">
                <span className="rounded-full bg-brand-primary-soft px-2 py-0.5 text-[11px] font-semibold text-brand-700">{labelOf(ACCESS_ROLE_LABELS, m.access_role, lang)}</span>
                <span className="text-[11px] text-text-muted">{m.project_ids ? t('tm_projects_restricted') : t('tm_all_projects')}</span>
                {d.can.manage_team && (
                  <button onClick={() => remove.mutate(m.user_id)} disabled={remove.isPending} className="rounded p-1.5 text-danger hover:bg-danger/10" aria-label={t('tm_remove')}><Trash2 size={15} /></button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {remove.isError && <p className="text-xs text-danger">{t('error_generic')}</p>}
    </div>
  )
}
