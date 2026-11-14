import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Check, Loader2, Pencil, X } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { formatMoney } from '@/features/funding/money'
import { toApiError } from '@/lib/api/client'
import { amendProposal, decideProposal, type Proposal } from './api'
import { STATUS_WORDS, violationText } from './proposalCopy'
import { SPECIALIST } from './agentCopy'

/**
 * AGENTS-ASSISTANT-001 — one proposed change, with its exact preview and the three decisions: Approve / Edit / Reject.
 *
 * The preview is what the gateway will be asked to do — the action, the target on its platform and, for a budget, the amount
 * before → after with its currency mark. Approving asks the server, which re-reads every rule and answers with what actually
 * happened; nothing here changes a campaign. Edit changes only a pending budget's amount, and the edited change still waits.
 */
export function ProposalCard({ projectId, proposal, ar, canDecide, selectable, selected, onSelect }: {
  projectId: string
  proposal: Proposal
  ar: boolean
  canDecide: boolean
  selectable?: boolean
  selected?: boolean
  onSelect?: (on: boolean) => void
}) {
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [amount, setAmount] = useState(String(proposal.payload?.daily_budget ?? ''))
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['agent-proposals', projectId] }); void qc.invalidateQueries({ queryKey: ['agents-team', projectId] }) }
  const decide = useMutation({ meta: { feedback: 'none' }, mutationFn: (d: 'approve' | 'reject') => decideProposal(projectId, proposal.id, d), onSuccess: refresh })
  const amend = useMutation({ meta: { feedback: 'none' }, mutationFn: (n: number) => amendProposal(projectId, proposal.id, n), onSuccess: () => { setEditing(false); refresh() } })

  const status = STATUS_WORDS[proposal.status]
  const pending = proposal.status === 'pending'
  const isBudget = proposal.action === 'set_daily_budget'
  const currency = proposal.currency
  const before = proposal.current_daily_budget ?? null
  const after = proposal.payload?.daily_budget ?? null
  const moneyText = (n: number | null) => (n === null ? '—' : currency ? formatMoney(n, currency, 2) : String(n))
  const Arrow = ar ? ArrowLeft : ArrowRight
  const n = Number(amount)
  const error = decide.error ?? amend.error
  const who = (SPECIALIST as Record<string, { ar: string; en: string }>)[proposal.agent]

  return (
    <div className="min-w-0 rounded-xl border border-border bg-surface p-3" data-testid="proposal-card" data-status={proposal.status}>
      <div className="flex min-w-0 items-start justify-between gap-2">
        <div className="flex min-w-0 items-start gap-2">
          {selectable && pending && (
            <input type="checkbox" checked={Boolean(selected)} onChange={(e) => onSelect?.(e.target.checked)} aria-label={ar ? 'تحديد' : 'Select'} data-testid="proposal-select" className="mt-1" />
          )}
          <div className="min-w-0">
            <p className="text-sm font-bold text-text-primary">
              {proposal.action === 'pause' ? (ar ? 'إيقاف' : 'Pause') : proposal.action === 'resume' ? (ar ? 'تشغيل' : 'Resume') : (ar ? 'الميزانية اليومية' : 'Daily budget')}
              <span className="ms-1.5 text-xs font-normal text-text-muted">{proposal.target_type === 'ad_set' ? (ar ? 'مجموعة إعلانية' : 'Ad set') : (ar ? 'حملة' : 'Campaign')} · <bdi dir="ltr">{proposal.provider} {proposal.target_external_id}</bdi></span>
            </p>
            {isBudget && (
              <p className="mt-1 flex items-center gap-1.5 text-sm" data-testid="proposal-preview">
                <bdi dir="ltr" className="tnum text-text-muted">{moneyText(before)}</bdi>
                <Arrow size={13} aria-hidden className="text-text-muted" />
                <bdi dir="ltr" className="tnum font-bold text-text-primary">{moneyText(after)}</bdi>
              </p>
            )}
            {proposal.reason && <p className="mt-1 text-xs text-text-secondary" dir="auto">{proposal.reason}</p>}
          </div>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <Badge tone={status.tone}>{ar ? status.ar : status.en}</Badge>
          {who && <span className="text-xs text-text-muted">{ar ? who.ar : who.en}</span>}
        </div>
      </div>

      {proposal.violations.length > 0 && (
        <ul className="mt-2 space-y-0.5 text-xs text-danger">{proposal.violations.map((v) => <li key={v}>{violationText(v, ar)}</li>)}</ul>
      )}

      {editing && (
        <form className="mt-2 flex items-center gap-2" onSubmit={(e) => { e.preventDefault(); if (n > 0) amend.mutate(n) }}>
          <input type="number" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} dir="ltr" aria-label={ar ? 'الميزانية اليومية الجديدة' : 'New daily budget'} data-testid="proposal-amount" className="h-8 w-28 rounded-lg border border-border bg-surface px-2 text-sm" />
          <button type="submit" disabled={!(n > 0) || amend.isPending} className="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-50" data-testid="proposal-amount-save">{ar ? 'حفظ' : 'Save'}</button>
          <button type="button" onClick={() => setEditing(false)} className="text-xs text-text-muted">{ar ? 'إلغاء' : 'Cancel'}</button>
        </form>
      )}

      {pending && canDecide && !editing && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          <button type="button" onClick={() => decide.mutate('approve')} disabled={decide.isPending} data-testid="proposal-approve" className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">
            {decide.isPending ? <Loader2 size={12} className="animate-spin" /> : <Check size={13} />}{ar ? 'موافقة' : 'Approve'}
          </button>
          {isBudget && (
            <button type="button" onClick={() => setEditing(true)} data-testid="proposal-edit" className="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-primary">
              <Pencil size={12} />{ar ? 'تعديل' : 'Edit'}
            </button>
          )}
          <button type="button" onClick={() => decide.mutate('reject')} disabled={decide.isPending} data-testid="proposal-reject" className="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary">
            <X size={13} />{ar ? 'رفض' : 'Reject'}
          </button>
        </div>
      )}
      {pending && !canDecide && <p className="mt-2 text-xs text-text-muted">{ar ? 'يقرره من يملك صلاحية إدارة الحملات.' : 'Decided by someone who manages campaigns.'}</p>}
      {error && <p role="alert" className="mt-1 text-xs text-danger">{toApiError(error).message}</p>}
    </div>
  )
}
