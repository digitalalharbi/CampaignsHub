import { providerLabel } from '@/features/campaigns/labels'
import { platformColor } from '@/features/analytics/components'
import { canonicalPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import {
  drawableFamilies,
  formatKpi,
  formatRankingValue,
  rankingMetricLabel,
  unavailableNote,
  type ObjectiveAnalytics,
  type ObjectiveFamilyBlock,
  type ObjectiveRanking,
  type RankingEnd,
} from './objectiveAnalytics'

/*
 * REPORT-OBJECTIVE-ANALYTICS-001 — the objective section, visual first.
 *
 * One card per objective family the scope ran: its KPI tiles, the strongest and weakest platform and
 * content on the family's own metric, and each platform's share of the family's outcome. The trend is
 * behind a disclosure — simple by default, deeper on request.
 *
 * Every figure is the server's. A family never appears beside a blended figure, a ranking with too little
 * volume behind it is simply not drawn, and a family with nothing reported draws no card at all.
 * Platform → content only: no campaign is named here, because none is in the payload.
 */

interface Props {
  section: ObjectiveAnalytics | null | undefined
  currency: string | null | undefined
  ar: boolean
  /** False when the link does not publish content: the content leaders are then not drawn. */
  showContent?: boolean
}

export function ObjectiveAnalyticsSection({ section, currency, ar, showContent = true }: Props) {
  const families = drawableFamilies(section)
  if (families.length === 0) return null

  return (
    <section data-testid="objective-analytics" className="flex flex-col gap-4">
      <h3 className="text-base font-bold tracking-tight text-text-primary">
        {ar ? 'الأداء حسب الهدف' : 'Performance by objective'}
      </h3>
      {families.map((block) => (
        <FamilyCard key={block.family} block={block} currency={currency} ar={ar} showContent={showContent} />
      ))}
    </section>
  )
}

function FamilyCard({ block, currency, ar, showContent }: { block: ObjectiveFamilyBlock; currency: Props['currency']; ar: boolean; showContent: boolean }) {
  const spend = block.kpis.find((k) => k.key === 'spend')
  const tiles = block.kpis.filter((k) => k.key !== 'spend')
  const contentRanking = showContent ? block.content_ranking : null

  return (
    <div data-testid={`objective-family-${block.family}`} className="rounded-2xl border border-border bg-surface p-4">
      <div className="mb-3 flex items-baseline justify-between gap-2">
        <span className="font-bold text-text-primary">{ar ? block.label_ar : block.label_en}</span>
        {spend && (
          <span className="text-xs text-text-muted">
            {ar ? spend.label_ar : spend.label_en} <Num className="tnum font-semibold text-text-primary">{formatKpi(spend, currency)}</Num>
          </span>
        )}
      </div>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        {tiles.map((kpi) => {
          const missing = kpi.state === 'reported' ? (kpi.not_reported_by ?? []) : []

          return (
            <div
              key={kpi.key}
              data-testid={`objective-kpi-${block.family}-${kpi.key}`}
              data-state={kpi.state}
              className="min-w-0 rounded-xl bg-surface-secondary p-3"
              title={kpi.state === 'reported' ? undefined : unavailableNote(kpi.reason, ar)}
            >
              <div className="truncate text-[11px] font-medium text-text-muted">{ar ? kpi.label_ar : kpi.label_en}</div>
              <div className="tnum mt-1 text-lg font-extrabold text-text-primary"><Num>{formatKpi(kpi, currency)}</Num></div>
              {missing.length > 0 && (
                <div className="mt-0.5 truncate text-[10px] text-text-muted">
                  {ar ? 'دون ' : 'Excl. '}
                  {missing.map((p) => providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')).join('، ')}
                </div>
              )}
            </div>
          )
        })}
      </div>

      {(hasEnds(block.platform_ranking) || hasEnds(contentRanking)) && (
        <div className={`mt-3 grid gap-3 ${hasEnds(block.platform_ranking) && hasEnds(contentRanking) ? 'md:grid-cols-2' : ''}`}>
          {hasEnds(block.platform_ranking) && (
            <Leaders testid={`objective-platform-leaders-${block.family}`} title={ar ? 'المنصات' : 'Platforms'} ranking={block.platform_ranking} currency={currency} ar={ar} />
          )}
          {hasEnds(contentRanking) && (
            <Leaders testid={`objective-content-leaders-${block.family}`} title={ar ? 'المحتوى' : 'Content'} ranking={contentRanking!} currency={currency} ar={ar} content />
          )}
        </div>
      )}

      {block.contribution && block.contribution.rows.some((r) => r.share !== null) && (
        <div data-testid={`objective-contribution-${block.family}`} className="mt-3">
          <div className="mb-1.5 text-[11px] font-medium text-text-muted">
            {ar ? `المساهمة في ${block.contribution.label_ar}` : `Share of ${block.contribution.label_en.toLowerCase()}`}
          </div>
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-surface-secondary" role="img" aria-label={ar ? 'المساهمة حسب المنصة' : 'Contribution by platform'}>
            {block.contribution.rows.filter((r) => (r.share ?? 0) > 0).map((r) => (
              <span key={r.provider} style={{ width: `${(r.share ?? 0) * 100}%`, background: platformColor(canonicalPlatform(r.provider)) }} />
            ))}
          </div>
          <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-text-secondary">
            {block.contribution.rows.map((r) => (
              <span key={r.provider} className="inline-flex items-center gap-1.5">
                <span className="inline-block h-2 w-2 rounded-full" style={{ background: platformColor(canonicalPlatform(r.provider)) }} aria-hidden />
                {providerLabel(canonicalPlatform(r.provider), ar ? 'ar' : 'en')}
                <Num className="tnum font-semibold text-text-primary">{r.share === null ? '—' : `${(r.share * 100).toFixed(1)}%`}</Num>
              </span>
            ))}
          </div>
        </div>
      )}

      {block.trend?.metric && block.trend.points.filter((p) => p.value !== null).length > 1 && (
        <details className="mt-3">
          <summary className="cursor-pointer text-[11px] font-semibold text-text-secondary">
            {ar ? `الاتجاه اليومي — ${rankingMetricLabel(block.trend.metric, true)}` : `Daily trend — ${rankingMetricLabel(block.trend.metric, false)}`}
          </summary>
          <Sparkline values={block.trend.points.map((p) => p.value)} testid={`objective-trend-${block.family}`} />
        </details>
      )}
    </div>
  )
}

function hasEnds(ranking: ObjectiveRanking | null | undefined): ranking is ObjectiveRanking {
  return !!ranking && !!ranking.best && !!ranking.weakest
}

function Leaders({ testid, title, ranking, currency, ar, content = false }: { testid: string; title: string; ranking: ObjectiveRanking; currency: Props['currency']; ar: boolean; content?: boolean }) {
  const name = (end: RankingEnd) => {
    const platform = providerLabel(canonicalPlatform(end.provider ?? ''), ar ? 'ar' : 'en')

    return content ? { primary: end.name || (ar ? 'محتوى' : 'Content'), secondary: platform } : { primary: platform, secondary: null }
  }

  return (
    <div data-testid={testid} className="rounded-xl border border-border p-3">
      <div className="mb-2 flex items-baseline justify-between gap-2">
        <span className="text-xs font-semibold text-text-primary">{title}</span>
        <span className="text-[11px] text-text-muted">{rankingMetricLabel(ranking.metric, ar)}</span>
      </div>
      <div className="grid grid-cols-2 gap-2">
        {([['best', ranking.best!, 'text-success', '▲'], ['weakest', ranking.weakest!, 'text-danger', '▼']] as const).map(([kind, end, tone, mark]) => {
          const label = name(end)

          return (
            <div key={kind} data-testid={`${testid}-${kind}`} className="min-w-0 rounded-lg bg-surface-secondary p-2.5">
              <div className={`text-[11px] font-semibold ${tone}`}>
                <span aria-hidden>{mark} </span>{kind === 'best' ? (ar ? 'الأقوى' : 'Strongest') : (ar ? 'الأضعف' : 'Weakest')}
              </div>
              <div className="mt-1 flex items-center gap-1.5 truncate text-sm font-bold text-text-primary">
                <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: platformColor(canonicalPlatform(end.provider ?? '')) }} aria-hidden />
                <span className="truncate">{label.primary}</span>
              </div>
              {label.secondary && <div className="truncate text-[10px] text-text-muted">{label.secondary}</div>}
              <div className="tnum mt-1 text-base font-extrabold text-text-primary"><Num>{formatRankingValue(ranking.metric, end.value, currency)}</Num></div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** Gaps stay gaps: a day nobody reported breaks the line rather than being drawn through. */
function Sparkline({ values, testid }: { values: (number | null)[]; testid: string }) {
  const present = values.filter((v): v is number => v !== null)
  const min = Math.min(...present)
  const max = Math.max(...present)
  const span = max - min || 1
  const w = 300
  const h = 48
  const x = (i: number) => (values.length > 1 ? (i / (values.length - 1)) * w : 0)
  const y = (v: number) => h - 4 - ((v - min) / span) * (h - 8)

  const segments: string[] = []
  let current: string[] = []
  values.forEach((v, i) => {
    if (v === null) {
      if (current.length > 0) segments.push(current.join(' '))
      current = []
    } else {
      current.push(`${x(i).toFixed(1)},${y(v).toFixed(1)}`)
    }
  })
  if (current.length > 0) segments.push(current.join(' '))

  return (
    <svg data-testid={testid} viewBox={`0 0 ${w} ${h}`} className="mt-2 h-12 w-full" preserveAspectRatio="none" aria-hidden>
      {segments.map((points, i) => (
        points.includes(' ')
          ? <polyline key={i} points={points} fill="none" stroke="var(--brand-500)" strokeWidth={2} vectorEffect="non-scaling-stroke" />
          : <circle key={i} cx={points.split(',')[0]} cy={points.split(',')[1]} r={2} fill="var(--brand-500)" />
      ))}
    </svg>
  )
}
