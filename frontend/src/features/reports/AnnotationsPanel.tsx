import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, EyeOff, XCircle } from 'lucide-react'
import { listAnnotations, setAnnotationStatus, type ReportAnnotation } from './api'
import { Skeleton } from '@/components/ui/States'
import { platformColor } from '@/features/analytics/components'

const STATUS_LABEL: Record<string, { label: string; cls: string }> = {
  draft: { label: 'مسودة', cls: 'bg-surface-secondary text-text-muted' },
  reviewed: { label: 'مُراجعة', cls: 'bg-[var(--info-background)] text-info' },
  approved: { label: 'معتمدة', cls: 'bg-[var(--positive-background)] text-success' },
  hidden: { label: 'مخفية', cls: 'bg-surface-secondary text-text-muted' },
  rejected: { label: 'مرفوضة', cls: 'bg-[var(--negative-background)] text-danger' },
}

/** Team-facing approval controls for a report's AI findings/recommendations. Only approved reach clients. */
export function AnnotationsPanel({ projectId, reportId }: { projectId: string; reportId: string }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['report-annotations', projectId, reportId], queryFn: () => listAnnotations(projectId, reportId) })
  const set = useMutation({
    mutationFn: (v: { annId: string; status: string }) => setAnnotationStatus(projectId, reportId, v.annId, v.status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['report-annotations', projectId, reportId] }),
  })

  if (q.isLoading) return <Skeleton className="h-24" />
  const recs = (q.data?.annotations ?? []).filter((a) => a.type === 'recommendation')
  if (recs.length === 0) return null

  return (
    <div className="rounded-2xl border border-border bg-surface-secondary p-4">
      <h4 className="mb-1 text-sm font-bold text-text-primary">اعتماد التوصيات (لا تظهر للعميل إلا المعتمدة)</h4>
      <p className="mb-3 text-xs text-text-muted">التوصيات الآلية تبدأ «مسودة» — اعتمدها ليراها العميل.</p>
      <div className="space-y-2">
        {recs.map((a: ReportAnnotation) => {
          const s = STATUS_LABEL[a.status] ?? STATUS_LABEL.draft
          return (
            <div key={a.id} className="flex items-center justify-between gap-3 rounded-xl border border-border bg-surface p-2.5">
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold text-text-primary">{a.text_ar ?? '—'}</div>
                <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-text-muted">
                  {a.platform && <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full" style={{ background: platformColor(a.platform) }} />{a.platform}</span>}
                  {a.kpi && <span>· {a.kpi}</span>}
                  <span className={`rounded-full px-1.5 py-0.5 font-semibold ${s.cls}`}>{s.label}</span>
                </div>
              </div>
              <div className="flex shrink-0 gap-1">
                <button title="اعتماد" disabled={set.isPending || a.status === 'approved'} onClick={() => set.mutate({ annId: a.id, status: 'approved' })} className="rounded-lg p-1.5 text-success hover:bg-[var(--positive-background)] disabled:opacity-40"><CheckCircle2 size={16} /></button>
                <button title="رفض" disabled={set.isPending} onClick={() => set.mutate({ annId: a.id, status: 'rejected' })} className="rounded-lg p-1.5 text-danger hover:bg-[var(--negative-background)] disabled:opacity-40"><XCircle size={16} /></button>
                <button title="إخفاء عن العميل" disabled={set.isPending} onClick={() => set.mutate({ annId: a.id, status: 'hidden' })} className="rounded-lg p-1.5 text-text-muted hover:bg-surface-hover disabled:opacity-40"><EyeOff size={16} /></button>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
