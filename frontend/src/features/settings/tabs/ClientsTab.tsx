import { useState } from 'react'
import { Archive, Building2, Plus } from 'lucide-react'
import { useClients, useClientActions, type ClientRow } from '../api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'

const MODES = [
  { value: 'managed', label: 'مُدار' },
  { value: 'collaborative', label: 'تعاوني' },
  { value: 'self_service', label: 'خدمة ذاتية' },
]

export function ClientsTab() {
  const { data, isLoading, isError } = useClients()
  const { create, update, archive } = useClientActions()
  const [form, setForm] = useState({ name: '', mode: 'managed' })
  const [err, setErr] = useState('')

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-20" /><Skeleton className="h-48" /></div>
  if (isError) return <Alert severity="danger" title="تعذّر تحميل العملاء">تحقق من صلاحية العرض.</Alert>

  const rows: ClientRow[] = Array.isArray(data) ? data : []
  const doCreate = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('')
    try { await create.mutateAsync(form); setForm({ name: '', mode: 'managed' }) } catch (e2) { setErr(toApiError(e2).message) }
  }
  const guard = (p: Promise<unknown>) => p.catch((e) => setErr(toApiError(e).message))

  return (
    <div className="space-y-5">
      <form onSubmit={doCreate} className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><Plus size={18} /> عميل جديد</h2>
        {err && <div className="mb-3"><Alert severity="danger" title="تعذّر تنفيذ الإجراء">{err}</Alert></div>}
        <div className="grid gap-3 sm:grid-cols-[1fr_200px_auto]">
          <Field label="اسم العميل" htmlFor="cl-name"><Input id="cl-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <Field label="النمط" htmlFor="cl-mode"><Select id="cl-mode" value={form.mode} onChange={(e) => setForm({ ...form, mode: e.target.value })} options={MODES} /></Field>
          <div className="flex items-end"><Button type="submit" disabled={create.isPending}>إنشاء</Button></div>
        </div>
      </form>

      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
        {rows.length === 0 ? <div className="p-6"><EmptyState title="لا عملاء بعد" description="أنشئ أول عميل لبدء ربط المشاريع." /></div> : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-sm">
              <thead><tr className="border-b border-border text-text-muted"><th className="p-3 text-start">العميل</th><th className="p-3 text-start">النمط</th><th className="p-3 text-start">المشاريع</th><th className="p-3 text-end">إجراءات</th></tr></thead>
              <tbody>
                {rows.map((c) => (
                  <tr key={c.id} className="border-b border-border last:border-0">
                    <td className="p-3"><span className="inline-flex items-center gap-2 font-semibold text-text-primary"><Building2 size={15} className="text-brand-600" /> {c.name}</span></td>
                    <td className="p-3 text-text-secondary">{MODES.find((m) => m.value === c.mode)?.label ?? c.mode}</td>
                    <td className="p-3 tnum text-text-secondary">{c.projects_count ?? 0}</td>
                    <td className="p-3 text-end">
                      <button onClick={() => { const name = prompt('اسم العميل الجديد', c.name); if (name && name !== c.name) guard(update.mutateAsync({ id: c.id, name })) }} className="me-3 text-xs font-semibold text-text-secondary hover:text-text-primary">تعديل</button>
                      <button onClick={() => { if (confirm('أرشفة هذا العميل؟')) guard(archive.mutateAsync(c.id)) }} className="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline"><Archive size={13} /> أرشفة</button>
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
