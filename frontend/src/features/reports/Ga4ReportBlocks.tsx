import { Badge } from '@/components/ui/Badge'
import { FixedPageTable } from '@/components/ui/FixedPageTable'
import { Num } from '@/components/ui/Num'
import { Ga4Acquisition, Ga4Gap, Ga4Series, Ga4Tables, Ga4Tiles, productColumns, sessionColumns } from '@/features/analytics/ga4/Ga4StoreView'
import type { Ga4AcquisitionSection, Ga4GapSection, Ga4OverviewSection, Ga4TablesSection, StoreProductRow, StoreSessionRow, StoreTableKey } from '@/features/analytics/ga4/storeTypes'
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
}

export const GA4_PARTS = ['ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables'] as const
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
    </section>
  )
}

const SLIDE_TITLE: Record<string, { ar: string; en: string }> = {
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
