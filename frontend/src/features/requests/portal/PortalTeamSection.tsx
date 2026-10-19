import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Users } from 'lucide-react'
import { getPortalTeam, invitePortalPerson, removePortalPerson } from './portalAccountApi'
import { toApiError } from '@/lib/api/client'

const ROLE = {
  client_viewer: { ar: 'اطلاع فقط', en: 'View only' },
  client_approver: { ar: 'اعتماد', en: 'Approver' },
  client_admin: { ar: 'مسؤول', en: 'Admin' },
} as Record<string, { ar: string; en: string }>

/**
 * CP-USERS-001 — a client admin's own people. Drawn only when the server answers: anyone who is not a
 * client admin, or whose agency did not grant team_admin, gets 403 and sees nothing new here. An admin adds
 * viewers and approvers only; admins stay the agency's grant.
 */
export function PortalTeamSection({ ar }: { ar: boolean }) {
  const qc = useQueryClient()
  const team = useQuery({ queryKey: ['client', 'team'], queryFn: getPortalTeam, retry: false })
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<'client_viewer' | 'client_approver'>('client_viewer')
  const space = team.data?.workspaces[0]
  const done = () => void qc.invalidateQueries({ queryKey: ['client', 'team'] })
  const invite = useMutation({ mutationFn: () => invitePortalPerson({ client_workspace_id: space!.id, email: email.trim(), role }), onSuccess: () => { setEmail(''); done() } })
  const remove = useMutation({ mutationFn: (id: string) => removePortalPerson(id), onSuccess: done })

  if (!team.data || !space) return null

  return (
    <section className="mt-5 rounded-2xl border border-border bg-surface p-5 sm:p-6" data-testid="portal-team">
      <h2 className="flex items-center gap-2 text-base font-bold text-text-primary"><Users size={16} aria-hidden />{ar ? 'الأشخاص' : 'People'}</h2>
      <ul className="mt-3 divide-y divide-border">
        {space.people.map((p) => (
          <li key={p.membership_id ?? p.invitation_id ?? p.email} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" data-testid="portal-team-person">
            <span className="min-w-0 truncate text-text-primary" dir="ltr">{p.email}</span>
            <span className="flex items-center gap-2 text-xs text-text-secondary">
              {ROLE[p.role] ? (ar ? ROLE[p.role].ar : ROLE[p.role].en) : p.role}
              {p.state === 'invited' && <span className="text-text-muted">{ar ? '· مدعو' : '· invited'}</span>}
              {p.membership_id && p.role !== 'client_admin' && (
                <button type="button" className="font-semibold text-danger hover:underline" disabled={remove.isPending} onClick={() => remove.mutate(p.membership_id!)} data-testid="portal-team-remove">
                  {ar ? 'إزالة' : 'Remove'}
                </button>
              )}
            </span>
          </li>
        ))}
      </ul>
      <form className="mt-3 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); if (email.includes('@')) invite.mutate() }}>
        <label className="min-w-0 flex-1">
          <span className="sr-only">{ar ? 'البريد الإلكتروني' : 'Email'}</span>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} dir="ltr" placeholder="name@company.com" data-testid="portal-team-email" className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm" />
        </label>
        <label>
          <span className="sr-only">{ar ? 'الصلاحية' : 'Role'}</span>
          <select value={role} onChange={(e) => setRole(e.target.value as 'client_viewer' | 'client_approver')} data-testid="portal-team-role" className="rounded-lg border border-border bg-surface px-2 py-2 text-sm">
            <option value="client_viewer">{ar ? ROLE.client_viewer.ar : ROLE.client_viewer.en}</option>
            <option value="client_approver">{ar ? ROLE.client_approver.ar : ROLE.client_approver.en}</option>
          </select>
        </label>
        <button type="submit" disabled={!email.includes('@') || invite.isPending} data-testid="portal-team-invite" className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          {ar ? 'دعوة' : 'Invite'}
        </button>
      </form>
      {(invite.isError || remove.isError) && <p role="alert" className="mt-2 text-xs text-danger">{toApiError(invite.error ?? remove.error).message}</p>}
    </section>
  )
}
