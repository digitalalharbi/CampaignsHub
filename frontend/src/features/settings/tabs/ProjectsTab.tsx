import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, FolderKanban, Plus, RotateCcw } from 'lucide-react'
import { createProject, listClientWorkspaces, listProjects, projectAction } from '@/features/projects/api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { QueryFailure } from '@/components/ui/QueryFailure'

export function ProjectsTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const qc = useQueryClient()
  const clients = useQuery({ queryKey: ['settings', 'clients-list'], queryFn: () => listClientWorkspaces() })
  const projects = useQuery({ queryKey: ['settings', 'projects', 'all'], queryFn: () => listProjects(true) })
  const inv = () => qc.invalidateQueries({ queryKey: ['settings', 'projects', 'all'] })
  const create = useMutation({ mutationFn: createProject, onSuccess: inv })
  const act = useMutation({ mutationFn: (b: { id: string; action: 'archive' | 'restore' }) => projectAction(b.id, b.action), onSuccess: inv })

  const [form, setForm] = useState({ client_workspace_id: '', name: '' })
  const [err, setErr] = useState('')

  if (projects.isLoading) return <div className="space-y-3"><Skeleton className="h-20" /><Skeleton className="h-48" /></div>
  if (projects.isError) {
    return <QueryFailure error={projects.error} ar={ar} testId="settings-projects-failure"
      fallbackTitle={ar ? 'تعذّر تحميل المشاريع.' : 'The projects could not be loaded.'} />
  }

  const clientName = (id: string) => clients.data?.find((c) => c.id === id)?.name ?? '—'
  const doCreate = async (e: React.FormEvent) => {
    e.preventDefault(); setErr('')
    if (!form.client_workspace_id) { setErr(ar ? 'اختر العميل أولًا.' : 'Choose the client first.'); return }
    try { await create.mutateAsync(form); setForm({ client_workspace_id: '', name: '' }) } catch (e2) { setErr(toApiError(e2).message) }
  }

  return (
    <div className="space-y-5">
      <form onSubmit={doCreate} className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
        <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><Plus size={18} /> {ar ? 'مشروع جديد' : 'New project'}</h2>
        {err && <div className="mb-3"><Alert severity="danger" title={ar ? 'تعذّر الإنشاء' : 'Could not create it'}>{err}</Alert></div>}
        <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
          <Field label={ar ? 'العميل' : 'Client'} htmlFor="pj-client"><Select id="pj-client" value={form.client_workspace_id} onChange={(e) => setForm({ ...form, client_workspace_id: e.target.value })} options={(clients.data ?? []).map((c) => ({ value: c.id, label: c.name }))} placeholder={ar ? 'اختر العميل' : 'Choose a client'} /></Field>
          <Field label={ar ? 'اسم المشروع' : 'Project name'} htmlFor="pj-name"><Input id="pj-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <div className="flex items-end"><Button type="submit" disabled={create.isPending}>{ar ? 'إنشاء' : 'Create'}</Button></div>
        </div>
      </form>

      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
        {(projects.data?.length ?? 0) === 0 ? <div className="p-6"><EmptyState title={ar ? 'لا مشاريع' : 'No projects'} description={ar ? 'أنشئ أول مشروع وربطه بعميل.' : 'Create the first project and attach it to a client.'} /></div> : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[600px] text-sm">
              <thead><tr className="border-b border-border text-text-muted"><th className="p-3 text-start">{ar ? 'المشروع' : 'Project'}</th><th className="p-3 text-start">{ar ? 'العميل' : 'Client'}</th><th className="p-3 text-start">{ar ? 'الحالة' : 'Status'}</th><th className="p-3 text-start">{ar ? 'الإعداد' : 'Setup'}</th><th className="p-3 text-end">{ar ? 'إجراءات' : 'Actions'}</th></tr></thead>
              <tbody>
                {projects.data!.map((p) => (
                  <tr key={p.id} className="border-b border-border last:border-0">
                    <td className="p-3"><span className="inline-flex items-center gap-2 font-semibold text-text-primary"><FolderKanban size={15} className="text-brand-600" /> {p.name}</span></td>
                    <td className="p-3 text-text-secondary">{clientName(p.client_workspace_id)}</td>
                    <td className="p-3">{p.status === 'archived' ? <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs text-text-muted">{ar ? 'مؤرشف' : 'Archived'}</span> : <span className="rounded-full bg-[var(--positive-background)] px-2 py-0.5 text-xs text-success">{p.status}</span>}</td>
                    <td className="p-3 tnum text-text-secondary">{Math.round((p.setup_completion ?? 0) * 100)}%</td>
                    <td className="p-3 text-end">
                      <Link to="/app/campaigns" className="me-3 text-xs font-semibold text-brand-600 hover:underline">{ar ? 'فتح' : 'Open'}</Link>
                      {p.status === 'archived'
                        ? <button onClick={() => act.mutate({ id: p.id, action: 'restore' })} className="inline-flex items-center gap-1 text-xs font-semibold text-text-secondary hover:text-text-primary"><RotateCcw size={13} /> {ar ? 'استعادة' : 'Restore'}</button>
                        : <button onClick={() => { if (confirm(ar ? 'أرشفة هذا المشروع؟' : 'Archive this project?')) act.mutate({ id: p.id, action: 'archive' }) }} className="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline"><Archive size={13} /> {ar ? 'أرشفة' : 'Archive'}</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
      <p className="text-xs text-text-muted">{ar ? 'مصادر البيانات ومصدر الحقيقة ونافذة الإسناد وأعضاء المشروع تُدار من صفحة المشروع التفصيلية.' : 'Data sources, the source of truth, the attribution window and project members are managed on the project’s own page.'}</p>
    </div>
  )
}
