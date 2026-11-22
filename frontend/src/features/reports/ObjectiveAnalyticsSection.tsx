import { providerLabel } from '@/features/campaigns/labels'
import { ProviderMark } from '@/components/ProviderMark'
import { ProviderName } from '@/components/ProviderMark'
import { platformColor } from '@/features/analytics/components'
import { canonicalPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import { useState } from 'react'
import { TrendPill } from '@/features/analytics/components'
import { compact, money, percent } from '@/features/analytics/format'
import {
  drawableFamilies,
  formatFigure,
  formatKpi,
  formatRankingValue,
  rankingMetricLabel,
  type ObjectiveAnalytics,
  type ObjectiveFamilyBlock,
  type ObjectiveKpi,
  type ObjectiveRanking,
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
  const [chosenFamily, setChosenFamily] = useState<string | null>(null)
  if (families.length === 0) return null
  const block = families.find((f) => f.family === chosenFamily) ?? families[0]

  /*
   * OBJECTIVE-COMPACT-001 (Owner 2026-11-22: «مشكلتي بالمساحات الكبيرة… دمج… واختصار… بدل ما تكون غير متاح أو فارغة»).
   * One card for every objective: the objectives are a switch at its head, not a stack of cards. Only figures the platforms
   * reported are drawn (a partial one says which platform is missing on hover); the chosen figure reads beside a short daily
   * line and its platforms as one plain sentence each («ميتا · 2,682 USD · 58%»), so the contribution needs no separate bar.
   */
  return (
    <section data-testid="objective-analytics" className="flex min-w-0 flex-col gap-2 rounded-2xl border border-border bg-surface p-3 sm:p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-extrabold tracking-tight text-text-primary">{ar ? 'الأداء حسب الهدف' : 'Performance by objective'}</h3>
        {families.length > 1 && (
          <div role="tablist" aria-label={ar ? 'الهدف' : 'Objective'} className="inline-flex flex-wrap rounded-lg border border-border p-0.5 text-xs">
            {families.map((f) => {
              const spend = f.kpis.find((k) => k.key === 'spend' && k.state === 'reported')
              const on = f.family === block.family

              return (
                <button
                  key={f.family}
                  type="button"
                  role="tab"
                  aria-selected={on}
                  data-testid={`objective-tab-${f.family}`}
                  onClick={() => setChosenFamily(f.family)}
                  className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 font-semibold ${on ? 'bg-brand-500 text-white' : 'text-text-secondary hover:text-text-primary'}`}
                >
                  {ar ? f.label_ar : f.label_en}
                  {spend && <span className={`tnum font-medium ${on ? 'text-white/80' : 'text-text-muted'}`}><Num>{money(spend.value!, currency ?? null)}</Num></span>}
                </button>
              )
            })}
          </div>
        )}
      </div>
      <FamilyPanel key={block.family} block={block} currency={currency} ar={ar} showContent={showContent} single={families.length === 1} />
    </section>
  )
}

/** Figures where less is better: their movement colours the other way. Spend is neither: its movement is drawn neutral. */
const LOWER_IS_BETTER = new Set(['cpa', 'cpc', 'cpm', 'cpl', 'cpi', 'cpe', 'cost_per_view', 'cost_per_conversion', 'cost_per_lpv', 'cost_per_result', 'frequency'])
const NEUTRAL = new Set(['spend'])
const ADDITIVE_MONEY = new Set(['spend', 'revenue'])

function cardText(kpi: ObjectiveKpi, currency: Props['currency']): string {
  if (kpi.kind === 'money') return money(kpi.value!, currency ?? null)
  if (kpi.kind === 'count') return compact(kpi.value!)

  return formatKpi(kpi, currency)
}

/** A daily series only where the server sent one for that very figure — never borrowed from another. */
function seriesFor(key: string, trend: ObjectiveFamilyBlock['trend']): { date: string; value: number | null }[] | null {
  if (!trend) return null
  const pick = key === 'spend' ? 'spend' : key === trend.outcome ? 'outcome' : key === trend.metric ? 'value' : null
  if (!pick) return null
  const points = trend.points.map((p) => ({ date: p.date, value: typeof p[pick] === 'number' ? (p[pick] as number) : null }))

  return points.filter((p) => p.value !== null).length > 1 ? points : null
}

function Movement({ kpi }: { kpi: ObjectiveKpi }) {
  if (kpi.delta === undefined || kpi.delta === null) return null
  if (NEUTRAL.has(kpi.key)) {
    return (
      <span className="tnum inline-flex items-center rounded-full bg-surface-secondary px-1.5 py-0.5 text-xs font-semibold text-text-secondary">
        <Num>{`${kpi.delta > 0 ? '+' : kpi.delta < 0 ? '−' : ''}${percent(Math.abs(kpi.delta), 0)}`}</Num>
      </span>
    )
  }

  return <TrendPill delta={kpi.delta} invertGood={LOWER_IS_BETTER.has(kpi.key)} />
}

/** The figure the family is read by first: its outcome, then its trend metric, then the first reported figure after spend. */
function leadKey(block: ObjectiveFamilyBlock, reported: ObjectiveKpi[]): string {
  const has = (key: string | null | undefined) => !!key && reported.some((k) => k.key === key)
  if (has(block.contribution?.outcome)) return block.contribution!.outcome
  if (has(block.trend?.outcome)) return block.trend!.outcome!
  if (has(block.trend?.metric)) return block.trend!.metric!

  return reported.find((k) => k.key !== 'spend')?.key ?? reported[0].key
}

function FamilyPanel({ block, currency, ar, showContent, single }: { block: ObjectiveFamilyBlock; currency: Props['currency']; ar: boolean; showContent: boolean; single: boolean }) {
  // Only what was reported is drawn: an unavailable figure takes no room.
  const reported = [...block.kpis].filter((k) => k.state === 'reported' && k.value !== null).sort((a, b) => (a.key === 'spend' ? -1 : b.key === 'spend' ? 1 : 0))
  const [chosen, setChosen] = useState<string>(() => leadKey(block, reported))
  const active = reported.find((k) => k.key === chosen) ?? reported[0]
  const contentRanking = showContent ? block.content_ranking : null
  const missing = (kpi: ObjectiveKpi) => {
    const out = kpi.not_reported_by ?? []

    return out.length ? (ar ? 'دون ' : 'Excl. ') + out.map((p) => providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')).join('، ') : undefined
  }

  return (
    <div data-testid={`objective-family-${block.family}`} className="flex min-w-0 flex-col gap-2">
      {single && <span className="text-xs font-semibold text-text-muted">{ar ? block.label_ar : block.label_en}</span>}

      <div role="tablist" aria-label={ar ? 'المؤشرات' : 'Figures'} className="flex flex-wrap gap-1.5">
        {reported.map((kpi) => {
          const on = kpi.key === active.key

          return (
            <button
              key={kpi.key}
              type="button"
              role="tab"
              aria-selected={on}
              data-testid={`objective-kpi-${block.family}-${kpi.key}`}
              data-state={kpi.state}
              title={missing(kpi)}
              onClick={() => setChosen(kpi.key)}
              className={`inline-flex min-w-0 items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-start ${on ? 'border-brand-500 bg-[color-mix(in_srgb,var(--brand-500)_10%,transparent)]' : 'border-border hover:border-brand-500/60'}`}
            >
              <span className="text-xs text-text-muted">{ar ? kpi.label_ar : kpi.label_en}</span>
              <span className="tnum text-sm font-extrabold text-text-primary" title={formatKpi(kpi, currency)}><Num>{cardText(kpi, currency)}</Num></span>
              <Movement kpi={kpi} />
            </button>
          )
        })}
      </div>

      <Spotlight block={block} kpi={active} currency={currency} ar={ar} />

      {(hasEnds(block.platform_ranking) || hasEnds(contentRanking)) && (
        <div className="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-border pt-2">
          {hasEnds(block.platform_ranking) && (
            <Leaders testid={`objective-platform-leaders-${block.family}`} title={ar ? 'المنصات' : 'Platforms'} ranking={block.platform_ranking} currency={currency} ar={ar} />
          )}
          {hasEnds(contentRanking) && (
            <Leaders testid={`objective-content-leaders-${block.family}`} title={ar ? 'المحتوى' : 'Content'} ranking={contentRanking!} currency={currency} ar={ar} content />
          )}
        </div>
      )}
    </div>
  )
}

function Spotlight({ block, kpi, currency, ar }: { block: ObjectiveFamilyBlock; kpi: ObjectiveKpi; currency: Props['currency']; ar: boolean }) {
  const series = seriesFor(kpi.key, block.trend)
  const lower = LOWER_IS_BETTER.has(kpi.key)
  const rows = block.platforms
    .map((p) => ({ provider: p.provider, k: p.kpis.find((x) => x.key === kpi.key) }))
    .filter((r): r is { provider: string; k: ObjectiveKpi } => !!r.k && r.k.state === 'reported' && r.k.value !== null)
    .sort((a, b) => (lower ? a.k.value! - b.k.value! : b.k.value! - a.k.value!))
  const top = rows.reduce((m, r) => Math.max(m, Math.abs(r.k.value!)), 0) || 1
  // Parts add up only for counts and money totals, and only when every platform sent the figure.
  const additive = kpi.kind === 'count' || (kpi.kind === 'money' && ADDITIVE_MONEY.has(kpi.key))
  const sum = rows.reduce((s, r) => s + r.k.value!, 0)
  const showShare = additive && sum > 0 && (kpi.not_reported_by ?? []).length === 0
  const ranked = !NEUTRAL.has(kpi.key) && rows.length >= 2 && rows[0].k.value !== rows[1].k.value
  if (!series && rows.length === 0) return null

  return (
    <div data-testid={`objective-spotlight-${block.family}`} data-kpi={kpi.key} className={`grid min-w-0 gap-3 ${series && rows.length ? 'md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]' : ''}`}>
      {series && <DailyChart points={series} format={(v) => formatFigure(kpi.kind, v, currency)} ar={ar} testid={`objective-trend-${block.family}`} />}
      {rows.length > 0 && (
        <ul data-testid={`objective-spotlight-platforms-${block.family}`} className="flex min-w-0 flex-col justify-center gap-1.5">
          {rows.map((r, i) => (
            <li key={r.provider} className="grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 text-xs">
              <ProviderName provider={r.provider} locale={ar ? 'ar' : 'en'} size={14} />
              <span className="h-1.5 overflow-hidden rounded-full bg-surface-secondary">
                <span className="block h-full rounded-full" style={{ width: `${Math.max(3, (Math.abs(r.k.value!) / top) * 100)}%`, background: platformColor(canonicalPlatform(r.provider)) }} />
              </span>
              <span className="tnum whitespace-nowrap font-bold text-text-primary">
                {ranked && i === 0 && <span className="me-1 text-success" aria-label={ar ? 'الأفضل' : 'Best'}>★</span>}
                <Num>{formatFigure(r.k.kind, r.k.value, currency)}</Num>
                {showShare && <span className="ms-1 font-medium text-text-muted"><Num>{`${Math.round((r.k.value! / sum) * 100)}%`}</Num></span>}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function hasEnds(ranking: ObjectiveRanking | null | undefined): ranking is ObjectiveRanking {
  return !!ranking && !!ranking.best && !!ranking.weakest
}

function Leaders({ testid, title, ranking, currency, ar, content = false }: { testid: string; title: string; ranking: ObjectiveRanking; currency: Props['currency']; ar: boolean; content?: boolean }) {
  return (
    <div data-testid={testid} className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs">
      <span className="font-semibold text-text-muted">{title} · {rankingMetricLabel(ranking.metric, ar)}</span>
      {([['best', ranking.best!, 'text-success', '▲'], ['weakest', ranking.weakest!, 'text-danger', '▼']] as const).map(([kind, end, tone, mark]) => (
        <span key={kind} data-testid={`${testid}-${kind}`} className="inline-flex min-w-0 items-center gap-1 rounded-md bg-surface-secondary px-1.5 py-0.5">
          <span className={tone} aria-label={kind === 'best' ? (ar ? 'الأقوى' : 'Strongest') : (ar ? 'الأضعف' : 'Weakest')}>{mark}</span>
          <ProviderMark provider={canonicalPlatform(end.provider ?? '')} size={14} />
          <span className="max-w-40 truncate font-semibold text-text-primary">{content ? end.name || (ar ? 'محتوى' : 'Content') : providerLabel(canonicalPlatform(end.provider ?? ''), ar ? 'ar' : 'en')}</span>
          <span className="tnum font-bold text-text-primary"><Num>{formatRankingValue(ranking.metric, end.value, currency)}</Num></span>
        </span>
      ))}
    </div>
  )
}

/** The chosen figure day by day. Gaps stay gaps: a day nobody reported breaks the line, and hovering it says so. */
function DailyChart({ points, format, ar, testid }: { points: { date: string; value: number | null }[]; format: (v: number) => string; ar: boolean; testid: string }) {
  const [hover, setHover] = useState<number | null>(null)
  const present = points.map((p) => p.value).filter((v): v is number => v !== null)
  const min = Math.min(0, ...present)
  const max = Math.max(...present)
  const span = max - min || 1
  const w = 320
  const h = 64
  const x = (i: number) => (points.length > 1 ? (i / (points.length - 1)) * w : 0)
  const y = (v: number) => h - 6 - ((v - min) / span) * (h - 12)

  const segments: { line: string; area: string }[] = []
  let run: number[] = []
  const flush = () => {
    if (run.length === 0) return
    const line = run.map((i) => `${x(i).toFixed(1)},${y(points[i].value!).toFixed(1)}`).join(' ')
    const area = `${x(run[0]).toFixed(1)},${h} ${line} ${x(run[run.length - 1]).toFixed(1)},${h}`
    segments.push({ line, area })
    run = []
  }
  points.forEach((p, i) => (p.value === null ? flush() : run.push(i)))
  flush()

  const pick = (clientX: number, rect: DOMRect) => {
    const ratio = (clientX - rect.left) / rect.width
    const i = Math.round(ratio * (points.length - 1))
    setHover(Math.min(points.length - 1, Math.max(0, i)))
  }
  const shown = hover

  return (
    <div className="mt-1 flex flex-col gap-1">
      <div className="flex min-h-5 items-center justify-between text-xs" dir="ltr">
        <span className="text-text-muted">{shown !== null ? points[shown].date : `${points[0].date} – ${points[points.length - 1].date}`}</span>
        {shown !== null && (
          <span data-testid={`${testid}-readout`} className="tnum font-bold text-text-primary">
            {points[shown].value === null ? (ar ? 'لم يُرسل' : 'Not reported') : format(points[shown].value!)}
          </span>
        )}
      </div>
      <svg
        data-testid={testid}
        viewBox={`0 0 ${w} ${h}`}
        className="h-16 w-full touch-none"
        preserveAspectRatio="none"
        role="img"
        aria-label={ar ? 'الاتجاه اليومي' : 'Daily trend'}
        onPointerMove={(e) => pick(e.clientX, e.currentTarget.getBoundingClientRect())}
        onPointerDown={(e) => pick(e.clientX, e.currentTarget.getBoundingClientRect())}
        onPointerLeave={() => setHover(null)}
      >
        <defs>
          <linearGradient id={`${testid}-fill`} x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="var(--brand-500)" stopOpacity={0.35} />
            <stop offset="100%" stopColor="var(--brand-500)" stopOpacity={0} />
          </linearGradient>
        </defs>
        {segments.map((s, i) => (
          s.line.includes(' ')
            ? <g key={i}><polygon points={s.area} fill={`url(#${testid}-fill)`} /><polyline points={s.line} fill="none" stroke="var(--brand-500)" strokeWidth={2} vectorEffect="non-scaling-stroke" /></g>
            : <circle key={i} cx={s.line.split(',')[0]} cy={s.line.split(',')[1]} r={2.5} fill="var(--brand-500)" />
        ))}
        {shown !== null && (
          <g>
            <line x1={x(shown)} x2={x(shown)} y1={0} y2={h} stroke="var(--text-muted)" strokeDasharray="3 3" vectorEffect="non-scaling-stroke" />
            {points[shown].value !== null && <circle cx={x(shown)} cy={y(points[shown].value!)} r={3.5} fill="var(--brand-500)" stroke="var(--surface)" strokeWidth={1.5} vectorEffect="non-scaling-stroke" />}
          </g>
        )}
      </svg>
    </div>
  )
}
