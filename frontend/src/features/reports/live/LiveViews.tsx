import { useMemo, useState } from 'react'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { platformColor } from '@/features/analytics/components'
import { ChartCard, MetricLineChart, ProgressRing } from '@/features/analytics/charts'
import { money, rowMoney } from '@/features/analytics/format'
import { canonicalPlatform } from '@/lib/platforms'
import { formatMoneyReading, readCostPer, type MoneyTotals } from '@/lib/money/contract'
import { Num } from '@/components/ui/Num'
import type { Locale } from '@/stores/ui'
import { portfolioBudget } from '@/lib/money/portfolioBudget'
import type { LivePayload } from '../api'
import { AttentionBlocks } from '../AttentionBlocks'
import { ClientAttention } from '../ClientAttention'
import { BusinessStreamsSection } from '../BusinessStreamsSection'
import { LiveDetailTables, LivePlatformComparison } from '../LiveDetailTables'
import { ReportAdsSection, type ReportAd } from '../ReportAdsSection'
import { ObjectiveAnalyticsSection } from '../ObjectiveAnalyticsSection'
import { asContent, ContentTile } from './LiveContent'
import { kpiKeysFor, LiveKpiBoard, LiveScopeCounts } from './LiveKpis'
import {
  FunnelSection,
  ObjectiveLeaders,
  ObjectiveSplit,
  StoreFunnelSection,
  TrendAndDistribution,
  useSpendCharting,
} from './LiveSections'
import type { useLiveMetricReader } from './liveMetrics'
import type { LiveLoad } from './useLivePayload'
import { sectionShown, type ReportSectionKey } from '../reportSections'

type Reader = ReturnType<typeof useLiveMetricReader>

type Common = {
  payload: LivePayload
  reader: Reader
  currency: string
  locale: Locale
  onOpenContent: (content: ReportAd) => void
}

const LEGACY_FLAG: Partial<Record<ReportSectionKey, keyof NonNullable<LivePayload['sections']>>> = {
  platform_comparison: 'platform_comparison',
  objective_breakdown: 'objective_breakdown',
  advanced_segmentation: 'objective_breakdown',
  content_performance: 'creatives',
  budget_pacing: 'budget',
  funnel: 'funnel_store',
}

/* The server's resolved set decides; the link's older flag is honoured for a payload that predates it. */
const sectionOn = (payload: LivePayload, key: ReportSectionKey) => {
  const legacy = LEGACY_FLAG[key]

  return sectionShown(payload, key) && (legacy === undefined || payload.sections?.[legacy] !== false)
}

function SectionTitle({ children, action }: { children: string; action?: React.ReactNode }) {
  return (
    <div className="mb-3 flex items-center justify-between gap-3">
      <h3 className="text-base font-bold tracking-tight text-text-primary">{children}</h3>
      {action}
    </div>
  )
}

function GoTo({ label, onClick, ar, testid }: { label: string; onClick: () => void; ar: boolean; testid: string }) {
  const Arrow = ar ? ArrowLeft : ArrowRight

  return (
    <button
      type="button"
      data-testid={testid}
      onClick={onClick}
      className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-surface-hover"
    >
      {label}
      <Arrow size={13} aria-hidden />
    </button>
  )
}

function ContentStrip({
  title,
  items,
  testid,
  currency,
  locale,
  onOpen,
  ranked = false,
  action,
  empty,
  columns = 4,
}: {
  columns?: 3 | 4
  title: string
  items: ReportAd[]
  testid: string
  currency: string
  locale: Locale
  onOpen: (c: ReportAd) => void
  ranked?: boolean
  action?: React.ReactNode
  empty?: string
}) {
  if (items.length === 0 && !empty) return null

  return (
    <section data-testid={testid}>
      <SectionTitle action={action}>{title}</SectionTitle>
      {items.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-text-secondary">{empty}</p>
      ) : (
        <div className={`grid grid-cols-2 gap-3 md:grid-cols-3 ${columns === 4 ? 'xl:grid-cols-4' : ''}`}>
          {items.map((c, i) => (
            <ContentTile key={c.content_key ?? `${c.name}-${i}`} content={c} locale={locale} currency={currency} onOpen={onOpen} rank={ranked ? i + 1 : undefined} />
          ))}
        </div>
      )}
    </section>
  )
}

/**
 * Results per platform as a bar list — counts only, no money, so no currency decision to get wrong.
 *
 * Drawn as markup rather than a Recharts horizontal bar: under `dir="rtl"` that chart dropped its
 * category labels and drew one bar, and a comparison whose rows cannot be told apart is not one.
 */
function PlatformResultsBars({ payload, ar }: { payload: LivePayload; ar: boolean }) {
  if (!sectionOn(payload, 'platform_comparison')) return null
  const rows = (payload.platforms ?? [])
    .filter((p) => p.conversions !== null && p.conversions !== undefined)
    .map((p) => ({ key: canonicalPlatform(p.provider), results: Number(p.conversions) }))
    .sort((a, b) => b.results - a.results)

  if (rows.length < 2) return null
  const top = Math.max(...rows.map((r) => r.results), 1)

  return (
    <ChartCard title={ar ? 'النتائج حسب المنصة' : 'Results by platform'}>
      <ul data-testid="live-summary-platform-results" className="flex flex-col gap-3">
        {rows.map((r) => (
          <li key={r.key} data-testid={`live-summary-results-${r.key}`} className="grid grid-cols-[6.5rem_1fr_auto] items-center gap-3">
            <span className="flex min-w-0 items-center gap-1.5 truncate text-sm font-semibold text-text-primary">
              <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: platformColor(r.key) }} aria-hidden />
              {providerLabel(r.key, ar ? 'ar' : 'en')}
            </span>
            <span className="h-3 overflow-hidden rounded-full bg-surface-secondary">
              <span className="block h-full rounded-full" style={{ width: `${Math.max(2, (r.results / top) * 100)}%`, background: platformColor(r.key) }} />
            </span>
            <span className="tnum text-sm font-extrabold text-text-primary"><Num>{r.results.toLocaleString('en-US')}</Num></span>
          </li>
        ))}
      </ul>
    </ChartCard>
  )
}

/**
 * High-level budget consumption — the one budget figure a summary needs, as a ring.
 *
 * `portfolioBudget` is the same arithmetic the dashboard's pacing and the campaigns overview use: only
 * comparable rows are added, and two budget currencies refuse a total rather than summing across
 * them. No ring is drawn when there is nothing honest to draw.
 */
function BudgetRing({ payload, ar }: { payload: LivePayload; ar: boolean }) {
  const rows = (payload.budget ?? []).filter((r) => r.budget !== null && r.budget > 0)
  const total = portfolioBudget(rows)
  if (total.budget === null || total.spent === null || total.budget <= 0) return null
  const share = total.spent / total.budget

  return (
    <ChartCard title={ar ? 'ما صُرف من الميزانية' : 'Budget used'}>
      <div data-testid="live-summary-budget" className="flex flex-wrap items-center justify-center gap-5 py-1">
        <ProgressRing value={share} size={124} tone={share > 1 ? 'danger' : 'brand'} />
        <dl className="grid gap-2 text-sm">
          <div>
            <dt className="text-xs text-text-muted">{ar ? 'المصروف' : 'Spent'}</dt>
            <dd className="tnum font-extrabold text-text-primary"><Num>{money(total.spent, total.currency ?? payload.currency)}</Num></dd>
          </div>
          <div>
            <dt className="text-xs text-text-muted">{ar ? 'الميزانية' : 'Budget'}</dt>
            <dd className="tnum font-extrabold text-text-primary"><Num>{money(total.budget, total.currency ?? payload.currency)}</Num></dd>
          </div>
        </dl>
      </div>
    </ChartCard>
  )
}

/**
 * Direct against blended is worth a card only where the two DIFFER — where no spend sits outside the
 * sales path they are one figure, and printing it twice under two names is padding. One rule for the
 * summary and the dashboard, so the two modes cannot disagree about when it appears.
 */
function splitDiffers(payload: LivePayload): boolean {
  const split = payload.objective_performance

  return sectionOn(payload, 'advanced_segmentation') && !!split
    && (Number(split.blended?.spend ?? 0) !== Number(split.direct?.spend ?? 0) || (split.blended?.blended_cpa ?? null) !== (split.direct?.cpa ?? null))
}

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the story's last step, the same on both views: the server's
 * client cut, drawn as blocks. The drill-down opens a piece of content this link already carries.
 */
function LiveAttention({ payload, ar, onOpenContent }: { payload: LivePayload; ar: boolean; onOpenContent: (content: ReportAd) => void }) {
  const open = (key: string) => {
    const ad = (payload.ads ?? []).find((a) => a.content_key === key)
    if (ad) onOpenContent(ad)
  }

  return <AttentionBlocks items={payload.attention} ar={ar} onOpenContent={open} />
}

/* ─────────────────────────────── A — Executive summary ─────────────────────────────── */

export function SummaryView({ payload, reader, currency, locale, onOpenContent }: Common) {
  const ar = locale === 'ar'
  const top = (payload.ads ?? []).slice(0, 3)
  const showSplit = splitDiffers(payload)

  return (
    <div data-testid="live-mode-summary" className="flex flex-col gap-5">
      <LiveKpiBoard payload={payload} reader={reader} ar={ar} keys={kpiKeysFor(payload, reader)} heroOnly />
      <LiveScopeCounts payload={payload} ar={ar} />
      {/*
        REPORT-OBJECTIVE-004 — the summary keeps direct against blended wherever the two DIFFER: it is
        the forwarded document, where a blended cost per order does the most damage. Where no spend sits
        outside the sales path the two are one figure, and printing it twice is padding. It comes before
        «where» for the reason CLIENT-FACING-PRESENTATION-001 gives: «at what cost» is asked first.
      */}
      {showSplit && <ObjectiveSplit payload={payload} ar={ar} reader={reader} />}
      <TrendAndDistribution payload={payload} ar={ar} currency={currency} />
      <PlatformResultsBars payload={payload} ar={ar} />
      {sectionOn(payload, 'content_performance') && (
        <ContentStrip
          title={ar ? 'أفضل المحتوى' : 'Best content'}
          items={top}
          testid="live-summary-top-content"
          columns={3}
          currency={currency}
          locale={locale}
          onOpen={onOpenContent}
          ranked
        />
      )}
      {sectionOn(payload, 'budget_pacing') && <BudgetRing payload={payload} ar={ar} />}
      {sectionOn(payload, 'recommendations') && <LiveAttention payload={payload} ar={ar} onOpenContent={onOpenContent} />}
    </div>
  )
}

/* ─────────────────────────────── B — Performance dashboard ─────────────────────────────── */

export function DashboardView({
  payload,
  reader,
  currency,
  locale,
  onOpenContent,
  goTo,
  onOpenPlatform,
}: Common & {
  goTo: (mode: 'content' | 'platforms', platform?: string) => void
  /** REPORT-DRILLDOWN-001 — the comparison's rows open a platform drawer where the link offers it. */
  onOpenPlatform?: (provider: string) => void
}) {
  const ar = locale === 'ar'

  return (
    <div data-testid="live-mode-dashboard" className="flex flex-col gap-5">
      <LiveKpiBoard payload={payload} reader={reader} ar={ar} keys={kpiKeysFor(payload, reader)} />
      <LiveScopeCounts payload={payload} ar={ar} />
      {/* CLIENT-FACING-PRESENTATION-001 — «at what cost» before «where»; shown only where it says something. */}
      {splitDiffers(payload) && <ObjectiveSplit payload={payload} ar={ar} reader={reader} />}
      {/* The charts come before the tables: the reader sees the shape of the period first, then the rows. */}
      <TrendAndDistribution payload={payload} ar={ar} currency={currency} />
      {sectionOn(payload, 'platform_comparison') && (
        <div>
          <LivePlatformComparison
            payload={payload}
            currency={currency}
            locale={ar ? 'ar' : 'en'}
            onOpenPlatform={payload.breakdowns?.platform_drilldown === false ? undefined : onOpenPlatform}
          />
          <div className="mt-1 flex justify-end">
            <GoTo testid="live-goto-platforms" ar={ar} label={ar ? 'كل منصة على حدة' : 'Each platform on its own'} onClick={() => goTo('platforms')} />
          </div>
        </div>
      )}
      {/*
        REPORT-OBJECTIVE-ANALYTICS-001 — the objective section supersedes the per-path leaders: the same
        «strongest and weakest inside an objective», per family rather than per money path, above a
        minimum volume, with content and contribution beside it. A payload built before it falls back.
        Either form is drawn only when the section model says the objective breakdown is visible.
      */}
      {sectionOn(payload, 'objective_breakdown') && (payload.objective_analytics
        ? <ObjectiveAnalyticsSection section={payload.objective_analytics} currency={currency} ar={ar} showContent={sectionOn(payload, 'content_performance')} />
        : <ObjectiveLeaders payload={payload} ar={ar} reader={reader} />)}
      {sectionOn(payload, 'objective_breakdown') && <ObjectiveLeaders payload={payload} ar={ar} reader={reader} />}
      {sectionOn(payload, 'advanced_segmentation') && <BusinessStreamsSection streams={payload.business_streams} coverTotal={payload.business_streams_cover_total === true} currency={currency} ar={ar} />}
      {sectionOn(payload, 'content_performance') && (
        <section className="flex flex-col gap-5">
          <div>
            <SectionTitle action={<GoTo testid="live-goto-content" ar={ar} label={ar ? 'كل المحتوى' : 'All content'} onClick={() => goTo('content')} />}>
              {ar ? 'أفضل المحتوى' : 'Best content'}
            </SectionTitle>
            <ReportAdsSection
              ads={payload.ads}
              groups={payload.ads_groups}
              currency={currency}
              absentReason={payload.ads_absent_reason}
              level={payload.ads_level}
              reading={payload.ads_reading}
              locale={locale}
              onOpen={onOpenContent}
            />
          </div>
          <ContentStrip
            title={ar ? 'أضعف المحتوى' : 'Weakest content'}
            items={(payload.ads_weakest ?? []).slice(0, 4)}
            testid="live-weakest-content"
            currency={currency}
            locale={locale}
            onOpen={onOpenContent}
          />
        </section>
      )}
      {sectionOn(payload, 'funnel') && <FunnelSection payload={payload} ar={ar} currency={currency} />}
      {sectionOn(payload, 'detailed_tables') && <LiveDetailTables payload={payload} currency={currency} locale={ar ? 'ar' : 'en'} />}
      {sectionOn(payload, 'budget_pacing') && <ClientAttention payload={payload} currency={currency} locale={locale} />}
      {sectionOn(payload, 'funnel') && <StoreFunnelSection payload={payload} ar={ar} />}
      {sectionOn(payload, 'recommendations') && <LiveAttention payload={payload} ar={ar} onOpenContent={onOpenContent} />}
    </div>
  )
}

/* ─────────────────────────────── shared: pending / failed of a narrowed load ─────────────────────────────── */

export function LoadGate({ load, ar, children }: { load: LiveLoad; ar: boolean; children: (payload: LivePayload) => React.ReactNode }) {
  if (load.state === 'pending') {
    return <div data-testid="live-view-pending" aria-busy="true" className="h-64 animate-pulse rounded-2xl bg-surface-secondary" />
  }
  if (load.state === 'failed') {
    return (
      <p data-testid="live-view-failed" className="rounded-2xl border border-border bg-surface p-6 text-center text-sm text-text-secondary">
        {load.message || (ar ? 'تعذّر تحميل هذه البيانات.' : 'These figures could not be loaded.')}
      </p>
    )
  }

  return (
    <div className={load.refreshing ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity'}>
      {children(load.payload)}
    </div>
  )
}

/** The platforms a link actually carries figures for, largest spend first. */
export function platformsOf(payload: LivePayload): string[] {
  return [...(payload.platforms ?? [])]
    .sort((a, b) => Number(b.spend ?? 0) - Number(a.spend ?? 0))
    .map((p) => canonicalPlatform(p.provider))
    .filter((p, i, all) => all.indexOf(p) === i)
}

/* ─────────────────────────────── C — Platform breakdown ─────────────────────────────── */

export function PlatformsView({
  whole,
  reader,
  currency,
  locale,
  onOpenContent,
  platform,
  onPlatform,
  narrowed,
  goToContent,
}: Omit<Common, 'payload'> & {
  whole: LivePayload
  platform: string | null
  onPlatform: (p: string) => void
  narrowed: LiveLoad
  goToContent: (platform: string) => void
}) {
  const ar = locale === 'ar'
  const platforms = platformsOf(whole)
  const wholeRows = whole.platforms ?? []
  const totalSpend = wholeRows.reduce((a, p) => a + Number(p.spend ?? 0), 0)
  const totalResults = wholeRows.reduce((a, p) => a + Number(p.conversions ?? 0), 0)

  if (platforms.length === 0) {
    return (
      <p data-testid="live-mode-platforms" className="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-text-secondary">
        {ar ? 'لا توجد أرقام لأي منصة في هذه الفترة.' : 'No platform has figures in this period.'}
      </p>
    )
  }

  return (
    <div data-testid="live-mode-platforms" className="flex flex-col gap-5">
      <div role="tablist" aria-label={ar ? 'المنصات' : 'Platforms'} className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {platforms.map((p) => {
          const row = wholeRows.find((r) => canonicalPlatform(r.provider) === p)
          const share = totalSpend > 0 && row ? Number(row.spend ?? 0) / totalSpend : null
          const selected = p === platform

          return (
            <button
              key={p}
              type="button"
              role="tab"
              aria-selected={selected}
              data-testid={`live-platform-card-${p}`}
              onClick={() => onPlatform(p)}
              className={`min-w-0 overflow-hidden rounded-2xl border bg-surface text-start transition-colors ${selected ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-border hover:border-brand-400'}`}
            >
              <span className="block h-1.5" style={{ background: platformColor(p) }} aria-hidden />
              <span className="flex flex-col gap-1 p-3">
                <span className="truncate text-sm font-bold text-text-primary">{providerLabel(p, locale)}</span>
                <span className="tnum truncate text-lg font-extrabold text-text-primary">
                  <Num>{row ? rowMoney(row as unknown as MoneyTotals, 'spend', currency) : '—'}</Num>
                </span>
                <span className="tnum truncate text-xs text-text-secondary">
                  <Num>{row?.conversions === null || row?.conversions === undefined ? '—' : Number(row.conversions).toLocaleString('en-US')}</Num>
                  {' '}{ar ? 'نتيجة' : 'results'}
                </span>
                {share !== null && (
                  <span className="mt-1 block h-1.5 overflow-hidden rounded-full bg-surface-secondary" aria-label={ar ? 'حصة الإنفاق' : 'Share of spend'}>
                    <span className="block h-full rounded-full" style={{ width: `${Math.round(share * 100)}%`, background: platformColor(p) }} />
                  </span>
                )}
              </span>
            </button>
          )
        })}
      </div>

      {platform && (
        <LoadGate load={narrowed} ar={ar}>
          {(one) => (
            <PlatformDetail
              platform={platform}
              payload={one}
              wholeRow={wholeRows.find((r) => canonicalPlatform(r.provider) === platform)}
              totalSpend={totalSpend}
              totalResults={totalResults}
              reader={reader}
              currency={currency}
              locale={locale}
              onOpenContent={onOpenContent}
              goToContent={goToContent}
            />
          )}
        </LoadGate>
      )}
    </div>
  )
}

function PlatformDetail({
  platform,
  payload,
  wholeRow,
  totalSpend,
  totalResults,
  reader,
  currency,
  locale,
  onOpenContent,
  goToContent,
}: Common & {
  platform: string
  wholeRow: LivePayload['platforms'][number] | undefined
  totalSpend: number
  totalResults: number
  goToContent: (platform: string) => void
}) {
  const ar = locale === 'ar'
  const { spendChartable } = useSpendCharting(payload, currency)
  const spendShare = totalSpend > 0 && wholeRow ? Number(wholeRow.spend ?? 0) / totalSpend : null
  const resultShare = totalResults > 0 && wholeRow ? Number(wholeRow.conversions ?? 0) / totalResults : null
  const name = providerLabel(platform, locale)

  return (
    <section data-testid={`live-platform-view-${platform}`} className="flex flex-col gap-5">
      <h2 className="flex items-center gap-2 text-xl font-extrabold text-text-primary">
        <span className="inline-block h-3 w-3 rounded-full" style={{ background: platformColor(platform) }} aria-hidden />
        {name}
      </h2>

      <LiveKpiBoard payload={payload} reader={reader} ar={ar} keys={kpiKeysFor(payload, reader)} />

      <div className="grid gap-3 lg:grid-cols-3 [&>*]:min-w-0">
        {sectionOn(payload, 'trends') && (
        <ChartCard title={ar ? 'الأداء بمرور الوقت' : 'Performance over time'} className="lg:col-span-2">
          <div data-testid="live-platform-trend">
            <MetricLineChart
              data={payload.timeseries ?? []}
              currency={currency}
              height={220}
              rightAxisFor="conversions"
              series={[
                ...(spendChartable ? [{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', color: platformColor(platform), kind: 'money' as const }] : []),
                ...(spendChartable ? [] : [{ key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const }]),
                { key: 'conversions', name: ar ? 'النتائج' : 'Results', color: 'var(--purple)', kind: 'num' as const },
              ]}
            />
          </div>
        </ChartCard>
        )}
        <ChartCard title={ar ? `حصة ${name} من الإجمالي` : `${name}’s share of the total`}>
          <div data-testid="live-platform-shares" className="flex flex-wrap items-center justify-around gap-4 py-2">
            {spendShare !== null && <ProgressRing value={spendShare} sublabel={ar ? 'من الإنفاق' : 'of spend'} size={116} />}
            {resultShare !== null && <ProgressRing value={resultShare} sublabel={ar ? 'من النتائج' : 'of results'} size={116} tone="success" />}
            {spendShare === null && resultShare === null && <span className="text-sm text-text-muted">—</span>}
          </div>
          {wholeRow && (
            <p className="tnum mt-2 text-center text-xs text-text-secondary">
              {ar ? 'تكلفة النتيجة' : 'Cost per result'}: <Num>{formatMoneyReading(readCostPer(wholeRow as unknown as MoneyTotals, 'cpa', 'conversions', currency, ar), (v) => reader.asExactMoney(v))}</Num>
            </p>
          )}
        </ChartCard>
      </div>

      {sectionOn(payload, 'content_performance') && (
        <>
          <ContentStrip
            title={ar ? `أفضل محتوى على ${name}` : `Best content on ${name}`}
            items={(payload.ads ?? []).slice(0, 4)}
            testid="live-platform-best-content"
            currency={currency}
            locale={locale}
            onOpen={onOpenContent}
            ranked
            action={<GoTo testid="live-platform-goto-content" ar={ar} label={ar ? 'كل محتوى المنصة' : 'All content on this platform'} onClick={() => goToContent(platform)} />}
            empty={ar ? 'لا يوجد محتوى بأرقام على هذه المنصة في هذه الفترة.' : 'No content with figures on this platform in this period.'}
          />
          <ContentStrip
            title={ar ? `أضعف محتوى على ${name}` : `Weakest content on ${name}`}
            items={(payload.ads_weakest ?? []).slice(0, 4)}
            testid="live-platform-weakest-content"
            currency={currency}
            locale={locale}
            onOpen={onOpenContent}
          />
        </>
      )}
    </section>
  )
}

/* ─────────────────────────────── D — Content drilldown ─────────────────────────────── */

const PAGE = 24

type SortKey = 'spend' | 'conversions' | 'ctr'

function sortValue(c: ReportAd, key: SortKey): number | null {
  const v = (c as Record<string, unknown>)[key]

  return typeof v === 'number' ? v : null
}

export function ContentView({
  whole,
  currency,
  locale,
  onOpenContent,
  platform,
  onPlatform,
  narrowed,
}: Omit<Common, 'payload' | 'reader'> & {
  whole: LivePayload
  platform: string | null
  onPlatform: (p: string | null) => void
  narrowed: LiveLoad
}) {
  const ar = locale === 'ar'
  // Without the comparison rows, the platforms the link covers still name the tabs.
  const fromRows = platformsOf(whole)
  const platforms = fromRows.length > 0 ? fromRows : (whole.available?.providers ?? []).map(canonicalPlatform).filter((p, i, all) => all.indexOf(p) === i)

  return (
    <div data-testid="live-mode-content" className="flex flex-col gap-5">
      <div role="tablist" aria-label={ar ? 'المنصة' : 'Platform'} className="flex flex-wrap gap-1.5">
        {[null, ...platforms].map((p) => {
          const selected = p === platform

          return (
            <button
              key={p ?? 'all'}
              type="button"
              role="tab"
              aria-selected={selected}
              data-testid={`live-content-platform-${p ?? 'all'}`}
              onClick={() => onPlatform(p)}
              className={`inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm font-semibold transition-colors ${selected ? 'border-transparent bg-brand-600 text-white' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
            >
              {p && <span className="inline-block h-2 w-2 rounded-full" style={{ background: platformColor(p) }} aria-hidden />}
              {p ? providerLabel(p, locale) : (ar ? 'كل المنصات' : 'All platforms')}
            </button>
          )
        })}
      </div>

      {platform === null
        ? <ContentBody payload={whole} currency={currency} locale={locale} onOpenContent={onOpenContent} />
        : (
          <LoadGate load={narrowed} ar={ar}>
            {(one) => <ContentBody payload={one} currency={currency} locale={locale} onOpenContent={onOpenContent} />}
          </LoadGate>
        )}
    </div>
  )
}

function ContentBody({ payload, currency, locale, onOpenContent }: Omit<Common, 'reader'>) {
  const ar = locale === 'ar'
  const [sort, setSort] = useState<SortKey>('spend')
  const [shown, setShown] = useState(PAGE)

  const all = useMemo(() => {
    const rows = (payload.ads_roster ?? []).map(asContent)

    return [...rows].sort((a, b) => {
      const va = sortValue(a, sort)
      const vb = sortValue(b, sort)
      // A creative with no figure for this column goes last, never ranked as a zero.
      if (va === null && vb === null) return 0
      if (va === null) return 1
      if (vb === null) return -1
      return vb - va
    })
  }, [payload, sort])

  if (!sectionOn(payload, 'content_performance')) {
    return <p className="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-text-secondary">{ar ? 'هذا الرابط لا يعرض المحتوى.' : 'This link does not show content.'}</p>
  }

  const inScope = payload.creatives_in_scope ?? all.length

  return (
    <div className="flex flex-col gap-5">
      <ContentStrip
        title={ar ? 'الأفضل أداءً' : 'Best performing'}
        items={(payload.ads ?? []).slice(0, 4)}
        testid="live-content-best"
        currency={currency}
        locale={locale}
        onOpen={onOpenContent}
        ranked
      />
      <ContentStrip
        title={ar ? 'الأضعف أداءً' : 'Weakest performing'}
        items={(payload.ads_weakest ?? []).slice(0, 4)}
        testid="live-content-weakest"
        currency={currency}
        locale={locale}
        onOpen={onOpenContent}
      />

      <section data-testid="live-content-all">
        <SectionTitle
          action={(
            <label className="inline-flex items-center gap-2 text-xs text-text-secondary">
              {ar ? 'ترتيب' : 'Sort'}
              <select
                data-testid="live-content-sort"
                value={sort}
                onChange={(e) => { setSort(e.target.value as SortKey); setShown(PAGE) }}
                className="rounded-lg border border-border bg-surface px-2 py-1 text-xs text-text-primary"
              >
                <option value="spend">{ar ? 'الإنفاق' : 'Spend'}</option>
                <option value="conversions">{ar ? 'النتائج' : 'Results'}</option>
                <option value="ctr">{ar ? 'نسبة النقر' : 'CTR'}</option>
              </select>
            </label>
          )}
        >
          {ar ? 'كل المحتوى' : 'All content'}
        </SectionTitle>
        <p data-testid="live-content-count" className="tnum -mt-2 mb-3 text-xs text-text-muted">
          <Num>{ar ? `${inScope} محتوى في هذه الفترة` : `${inScope} pieces of content in this period`}</Num>
        </p>
        {all.length === 0 ? (
          <p className="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-text-secondary">
            {ar ? 'لم يُعرض أي محتوى في هذه الفترة.' : 'No content ran in this period.'}
          </p>
        ) : (
          <>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
              {all.slice(0, shown).map((c, i) => (
                <ContentTile key={c.content_key ?? `${c.name}-${i}`} content={c} locale={locale} currency={currency} onOpen={onOpenContent} />
              ))}
            </div>
            {shown < all.length && (
              <div className="mt-3 flex justify-center">
                <button
                  type="button"
                  data-testid="live-content-more"
                  onClick={() => setShown((n) => n + PAGE)}
                  className="rounded-xl border border-border px-4 py-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover"
                >
                  <Num>{ar ? `عرض المزيد (${all.length - shown})` : `Show more (${all.length - shown})`}</Num>
                </button>
              </div>
            )}
          </>
        )}
      </section>
    </div>
  )
}
