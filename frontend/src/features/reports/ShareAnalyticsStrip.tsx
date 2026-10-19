import { useQuery } from '@tanstack/react-query'
import { Num } from '@/components/ui/Num'
import { useUi } from '@/stores/ui'
import { shareAnalytics } from './api'

/**
 * REPORT-LINK-ANALYTICS-001 — how often this client link was opened, for the Owner only.
 *
 * Four facts and nothing that identifies anybody: total openings, distinct browsers (a privacy-safe count
 * the server derives from a salted hash), the first opening and the latest. Link-preview fetches by chat apps
 * are already excluded server-side, so a pasted link is not «viewed» until a person opens it. This sits in the
 * agency's access-history dialog; the client's own report never receives it.
 */
const stamp = (iso: string | null, ar: boolean): string => {
  if (iso === null) return '—'
  const d = new Date(iso)

  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
}

export function ShareAnalyticsStrip({ projectId, reportId, shareId }: { projectId: string; reportId: string; shareId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useQuery({ queryKey: ['share-analytics', projectId, reportId, shareId], queryFn: () => shareAnalytics(projectId, reportId, shareId), retry: false })

  if (q.isLoading) return <div className="mb-3 h-16 animate-pulse rounded-xl bg-surface-secondary" />
  if (q.isError || !q.data) return null

  const a = q.data
  const items: Array<[string, string]> = [
    [ar ? 'مرات الفتح' : 'Views', String(a.views)],
    [ar ? 'زوّار فريدون' : 'Unique visitors', String(a.unique_views)],
    [ar ? 'أول فتح' : 'First viewed', stamp(a.first_viewed_at, ar)],
    [ar ? 'آخر فتح' : 'Last viewed', stamp(a.last_viewed_at, ar)],
  ]

  return (
    <dl data-testid="share-analytics" className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
      {items.map(([label, value]) => (
        <div key={label} className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
          <dt className="text-[11px] text-text-muted">{label}</dt>
          <dd className="mt-0.5 text-sm font-bold text-text-primary"><Num>{value}</Num></dd>
        </div>
      ))}
    </dl>
  )
}
