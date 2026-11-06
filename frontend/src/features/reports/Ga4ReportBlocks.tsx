import { Badge } from '@/components/ui/Badge'
import { FixedPageTable } from '@/components/ui/FixedPageTable'
import { Num } from '@/components/ui/Num'
import { Ga4Acquisition, Ga4Gap, Ga4Series, Ga4Tables, Ga4Tiles, productColumns, sessionColumns } from '@/features/analytics/ga4/Ga4StoreView'
import type { Ga4AcquisitionSection, Ga4GapSection, Ga4OverviewSection, Ga4TablesSection, StoreProductRow, StoreSessionRow, StoreTableKey } from '@/features/analytics/ga4/storeTypes'
import { AttributionMatchBlock, GapReasonsBlock } from '@/features/analytics/ga4/Ga4MatchBlock'
import type { ClassificationRow, GapReasonsPayload, MatchPayload } from '@/features/analytics/ga4/matchTypes'
import { ChartCard } from '@/features/analytics/charts'
import { money, num, percent } from '@/features/analytics/format'
import { useState } from 'react'
import type { FixedColumn } from '@/components/ui/FixedPageTable'
import { sectionShown, type ReportSectionKey, type WithReportSections } from './reportSections'

/**
 * GA4-REPORT-SECTIONS-001 — the store reading inside a report: the live link and the saved report
 * draw the same four parts with the same components Analytics uses.
 *
 * A part is drawn only when the server resolved it as shown AND sent its data: a hidden part's data
 * is absent from the payload, and an older snapshot that never had the part gains nothing. What a
 * client may read was decided on the server (no campaigns table, no spend, money nulled where the
 * link hides revenue); nothing here adds a GA4 figure to a platform's.
 */
export interface WithGa4Sections extends WithReportSections {
  ga4_overview?: (Ga4OverviewSection & { revenue_hidden?: boolean }) | null
  ga4_acquisition?: Ga4AcquisitionSection | null
  ga4_platform_gap?: Ga4GapSection | null
  ga4_tables?: Ga4TablesSection | null
  ga4_attribution?: MatchPayload | null
  ga4_pulse?: { currency: string | null; usd: unknown; period: { from: string; to: string }; kpis: Ga4OverviewSection['kpis']; funnel: Ga4AcquisitionSection['funnel'] } | null
  ga4_classification?: { currency: string | null; groups: Record<string, { total: number; rows: ClassificationRow[] }> } | null
  ga4_products?: { currency: string | null; table: { rows: StoreProductRow[]; total: number } } | null
  ga4_gap_reasons?: GapReasonsPayload | null
}

export const GA4_PARTS = ['ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables', 'ga4_attribution', 'ga4_pulse', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons'] as const
export type Ga4Part = (typeof GA4_PARTS)[number]

/** Stricter than `sectionShown`: the server must have NAMED the part, and sent it. */
export function ga4PartShown(payload: WithGa4Sections | null | undefined, part: Ga4Part): boolean {
  const list = payload?.report_sections

  return Array.isArray(list) && (list as string[]).includes(part) && sectionShown(payload, part as ReportSectionKey) && payload?.[part] != null
}

export function Ga4ReportBlocks({ payload, ar, parts = GA4_PARTS, pageSize = 8 }: { payload: WithGa4Sections; ar: boolean; parts?: readonly Ga4Part[]; pageSize?: number }) {
  const shown = parts.filter((p) => ga4PartShown(payload, p))
  if (shown.length === 0) return null
  const overview = shown.includes('ga4_overview') ? payload.ga4_overview : null
  const acquisition = shown.includes('ga4_acquisition') ? payload.ga4_acquisition : null
  const gap = shown.includes('ga4_platform_gap') ? payload.ga4_platform_gap : null
  const tables = shown.includes('ga4_tables') ? payload.ga4_tables : null
  const attribution = shown.includes('ga4_attribution') ? payload.ga4_attribution : null
  const pulse = shown.includes('ga4_pulse') ? payload.ga4_pulse : null
  const classification = shown.includes('ga4_classification') ? payload.ga4_classification : null
  const products = shown.includes('ga4_products') ? payload.ga4_products : null
  const reasons = shown.includes('ga4_gap_reasons') ? payload.ga4_gap_reasons : null

  return (
    <section data-testid="report-ga4" className="flex min-w-0 flex-col gap-4">
      <header className="flex flex-wrap items-center gap-2">
        <h2 className="text-lg font-extrabold tracking-tight text-text-primary">{ar ? 'المتجر' : 'Store'}</h2>
        <Badge tone="info">GA4</Badge>
        {overview?.property?.name && <span className="text-xs text-text-secondary">{overview.property.name}</span>}
      </header>
      {overview && (
        <div data-testid="report-ga4-overview" className="flex min-w-0 flex-col gap-4">
          <Ga4Tiles kpis={overview.kpis} ar={ar} />
          <Ga4Series series={overview.series} currency={overview.currency} ar={ar} />
        </div>
      )}
      {acquisition && <div data-testid="report-ga4-acquisition" className="min-w-0"><Ga4Acquisition channels={acquisition.channels} devices={acquisition.devices} funnel={acquisition.funnel} ar={ar} /></div>}
      {gap && <div data-testid="report-ga4-gap" className="min-w-0"><Ga4Gap gap={gap} ar={ar} /></div>}
      {tables && <div data-testid="report-ga4-tables" className="min-w-0"><Ga4Tables tables={tables.tables} currency={tables.currency} ar={ar} pageSize={pageSize} /></div>}
      {pulse && <div data-testid="report-ga4-pulse" className="flex min-w-0 flex-col gap-4"><Ga4Tiles kpis={pulse.kpis} ar={ar} />{pulse.funnel.length > 0 && <Ga4Acquisition channels={null} devices={null} funnel={pulse.funnel} ar={ar} />}</div>}
      {attribution && <div data-testid="report-ga4-attribution" className="min-w-0"><AttributionMatchBlock data={attribution} ar={ar} pageSize={pageSize} /></div>}
      {reasons && <div data-testid="report-ga4-reasons" className="min-w-0"><GapReasonsBlock data={reasons} ar={ar} /></div>}
      {classification && <div data-testid="report-ga4-classification" className="min-w-0"><ClassificationBlock data={classification} ar={ar} pageSize={pageSize} /></div>}
      {products && <div data-testid="report-ga4-products" className="min-w-0"><ChartCard title={ar ? 'المنتجات' : 'Products'}><FixedPageTable testId="ga4-products-table" ar={ar} pageSize={pageSize} rows={products.table.rows} rowKey={(r) => r.name} columns={productColumns(ar, products.currency)} initialSort={{ key: 'revenue', dir: 'desc' }} /></ChartCard></div>}
    </section>
  )
}

const GROUP_TITLE: Record<string, { ar: string; en: string }> = { channel: { ar: 'القنوات', en: 'Channels' }, platform: { ar: 'المنصات', en: 'Platforms' }, landing_page: { ar: 'الصفحات', en: 'Pages' } }

function classificationColumns(ar: boolean, first: string): FixedColumn<ClassificationRow>[] {
  const c = (v: number | null | undefined, f: (x: number) => string = num) => (v === null || v === undefined ? '—' : f(v))

  return [
    { key: 'label', header: first, width: '38%', value: (r) => r.label, title: (r) => r.label, render: (r) => <span dir="auto" className="font-semibold">{r.label}</span> },
    { key: 'sessions', header: ar ? 'الزيارات' : 'Sessions', width: '16%', align: 'end', value: (r) => r.sessions ?? null, render: (r) => <Num>{c(r.sessions)}</Num> },
    { key: 'purchases', header: ar ? 'الشراء' : 'Orders', width: '14%', align: 'end', value: (r) => r.purchases ?? null, render: (r) => <Num>{c(r.purchases)}</Num> },
    { key: 'conversion_rate', header: ar ? 'التحويل' : 'CVR', width: '14%', align: 'end', value: (r) => r.conversion_rate ?? null, render: (r) => <Num>{c(r.conversion_rate, (x) => percent(x, 2))}</Num> },
    { key: 'revenue', header: ar ? 'المبيعات' : 'Sales', width: '18%', align: 'end', value: (r) => r.revenue_usd ?? null, render: (r) => <Num>{c(r.revenue_usd, (x) => money(x, 'USD'))}</Num> },
  ]
}

/** The grouping the saved report froze: a short tab per grouping, one fixed-size table under it. */
export function ClassificationBlock({ data, ar, pageSize = 8, paged = false, group, range }: { data: NonNullable<WithGa4Sections['ga4_classification']>; ar: boolean; pageSize?: number; paged?: boolean; group?: string; range?: [number, number] }) {
  const keys = group && data.groups[group] ? [group] : Object.keys(data.groups)
  const [chosen, setChosen] = useState<string | null>(null)
  const active = chosen !== null && keys.includes(chosen) ? chosen : keys[0]
  if (active === undefined) return null

  return (
    <ChartCard title={ar ? 'تصنيف' : 'Classify'} action={paged ? <Badge tone="neutral">{ar ? GROUP_TITLE[active]?.ar ?? active : GROUP_TITLE[active]?.en ?? active}</Badge> : <div role="tablist" className="flex gap-1">{keys.map((k) => (
      <button key={k} type="button" role="tab" aria-selected={k === active} data-testid={`ga4-classification-tab-${k}`} onClick={() => setChosen(k)} className={`rounded-full px-3 py-1 text-xs font-bold ${k === active ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary'}`}>{ar ? GROUP_TITLE[k]?.ar ?? k : GROUP_TITLE[k]?.en ?? k}</button>
    ))}</div>}>
      <FixedPageTable key={active} testId="ga4-classification-table" ar={ar} pageSize={pageSize} pager={!paged} rows={range ? data.groups[active].rows.slice(range[0], range[1]) : data.groups[active].rows} rowKey={(r) => r.label} columns={classificationColumns(ar, ar ? GROUP_TITLE[active]?.ar ?? active : GROUP_TITLE[active]?.en ?? active)} initialSort={{ key: 'sessions', dir: 'desc' }} />
    </ChartCard>
  )
}

const SLIDE_TITLE: Record<string, { ar: string; en: string }> = {
  __ga4_pulse: { ar: 'نبض المتجر', en: 'Store pulse' },
  __ga4_attribution: { ar: 'مطابقة الإسناد', en: 'Attribution match' },
  __ga4_reasons: { ar: 'سبب الفرق', en: 'Why the gap' },
  __ga4_classification: { ar: 'تصنيف', en: 'Classify' },
  __ga4_products: { ar: 'المنتجات', en: 'Products' },
  __ga4_overview: { ar: 'المتجر', en: 'Store' },
  __ga4_acquisition: { ar: 'القنوات', en: 'Channels' },
  __ga4_gap: { ar: 'GA4 والمنصات', en: 'GA4 vs platforms' },
  __ga4_tables: { ar: 'الجداول', en: 'Tables' },
  sources: { ar: 'المصادر', en: 'Sources' },
  campaigns: { ar: 'الحملات', en: 'Campaigns' },
  landing_pages: { ar: 'الصفحات', en: 'Pages' },
  products: { ar: 'المنتجات', en: 'Products' },
  countries: { ar: 'الدول', en: 'Countries' },
}
const FIRST: Record<string, { ar: string; en: string }> = {
  sources: { ar: 'المصدر', en: 'Source' }, campaigns: { ar: 'الحملة', en: 'Campaign' }, landing_pages: { ar: 'الصفحة', en: 'Page' }, countries: { ar: 'الدولة', en: 'Country' },
}

/**
 * One page of the saved report's deck. `paged` is the printed page: charts are a little shorter so a
 * page holds its tiles and its chart at full size, and a table page is exactly its rows.
 */
export function Ga4Slide({ slide, data, ar = true, paged = false }: { slide: { id: string; type: string; range?: [number, number] }; data: WithGa4Sections; ar?: boolean; paged?: boolean }) {
  const head = (key: string, chip?: string) => (
    <div className="mb-3 flex flex-wrap items-center gap-2">
      <h2 className="text-2xl font-extrabold tracking-tight text-text-primary">{ar ? SLIDE_TITLE[key]?.ar : SLIDE_TITLE[key]?.en}</h2>
      <Badge tone="info">GA4</Badge>
      {chip && <Badge tone="neutral"><Num>{chip}</Num></Badge>}
    </div>
  )

  switch (slide.type) {
    case '__ga4_overview':
      return data.ga4_overview ? (
        <div data-testid="slide-ga4-overview">{head('__ga4_overview', data.ga4_overview.property?.name ?? undefined)}
          <div className="flex flex-col gap-3"><Ga4Tiles kpis={data.ga4_overview.kpis} ar={ar} /><Ga4Series series={data.ga4_overview.series} currency={data.ga4_overview.currency} ar={ar} height={paged ? 190 : 260} /></div>
        </div>
      ) : null
    case '__ga4_acquisition':
      return data.ga4_acquisition ? <div data-testid="slide-ga4-acquisition">{head('__ga4_acquisition')}<Ga4Acquisition channels={data.ga4_acquisition.channels} devices={data.ga4_acquisition.devices} funnel={data.ga4_acquisition.funnel} ar={ar} /></div> : null
    case '__ga4_gap':
      return data.ga4_platform_gap ? <div data-testid="slide-ga4-gap">{head('__ga4_gap')}<Ga4Gap gap={data.ga4_platform_gap} ar={ar} /></div> : null
    case '__ga4_tables':
      return data.ga4_tables ? <div data-testid="slide-ga4-tables">{head('__ga4_tables')}<Ga4Tables tables={data.ga4_tables.tables} currency={data.ga4_tables.currency} ar={ar} /></div> : null
    case '__ga4_pulse':
      return data.ga4_pulse ? <div data-testid="slide-ga4-pulse">{head('__ga4_pulse')}<div className="flex flex-col gap-3"><Ga4Tiles kpis={data.ga4_pulse.kpis} ar={ar} />{data.ga4_pulse.funnel.length > 0 && <Ga4Acquisition channels={null} devices={null} funnel={data.ga4_pulse.funnel} ar={ar} />}</div></div> : null
    case '__ga4_attribution':
      return data.ga4_attribution ? <div data-testid="slide-ga4-attribution">{head('__ga4_attribution')}<AttributionMatchBlock data={data.ga4_attribution} ar={ar} pageSize={paged ? 6 : 8} paged={paged} range={slide.range} /></div> : null
    case '__ga4_reasons':
      return data.ga4_gap_reasons ? <div data-testid="slide-ga4-reasons">{head('__ga4_reasons')}<GapReasonsBlock data={data.ga4_gap_reasons} ar={ar} /></div> : null
    case '__ga4_classification':
      return data.ga4_classification ? <div data-testid="slide-ga4-classification">{head('__ga4_classification')}<ClassificationBlock data={data.ga4_classification} ar={ar} pageSize={8} paged={paged} group={slide.id.split(':')[1]} range={slide.range} /></div> : null
    case '__ga4_products': {
      const rows = data.ga4_products?.table.rows ?? []
      const [from, to] = slide.range ?? [0, 8]

      return data.ga4_products ? <div data-testid="slide-ga4-products">{head('__ga4_products')}<FixedPageTable testId="ga4-print-table" ar={ar} pager={false} pageSize={8} rows={rows.slice(from, to)} rowKey={(r) => r.name} columns={productColumns(ar, data.ga4_products.currency)} /></div> : null
    }
    case '__ga4_table': {
      const [, table, start] = slide.id.split(':') as [string, StoreTableKey, string]
      const all = data.ga4_tables?.tables?.[table]
      if (!all) return null
      const [from, to] = slide.range ?? [Number(start) || 0, (Number(start) || 0) + 8]
      const chip = `${from + 1}–${Math.min(to, all.rows.length)} / ${all.total}`

      return (
        <div data-testid={`slide-ga4-table-${table}`} data-range={`${from}-${to}`}>{head(table, chip)}
          {table === 'products'
            ? <FixedPageTable testId="ga4-print-table" ar={ar} pager={false} pageSize={8} rows={(all.rows as StoreProductRow[]).slice(from, to)} rowKey={(r) => r.name} columns={productColumns(ar, data.ga4_tables?.currency)} />
            : <FixedPageTable testId="ga4-print-table" ar={ar} pager={false} pageSize={8} rows={(all.rows as StoreSessionRow[]).slice(from, to)} rowKey={(r) => `${r.name}\u001f${r.sub ?? ''}`} columns={sessionColumns(ar, data.ga4_tables?.currency, (ar ? FIRST[table]?.ar : FIRST[table]?.en) ?? '')} />}
        </div>
      )
    }
    default:
      return null
  }
}
