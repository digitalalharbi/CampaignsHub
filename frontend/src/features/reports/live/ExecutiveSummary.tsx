import { useMemo, useState } from 'react'
import { AlertTriangle, CheckCircle2, CircleDashed, Lightbulb, TrendingUp } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { KpiCard, TrendPill, platformColor } from '@/features/analytics/components'
import { ChartCard, ConversionFunnelChart, MetricLineChart, PlatformDonutChart } from '@/features/analytics/charts'
import { money, num, rowCostPer, rowMoney, rowRoas } from '@/features/analytics/format'
import { canonicalPlatform } from '@/lib/platforms'
import { readRoas, type MoneyTotals } from '@/lib/money/contract'
import { portfolioBudget } from '@/lib/money/portfolioBudget'
import { Num } from '@/components/ui/Num'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import type { Locale } from '@/stores/ui'
import type { LivePayload } from '../api'
import type { ReportAd } from '../ReportAdsSection'
import { attentionAction, attentionHeadline, attentionImpact, type AttentionItem } from '../attention'
import { ContentTile } from './LiveContent'
import { liveSpark } from './liveSpark'
import { ComparisonWithheld } from './LiveKpis'
import { useSpendCharting, SiteMeasurementSection } from './LiveSections'
import type { useLiveMetricReader } from './liveMetrics'

type Reader = ReturnType<typeof useLiveMetricReader>

/**
 * REPORT-EXECUTIVE-SUMMARY-001 — the shared report's summary as an executive dashboard.
 *
 * The question a client opens the link with is «how did all my advertising do, in one place». The
 * page answers in the order a reader scans: four headline figures with their movement, the shape of
 * the period, where the money went, how each platform compares, what each objective bought, the
 * funnel, the content that worked, whether budget and data are in order, and what to do next.
 *
 * Every figure is the payload's — the same canonical readers the rest of the report uses
 * (`reader.meta`, `rowMoney`, `rowCostPer`, `readRoas`, `portfolioBudget`), so this view cannot
 * disagree with the dashboard tab. Absent is shown as absent. No campaign is named anywhere
 * (CLIENT-REPORT-ENTITY-BOUNDARY-001): campaigns appear as a COUNT, and the drill-down is platform →
 * content. Choosing a platform in the comparison narrows the content block to that platform.
 */

/** The result an objective is judged on, in the order the reader's keys may name them. */
const RESULT_KEYS = ['purchases', 'conversions', 'leads', 'installs', 'landing_page_views', 'engagements', 'clicks', 'reach', 'impressions']
/** Each result's cost, as the totals carry it. */
const COST_FOR: Record<string, string> = {
  purchases: 'cpa', conversions: 'cpa', leads: 'cpl', installs: 'cpi', landing_page_views: 'cost_per_lpv',
  engagements: 'cpe', clicks: 'cpc', reach: 'cpm', impressions: 'cpm',
}

export function headlineKeys(payload: LivePayload, reader: Reader): { result: string; cost: string } {
  const keys = reader.keys(payload)
  const totals = payload.totals ?? {}
  // The objective's own result first, in RESULT_KEYS' priority — a reach figure never outranks an order.
  const result = RESULT_KEYS.find((k) => keys.includes(k) && totals[k] !== null && totals[k] !== undefined && Number(totals[k]) > 0)
    ?? RESULT_KEYS.find((k) => keys.includes(k) && totals[k] !== null && totals[k] !== undefined)
    ?? 'clicks'

  return { result, cost: COST_FOR[result] ?? 'cpa' }
}

function Panel({ title, testid, children, className = '', action }: { title: string; testid: string; children: React.ReactNode; className?: string; action?: React.ReactNode }) {
  return (
    <section data-testid={testid} className={`min-w-0 rounded-2xl border border-border bg-surface p-4 ${className}`}>
      <header className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-bold text-text-primary">{title}</h3>
        {action}
      </header>
      {children}
    </section>
  )
}

export function ExecutiveSummary({
  payload,
  reader,
  currency,
  locale,
  onOpenContent,
  sectionOn,
  afterKpis,
}: {
  payload: LivePayload
  reader: Reader
  currency: string
  locale: Locale
  onOpenContent: (content: ReportAd) => void
  /** A block the caller places under the KPI strip — the direct/blended guard where the two differ. */
  afterKpis?: React.ReactNode
  sectionOn: (key: 'kpis' | 'trends' | 'platform_comparison' | 'budget_pacing' | 'funnel' | 'content_performance' | 'site_measurement' | 'recommendations' | 'objective_breakdown') => boolean
}) {
  const ar = locale === 'ar'
  const [platform, setPlatform] = useState<string | null>(null)
  const { result, cost } = headlineKeys(payload, reader)
  // Drawn only where the report carries what each objective bought; an absent breakdown is not «nothing spent».
  const objectivesShown = sectionOn('objective_breakdown') && (payload.objective_performance?.paths ?? []).some((p) => (p.spend ?? 0) > 0)

  return (
    <div data-testid="live-mode-summary" className="flex flex-col gap-4">
      {sectionOn('kpis') && <KpiStrip payload={payload} reader={reader} ar={ar} result={result} cost={cost} />}
      {afterKpis}

      {(sectionOn('trends') || sectionOn('platform_comparison')) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {sectionOn('trends') && <TrendPanel payload={payload} reader={reader} ar={ar} currency={currency} result={result} wide={!sectionOn('platform_comparison')} />}
          {sectionOn('platform_comparison') && <SpendSplit payload={payload} ar={ar} currency={currency} wide={!sectionOn('trends')} selected={platform} onSelect={setPlatform} />}
        </div>
      )}

      {(sectionOn('platform_comparison') || objectivesShown) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {sectionOn('platform_comparison') && (
            <PlatformComparison payload={payload} reader={reader} ar={ar} currency={currency} result={result} cost={cost} selected={platform} onSelect={setPlatform} wide={!objectivesShown} />
          )}
          {objectivesShown && <ObjectiveBreakdown payload={payload} ar={ar} currency={currency} />}
        </div>
      )}

      {(sectionOn('funnel') || sectionOn('content_performance')) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {sectionOn('funnel') && <FunnelPanel payload={payload} ar={ar} currency={currency} />}
          {sectionOn('content_performance') && (
            <BestContent payload={payload} locale={locale} currency={currency} platform={platform} onClear={() => setPlatform(null)} onOpen={onOpenContent} />
          )}
        </div>
      )}

      <HealthStrip payload={payload} ar={ar} showBudget={sectionOn('budget_pacing')} />

      {sectionOn('site_measurement') && <SiteMeasurementSection payload={payload} ar={ar} />}

      {sectionOn('recommendations') && <ActionCards payload={payload} ar={ar} onOpenContent={onOpenContent} />}
    </div>
  )
}

/* ─────────────────────────────── KPI strip ─────────────────────────────── */

function KpiStrip({ payload, reader, ar, result, cost }: { payload: LivePayload; reader: Reader; ar: boolean; result: string; cost: string }) {
  const t = payload.totals
  const withheld = payload.comparison !== undefined && !payload.comparison.comparable
  const d: Record<string, number | null | undefined> = withheld ? {} : (payload.deltas ?? {})
  const card = (key: string) => {
    const meta = reader.meta[key]
    if (!meta) return null
    const read = meta.format(t, payload, reader.asMoney, reader.count)

    return (
      <KpiCard key={key} label={ar ? meta.ar : meta.en} value={read.text} exact={read.exact ?? undefined} delta={d[key]} invertGood={meta.invertGood} spark={liveSpark(key, payload.timeseries)} />
    )
  }
  const campaignCount = payload.delivering_campaigns
  const campaignCountDelta = campaignCount && campaignCount.previous !== null && campaignCount.previous > 0 && !withheld ? (campaignCount.current - campaignCount.previous) / campaignCount.previous : undefined
  const campaignCountLabel = ar ? 'الحملات التي عملت' : 'Campaigns that ran' // campaignCount — a number, never a name

  return (
    <div className="flex flex-col gap-3">
    <ComparisonWithheld payload={payload} ar={ar} />
    <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4" data-testid="live-kpis">
      {card('spend')}
      {card(result)}
      {campaignCount !== undefined && (
        <div data-testid="exec-kpi-count" className="contents">
          <KpiCard label={campaignCountLabel} value={num(campaignCount.current)} delta={campaignCountDelta} />
        </div>
      )}
      {card(cost)}
    </div>
    </div>
  )
}

/* ─────────────────────────────── Trend ─────────────────────────────── */

function TrendPanel({ payload, reader, ar, currency, result, wide }: { payload: LivePayload; reader: Reader; ar: boolean; currency: string; result: string; wide: boolean }) {
  const { spendChartable } = useSpendCharting(payload, currency)
  const resultName = reader.meta[result] ? (ar ? reader.meta[result].ar : reader.meta[result].en) : result
  const options = [
    ...(spendChartable ? [{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', color: 'var(--brand-600)', kind: 'money' as const }] : []),
    { key: result, name: resultName, color: 'var(--purple)', kind: 'num' as const },
    ...(result !== 'clicks' ? [{ key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const }] : []),
  ]
  const [on, setOn] = useState<string[]>(options.slice(0, 2).map((o) => o.key))
  const series = options.filter((o) => on.includes(o.key))

  return (
    <div className={wide ? 'lg:col-span-12' : 'lg:col-span-8'}>
      <ChartCard title={ar ? 'الأداء خلال الفترة' : 'Performance over the period'}>
        <div className="mb-2 flex flex-wrap gap-1.5" data-testid="exec-trend-toggles">
          {options.map((o) => (
            <button
              key={o.key}
              type="button"
              aria-pressed={on.includes(o.key)}
              data-testid={`exec-trend-${o.key}`}
              onClick={() => setOn((cur) => (cur.includes(o.key) ? (cur.length > 1 ? cur.filter((k) => k !== o.key) : cur) : [...cur, o.key]))}
              className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold ${on.includes(o.key) ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-muted'}`}
            >
              <span className="h-2 w-2 rounded-full" style={{ background: o.color }} />
              {o.name}
            </button>
          ))}
        </div>
        <div data-testid="exec-trend">
          <MetricLineChart data={payload.timeseries ?? []} currency={currency} height={230} rightAxisFor={series.some((s) => s.key === 'spend') ? series.find((s) => s.key !== 'spend')?.key : undefined} series={series} />
        </div>
      </ChartCard>
    </div>
  )
}

/* ─────────────────────────────── Spend split ─────────────────────────────── */

function SpendSplit({ payload, ar, currency, wide, selected, onSelect }: { payload: LivePayload; ar: boolean; currency: string; wide: boolean; selected: string | null; onSelect: (p: string | null) => void }) {
  const { platformSpendRank } = useSpendCharting(payload, currency)
  const rows = (payload.platforms ?? []).flatMap((p, i) => {
    const value = platformSpendRank?.values[i] ?? null
    return value === null || value <= 0 ? [] : [{ key: canonicalPlatform(p.provider), name: providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'), value }]
  })
  const total = rows.reduce((s, r) => s + r.value, 0)

  return (
    <Panel title={ar ? 'أين ذهب الإنفاق' : 'Where the money went'} testid="exec-spend-split" className={wide ? 'lg:col-span-12' : 'lg:col-span-4'}>
      {platformSpendRank === null || total <= 0 ? (
        <p className="flex h-[200px] items-center justify-center text-center text-sm text-text-muted">
          {ar ? 'لا يُرسم التوزيع: المبالغ بانتظار سعر صرف أو بعملات لا تُجمع' : 'No split drawn: amounts await a rate or span currencies'}
        </p>
      ) : (
        <div className="grid grid-cols-1 items-center gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
          <PlatformDonutChart data={rows.map((r) => ({ name: r.name, value: r.value, key: r.key }))} currency={platformSpendRank.currency ?? currency} height={170} legend={false} />
          <ul className="flex flex-col gap-1.5">
            {rows.sort((a, b) => b.value - a.value).map((r) => (
              <li key={r.key}>
                <button
                  type="button"
                  data-testid={`exec-split-${r.key}`}
                  aria-pressed={selected === r.key}
                  onClick={() => onSelect(selected === r.key ? null : r.key)}
                  className={`flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1 text-xs ${selected === r.key ? 'bg-brand-500/10' : 'hover:bg-surface-hover'}`}
                >
                  <span className="flex min-w-0 items-center gap-1.5 text-text-secondary">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: platformColor(r.key) }} />
                    <span className="truncate">{r.name}</span>
                  </span>
                  <span className="tnum font-bold text-text-primary"><Num>{`${Math.round((r.value / total) * 100)}%`}</Num></span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Panel>
  )
}

/* ─────────────────────────────── Platform comparison ─────────────────────────────── */

function PlatformComparison({
  payload, reader, ar, currency, result, cost, selected, onSelect, wide,
}: { payload: LivePayload; reader: Reader; ar: boolean; currency: string; result: string; cost: string; selected: string | null; onSelect: (p: string | null) => void; wide: boolean }) {
  const rows = (payload.platforms ?? []).filter((p) => Number(p.spend ?? 0) > 0 || Number(p[result] ?? 0) > 0)
  const roasValues = rows.map((p) => readRoas(p as unknown as MoneyTotals, ar).value)
  const maxRoas = Math.max(0, ...roasValues.map((v) => v ?? 0))
  const showRoas = maxRoas > 0
  const resultName = reader.meta[result] ? (ar ? reader.meta[result].ar : reader.meta[result].en) : result
  const costName = reader.meta[cost] ? (ar ? reader.meta[cost].ar : reader.meta[cost].en) : cost

  return (
    <Panel
      title={ar ? 'مقارنة المنصات' : 'Platforms side by side'}
      testid="exec-platforms"
      className={wide ? 'lg:col-span-12' : 'lg:col-span-7'}
      action={selected ? (
        <button type="button" onClick={() => onSelect(null)} className="text-[11px] font-semibold text-accent hover:underline" data-testid="exec-platform-clear">
          {ar ? 'كل المنصات' : 'All platforms'}
        </button>
      ) : undefined}
    >
      {rows.length === 0 ? (
        <p className="text-sm text-text-muted">{ar ? 'لا منصة أنفقت في هذه الفترة' : 'No platform spent in this period'}</p>
      ) : (
        <div className="min-w-0 text-xs" data-testid="exec-platform-table">
          <MetricTable
            head={[ar ? 'المنصة' : 'Platform', ar ? 'الإنفاق' : 'Spend', resultName, costName, ...(showRoas ? [ar ? 'العائد' : 'ROAS'] : [])]}
            rows={rows.map((p, i) => {
              const key = canonicalPlatform(p.provider)
              const r = roasValues[i]
              const mov = (p as { movement?: Record<string, number | null> }).movement?.spend

              return [
                <button
                  key="p"
                  type="button"
                  data-testid={`exec-platform-${key}`}
                  aria-pressed={selected === key}
                  onClick={() => onSelect(selected === key ? null : key)}
                  className={`flex items-center gap-1.5 rounded-lg px-1.5 py-0.5 font-semibold text-text-primary ${selected === key ? 'bg-brand-500/15' : 'hover:bg-surface-hover'}`}
                >
                  <span className="h-2.5 w-2.5 rounded-full" style={{ background: platformColor(key) }} />
                  {providerLabel(key, ar ? 'ar' : 'en')}
                </button>,
                <span key="s" className="inline-flex items-center gap-1">
                  <span className="font-semibold text-text-primary"><Num>{rowMoney(p as unknown as MoneyTotals, 'spend', currency || null)}</Num></span>
                  {mov !== undefined && mov !== null && <TrendPill delta={mov} invertGood />}
                </span>,
                <span key="r" className="text-text-primary"><Num>{num(p[result] === null || p[result] === undefined ? null : Number(p[result]))}</Num></span>,
                <span key="c" className="text-text-secondary"><Num>{rowCostPer(p as unknown as MoneyTotals, cost, result, currency || null)}</Num></span>,
                ...(showRoas ? [
                  <span key="o" className="inline-flex items-center gap-2">
                    <span className="h-1.5 w-14 overflow-hidden rounded-full bg-surface-secondary">
                      <span className="block h-full rounded-full" style={{ width: `${r === null ? 0 : Math.round((r / maxRoas) * 100)}%`, background: platformColor(key) }} />
                    </span>
                    <span className="font-semibold text-text-primary"><Num>{rowRoas(p as unknown as MoneyTotals)}</Num></span>
                  </span>,
                ] : []),
              ]
            })}
            values={rows.map((p, i): SortValues => [
              providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'),
              p.spend === null || p.spend === undefined ? null : Number(p.spend),
              p[result] === null || p[result] === undefined ? null : Number(p[result]),
              p[cost] === null || p[cost] === undefined ? null : Number(p[cost]),
              ...(showRoas ? [roasValues[i]] : []),
            ])}
            initialSort={{ column: 1, dir: 'desc' }}
          />
        </div>
      )}
      <p className="mt-2 text-[11px] text-text-muted">{ar ? 'اضغط منصة لترى أفضل محتواها أدناه.' : 'Pick a platform to see its best content below.'}</p>
    </Panel>
  )
}

/* ─────────────────────────────── Objective breakdown ─────────────────────────────── */

const PATH_RESULT: Record<string, { key: 'impressions' | 'clicks' | 'orders'; cost: 'cpm' | 'cpc' | 'cpa'; ar: string; en: string; costAr: string; costEn: string }> = {
  awareness: { key: 'impressions', cost: 'cpm', ar: 'الظهور', en: 'Impressions', costAr: 'تكلفة الألف', costEn: 'CPM' },
  traffic: { key: 'clicks', cost: 'cpc', ar: 'النقرات', en: 'Clicks', costAr: 'تكلفة النقرة', costEn: 'CPC' },
  conversion: { key: 'orders', cost: 'cpa', ar: 'الطلبات', en: 'Orders', costAr: 'تكلفة الطلب', costEn: 'CPA' },
}
const PATH_COLOR: Record<string, string> = { awareness: 'var(--info)', traffic: 'var(--purple)', conversion: 'var(--brand-600)' }

function ObjectiveBreakdown({ payload, ar, currency }: { payload: LivePayload; ar: boolean; currency: string }) {
  const paths = (payload.objective_performance?.paths ?? []).filter((p) => (p.spend ?? 0) > 0)
  const comparable = paths.every((p) => !(p.spend_withheld_rows ?? 0))
  const total = paths.reduce((s, p) => s + (p.spend ?? 0), 0)

  return (
    <Panel title={ar ? 'ماذا اشترى كل هدف' : 'What each objective bought'} testid="exec-objectives" className="lg:col-span-5">
      {paths.length === 0 ? (
        <p className="text-sm text-text-muted">{ar ? 'لا هدف أنفق في هذه الفترة' : 'No objective spent in this period'}</p>
      ) : (
        <>
          {comparable && total > 0 && (
            <div className="mb-3 flex h-2.5 overflow-hidden rounded-full bg-surface-secondary" data-testid="exec-objective-bar">
              {paths.map((p) => (
                <span key={p.path} style={{ width: `${(p.spend / total) * 100}%`, background: PATH_COLOR[p.path] ?? 'var(--brand-500)' }} />
              ))}
            </div>
          )}
          <ul className="grid grid-cols-1 gap-2">
            {paths.map((p) => {
              const r = PATH_RESULT[p.path]
              const costValue = r ? p[r.cost] : null

              return (
                <li key={p.path} data-testid={`exec-objective-${p.path}`} className="flex min-w-0 items-center justify-between gap-3 rounded-xl bg-surface-secondary px-3 py-2">
                  <span className="flex min-w-0 items-center gap-2">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: PATH_COLOR[p.path] ?? 'var(--brand-500)' }} />
                    <span className="min-w-0">
                      <span className="block truncate text-xs font-bold text-text-primary">{ar ? p.label_ar : p.label_en}</span>
                      <span className="block text-[11px] text-text-muted">
                        {ar ? 'الإنفاق' : 'Spend'} <Num>{(p.spend_withheld_rows ?? 0) > 0 ? '—' : money(p.spend, currency || null)}</Num>
                      </span>
                    </span>
                  </span>
                  {r && (
                    <span className="flex shrink-0 gap-3 text-end">
                      <span>
                        <span className="block tnum text-sm font-extrabold text-text-primary"><Num>{num(p[r.key])}</Num></span>
                        <span className="block text-[10px] text-text-muted">{ar ? r.ar : r.en}</span>
                      </span>
                      <span>
                        <span className="block tnum text-sm font-bold text-text-secondary"><Num>{costValue === null || costValue === undefined || (p.spend_withheld_rows ?? 0) > 0 ? '—' : money(costValue, currency || null)}</Num></span>
                        <span className="block text-[10px] text-text-muted">{ar ? r.costAr : r.costEn}</span>
                      </span>
                    </span>
                  )}
                </li>
              )
            })}
          </ul>
        </>
      )}
    </Panel>
  )
}

/* ─────────────────────────────── Funnel ─────────────────────────────── */

function FunnelPanel({ payload, ar, currency }: { payload: LivePayload; ar: boolean; currency: string }) {
  const stages = (payload.funnel ?? []).filter((s) => s.reported)
  if (stages.length < 2) return null

  return (
    <div className="lg:col-span-5" data-testid="exec-funnel">
      <ChartCard title={ar ? 'من الظهور إلى النتيجة' : 'From impression to result'}>
        <ConversionFunnelChart stages={stages} currency={currency} ar={ar} />
      </ChartCard>
    </div>
  )
}

/* ─────────────────────────────── Best content ─────────────────────────────── */

function BestContent({ payload, locale, currency, platform, onClear, onOpen }: {
  payload: LivePayload; locale: Locale; currency: string; platform: string | null; onClear: () => void; onOpen: (c: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const all = payload.ads ?? []
  const items = useMemo(
    () => (platform === null ? all : all.filter((a) => a.provider && canonicalPlatform(a.provider) === platform)).slice(0, 4),
    [all, platform],
  )
  const wide = (payload.funnel ?? []).filter((s) => s.reported).length < 2

  return (
    <Panel
      title={platform === null ? (ar ? 'أفضل المحتوى' : 'Best content') : `${ar ? 'أفضل المحتوى —' : 'Best content —'} ${providerLabel(platform, locale)}`}
      testid="exec-best-content"
      className={wide ? 'lg:col-span-12' : 'lg:col-span-7'}
      action={platform !== null ? (
        <button type="button" onClick={onClear} className="text-[11px] font-semibold text-accent hover:underline">{ar ? 'كل المنصات' : 'All platforms'}</button>
      ) : undefined}
    >
      {items.length === 0 ? (
        <p data-testid="exec-best-content-empty" className="text-sm text-text-muted">
          {platform === null
            ? (ar ? 'لا محتوى بنتائج قابلة للترتيب في هذه الفترة' : 'No content with rankable results in this period')
            : (ar ? 'لا محتوى مرتّب لهذه المنصة في هذه الفترة' : 'No ranked content for this platform in this period')}
        </p>
      ) : (
        <div className={`grid grid-cols-2 gap-3 ${wide ? 'lg:grid-cols-4' : 'xl:grid-cols-4'}`}>
          {items.map((ad, i) => (
            <ContentTile key={ad.content_key ?? `${ad.provider}-${i}`} content={ad} locale={locale} currency={currency || null} onOpen={onOpen} rank={i + 1} />
          ))}
        </div>
      )}
    </Panel>
  )
}

/* ─────────────────────────────── Health strip ─────────────────────────────── */

const STORES: Record<string, { ar: string; en: string }> = { salla: { ar: 'سلة', en: 'Salla' }, zid: { ar: 'زد', en: 'Zid' }, shopify: { ar: 'شوبيفاي', en: 'Shopify' } }
const sourceName = (provider: string, ar: boolean) => STORES[provider]?.[ar ? 'ar' : 'en'] ?? providerLabel(canonicalPlatform(provider), ar ? 'ar' : 'en')

/** The newest day in the window that carries any figure — not the link's allowed range, which can run ahead of the data. */
function lastDataDay(payload: LivePayload): string {
  const days = (payload.timeseries ?? [])
    .filter((row) => ['spend', 'impressions', 'clicks', 'conversions'].some((k) => Number(row[k] ?? 0) > 0))
    .map((row) => String(row.date ?? ''))
    .filter((d) => d !== '')
    .sort()

  return days[days.length - 1] ?? payload.period.to
}

function HealthStrip({ payload, ar, showBudget }: { payload: LivePayload; ar: boolean; showBudget: boolean }) {
  const budget = portfolioBudget((payload.budget ?? []).filter((r) => r.budget !== null && r.budget > 0))
  const used = showBudget && budget.budget !== null && budget.spent !== null && budget.budget > 0 ? budget.spent / budget.budget : null
  const missing = (payload.freshness ?? []).filter((f) => f.state === 'awaiting_credentials')
  const covered = (payload.freshness ?? []).filter((f) => f.state === 'synced')
  const attention = (payload.attention ?? []).filter((a) => a.decision !== 'hidden')
  const problems = attention.filter((a) => a.nature === 'problem').length

  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-3" data-testid="exec-health">
      <div className="min-w-0 rounded-2xl border border-border bg-surface p-3" data-testid="exec-health-budget">
        <p className="text-[11px] font-semibold text-text-muted">{ar ? 'الميزانية' : 'Budget'}</p>
        {used === null ? (
          <p className="mt-1 text-xs text-text-secondary">{ar ? 'لا ميزانية مقارنة لهذه الفترة' : 'No comparable budget for this period'}</p>
        ) : (
          <>
            <div className="mt-2 flex items-center gap-2">
              <span className="h-2 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                <span className={`block h-full rounded-full ${used > 1 ? 'bg-danger' : 'bg-success'}`} style={{ width: `${Math.min(100, Math.round(used * 100))}%` }} />
              </span>
              <span className="tnum text-sm font-extrabold text-text-primary"><Num>{`${Math.round(used * 100)}%`}</Num></span>
            </div>
            <p className="mt-1 text-[11px] text-text-muted">
              <Num>{money(budget.spent, budget.currency ?? payload.currency)}</Num> / <Num>{money(budget.budget, budget.currency ?? payload.currency)}</Num>
              {budget.pace !== null && <> · {ar ? 'الوتيرة' : 'Pace'} <Num>{`${budget.pace.toFixed(2)}×`}</Num></>}
            </p>
          </>
        )}
      </div>

      <div className="min-w-0 rounded-2xl border border-border bg-surface p-3" data-testid="exec-health-sources">
        <p className="text-[11px] font-semibold text-text-muted">{ar ? 'مصادر البيانات' : 'Data sources'}</p>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {covered.map((f) => (
            <span key={`ok-${f.provider}-${f.name ?? ''}`} className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] text-text-secondary">
              <CheckCircle2 size={11} className="text-success" />{sourceName(f.provider, ar)}
            </span>
          ))}
          {missing.map((f) => (
            <span key={`miss-${f.provider}-${f.name ?? ''}`} data-testid={`exec-source-missing-${f.provider}`} className="inline-flex items-center gap-1 rounded-full bg-[var(--warning-background)] px-2 py-0.5 text-[11px] text-warning">
              <CircleDashed size={11} />{sourceName(f.provider, ar)} — {ar ? 'غير مشمولة' : 'not included'}
            </span>
          ))}
        </div>
        <p className="mt-1.5 text-[11px] text-text-muted" data-testid="exec-data-through">{ar ? 'آخر يوم ببيانات' : 'Last day with data'} <Num>{lastDataDay(payload)}</Num></p>
      </div>

      <div className="min-w-0 rounded-2xl border border-border bg-surface p-3" data-testid="exec-health-attention">
        <p className="text-[11px] font-semibold text-text-muted">{ar ? 'ما يحتاج انتباهًا' : 'Needs attention'}</p>
        <div className="mt-2 flex items-center gap-3">
          <span className="inline-flex items-center gap-1 text-sm font-extrabold text-text-primary">
            <AlertTriangle size={14} className={problems > 0 ? 'text-warning' : 'text-text-muted'} /><Num>{String(problems)}</Num>
            <span className="text-[11px] font-semibold text-text-muted">{ar ? 'مشكلات' : 'issues'}</span>
          </span>
          <span className="inline-flex items-center gap-1 text-sm font-extrabold text-text-primary">
            <TrendingUp size={14} className="text-success" /><Num>{String(attention.length - problems)}</Num>
            <span className="text-[11px] font-semibold text-text-muted">{ar ? 'فرص' : 'opportunities'}</span>
          </span>
        </div>
      </div>
    </div>
  )
}

/* ─────────────────────────────── Recommendations ─────────────────────────────── */

const SEVERITY_STRIPE: Record<string, string> = { critical: 'bg-danger', warning: 'bg-warning', info: 'bg-info' }

function ActionCards({ payload, ar, onOpenContent }: { payload: LivePayload; ar: boolean; onOpenContent: (c: ReportAd) => void }) {
  const items: AttentionItem[] = (payload.attention ?? []).filter((a) => a.decision !== 'hidden')
  if (items.length === 0) return null
  const open = (item: AttentionItem) => {
    const key = item.evidence?.content?.[0]?.content_key
    const ad = key ? (payload.ads ?? []).find((a) => a.content_key === key) : undefined
    if (ad) onOpenContent(ad)
  }

  return (
    <section data-testid="exec-actions" aria-label={ar ? 'التوصيات' : 'Recommendations'}>
      <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><Lightbulb size={15} className="text-warning" />{ar ? 'التوصيات' : 'Recommendations'}</h3>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {items.slice(0, 6).map((item) => {
          const hasEvidence = !!item.evidence?.content?.[0]?.content_key

          return (
            <article key={item.key} data-testid={`exec-action-${item.key}`} className="relative flex min-w-0 flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-surface p-3 ps-4">
              <span className={`absolute inset-y-0 start-0 w-1 ${SEVERITY_STRIPE[item.severity] ?? 'bg-info'}`} />
              <div className="flex items-center justify-between gap-2">
                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${item.nature === 'problem' ? 'bg-[var(--negative-background)] text-danger' : 'bg-[var(--positive-background)] text-success'}`}>
                  {item.nature === 'problem' ? <AlertTriangle size={10} /> : <TrendingUp size={10} />}
                  {item.nature === 'problem' ? (ar ? 'مشكلة' : 'Issue') : (ar ? 'فرصة' : 'Opportunity')}
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] text-text-muted">
                  <span className="h-2 w-2 rounded-full" style={{ background: platformColor(canonicalPlatform(item.platform)) }} />
                  {providerLabel(canonicalPlatform(item.platform), ar ? 'ar' : 'en')}
                </span>
              </div>
              <p className="text-sm font-bold leading-snug text-text-primary">{attentionHeadline(item.code, ar)}</p>
              {item.impact !== null && (
                <p className="text-xs text-text-secondary" data-testid="exec-action-impact">{attentionImpact(item.impact.kind, ar)}: <span className="font-bold text-text-primary"><Num>{money(item.impact.amount, item.impact.currency)}</Num></span></p>
              )}
              <button
                type="button"
                disabled={!hasEvidence}
                onClick={() => open(item)}
                className="mt-auto inline-flex items-center justify-between gap-2 rounded-xl bg-surface-secondary px-3 py-2 text-start text-xs font-semibold text-text-primary enabled:hover:bg-surface-hover"
                data-testid="exec-action-do"
              >
                <span>{attentionAction(item.action, ar)}</span>
              </button>
            </article>
          )
        })}
      </div>
    </section>
  )
}
