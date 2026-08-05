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
import { useUi } from '@/stores/ui'
import { QueryFailure } from '@/components/ui/QueryFailure'

const MODES = [
  { value: 'managed', ar: 'مُدار', en: 'Managed' },
  { value: 'collaborative', ar: 'تعاوني', en: 'Collaborative' },
  { value: 'self_service', ar: 'خدمة ذاتية', en: 'Self-service' },
]

export function ClientsTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, error, isLoading, isError } = useClients()
  const { create, update, archive } = useClientActions()
  const [form, setForm] = useState({ name: '', mode: 'managed' })
  const [err, setErr] = useState('')

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-20" /><Skeleton className="h-48" /></div>
  if (isError) {
    return <QueryFailure error={error} ar={ar} testId="settings-clients-failure"
      fallbackTitle={ar ? 'تعذّر تحميل العملاء.' : 'The clients could not be loaded.'} />
  }

  const rows: ClientRow[] = Array.isArray(data) ? data : []
  const doCreate = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('')
    try { await create.mutateAsync(form); setForm({ name: '', mode: 'managed' }) } catch (e2) { setErr(toApiError(e2).message) }
  }
  const guard = (p: Promise<unknown>) => p.catch((e) => setErr(toApiError(e).message))

  return (
    <div className="space-y-5">
      <form onSubmit={doCreate} className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><Plus size={18} /> {ar ? 'عميل جديد' : 'New client'}</h2>
        {err && <div className="mb-3"><Alert severity="danger" title={ar ? 'تعذّر تنفيذ الإجراء' : 'That action could not be completed'}>{err}</Alert></div>}
        <div className="grid gap-3 sm:grid-cols-[1fr_200px_auto]">
          <Field label={ar ? 'اسم العميل' : 'Client name'} htmlFor="cl-name"><Input id="cl-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <Field label={ar ? 'النمط' : 'Mode'} htmlFor="cl-mode"><Select id="cl-mode" value={form.mode} onChange={(e) => setForm({ ...form, mode: e.target.value })} options={MODES.map((m) => ({ value: m.value, label: ar ? m.ar : m.en }))} /></Field>
          <div className="flex items-end"><Button type="submit" disabled={create.isPending}>{ar ? 'إنشاء' : 'Create'}</Button></div>
        </div>
      </form>

      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
        {rows.length === 0 ? <div className="p-6"><EmptyState title={ar ? 'لا عملاء بعد' : 'No clients yet'} description={ar ? 'أنشئ أول عميل لبدء ربط المشاريع.' : 'Create the first client to start attaching projects.'} /></div> : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-sm">
              <thead><tr className="border-b border-border text-text-muted"><th className="p-3 text-start">{ar ? 'العميل' : 'Client'}</th><th className="p-3 text-start">{ar ? 'النمط' : 'Mode'}</th><th className="p-3 text-start">{ar ? 'المشاريع' : 'Projects'}</th><th className="p-3 text-end">{ar ? 'إجراءات' : 'Actions'}</th></tr></thead>
              <tbody>
                {rows.map((c) => (
                  <tr key={c.id} className="border-b border-border last:border-0">
                    <td className="p-3"><span className="inline-flex items-center gap-2 font-semibold text-text-primary"><Building2 size={15} className="text-brand-600" /> {c.name}</span></td>
                    <td className="p-3 text-text-secondary">{(() => { const m = MODES.find((x) => x.value === c.mode); return m ? (ar ? m.ar : m.en) : c.mode })()}</td>
                    <td className="p-3 tnum text-text-secondary">{c.projects_count ?? 0}</td>
                    <td className="p-3 text-end">
                      <button onClick={() => { const name = prompt(ar ? 'اسم العميل الجديد' : 'New client name', c.name); if (name && name !== c.name) guard(update.mutateAsync({ id: c.id, name })) }} className="me-3 text-xs font-semibold text-text-secondary hover:text-text-primary">{ar ? 'تعديل' : 'Edit'}</button>
                      <button onClick={() => { if (confirm(ar ? 'أرشفة هذا العميل؟' : 'Archive this client?')) guard(archive.mutateAsync(c.id)) }} className="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline"><Archive size={13} /> {ar ? 'أرشفة' : 'Archive'}</button>
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
