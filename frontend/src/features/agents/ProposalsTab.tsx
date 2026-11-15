import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/States'
import { usePortalPath } from '@/app/portalPath'
import { toApiError } from '@/lib/api/client'
import { bulkDecide, listProposals } from './api'
import { ProposalCard } from './ProposalCard'
import { actionText } from './proposalCopy'

/**
 * AGENTS-ASSISTANT-001 — «الاقتراحات»: every pending change, whichever specialist raised it, each with its exact preview.
 *
 * Several may be decided at once, safely: a bulk approval first lists every selected change again in a confirmation, and the
 * server still decides them ONE BY ONE through the gateway (rules re-read, permissions checked) and answers per item. Nothing is
 * executed without its own preview having been on screen.
 */
export function ProposalsTab({ projectId, ar, canDecide }: { projectId: string; ar: boolean; canDecide: boolean }) {
  const qc = useQueryClient()
  const portalTo = usePortalPath()
  const [selected, setSelected] = useState<string[]>([])
  const [confirm, setConfirm] = useState<'approve' | 'reject' | null>(null)
  const q = useQuery({ queryKey: ['agent-proposals', projectId], queryFn: () => listProposals(projectId), retry: false })
  const bulk = useMutation({
    meta: { feedback: 'none' },
    mutationFn: (d: 'approve' | 'reject') => bulkDecide(projectId, d, selected),
    onSuccess: () => { setSelected([]); setConfirm(null); void qc.invalidateQueries({ queryKey: ['agent-proposals', projectId] }); void qc.invalidateQueries({ queryKey: ['agents-team', projectId] }) },
  })

  if (q.isLoading) return <Skeleton className="h-32" />
  const pending = (q.data?.proposals ?? []).filter((p) => p.status === 'pending')
  const chosen = pending.filter((p) => selected.includes(p.id))
  const failed = (bulk.data?.results ?? []).filter((r) => !r.ok)

  return (
    <div className="space-y-3" data-testid="proposals-tab">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-text-secondary">{ar ? `بانتظار قرارك: ${pending.length}` : `Waiting for you: ${pending.length}`}</p>
        <Link to={portalTo('/agents/approvals')} className="text-xs font-semibold text-brand-700 hover:underline">{ar ? 'السجل الكامل' : 'Full history'}</Link>
      </div>

      {canDecide && pending.length > 1 && (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-xs" data-testid="proposals-bulk">
          <label className="inline-flex items-center gap-1.5">
            <input type="checkbox" checked={selected.length === pending.length} onChange={(e) => setSelected(e.target.checked ? pending.map((p) => p.id) : [])} data-testid="proposals-select-all" />
            {ar ? 'تحديد الكل' : 'Select all'}
          </label>
          <span className="text-text-muted">{ar ? `المحدد: ${selected.length}` : `Selected: ${selected.length}`}</span>
          <span className="ms-auto flex gap-1.5">
            <button type="button" disabled={selected.length === 0} onClick={() => setConfirm('approve')} data-testid="proposals-bulk-approve" className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1 font-semibold text-white disabled:opacity-40"><Check size={12} />{ar ? 'موافقة' : 'Approve'}</button>
            <button type="button" disabled={selected.length === 0} onClick={() => setConfirm('reject')} data-testid="proposals-bulk-reject" className="inline-flex items-center gap-1 rounded-lg border border-border bg-surface px-2.5 py-1 font-semibold text-text-secondary disabled:opacity-40"><X size={12} />{ar ? 'رفض' : 'Reject'}</button>
          </span>
        </div>
      )}

      {pending.length === 0 && <p className="rounded-xl border border-dashed border-border px-3 py-6 text-center text-sm text-text-muted" data-testid="proposals-empty">{ar ? 'لا اقتراحات بانتظارك.' : 'Nothing waiting for you.'}</p>}
      <div className="space-y-2">
        {pending.map((p) => (
          <ProposalCard key={p.id} projectId={projectId} proposal={p} ar={ar} canDecide={canDecide}
            selectable={canDecide && pending.length > 1} selected={selected.includes(p.id)}
            onSelect={(on) => setSelected((s) => (on ? [...s, p.id] : s.filter((x) => x !== p.id)))} />
        ))}
      </div>
      {failed.length > 0 && <p role="alert" className="text-xs text-danger" data-testid="proposals-bulk-failed">{ar ? `لم يُقرَّر ${failed.length}: تحقق من الصلاحية أو الحالة.` : `${failed.length} not decided: check permission or status.`}</p>}

      <Modal open={confirm !== null} onClose={() => setConfirm(null)} title={confirm === 'approve' ? (ar ? 'تأكيد الموافقة' : 'Confirm approval') : (ar ? 'تأكيد الرفض' : 'Confirm rejection')}
        footer={(
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setConfirm(null)} className="rounded-lg border border-border px-3 py-1.5 text-sm">{ar ? 'إلغاء' : 'Cancel'}</button>
            <button type="button" onClick={() => confirm && bulk.mutate(confirm)} disabled={bulk.isPending} data-testid="proposals-bulk-confirm" className="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">{ar ? 'تأكيد' : 'Confirm'}</button>
          </div>
        )}>
        <ul className="space-y-1 text-sm" data-testid="proposals-bulk-preview">
          {chosen.map((p) => (
            <li key={p.id} className="flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border px-2.5 py-1.5">
              <span className="min-w-0 truncate">{actionText(p, ar)}</span>
              <bdi dir="ltr" className="shrink-0 text-xs text-text-muted">{p.provider} {p.target_external_id}</bdi>
            </li>
          ))}
        </ul>
        {confirm === 'approve' && <p className="mt-2 text-xs text-text-muted">{ar ? 'كل تغيير يُراجَع على حدة قبل تنفيذه، وأي تغيير يخالف قاعدة يتوقف.' : 'Each change is checked on its own before it runs; any that breaks a rule stops.'}</p>}
        {bulk.isError && <p role="alert" className="mt-2 text-xs text-danger">{toApiError(bulk.error).message}</p>}
      </Modal>
    </div>
  )
}
