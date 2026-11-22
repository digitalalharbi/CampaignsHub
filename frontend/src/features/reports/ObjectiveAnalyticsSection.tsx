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
  unavailableNote,
  type ObjectiveAnalytics,
  type ObjectiveFamilyBlock,
  type ObjectiveKpi,
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

/** Figures where less is better: their movement colours the other way, as on the summary's cards. Spend is neither: its movement is drawn neutral. */
const LOWER_IS_BETTER = new Set(['cpa', 'cpc', 'cpm', 'cpl', 'cpi', 'cpe', 'cost_per_view', 'cost_per_conversion', 'cost_per_lpv', 'cost_per_result', 'frequency'])
const NEUTRAL = new Set(['spend'])
const ADDITIVE_MONEY = new Set(['spend', 'revenue'])

/** The summary card's compact figure (1.49K USD, 1.63M); rates and multipliers keep their own reading. */
function cardText(kpi: ObjectiveKpi, currency: Props['currency'], ar: boolean): string {
  if (kpi.state !== 'reported' || kpi.value === null) return ar ? 'غير متاح' : 'Not available'
  if (kpi.kind === 'money') return money(kpi.value, currency ?? null)
  if (kpi.kind === 'count') return compact(kpi.value)

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
function leadKey(block: ObjectiveFamilyBlock): string {
  const reported = block.kpis.filter((k) => k.state === 'reported')
  const has = (key: string | null | undefined) => !!key && reported.some((k) => k.key === key)
  if (has(block.contribution?.outcome)) return block.contribution!.outcome
  if (has(block.trend?.outcome)) return block.trend!.outcome!
  if (has(block.trend?.metric)) return block.trend!.metric!

  return reported.find((k) => k.key !== 'spend')?.key ?? reported[0]?.key ?? block.kpis[0]?.key ?? 'spend'
}

function FamilyCard({ block, currency, ar, showContent }: { block: ObjectiveFamilyBlock; currency: Props['currency']; ar: boolean; showContent: boolean }) {
  /*
   * OBJECTIVE-CARDS-CREATIVE-001 (Owner 2026-11-22: «خلها بطاقات صغيرة أفضل وجانب إبداعي معها»). Every figure of the family as a
   * small card (figure and its movement); a card is also a switch. The chosen figure opens in the spotlight beneath: the figure,
   * its movement, its daily line (only where the server sent that figure's own series, read day by day on hover or touch) and
   * each platform's value of it, with a share only where the parts add up and every platform reported. Nothing is computed that
   * the server did not send, except a share of parts it sent.
   */
  const ordered = [...block.kpis].sort((a, b) => (a.key === 'spend' ? -1 : b.key === 'spend' ? 1 : 0))
  const [chosen, setChosen] = useState<string>(() => leadKey(block))
  const active = ordered.find((k) => k.key === chosen) ?? ordered[0]
  const contentRanking = showContent ? block.content_ranking : null
  const missingNote = (kpi: ObjectiveKpi) => {
    const missing = kpi.state === 'reported' ? (kpi.not_reported_by ?? []) : []
    if (missing.length === 0) return kpi.state === 'reported' ? undefined : unavailableNote(kpi.reason, ar)

    return (ar ? 'دون ' : 'Excl. ') + missing.map((p) => providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')).join('، ')
  }

  return (
    <div data-testid={`objective-family-${block.family}`} className="rounded-2xl border border-border bg-surface p-4">
      <div className="mb-3 flex items-baseline justify-between gap-2">
        <span className="font-bold text-text-primary">{ar ? block.label_ar : block.label_en}</span>
        <span className="text-xs text-text-muted">{ar ? 'اختر مؤشراً لعرض تفاصيله' : 'Pick a figure to explore it'}</span>
      </div>

      <div role="tablist" aria-label={ar ? 'المؤشرات' : 'Figures'} className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        {ordered.map((kpi) => {
          const on = kpi.key === active?.key

          return (
            <button
              key={kpi.key}
              type="button"
              role="tab"
              aria-selected={on}
              data-testid={`objective-kpi-${block.family}-${kpi.key}`}
              data-state={kpi.state}
              title={missingNote(kpi)}
              onClick={() => setChosen(kpi.key)}
              className={`min-w-0 rounded-xl border px-3 py-2 text-start transition-colors ${on ? 'border-brand-500 bg-[color-mix(in_srgb,var(--brand-500)_10%,transparent)] ring-1 ring-brand-500' : 'border-border bg-surface hover:border-brand-500/60'}`}
            >
              <span className="block truncate text-xs font-semibold text-text-muted">{ar ? kpi.label_ar : kpi.label_en}</span>
              <span className="mt-0.5 flex flex-wrap items-center justify-between gap-1">
                <span className={`tnum text-base font-extrabold ${kpi.state === 'reported' ? 'text-text-primary' : 'text-text-muted'}`} title={kpi.state === 'reported' ? formatKpi(kpi, currency) : undefined}>
                  <Num>{cardText(kpi, currency, ar)}</Num>
                </span>
                <Movement kpi={kpi} />
              </span>
            </button>
          )
        })}
      </div>

      {active && <Spotlight block={block} kpi={active} currency={currency} ar={ar} note={missingNote(active)} />}

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
          <div className="mb-1.5 text-xs font-medium text-text-muted">
            {ar ? `المساهمة في ${block.contribution.label_ar}` : `Share of ${block.contribution.label_en.toLowerCase()}`}
          </div>
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-surface-secondary" role="img" aria-label={ar ? 'المساهمة حسب المنصة' : 'Contribution by platform'}>
            {block.contribution.rows.filter((r) => (r.share ?? 0) > 0).map((r) => (
              <span key={r.provider} style={{ width: `${(r.share ?? 0) * 100}%`, background: platformColor(canonicalPlatform(r.provider)) }} />
            ))}
          </div>
          <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-secondary">
            {block.contribution.rows.map((r) => (
              <span key={r.provider} className="inline-flex items-center gap-1.5">
                <ProviderName provider={r.provider} locale={ar ? 'ar' : 'en'} size={16} />
                <Num className="tnum font-semibold text-text-primary">{r.share === null ? '—' : `${(r.share * 100).toFixed(1)}%`}</Num>
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

function Spotlight({ block, kpi, currency, ar, note }: { block: ObjectiveFamilyBlock; kpi: ObjectiveKpi; currency: Props['currency']; ar: boolean; note: string | undefined }) {
  const series = kpi.state === 'reported' ? seriesFor(kpi.key, block.trend) : null
  const lower = LOWER_IS_BETTER.has(kpi.key)
  const rows = block.platforms
    .map((p) => ({ provider: p.provider, k: p.kpis.find((x) => x.key === kpi.key) }))
    .filter((r): r is { provider: string; k: ObjectiveKpi } => !!r.k && r.k.state === 'reported' && r.k.value !== null)
    .sort((a, b) => (lower ? a.k.value! - b.k.value! : b.k.value! - a.k.value!))
  const top = rows.reduce((m, r) => Math.max(m, Math.abs(r.k.value!)), 0) || 1
  // Parts add up only for counts and for money totals (spend, revenue) — never for a cost per something, a rate or a multiplier.
  const additive = kpi.kind === 'count' || (kpi.kind === 'money' && ADDITIVE_MONEY.has(kpi.key))
  const sum = rows.reduce((s, r) => s + r.k.value!, 0)
  const showShare = additive && sum > 0 && (kpi.not_reported_by ?? []).length === 0
  const ranked = !NEUTRAL.has(kpi.key) && rows.length >= 2 && rows[0].k.value !== rows[1].k.value

  return (
    <div data-testid={`objective-spotlight-${block.family}`} data-kpi={kpi.key} className="mt-3 grid gap-4 rounded-xl border border-border bg-surface-secondary/60 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
      <div className="flex min-w-0 flex-col gap-2">
        <span className="text-xs font-semibold text-text-muted">{ar ? kpi.label_ar : kpi.label_en}</span>
        <div className="flex flex-wrap items-center gap-2">
          <span className="tnum text-xl font-extrabold tracking-tight sm:text-xl text-text-primary" title={kpi.state === 'reported' ? formatKpi(kpi, currency) : undefined}>
            <Num>{cardText(kpi, currency, ar)}</Num>
          </span>
          <Movement kpi={kpi} />
        </div>
        {kpi.delta !== undefined && kpi.delta !== null && <span className="text-xs text-text-muted">{ar ? 'مقابل الفترة السابقة' : 'vs the previous period'}</span>}
        {note && <span className="text-xs text-text-muted">{note}</span>}
        {series ? (
          <DailyChart points={series} format={(v) => formatFigure(kpi.kind, v, currency)} ar={ar} testid={`objective-trend-${block.family}`} />
        ) : (
          kpi.state === 'reported' && <span className="text-xs text-text-muted">{ar ? 'لا تتوفر سلسلة يومية لهذا المؤشر' : 'No daily series for this figure'}</span>
        )}
      </div>

      <div data-testid={`objective-spotlight-platforms-${block.family}`} className="flex min-w-0 flex-col gap-2">
        <span className="text-xs font-semibold text-text-muted">{ar ? 'حسب المنصة' : 'By platform'}</span>
        {rows.length === 0 ? (
          <span className="text-xs text-text-muted">{ar ? 'لم تُرسل المنصات هذا المؤشر' : 'No platform reported this figure'}</span>
        ) : (
          rows.map((r, i) => (
            <div key={r.provider} className="flex min-w-0 flex-col gap-1">
              <div className="flex items-center justify-between gap-2 text-xs">
                <span className="inline-flex min-w-0 items-center gap-1.5">
                  <ProviderName provider={r.provider} locale={ar ? 'ar' : 'en'} size={16} />
                  {ranked && i === 0 && <span className="rounded-full bg-[var(--positive-background)] px-1.5 text-xs font-semibold text-success">{ar ? 'الأفضل' : 'Best'}</span>}
                </span>
                <span className="tnum font-bold text-text-primary">
                  <Num>{formatFigure(r.k.kind, r.k.value, currency)}</Num>
                  {showShare && <span className="ms-1.5 font-medium text-text-muted"><Num>{`${((r.k.value! / sum) * 100).toFixed(1)}%`}</Num></span>}
                </span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-surface">
                <span className="block h-full rounded-full" style={{ width: `${Math.max(2, (Math.abs(r.k.value!) / top) * 100)}%`, background: platformColor(canonicalPlatform(r.provider)) }} />
              </div>
            </div>
          ))
        )}
      </div>
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
  const h = 96
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
        className="h-24 w-full touch-none"
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
                {/* REPORT-PLATFORM-LOGOS-001 — the platform's own logo; a content name keeps the colour dot and carries the logo on its platform line. */}
                {content ? <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: platformColor(canonicalPlatform(end.provider ?? '')) }} aria-hidden /> : <ProviderMark provider={canonicalPlatform(end.provider ?? '')} size={18} />}
                <span className="truncate">{label.primary}</span>
              </div>
              {label.secondary && <div className="flex items-center gap-1 truncate text-[10px] text-text-muted"><ProviderMark provider={canonicalPlatform(end.provider ?? '')} size={12} /><span className="truncate">{label.secondary}</span></div>}
              <div className="tnum mt-1 text-base font-extrabold text-text-primary"><Num>{formatRankingValue(ranking.metric, end.value, currency)}</Num></div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
