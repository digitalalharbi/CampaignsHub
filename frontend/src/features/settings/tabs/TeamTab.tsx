import { useState } from 'react'
import { ShieldCheck, UserMinus, UserPlus } from 'lucide-react'
import { useTeam, useTeamActions } from '../api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { fmtDate } from '@/lib/datetime'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { QueryFailure } from '@/components/ui/QueryFailure'

export function TeamTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, error, isLoading, isError } = useTeam()
  const { invite, setRole, toggle, remove, revokeInvitation } = useTeamActions()
  const [form, setForm] = useState({ email: '', role: '' })
  const [err, setErr] = useState('')

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-24" /><Skeleton className="h-48" /></div>
  // It used to GUESS — «تحقق من صلاحية settings.manage» — on every failure, including a dead
  // server. The status already knows which one it was.
  if (isError || !data) {
    return <QueryFailure error={error} ar={ar} testId="settings-team-failure"
      fallbackTitle={ar ? 'تعذّر تحميل الفريق.' : 'The team could not be loaded.'} />
  }

  const roleOptions = data.roles.map((r) => ({ value: r.slug, label: r.name }))

  const doInvite = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    try {
      await invite.mutateAsync({ ...form, role: form.role || data.roles[0]?.slug })
      setForm({ email: '', role: '' })
    } catch (e2) { setErr(toApiError(e2).message) }
  }
  const guard = (p: Promise<unknown>) => p.catch((e) => setErr(toApiError(e).message))

  return (
    <div className="space-y-5">
      <form onSubmit={doInvite} className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><UserPlus size={18} /> {ar ? 'دعوة عضو' : 'Invite a member'}</h2>
        {err && <div className="mb-3"><Alert severity="danger" title={ar ? 'تعذّر تنفيذ الإجراء' : 'That action could not be completed'}>{err}</Alert></div>}
        <div className="grid gap-3 sm:grid-cols-3">
          {/*
            No name field — TEAM-INVITE-001.

            The invited person names themselves when they accept, which is the only moment anybody
            has actually asked them. A name typed by a colleague was never verified and was often
            wrong, and it stopped being needed the moment the account stopped being created here.
          */}
          <Field label={ar ? 'البريد' : 'Email'} htmlFor="inv-email"><Input id="inv-email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></Field>
          <Field label={ar ? 'الدور' : 'Role'} htmlFor="inv-role"><Select id="inv-role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} options={roleOptions} placeholder={ar ? 'اختر الدور' : 'Choose a role'} /></Field>
          <div className="flex items-end"><Button type="submit" disabled={invite.isPending} className="w-full">{invite.isPending ? '…' : (ar ? 'دعوة' : 'Invite')}</Button></div>
        </div>
        <p className="mt-2 max-w-2xl text-xs leading-6 text-text-muted">
          {ar
            ? 'تُرسل دعوة برابط ينتهي خلال ٧٢ ساعة. لا يُنشأ الحساب ولا تُمنح أي صلاحية قبل أن يفتح الشخص الرابط ويختار كلمة مروره — لذلك لا يترك البريد الخاطئ حسابًا معلّقًا.'
            : 'An invitation link is sent and expires in 72 hours. No account exists and nothing is granted until the person opens it and chooses a password — so a mistyped address leaves nothing behind.'}
        </p>
      </form>

      {/*
        Invitations nobody has accepted — TEAM-INVITE-001.

        Above the member list rather than below it: this is the part somebody is looking for when
        they come back to check, and an expired invitation is the one thing here that needs doing
        something about.
      */}
      {(data.invitations ?? []).length > 0 && (
        <div className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
          <h2 className="mb-3 text-lg font-bold text-text-primary">{ar ? 'دعوات لم تُقبل بعد' : 'Invitations not yet accepted'}</h2>
          <ul className="divide-y divide-border">
            {data.invitations.map((i) => (
              <li key={i.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div>
                  <div className="font-medium text-text-primary" dir="ltr">{i.email}</div>
                  <div className="text-[13px] text-text-muted">
                    {i.role_slug}
                    {i.expired
                      ? ` · ${ar ? 'انتهت صلاحية الرابط' : 'the link has expired'}`
                      : ` · ${ar ? 'ينتهي' : 'expires'} ${i.expires_at.slice(0, 10)}`}
                    {i.delivery_status !== 'sent' && ` · ${ar ? 'لم يُرسل البريد بعد' : 'not emailed yet'}`}
                  </div>
                </div>
                <Button variant="secondary" onClick={() => guard(revokeInvitation.mutateAsync(i.id))}>
                  {ar ? 'سحب الدعوة' : 'Withdraw'}
                </Button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
        {data.members.length === 0 ? <div className="p-6"><EmptyState title={ar ? 'لا أعضاء' : 'No members'} /></div> : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead><tr className="border-b border-border text-start text-text-muted">
                <th className="p-3 text-start">{ar ? 'العضو' : 'Member'}</th><th className="p-3 text-start">{ar ? 'الدور' : 'Role'}</th><th className="p-3 text-start">{ar ? 'آخر دخول' : 'Last sign-in'}</th><th className="p-3 text-start">{ar ? 'الحالة' : 'Status'}</th><th className="p-3 text-end">{ar ? 'إجراءات' : 'Actions'}</th>
              </tr></thead>
              <tbody>
                {data.members.map((m) => (
                  <tr key={m.id} className="border-b border-border last:border-0">
                    <td className="p-3">
                      <div className="font-semibold text-text-primary">{m.name} {m.is_owner && <span className="ms-1 rounded bg-brand-100 px-1.5 py-0.5 text-[10px] font-bold text-brand-700">Owner</span>}{m.two_factor_enabled && <ShieldCheck size={13} className="ms-1 inline text-success" />}</div>
                      <div className="text-xs text-text-muted">{m.email}</div>
                    </td>
                    <td className="p-3">
                      <Select value={m.roles[0]?.slug ?? ''} onChange={(e) => guard(setRole.mutateAsync({ id: m.id, role: e.target.value }))} options={roleOptions} className="min-w-[140px]" />
                    </td>
                    <td className="p-3 text-xs text-text-secondary">{m.last_login_at ? fmtDate(m.last_login_at) : '—'}</td>
                    <td className="p-3">{m.disabled ? <span className="rounded-full bg-[var(--negative-background)] px-2 py-0.5 text-xs text-danger">{ar ? 'معطّل' : 'Disabled'}</span> : <span className="rounded-full bg-[var(--positive-background)] px-2 py-0.5 text-xs text-success">{ar ? 'نشط' : 'Active'}</span>}</td>
                    <td className="p-3 text-end">
                      <button onClick={() => guard(toggle.mutateAsync(m.id))} className="me-2 text-xs font-semibold text-text-secondary hover:text-text-primary">{m.disabled ? (ar ? 'تفعيل' : 'Enable') : (ar ? 'تعطيل' : 'Disable')}</button>
                      <button onClick={() => guard(remove.mutateAsync(m.id))} className="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline"><UserMinus size={13} /> {ar ? 'إزالة' : 'Remove'}</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
