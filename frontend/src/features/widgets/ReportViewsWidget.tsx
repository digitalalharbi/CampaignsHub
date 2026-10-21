import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { Num } from '@/components/ui/Num'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ChartCard, MetricLineChart } from '@/features/analytics/charts'
import { useUi } from '@/stores/ui'
import { DataState } from './DataState'

export interface ReportViewsSummary {
  state: 'no_views' | 'measured'
  period: { from: string; to: string; timezone: string }
  opens: number
  machine_opens: number
  last_opened_at: string | null
  series: Array<{ period: string; opens: number }>
  top_reports: Array<{ id: string; name: string; opens: number; last_opened_at: string }>
  basis: { counts: string; machines_excluded: boolean }
}

const stamp = (iso: string | null, ar: boolean): string => {
  if (iso === null) return '—'
  const d = new Date(iso)

  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { year: 'numeric', month: '2-digit', day: '2-digit' })
}

/**
 * WIDGET-REGISTRY-001 — how often this project's shared report links were opened, across all of them.
 *
 * Opens, never people: one person on two devices is two opens, and a forwarded link is opened by someone the agency
 * never named. Opens by something that announced itself as a machine (a link preview, a crawler) are counted apart
 * and left out. Agency-only; a client's page never receives it.
 */
export function ReportViewsWidget({ projectId, range }: { projectId: string; range: { from: string; to: string } }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const title = ar ? 'فتح روابط التقارير' : 'Report link opens'
  const q = useQuery({
    queryKey: ['report-views', projectId, range.from, range.to],
    queryFn: () => getData<ReportViewsSummary>(`/projects/${projectId}/report-views/summary?from=${range.from}&to=${range.to}`),
    retry: false,
  })

  if (q.isLoading) return <ChartCard title={title}><Skeleton className="h-48" /></ChartCard>
  if (q.isError || !q.data) return <ChartCard title={title}><EmptyState title={ar ? 'تعذّر تحميل فتحات الروابط' : 'Link opens could not be loaded'} /></ChartCard>

  const s = q.data
  const empty = s.state === 'no_views'

  return (
    <ChartCard
      title={title}
      subtitle={ar ? 'فتحات وليست أشخاصًا — المعاينات والزواحف مستبعدة' : 'Opens, not people — previews and crawlers are left out'}
      action={<DataState kind={empty ? 'missing' : 'measured'} testId="report-views-state" />}
    >
      {empty
        ? <EmptyState title={ar ? 'لم تُفتح أي روابط بعد' : 'No report link has been opened yet'} description={ar ? 'سيظهر هنا بعد أول فتح لرابط مشاركة.' : 'This fills in after the first open of a shared link.'} />
        : (
          <div className="space-y-4" data-testid="report-views">
            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
                <dt className="text-[11px] text-text-muted">{ar ? 'فتحات' : 'Opens'}</dt>
                <dd className="mt-0.5 text-lg font-bold text-text-primary" data-testid="report-views-opens"><Num>{String(s.opens)}</Num></dd>
              </div>
              <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
                <dt className="text-[11px] text-text-muted">{ar ? 'فتحات آلية مستبعدة' : 'Machine opens left out'}</dt>
                <dd className="mt-0.5 text-lg font-bold text-text-primary" data-testid="report-views-machines"><Num>{String(s.machine_opens)}</Num></dd>
              </div>
              <div className="col-span-2 rounded-xl border border-border bg-surface-secondary px-3 py-2 sm:col-span-1">
                <dt className="text-[11px] text-text-muted">{ar ? 'آخر فتح' : 'Last opened'}</dt>
                <dd className="mt-0.5 text-sm font-bold text-text-primary">{stamp(s.last_opened_at, ar)}</dd>
              </div>
            </dl>
            <div data-testid="report-views-trend">
              <MetricLineChart height={200} data={s.series.map((p) => ({ date: p.period, opens: p.opens }))} series={[{ key: 'opens', name: ar ? 'الفتحات' : 'Opens' }]} />
            </div>
            {s.top_reports.length > 0 && (
              <ol className="space-y-1" data-testid="report-views-top">
                {s.top_reports.map((r) => (
                  <li key={r.id} className="flex items-center justify-between gap-3 rounded-lg px-2 py-1 text-sm">
                    <span className="min-w-0 truncate text-text-primary">{r.name}</span>
                    <span className="shrink-0 font-bold"><Num>{String(r.opens)}</Num></span>
                  </li>
                ))}
              </ol>
            )}
          </div>
        )}
    </ChartCard>
  )
}
