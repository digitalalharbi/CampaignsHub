import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Badge } from '@/components/ui/Badge'
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

const STATE: Record<'active' | 'paused' | 'revoked' | 'expired', { ar: string; en: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
  active: { ar: 'الرابط فعّال', en: 'Link active', tone: 'success' },
  paused: { ar: 'الرابط متوقف', en: 'Link paused', tone: 'warning' },
  revoked: { ar: 'الرابط ملغى', en: 'Link revoked', tone: 'danger' },
  expired: { ar: 'الرابط منتهٍ', en: 'Link expired', tone: 'neutral' },
}

/** «+50%» / «−20%» / «—» when the earlier window had nothing to compare with. */
const delta = (now: number, before: number): string => {
  if (before === 0) return now === 0 ? '—' : '+∞'
  const pct = Math.round(((now - before) / before) * 100)

  return `${pct > 0 ? '+' : pct < 0 ? '−' : ''}${Math.abs(pct)}%`
}

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
    [ar ? 'التنزيلات' : 'Downloads', String(a.downloads)],
    [ar ? 'أول فتح' : 'First viewed', stamp(a.first_viewed_at, ar)],
    [ar ? 'آخر فتح' : 'Last viewed', stamp(a.last_viewed_at, ar)],
  ]
  const state = a.state ? STATE[a.state] : null
  const cmp = a.comparison
  const series = a.series ?? []
  const drawn = series.some((p) => p.views > 0)

  return (
    <div className="mb-3 space-y-3">
      {state && (
        <p className="flex flex-wrap items-center gap-2 text-xs text-text-secondary" data-testid="share-state" data-state={a.state}>
          <Badge tone={state.tone}>{ar ? state.ar : state.en}</Badge>
          {a.state_since && <span>{ar ? `منذ ${stamp(a.state_since, ar)}` : `since ${stamp(a.state_since, ar)}`}</span>}
          {a.state === 'paused' && <span>{ar ? '— لا تُحتسب فتحات أثناء التوقف؛ الرقم أدناه يسبق التوقف.' : '— no opens are counted while paused; the figures below predate it.'}</span>}
        </p>
      )}
      <dl data-testid="share-analytics" className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        {items.map(([label, value]) => (
          <div key={label} className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
            <dt className="text-[11px] text-text-muted">{label}</dt>
            <dd className="mt-0.5 text-sm font-bold text-text-primary"><Num>{value}</Num></dd>
          </div>
        ))}
      </dl>

      {cmp && (
        <dl data-testid="share-comparison" className="grid grid-cols-3 gap-2 rounded-xl border border-border px-3 py-2 text-xs">
          <div className="col-span-3 text-[11px] text-text-muted">
            {ar
              ? `مقارنة بالفترة السابقة: ${cmp.current.from} → ${cmp.current.to} مقابل ${cmp.previous.from} → ${cmp.previous.to}`
              : `Against the period before: ${cmp.current.from} → ${cmp.current.to} vs ${cmp.previous.from} → ${cmp.previous.to}`}
          </div>
          {([['views', ar ? 'مرات الفتح' : 'Views'], ['devices', ar ? 'أجهزة' : 'Devices'], ['downloads', ar ? 'التنزيلات' : 'Downloads']] as const).map(([key, label]) => (
            <div key={key} data-testid={`share-compare-${key}`}>
              <dt className="text-text-muted">{label}</dt>
              <dd className="font-bold text-text-primary"><Num>{String(cmp.current[key])}</Num> <span className="font-normal text-text-secondary"><Num>{delta(cmp.current[key], cmp.previous[key])}</Num></span> <span className="font-normal text-text-muted">({ar ? 'سابقًا' : 'before'} <Num>{String(cmp.previous[key])}</Num>)</span></dd>
            </div>
          ))}
        </dl>
      )}

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
