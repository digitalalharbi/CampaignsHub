import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Check, ShieldCheck, X } from 'lucide-react'
import { decideProposal, listProposals, type Proposal } from './api'
import { STATUS_WORDS, actionText, violationText } from './proposalCopy'
import { AutonomyCard } from './AutonomyCard'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { PageIntro } from '@/components/ui/PageIntro'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { toApiError } from '@/lib/api/client'
import { usePortalPath } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * AGENTS-APPROVAL-GATEWAY-001 — «what an agent wants done», and who said yes.
 *
 * The queue shows every request with its reason, the rule that stopped it (if one did) and what happened
 * after a decision. The buttons only ASK: the server decides whether a change may go ahead, re-checks the
 * rules at that moment, and reports `blocked` when an action's platform is not enabled — so «approved»
 * is never shown for something that was not carried out.
 */
export function ApprovalsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const portalTo = usePortalPath()
  const qc = useQueryClient()
  const canDecide = useAuth((s) => s.hasPermission('campaigns.pause') || s.hasPermission('campaigns.budget.change'))

  const query = useQuery({
    queryKey: ['agent-proposals', currentProjectId],
    queryFn: () => listProposals(currentProjectId!),
    enabled: Boolean(currentProjectId),
    retry: false,
  })

  const decide = useMutation({
    mutationFn: ({ id, decision }: { id: string; decision: 'approve' | 'reject' }) => decideProposal(currentProjectId!, id, decision),
    onSettled: () => void qc.invalidateQueries({ queryKey: ['agent-proposals'] }),
  })

  const title = ar ? 'طلبات الموافقة' : 'Approvals'
  const purpose = ar
    ? 'ما يريد الوكلاء تغييره في الحملات — لا يُنفَّذ شيء قبل موافقة شخص مخوَّل، وتُعاد فحص القواعد لحظة القرار.'
    : 'What agents want changed in campaigns — nothing runs before an authorised person approves, and the rules are checked again at the moment of decision.'

  if (!currentProjectId) {
    return (
      <div className="space-y-6">
        <PageIntro title={title} purpose={purpose} />
        <ProjectChooser purpose="recommendations" />
      </div>
    )
  }

  const rows = query.data?.proposals ?? []
  const waiting = rows.filter((r) => r.status === 'pending')
  const failure = decide.isError ? toApiError(decide.error).message : null

  return (
    <div className="space-y-4" data-testid="approvals-page">
      <PageIntro
        title={title}
        purpose={purpose}
        badges={waiting.length > 0 ? <Badge tone="warning">{ar ? `بانتظار القرار: ${waiting.length}` : `Waiting: ${waiting.length}`}</Badge> : undefined}
      />

      {query.data && (
        <p className="flex items-center gap-2 text-xs text-text-secondary" data-testid="approvals-limits">
          <ShieldCheck size={14} aria-hidden />
          {ar
            ? `سقف الزيادة لمرة واحدة ${query.data.limits.max_increase_percent}% · ${query.data.limits.daily_budget_ceiling === null ? 'بلا سقف يومي مطلق' : `السقف اليومي ${query.data.limits.daily_budget_ceiling}`} · لا حذف أبدًا`
            : `Single raise capped at ${query.data.limits.max_increase_percent}% · ${query.data.limits.daily_budget_ceiling === null ? 'no absolute daily ceiling' : `daily ceiling ${query.data.limits.daily_budget_ceiling}`} · never a delete`}
        </p>
      )}

      {failure && <p role="alert" data-testid="approvals-error" className="rounded-xl bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">{failure}</p>}

      {query.isLoading && <Skeleton className="h-40" />}
      {query.isError && <p className="text-sm text-danger">{ar ? 'تعذّر تحميل الطلبات.' : 'The requests could not be loaded.'}</p>}
      {query.data && rows.length === 0 && (
        <EmptyState title={ar ? 'لا طلبات بعد' : 'Nothing has been requested yet'} description={ar ? 'عندما يقترح وكيل أو زميل تغييرًا يظهر هنا لاعتماده أو رفضه.' : 'When an agent or a colleague proposes a change it appears here to approve or reject.'} />
      )}

      <ul className="grid gap-3" data-testid="approvals-list">
        {rows.map((p) => (
          <li key={p.id}>
            <ProposalCard
              proposal={p}
              ar={ar}
              canDecide={canDecide}
              pending={decide.isPending}
              onDecide={(decision) => decide.mutate({ id: p.id, decision })}
            />
          </li>
        ))}
      </ul>

      <AutonomyCard projectId={currentProjectId} ar={ar} />

      <p className="text-xs text-text-muted">
        <Link to={portalTo('/recommendations')} className="font-semibold text-brand-700 hover:underline">{ar ? 'العودة إلى التوصيات' : 'Back to recommendations'}</Link>
      </p>
    </div>
  )
}

function ProposalCard({ proposal: p, ar, canDecide, pending, onDecide }: {
  proposal: Proposal
  ar: boolean
  canDecide: boolean
  pending: boolean
  onDecide: (decision: 'approve' | 'reject') => void
}) {
  const word = STATUS_WORDS[p.status]

  return (
    <Card className="space-y-2" data-testid={`proposal-${p.id}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-sm font-bold text-text-primary">{actionText(p, ar)}</p>
          <p className="text-xs text-text-secondary" dir="ltr">{p.provider} · {p.target_type} · {p.target_external_id}</p>
        </div>
        <Badge tone={word.tone}>{ar ? word.ar : word.en}</Badge>
      </div>

      <p className="text-sm text-text-secondary">{p.reason}</p>
      <p className="text-[11px] text-text-muted">{ar ? 'من' : 'From'}: {p.agent === 'person' ? (ar ? 'زميل' : 'a colleague') : p.agent}</p>

      {p.violations.length > 0 && (
        <ul className="space-y-1 text-xs text-danger" data-testid={`proposal-${p.id}-violations`}>
          {p.violations.map((v) => <li key={v}>{violationText(v, ar)}</li>)}
        </ul>
      )}

      {p.status === 'pending' && canDecide && (
        <div className="flex flex-wrap gap-2 pt-1">
          <Button size="sm" disabled={pending} onClick={() => onDecide('approve')} data-testid={`proposal-${p.id}-approve`}>
            <Check size={14} /> {ar ? 'اعتماد' : 'Approve'}
          </Button>
          <Button size="sm" variant="secondary" disabled={pending} onClick={() => onDecide('reject')} data-testid={`proposal-${p.id}-reject`}>
            <X size={14} /> {ar ? 'رفض' : 'Reject'}
          </Button>
        </div>
      )}
    </Card>
  )
}
