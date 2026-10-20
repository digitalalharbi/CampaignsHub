import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { Num } from '@/components/ui/Num'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ChartCard, MetricLineChart, PlatformDonutChart } from '@/features/analytics/charts'
import { useUi } from '@/stores/ui'
import { DataState } from './DataState'

export interface TrackingSummary {
  state: 'no_events' | 'measured'
  period: { from: string; to: string; timezone: string }
  tracking_since: string | null
  opens: number
  /** The agency's diagnostics below are absent from what a client link receives. */
  machine_opens?: number
  series: Array<{ period: string; opens: number; devices?: number }>
  by_type?: Record<string, number>
  by_destination: Record<string, number>
  top_links?: Array<{ id: string; slug: string | null; destination_host: string | null; opens: number }>
  basis: { counts: string; unique_basis?: string; includes_sales: boolean }
}

const DESTINATIONS: Record<string, { en: string; ar: string }> = {
  whatsapp: { en: 'WhatsApp', ar: 'واتساب' },
  external_url: { en: 'External site', ar: 'موقع خارجي' },
  internal_report: { en: 'Report', ar: 'تقرير' },
  store: { en: 'Store', ar: 'المتجر' },
  unknown: { en: 'Other', ar: 'أخرى' },
}

/**
 * WIDGET-REGISTRY-001 — recorded link activity: how often tracked links were opened, by day, and where they led.
 *
 * What it will not do, on purpose: it draws no sales and no «visitors». An open is an open; a click is not a purchase,
 * and the only per-day device figure the data supports is an estimate that is never added across days. Machine opens
 * (previews, crawlers) are counted apart and stated. The window starts where measurement started, and says so.
 */
export function LinkActivityWidget({ projectId, range }: { projectId: string; range: { from: string; to: string } }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const q = useQuery({
    queryKey: ['tracking-summary', projectId, range.from, range.to],
    queryFn: () => getData<TrackingSummary>(`/projects/${projectId}/tracking/summary?from=${range.from}&to=${range.to}`),
    retry: false,
  })

  if (q.isLoading) return <ChartCard title={ar ? 'نشاط الروابط' : 'Link activity'}><Skeleton className="h-48" /></ChartCard>
  if (q.isError || !q.data) {
    return (
      <ChartCard title={ar ? 'نشاط الروابط' : 'Link activity'}>
        <EmptyState title={ar ? 'تعذّر تحميل نشاط الروابط' : 'Link activity could not be loaded'} />
      </ChartCard>
    )
  }

  return <LinkActivityView summary={q.data} />
}

/**
 * The drawing, given its data. The agency widget and the client report both use it; `client` removes what is the
 * agency's alone (machine opens, the per-day device note, the per-link list).
 */
export function LinkActivityView({ summary: s, client = false }: { summary: TrackingSummary; client?: boolean }) {
  const ar = useUi((u) => u.locale) === 'ar'
  const title = ar ? 'نشاط الروابط' : 'Link activity'
  const empty = s.state === 'no_events'
  const top = s.top_links ?? []

  return (
    <ChartCard
      title={title}
      subtitle={empty
        ? (ar ? 'لم يُسجَّل أي فتح بعد' : 'No opens recorded yet')
        : (ar ? `يُقاس منذ ${s.tracking_since} — فتحات الروابط، وليست مبيعات` : `Measured since ${s.tracking_since} — link opens, not sales`)}
      action={<DataState kind={empty ? 'missing' : 'measured'} testId="link-activity-state" />}
    >
      {empty
        ? (
          <EmptyState
            title={ar ? 'لا يوجد نشاط مسجَّل' : 'No recorded activity'}
            description={ar ? 'سيظهر هنا بعد أول فتح لرابط مُتتبَّع. الأيام التي سبقت تفعيل التتبع لم تُقَس.' : 'This fills in after the first open of a tracked link. Days before tracking was switched on were not measured.'}
          />
        )
        : (
          <div className="space-y-4" data-testid="link-activity">
            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
                <dt className="text-[11px] text-text-muted">{client ? (ar ? 'مرات فتح الروابط' : 'Link opens') : (ar ? 'فتحات بشرية' : 'Human opens')}</dt>
                <dd className="mt-0.5 text-lg font-bold text-text-primary" data-testid="link-activity-opens"><Num>{String(s.opens)}</Num></dd>
              </div>
              {!client && s.machine_opens !== undefined && (
                <>
                  <div className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
                    <dt className="text-[11px] text-text-muted">{ar ? 'فتحات آلية مستبعدة' : 'Machine opens left out'}</dt>
                    <dd className="mt-0.5 text-lg font-bold text-text-primary" data-testid="link-activity-machines"><Num>{String(s.machine_opens)}</Num></dd>
                  </div>
                  <div className="col-span-2 rounded-xl border border-border bg-surface-secondary px-3 py-2 sm:col-span-1">
                    <dt className="text-[11px] text-text-muted">{ar ? 'أجهزة يوميًا (تقديري)' : 'Devices per day (estimate)'}</dt>
                    <dd className="mt-0.5 text-sm text-text-secondary">{ar ? 'لا يُجمع عبر الأيام' : 'never added across days'}</dd>
                  </div>
                </>
              )}
            </dl>

            <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
              <div data-testid="link-activity-trend">
                <MetricLineChart
                  height={220}
                  data={s.series.map((p) => ({ date: p.period, opens: p.opens }))}
                  series={[{ key: 'opens', name: ar ? 'الفتحات' : 'Opens' }]}
                />
              </div>
              <div data-testid="link-activity-destinations">
                <PlatformDonutChart
                  height={220}
                  colorBy="series"
                  centerValue={String(s.opens)}
                  centerLabel={ar ? 'فتحة' : 'opens'}
                  data={Object.entries(s.by_destination).map(([k, v]) => ({ key: k, name: DESTINATIONS[k]?.[ar ? 'ar' : 'en'] ?? k, value: v }))}
                />
              </div>
            </div>

            {!client && top.length > 0 && (
              <ol className="space-y-1" data-testid="link-activity-top">
                {top.slice(0, 5).map((l) => (
                  <li key={l.id} className="flex items-center justify-between gap-3 rounded-lg px-2 py-1 text-sm">
                    <span className="min-w-0 truncate text-text-primary" dir="ltr">/l/{l.slug ?? '—'} <span className="text-text-muted">→ {l.destination_host ?? '—'}</span></span>
                    <span className="shrink-0 font-bold"><Num>{String(l.opens)}</Num></span>
                  </li>
                ))}
              </ol>
            )}
          </div>
        )}
    </ChartCard>
  )
}
