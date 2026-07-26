import { useState } from 'react'
import { ShieldCheck, UserMinus, UserPlus } from 'lucide-react'
import { useTeam, useTeamActions } from '../api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'

export function TeamTab() {
  const { data, isLoading, isError } = useTeam()
  const { invite, setRole, toggle, remove } = useTeamActions()
  const [form, setForm] = useState({ name: '', email: '', role: '' })
  const [err, setErr] = useState('')

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-24" /><Skeleton className="h-48" /></div>
  if (isError || !data) return <Alert severity="danger" title="تعذّر تحميل الفريق">تحقق من صلاحية settings.manage.</Alert>

  const roleOptions = data.roles.map((r) => ({ value: r.slug, label: r.name }))

  const doInvite = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    try {
      await invite.mutateAsync({ ...form, role: form.role || data.roles[0]?.slug })
      setForm({ name: '', email: '', role: '' })
    } catch (e2) { setErr(toApiError(e2).message) }
  }
  const guard = (p: Promise<unknown>) => p.catch((e) => setErr(toApiError(e).message))

  return (
    <div className="space-y-5">
      <form onSubmit={doInvite} className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><UserPlus size={18} /> دعوة عضو</h2>
        {err && <div className="mb-3"><Alert severity="danger" title="تعذّر تنفيذ الإجراء">{err}</Alert></div>}
        <div className="grid gap-3 sm:grid-cols-4">
          <Field label="الاسم" htmlFor="inv-name"><Input id="inv-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <Field label="البريد" htmlFor="inv-email"><Input id="inv-email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></Field>
          <Field label="الدور" htmlFor="inv-role"><Select id="inv-role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} options={roleOptions} placeholder="اختر الدور" /></Field>
          <div className="flex items-end"><Button type="submit" disabled={invite.isPending} className="w-full">{invite.isPending ? '…' : 'دعوة'}</Button></div>
        </div>
        <p className="mt-2 text-xs text-text-muted">تُنشأ العضوية فورًا؛ يصل العضو عبر إعادة تعيين كلمة المرور حتى تُفعّل رسائل الدعوة في مرحلة البريد.</p>
      </form>

      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
        {data.members.length === 0 ? <div className="p-6"><EmptyState title="لا أعضاء" /></div> : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead><tr className="border-b border-border text-start text-text-muted">
                <th className="p-3 text-start">العضو</th><th className="p-3 text-start">الدور</th><th className="p-3 text-start">آخر دخول</th><th className="p-3 text-start">الحالة</th><th className="p-3 text-end">إجراءات</th>
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
                    <td className="p-3 text-xs text-text-secondary">{m.last_login_at ? new Date(m.last_login_at).toLocaleDateString('en-GB') : '—'}</td>
                    <td className="p-3">{m.disabled ? <span className="rounded-full bg-[var(--negative-background)] px-2 py-0.5 text-xs text-danger">معطّل</span> : <span className="rounded-full bg-[var(--positive-background)] px-2 py-0.5 text-xs text-success">نشط</span>}</td>
                    <td className="p-3 text-end">
                      <button onClick={() => guard(toggle.mutateAsync(m.id))} className="me-2 text-xs font-semibold text-text-secondary hover:text-text-primary">{m.disabled ? 'تفعيل' : 'تعطيل'}</button>
                      <button onClick={() => guard(remove.mutateAsync(m.id))} className="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline"><UserMinus size={13} /> إزالة</button>
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
