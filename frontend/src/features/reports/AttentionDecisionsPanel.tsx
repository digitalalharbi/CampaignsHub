import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Skeleton } from '@/components/ui/States'
import { AttentionBlocks } from './AttentionBlocks'
import type { AttentionItem } from './attention'
import { decideAttention, fetchAttention } from './api'

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the operator approves or hides each attention item for clients.
 *
 * Every item for the report's window, including the operator-internal ones a client never receives,
 * each with its current decision. A decision belongs to THIS report and period: the server applies it
 * to this report's link, snapshot and PDF without regenerating, and never to another period.
 */
export function AttentionDecisionsPanel({ projectId, reportId, ar }: { projectId: string; reportId: string; ar: boolean }) {
  const qc = useQueryClient()
  const key = ['report-attention', projectId, reportId]
  const q = useQuery({ queryKey: key, queryFn: () => fetchAttention(projectId, reportId) })
  const decide = useMutation({
    mutationFn: (v: { item: AttentionItem; decision: 'approved' | 'hidden' | null }) => decideAttention(projectId, reportId, v.item.key, v.decision),
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  })

  if (q.isLoading) return <Skeleton className="h-24" />
  if (q.isError) {
    return (
      <p className="rounded-xl border border-danger/40 p-3 text-sm text-danger" data-testid="attention-panel-failed">
        {ar ? 'تعذّر تحميل ما يحتاج انتباهًا.' : 'Could not load what needs attention.'}
      </p>
    )
  }
  const items = q.data?.items ?? []
  if (items.length === 0) return null

  return (
    <div className="rounded-2xl border border-border bg-surface-secondary p-4" data-testid="attention-panel">
      <p className="mb-3 text-xs text-text-muted">
        {ar
          ? 'البنود الداخلية لا تصل إلى العميل إلا باعتمادك، والبنود المخفية لا تصل أبدًا — على الرابط والنسخة والملف. القرار لهذا التقرير وفترته فقط.'
          : 'Internal items reach a client only when you approve them, and hidden items never do — on the link, the snapshot and the file. A decision applies to this report and period only.'}
      </p>
      <AttentionBlocks
        items={items}
        ar={ar}
        onDecide={(item, decision) => decide.mutate({ item, decision })}
        busyKey={decide.isPending ? decide.variables?.item.key : null}
      />
      {decide.isError && (
        <p className="mt-2 text-xs text-danger" role="alert">{ar ? 'لم يُحفظ القرار.' : 'The decision was not saved.'}</p>
      )}
    </div>
  )
}
