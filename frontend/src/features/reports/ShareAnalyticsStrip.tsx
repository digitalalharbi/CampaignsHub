import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Num } from '@/components/ui/Num'
import { ChartCard, RankingBarChart } from '@/features/analytics/charts'
import { useUi } from '@/stores/ui'
import { shareAnalytics, type ShareGranularity } from './api'

const stamp = (iso: string | null, ar: boolean): string => {
  if (iso === null) return '—'
  const d = new Date(iso)

  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
}

/** A bucket start as a short label: «10-14» for a day or a week, «2026-10» for a month. */
const bucketLabel = (period: string, granularity: ShareGranularity): string => (granularity === 'month' ? period.slice(0, 7) : period.slice(5))

const GRANULARITIES: Array<{ id: ShareGranularity; ar: string; en: string }> = [
  { id: 'day', ar: 'يومي', en: 'Daily' },
  { id: 'week', ar: 'أسبوعي', en: 'Weekly' },
  { id: 'month', ar: 'شهري', en: 'Monthly' },
]

/**
 * REPORT-LINK-ANALYTICS-001/002 — what the Owner learns about one shared link: how often it was opened, by how
 * many DEVICES (an estimate of people, said as such), how many opens were repeats, when it was last opened, and the
 * trend. Agency-only: the public payload carries none of it, and the server draws no address into it.
 */
export function ShareAnalyticsStrip({ projectId, reportId, shareId }: { projectId: string; reportId: string; shareId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [granularity, setGranularity] = useState<ShareGranularity>('day')
  const q = useQuery({
    queryKey: ['share-analytics', projectId, reportId, shareId, granularity],
    queryFn: () => shareAnalytics(projectId, reportId, shareId, granularity),
    retry: false,
    placeholderData: (previous) => previous,
  })

  if (q.isLoading) return <div className="mb-3 h-16 animate-pulse rounded-xl bg-surface-secondary" />
  if (q.isError || !q.data) return null

  const a = q.data
  const items: Array<[string, string]> = [
    [ar ? 'مرات الفتح' : 'Views', String(a.views)],
    [ar ? 'أجهزة فريدة (تقديري)' : 'Unique devices (estimate)', String(a.unique_views)],
    ...(a.repeat_visits === undefined ? [] : [[ar ? 'فتحات متكررة' : 'Repeat opens', String(a.repeat_visits)] as [string, string]]),
    [ar ? 'أول فتح' : 'First viewed', stamp(a.first_viewed_at, ar)],
    [ar ? 'آخر فتح' : 'Last viewed', stamp(a.last_viewed_at, ar)],
  ]
  const series = a.series ?? []
  const drawn = series.some((p) => p.views > 0)

  return (
    <div className="mb-3 space-y-3">
      <dl data-testid="share-analytics" className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        {items.map(([label, value]) => (
          <div key={label} className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <dt className="text-[11px] text-text-muted">{label}</dt>
            <dd className="mt-0.5 text-sm font-bold text-text-primary"><Num>{value}</Num></dd>
          </div>
        ))}
      </dl>

      {drawn && (
        <ChartCard
          title={ar ? 'الفتح عبر الزمن' : 'Opens over time'}
          subtitle={ar ? 'فتحات بشرية فقط — معاينات الروابط والزواحف مستبعدة' : 'Human opens only — link previews and crawlers are left out'}
          action={(
            <div role="group" aria-label={ar ? 'الدقّة الزمنية' : 'Time grain'} className="inline-flex rounded-lg border border-border p-0.5">
              {GRANULARITIES.map((g) => (
                <button
                  key={g.id}
                  type="button"
                  data-testid={`share-grain-${g.id}`}
                  aria-pressed={granularity === g.id}
                  onClick={() => setGranularity(g.id)}
                  className={`rounded-md px-2.5 py-1 text-xs font-bold ${granularity === g.id ? 'bg-brand-600 text-white' : 'text-text-secondary'}`}
                >
                  {ar ? g.ar : g.en}
                </button>
              ))}
            </div>
          )}
        >
          <div data-testid="share-trend">
            <RankingBarChart
              height={200}
              data={series.map((p) => ({ label: bucketLabel(p.period, granularity), views: p.views }))}
              bars={[{ key: 'views', name: ar ? 'مرات الفتح' : 'Views' }]}
            />
          </div>
          {(a.bot_opens ?? 0) > 0 && (
            <p data-testid="share-bot-note" className="mt-2 text-xs text-text-muted">
              {ar ? `استُبعد ${a.bot_opens} فتحًا آليًا (معاينات وزواحف).` : `${a.bot_opens} automated open${a.bot_opens === 1 ? '' : 's'} left out (previews and crawlers).`}
            </p>
          )}
        </ChartCard>
      )}
    </div>
  )
}
