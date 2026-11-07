import { useState } from 'react'
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { Badge } from '@/components/ui/Badge'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { FixedPageTable, type FixedColumn } from '@/components/ui/FixedPageTable'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { ChartCard, ConversionFunnelChart, KpiSparkline, MetricLineChart, PlatformDonutChart, RankingBarChart } from '../charts'
import { money, num, percent } from '../format'
import type { StoreFunnelStep, StoreGap, StoreKpi, StoreProductRow, StoreSeriesDay, StoreSessionRow, StoreSlice, StoreTableKey, StoreTables } from './storeTypes'

/**
 * GA4-STORE-ANALYTICS-001 — the drawn parts of the store reading.
 *
 * Presentational only: every part takes what the server stated and draws it, so Analytics, the live
 * link and the saved report show the same thing from the same component. The Owner's standing rules
 * are held here once: one-word labels, a chart or a chip before a sentence, money in USD with the
 * property's own currency kept beside it, and tables whose box does not move between pages.
 *
 * Nothing in this file adds a GA4 figure to an ad platform's figure. The gap is two bars side by side.
 */
type L = { ar: string; en: string }
const t = (l: L, ar: boolean) => (ar ? l.ar : l.en)

export const KPI_LABEL: Record<string, L> = {
  sessions: { ar: 'الزيارات', en: 'Sessions' },
  users_daily_sum: { ar: 'المستخدمون', en: 'Users' },
  new_users: { ar: 'الجدد', en: 'New' },
  bounce_rate: { ar: 'الارتداد', en: 'Bounce' },
  engagement_rate: { ar: 'التفاعل', en: 'Engagement' },
  avg_session_seconds: { ar: 'المدة', en: 'Duration' },
  transactions: { ar: 'المشتريات', en: 'Purchases' },
  revenue: { ar: 'المبيعات', en: 'Sales' },
  aov: { ar: 'السلة', en: 'Basket' },
  conversion_rate: { ar: 'التحويل', en: 'Conversion' },
  add_to_carts: { ar: 'الإضافة', en: 'Carts' },
  checkouts: { ar: 'الدفع', en: 'Checkouts' },
}

/** A fall in these is the good direction. */
const LOWER_IS_BETTER = new Set(['bounce_rate'])
const TONE: Record<string, 'brand' | 'info' | 'success' | 'neutral' | 'warning'> = {
  sessions: 'brand', users_daily_sum: 'brand', new_users: 'brand', bounce_rate: 'warning', engagement_rate: 'info', avg_session_seconds: 'info',
  transactions: 'success', revenue: 'success', aov: 'success', conversion_rate: 'success', add_to_carts: 'neutral', checkouts: 'neutral',
}

const CHANNEL: Record<string, L> = {
  'Paid Social': { ar: 'اجتماعي مدفوع', en: 'Paid social' },
  'Paid Search': { ar: 'بحث مدفوع', en: 'Paid search' },
  'Paid Video': { ar: 'فيديو مدفوع', en: 'Paid video' },
  'Paid Shopping': { ar: 'تسوق مدفوع', en: 'Paid shopping' },
  'Paid Other': { ar: 'مدفوع آخر', en: 'Paid other' },
  'Organic Search': { ar: 'بحث مجاني', en: 'Organic search' },
  'Organic Social': { ar: 'اجتماعي مجاني', en: 'Organic social' },
  'Organic Video': { ar: 'فيديو مجاني', en: 'Organic video' },
  'Organic Shopping': { ar: 'تسوق مجاني', en: 'Organic shopping' },
  Direct: { ar: 'مباشر', en: 'Direct' },
  Referral: { ar: 'إحالة', en: 'Referral' },
  Email: { ar: 'بريد', en: 'Email' },
  SMS: { ar: 'رسائل', en: 'SMS' },
  Display: { ar: 'عرض', en: 'Display' },
  Affiliates: { ar: 'شركاء', en: 'Affiliates' },
  'Cross-network': { ar: 'متعدد', en: 'Cross-network' },
  Unassigned: { ar: 'غير مصنف', en: 'Unassigned' },
}
const DEVICE: Record<string, L> = {
  mobile: { ar: 'جوال', en: 'Mobile' }, desktop: { ar: 'حاسوب', en: 'Desktop' }, tablet: { ar: 'لوحي', en: 'Tablet' }, 'smart tv': { ar: 'تلفاز', en: 'TV' },
}
const UNPAID: Record<string, L> = {
  organic: { ar: 'مجاني', en: 'Organic' }, direct: { ar: 'مباشر', en: 'Direct' }, referral: { ar: 'إحالة', en: 'Referral' }, other: { ar: 'أخرى', en: 'Other' },
}
const FUNNEL: Record<string, L> = {
  sessions: { ar: 'الزيارات', en: 'Sessions' }, add_to_carts: { ar: 'السلة', en: 'Cart' }, checkouts: { ar: 'الدفع', en: 'Checkout' }, purchases: { ar: 'الشراء', en: 'Purchase' },
}

export function kpiText(k: Pick<StoreKpi, 'kind' | 'currency'>, v: number | null | undefined, usd?: number | null): string {
  if (v === null || v === undefined) return '—'
  switch (k.kind) {
    case 'rate': return percent(v, 1)
    case 'money': return usd !== null && usd !== undefined ? money(usd, 'USD') : money(v, k.currency)
    case 'seconds': return `${Math.floor(v / 60)}:${String(Math.round(v % 60)).padStart(2, '0')}`
    default: return num(v)
  }
}

/** USD first; the property's own currency stays beside it. Without a rate the original stands alone. */
export function usdFirst(usd: number | null | undefined, original: number | null | undefined, currency: string | null | undefined): { text: string; original: string | null } {
  if (original === null || original === undefined) return { text: '—', original: null }
  if (usd === null || usd === undefined || (currency ?? '').toUpperCase() === 'USD') return { text: money(original, currency ?? ''), original: null }

  return { text: money(usd, 'USD'), original: money(original, currency ?? '') }
}

function Delta({ kpi, ar }: { kpi: StoreKpi; ar: boolean }) {
  const c = kpi.change_pct
  if (c === null || c === undefined) return null
  const flat = Math.abs(c) < 0.0005
  const good = LOWER_IS_BETTER.has(kpi.key) ? c < 0 : c > 0
  const Icon = flat ? Minus : c > 0 ? ArrowUpRight : ArrowDownRight
  const tone = flat ? 'text-text-muted bg-surface-secondary' : good ? 'text-success bg-success/10' : 'text-danger bg-danger/10'

  return (
    <span data-testid={`ga4-delta-${kpi.key}`} data-direction={flat ? 'flat' : c > 0 ? 'up' : 'down'} data-good={flat ? 'flat' : good ? 'yes' : 'no'}
      title={ar ? 'عن الفترة السابقة' : 'vs previous period'}
      className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-bold ${tone}`}>
      <Icon size={12} aria-hidden />
      <Num>{percent(Math.abs(c), 1)}</Num>
    </span>
  )
}

export function Ga4Tiles({ kpis, ar }: { kpis: StoreKpi[]; ar: boolean }) {
  return (
    <section data-testid="ga4-tiles" aria-label={ar ? 'مؤشرات المتجر' : 'Store KPIs'}>
      <StatGrid columns="grid-cols-2 sm:grid-cols-3 xl:grid-cols-6" sparks>
        {kpis.map((k) => {
          const usd = k.kind === 'money' ? k.current_usd : undefined
          const shownInUsd = k.kind === 'money' && usd !== null && usd !== undefined && (k.currency ?? '').toUpperCase() !== 'USD'
          const hint = k.current === null ? undefined : shownInUsd ? money(k.current, k.currency) : k.previous !== null ? kpiText(k, k.previous, k.previous_usd) : undefined

          return (
            <StatCard key={k.key} testid={`ga4-tile-${k.key}`} tone={TONE[k.key] ?? 'neutral'} dot label={t(KPI_LABEL[k.key] ?? { ar: k.key, en: k.key }, ar)}
              value={<Num>{kpiText(k, k.current, usd)}</Num>}
              hint={hint}
              trailing={<Delta kpi={k} ar={ar} />}
              spark={(k.trend?.length ?? 0) >= 2 ? <KpiSparkline points={(k.trend ?? []).map((p) => p.value)} /> : undefined} />
          )
        })}
      </StatGrid>
    </section>
  )
}

/** The chart's rows: revenue in USD where every day converted, otherwise in the property's currency. */
export function seriesForChart(series: StoreSeriesDay[], currency: string | null | undefined): { data: Array<Record<string, unknown>>; currency: string } {
  const withRevenue = series.filter((d) => d.revenue !== null)
  const usd = withRevenue.length > 0 && withRevenue.every((d) => d.revenue_usd !== null)

  return {
    data: series.map((d) => ({ date: d.date, sessions: d.sessions, purchases: d.purchases, revenue: usd ? d.revenue_usd : d.revenue })),
    currency: usd ? 'USD' : (currency ?? ''),
  }
}

export function Ga4Series({ series, currency, ar, height = 260 }: { series: StoreSeriesDay[]; currency: string | null | undefined; ar: boolean; height?: number }) {
  const chart = seriesForChart(series, currency)
  const hasRevenue = series.some((d) => d.revenue !== null)

  return (
    <ChartCard title={ar ? 'الزيارات والمبيعات' : 'Sessions & sales'} action={<Badge tone="neutral">{chart.currency || '—'}</Badge>}>
      <div data-testid="ga4-series" data-points={series.length} style={{ height }}>
        <MetricLineChart data={chart.data} height={height} currency={chart.currency} rightAxisFor={hasRevenue ? 'revenue' : undefined}
          series={[
            { key: 'sessions', name: ar ? 'الزيارات' : 'Sessions', kind: 'num' },
            ...(hasRevenue ? [{ key: 'revenue', name: ar ? 'المبيعات' : 'Sales', kind: 'money' as const, color: 'var(--success)' }] : []),
          ]} />
      </div>
    </ChartCard>
  )
}

/** Donut rows for a breakdown: the slice's own name translated, sessions as the value. */
export function donutRows(slices: StoreSlice[], labels: Record<string, L>, ar: boolean): Array<{ name: string; value: number; key: string }> {
  return slices.filter((s) => s.sessions > 0).map((s) => ({ key: s.key, name: t(labels[s.key] ?? labels[s.key.toLowerCase()] ?? { ar: s.key, en: s.key }, ar), value: s.sessions }))
}

function Donut({ testId, title, slices, labels, ar }: { testId: string; title: string; slices: StoreSlice[] | null | undefined; labels: Record<string, L>; ar: boolean }) {
  const rows = slices ? donutRows(slices, labels, ar) : []
  const total = rows.reduce((a, b) => a + b.value, 0)

  return (
    <ChartCard title={title}>
      {rows.length === 0 ? (
        <div data-testid={`${testId}-absent`} className="grid place-items-center" style={{ height: 260 }}><Badge tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Badge></div>
      ) : (
        <div data-testid={testId} data-slices={rows.length}>
          <PlatformDonutChart data={rows} colorBy="series" height={260} centerLabel={ar ? 'الزيارات' : 'Sessions'} centerValue={num(total)} />
        </div>
      )}
    </ChartCard>
  )
}

export function Ga4Acquisition({ channels, devices, funnel, ar }: { channels: StoreSlice[] | null | undefined; devices: StoreSlice[] | null | undefined; funnel: StoreFunnelStep[]; ar: boolean }) {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3" data-testid="ga4-acquisition">
      <Donut testId="ga4-channels" title={ar ? 'القنوات' : 'Channels'} slices={channels} labels={CHANNEL} ar={ar} />
      <Donut testId="ga4-devices" title={ar ? 'الأجهزة' : 'Devices'} slices={devices} labels={DEVICE} ar={ar} />
      <ChartCard title={ar ? 'القمع' : 'Funnel'}>
        <div data-testid="ga4-funnel">
          <ConversionFunnelChart ar={ar} stages={funnel.map((s) => ({ stage: s.stage, label: t(FUNNEL[s.stage] ?? { ar: s.stage, en: s.stage }, ar), count: s.count, step_rate: s.step_rate, cost_per: null, exceeds_previous: s.exceeds_previous }))} />
        </div>
      </ChartCard>
    </div>
  )
}

/** The comparison bars' rows: one row per platform, the platform's figure and GA4's side by side. */
export function gapBars(gap: StoreGap, ar: boolean): Array<{ label: string; platform: string; reported: number | null; ga4: number | null }> {
  return gap.rows.map((r) => ({ label: providerLabel(r.platform, ar ? 'ar' : 'en'), platform: r.platform, reported: r.reported?.conversions ?? null, ga4: r.ga4?.purchases ?? null }))
}

export function Ga4Gap({ gap, ar }: { gap: StoreGap; ar: boolean }) {
  const bars = gapBars(gap, ar)
  const locale = ar ? 'ar' : 'en'

  return (
    <ChartCard title={ar ? 'GA4 والمنصات' : 'GA4 vs platforms'} action={<Badge tone="info" data-testid="ga4-gap-not-summed">{ar ? 'بلا جمع' : 'Not summed'}</Badge>}>
      <div data-testid="ga4-gap" className="grid grid-cols-1 gap-4 lg:grid-cols-5">
        <div className="min-w-0 lg:col-span-3">
          {bars.length === 0 ? (
            <div className="grid place-items-center" style={{ height: 240 }}><Badge tone="neutral">{ar ? 'لا منصات' : 'No platforms'}</Badge></div>
          ) : (
            <div data-testid="ga4-gap-bars" data-rows={bars.length}>
              <RankingBarChart height={Math.max(240, bars.length * 56)} horizontal data={bars}
                bars={[
                  { key: 'reported', name: ar ? 'المنصة' : 'Platform', kind: 'num', color: 'var(--brand-600)' },
                  { key: 'ga4', name: 'GA4', kind: 'num', color: 'var(--teal)' },
                ]} />
            </div>
          )}
        </div>
        <ul className="min-w-0 space-y-2 lg:col-span-2" data-testid="ga4-gap-rows">
          {gap.rows.map((r) => {
            const g = r.gap.conversions
            const tone = g === null ? 'neutral' : Math.abs(r.gap.conversions_share ?? 0) >= 0.2 ? 'warning' : 'success'

            return (
              <li key={r.platform} data-testid={`ga4-gap-row-${r.platform}`} className="flex min-w-0 items-center justify-between gap-2 rounded-xl border border-border px-3 py-2">
                <span className="inline-flex min-w-0 items-center gap-2 font-semibold text-text-primary">
                  <ProviderMark provider={r.platform} size={18} />
                  <span className="truncate">{providerLabel(r.platform, locale)}</span>
                </span>
                <span className="inline-flex shrink-0 items-center gap-1.5 text-xs text-text-secondary">
                  <Num className="tnum">{r.reported?.conversions == null ? '—' : num(r.reported.conversions)}</Num>
                  <span aria-hidden>/</span>
                  <Num className="tnum">{r.ga4 == null ? '—' : num(r.ga4.purchases)}</Num>
                  <Badge tone={tone} data-testid={`ga4-gap-chip-${r.platform}`}>
                    <Num>{g === null ? '—' : `${g > 0 ? '+' : g < 0 ? '−' : ''}${num(Math.abs(g))}`}{r.gap.conversions_share !== null ? ` · ${percent(Math.abs(r.gap.conversions_share), 0)}` : ''}</Num>
                  </Badge>
                </span>
              </li>
            )
          })}
          {(gap.unpaid.length > 0 || gap.other_paid) && (
            <li className="flex flex-wrap gap-1.5 pt-1" data-testid="ga4-gap-unpaid">
              {gap.unpaid.map((u) => (
                <Badge key={u.class} tone="neutral" data-testid={`ga4-unpaid-${u.class}`}>
                  {t(UNPAID[u.class], ar)} <Num>{num(u.sessions)}{u.session_share !== null ? ` · ${percent(u.session_share, 0)}` : ''}</Num>
                </Badge>
              ))}
              {gap.other_paid && <Badge tone="neutral" data-testid="ga4-unpaid-other_paid">{ar ? 'مدفوع آخر' : 'Other paid'} <Num>{num(gap.other_paid.sessions)}</Num></Badge>}
            </li>
          )}
        </ul>
      </div>
    </ChartCard>
  )
}

const TABLE_LABEL: Record<StoreTableKey, L> = {
  sources: { ar: 'المصادر', en: 'Sources' },
  campaigns: { ar: 'الحملات', en: 'Campaigns' },
  landing_pages: { ar: 'الصفحات', en: 'Pages' },
  products: { ar: 'المنتجات', en: 'Products' },
  countries: { ar: 'الدول', en: 'Countries' },
}
export const TABLE_ORDER: StoreTableKey[] = ['sources', 'campaigns', 'landing_pages', 'products', 'countries']

function Money({ usd, original, currency }: { usd: number | null; original: number | null; currency: string | null | undefined }) {
  const m = usdFirst(usd, original, currency)

  return <span title={m.original ?? undefined}><Num>{m.text}</Num></span>
}

export function sessionColumns(ar: boolean, currency: string | null | undefined, first: string): FixedColumn<StoreSessionRow>[] {
  return [
    {
      key: 'name', header: first, width: '34%', value: (r) => r.name, title: (r) => (r.sub ? `${r.name} · ${r.sub}` : r.name),
      render: (r) => <span dir="auto" className="font-semibold">{r.name}{r.sub ? <span className="ms-1.5 font-normal text-text-muted" dir="ltr">{r.sub}</span> : null}</span>,
    },
    { key: 'sessions', header: ar ? 'الزيارات' : 'Sessions', width: '14%', align: 'end', value: (r) => r.sessions, render: (r) => <Num>{num(r.sessions)}</Num> },
    { key: 'engagement_rate', header: ar ? 'التفاعل' : 'Engaged', width: '13%', align: 'end', value: (r) => r.engagement_rate, render: (r) => <Num>{r.engagement_rate === null ? '—' : percent(r.engagement_rate, 0)}</Num> },
    { key: 'purchases', header: ar ? 'الشراء' : 'Orders', width: '12%', align: 'end', value: (r) => r.purchases, render: (r) => <Num>{num(r.purchases)}</Num> },
    { key: 'conversion_rate', header: ar ? 'التحويل' : 'CVR', width: '12%', align: 'end', value: (r) => r.conversion_rate, render: (r) => <Num>{r.conversion_rate === null ? '—' : percent(r.conversion_rate, 2)}</Num> },
    { key: 'revenue', header: ar ? 'المبيعات' : 'Sales', width: '15%', align: 'end', value: (r) => r.revenue, render: (r) => <Money usd={r.revenue_usd} original={r.revenue} currency={currency} /> },
  ]
}

export function productColumns(ar: boolean, currency: string | null | undefined): FixedColumn<StoreProductRow>[] {
  return [
    { key: 'name', header: ar ? 'المنتج' : 'Product', width: '40%', value: (r) => r.name, title: (r) => r.name, render: (r) => <span dir="auto" className="font-semibold">{r.name}</span> },
    { key: 'views', header: ar ? 'المشاهدة' : 'Views', width: '14%', align: 'end', value: (r) => r.views, render: (r) => <Num>{num(r.views)}</Num> },
    { key: 'carts', header: ar ? 'السلة' : 'Carts', width: '14%', align: 'end', value: (r) => r.carts, render: (r) => <Num>{num(r.carts)}</Num> },
    { key: 'purchased', header: ar ? 'المباع' : 'Sold', width: '14%', align: 'end', value: (r) => r.purchased, render: (r) => <Num>{num(r.purchased)}</Num> },
    { key: 'revenue', header: ar ? 'المبيعات' : 'Sales', width: '18%', align: 'end', value: (r) => r.revenue, render: (r) => <Money usd={r.revenue_usd} original={r.revenue} currency={currency} /> },
  ]
}

const FIRST_COLUMN: Record<Exclude<StoreTableKey, 'products'>, L> = {
  sources: { ar: 'المصدر', en: 'Source' }, campaigns: { ar: 'الحملة', en: 'Campaign' }, landing_pages: { ar: 'الصفحة', en: 'Page' }, countries: { ar: 'الدولة', en: 'Country' },
}

/**
 * The tables, one at a time behind short tabs, in ONE card whose size is the same for every tab and
 * every page. A table the server did not send (a client link never carries campaigns) has no tab.
 */
export function Ga4Tables({ tables, currency, ar, pageSize = 8, searchable = true }: { tables: StoreTables; currency: string | null | undefined; ar: boolean; pageSize?: number; searchable?: boolean }) {
  const available = TABLE_ORDER.filter((k) => (tables[k]?.rows.length ?? 0) > 0)
  const [chosen, setChosen] = useState<StoreTableKey | null>(null)
  const active = chosen !== null && available.includes(chosen) ? chosen : available[0]

  if (available.length === 0 || active === undefined) {
    return (
      <ChartCard title={ar ? 'الجداول' : 'Tables'}>
        <div data-testid="ga4-tables-absent" className="grid h-24 place-items-center"><Badge tone="neutral">{ar ? 'غير متاح' : 'Unavailable'}</Badge></div>
      </ChartCard>
    )
  }

  return (
    <ChartCard title={ar ? 'الجداول' : 'Tables'} action={
      <div role="tablist" data-testid="ga4-table-tabs" className="flex max-w-full flex-wrap gap-1">
        {available.map((k) => (
          <button key={k} type="button" role="tab" aria-selected={k === active} data-testid={`ga4-table-tab-${k}`} onClick={() => setChosen(k)}
            className={`rounded-full px-3 py-1 text-xs font-bold transition-colors ${k === active ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:text-text-primary'}`}>
            {t(TABLE_LABEL[k], ar)}
          </button>
        ))}
      </div>
    }>
      {active === 'products' ? (
        <FixedPageTable key="products" testId="ga4-table" ar={ar} pageSize={pageSize} searchable={searchable} rows={tables.products?.rows ?? []} rowKey={(r) => r.name}
          columns={productColumns(ar, currency)} initialSort={{ key: 'revenue', dir: 'desc' }} />
      ) : (
        <FixedPageTable key={active} testId="ga4-table" ar={ar} pageSize={pageSize} searchable={searchable} rows={tables[active]?.rows ?? []} rowKey={(r) => `${r.name}\u001f${r.sub ?? ''}`}
          columns={sessionColumns(ar, currency, t(FIRST_COLUMN[active], ar))} initialSort={{ key: 'sessions', dir: 'desc' }} />
      )}
    </ChartCard>
  )
}
