import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BadgeCheck, Loader2, Plus, Undo2 } from 'lucide-react'
import { getData, postData, toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { ClientDetail } from '../api'

/** CLIENT-CONTENT-APPROVAL-001 — one ask, as the agency API answers it. */
export interface AgencyContentApproval {
  id: string
  title: string
  caption: string | null
  status: 'pending' | 'approved' | 'changes_requested' | 'withdrawn'
  project_id: string
  decision_note: string | null
  decided_by_contact: string | null
  decided_at: string | null
  created_at: string | null
}

const list = (clientId: string) => getData<{ approvals: AgencyContentApproval[] }>(`/app/clients/${clientId}/content-approvals`).then((d) => d.approvals)
const create = (clientId: string, body: { project_id: string; title: string; caption?: string }) =>
  postData<{ approval: AgencyContentApproval }>(`/app/clients/${clientId}/content-approvals`, body)
const withdraw = (clientId: string, id: string) => postData(`/app/clients/${clientId}/content-approvals/${id}/withdraw`)

const STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  pending: { ar: 'بانتظار العميل', en: 'With the client', tone: 'bg-warning/15 text-warning' },
  approved: { ar: 'معتمد', en: 'Approved', tone: 'bg-success/15 text-success' },
  changes_requested: { ar: 'طلب تعديل', en: 'Changes asked', tone: 'bg-surface-secondary text-text-secondary' },
  withdrawn: { ar: 'مسحوب', en: 'Withdrawn', tone: 'bg-surface-secondary text-text-muted' },
}

/**
 * «للاعتماد» on the client page: put content before the client (it appears in their «بانتظار اعتمادك»),
 * see their answer, withdraw an ask nobody answered. The server holds the lock (`content.*` + client and
 * project reach); this only hides the form from someone who would be refused.
 */
export function TabContentApprovals({ d }: { d: ClientDetail }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const canCreate = useAuth((s) => s.hasPermission('content.create'))
  const canWithdraw = useAuth((s) => s.hasPermission('content.update'))
  const qc = useQueryClient()
  const key = ['client', d.id, 'content-approvals']
  const q = useQuery({ queryKey: key, queryFn: () => list(d.id) })
  const [projectId, setProjectId] = useState(d.projects[0]?.id ?? '')
  const [title, setTitle] = useState('')
  const [caption, setCaption] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  const add = useMutation({
    mutationFn: () => create(d.id, { project_id: projectId, title: title.trim(), caption: caption.trim() || undefined }),
    onSuccess: () => { setTitle(''); setCaption(''); setError(null); setSaved(true); void qc.invalidateQueries({ queryKey: key }) },
    onError: (e) => { setSaved(false); setError(toApiError(e).message) },
  })
  const back = useMutation({
    mutationFn: (id: string) => withdraw(d.id, id),
    onSuccess: () => void qc.invalidateQueries({ queryKey: key }),
    onError: (e) => setError(toApiError(e).message),
  })
  const projectName = (id: string) => d.projects.find((p) => p.id === id)?.name ?? ''
  const rows = q.data ?? []

  return (
    <div className="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
      {canCreate && (
        <form
          data-testid="content-approval-form"
          className="grid min-w-0 content-start gap-2 rounded-2xl border border-border bg-surface p-3"
          onSubmit={(e) => { e.preventDefault(); if (title.trim().length >= 2 && projectId !== '') add.mutate() }}
        >
          <h3 className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><BadgeCheck size={15} aria-hidden /> {ar ? 'أرسل للاعتماد' : 'Send for approval'}</h3>
          {d.projects.length > 1 && (
            <select aria-label={ar ? 'المشروع' : 'Project'} value={projectId} onChange={(e) => setProjectId(e.target.value)} className="min-w-0 rounded-lg border border-border bg-background px-2 py-1.5 text-sm">
              {d.projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}
          <input aria-label={ar ? 'العنوان' : 'Title'} placeholder={ar ? 'العنوان' : 'Title'} value={title} maxLength={160} onChange={(e) => { setTitle(e.target.value); setSaved(false) }} className="min-w-0 rounded-lg border border-border bg-background px-2 py-1.5 text-sm" />
          <textarea aria-label={ar ? 'النص' : 'Copy'} placeholder={ar ? 'النص (اختياري)' : 'Copy (optional)'} value={caption} maxLength={4000} rows={3} onChange={(e) => setCaption(e.target.value)} className="min-w-0 rounded-lg border border-border bg-background px-2 py-1.5 text-sm" />
          <button type="submit" disabled={add.isPending || title.trim().length < 2 || projectId === ''} className="flex items-center justify-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60">
            {add.isPending ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />} {ar ? 'أرسل' : 'Send'}
          </button>
          {saved && <p role="status" className="text-xs text-success">{ar ? 'تم الإرسال للعميل' : 'Sent to the client'}</p>}
          {error && <p role="alert" className="rounded-lg bg-[var(--negative-background)] px-2 py-1 text-xs text-danger">{error}</p>}
        </form>
      )}
      <ul data-testid="content-approval-list" className="grid min-w-0 content-start gap-2">
        {q.isLoading && <li className="h-16 animate-pulse rounded-2xl bg-surface-secondary" />}
        {!q.isLoading && rows.length === 0 && <li className="rounded-2xl border border-border bg-surface p-4 text-center text-sm text-text-muted">{ar ? 'لا يوجد محتوى للاعتماد.' : 'Nothing sent for approval.'}</li>}
        {rows.map((a) => {
          const s = STATUS[a.status] ?? STATUS.pending
          return (
            <li key={a.id} className="flex min-w-0 items-start justify-between gap-2 rounded-2xl border border-border bg-surface p-3">
              <div className="min-w-0">
                <div className="truncate text-sm font-bold text-text-primary">{a.title}</div>
                <div className="truncate text-xs text-text-muted">{projectName(a.project_id)}</div>
                {a.decision_note && <p className="mt-1 break-words text-xs text-text-secondary">{a.decision_note}</p>}
              </div>
              <div className="flex shrink-0 flex-col items-end gap-1">
                <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ${s.tone}`}>{ar ? s.ar : s.en}</span>
                {canWithdraw && a.status === 'pending' && (
                  <button onClick={() => back.mutate(a.id)} disabled={back.isPending} className="flex items-center gap-1 text-xs font-semibold text-text-secondary hover:text-text-primary disabled:opacity-60">
                    <Undo2 size={12} /> {ar ? 'اسحب' : 'Withdraw'}
                  </button>
                )}
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
