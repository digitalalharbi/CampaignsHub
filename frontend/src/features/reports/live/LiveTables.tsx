import { ProviderName } from '@/components/ProviderMark'
import { compact, percent } from '@/features/analytics/format'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { ChartCard } from '@/features/analytics/charts'
import type { LivePayload } from '../api'
import type { ReportAd } from '../ReportAdsSection'
import { spendHiddenByLink, type useLiveMetricReader } from './liveMetrics'
import { sectionShown } from '../reportSections'
import { asContent } from './LiveContent'
import { resultWords } from './leadResults'

type Reader = ReturnType<typeof useLiveMetricReader>
type Row = Record<string, unknown>

const num = (row: Row, key: string): number | null => (typeof row[key] === 'number' ? (row[key] as number) : null)

/**
 * REPORT-ARCHITECTURE-001 — the DETAILED product's analytical workspace.
 *
 * Standard is a story to read; Detailed is a set of tables to work in. This view carries the depth
 * the other levels never draw — the day-by-day record, every platform against the previous period,
 * every ad account, every piece of content that ran, and the objective split — each as a sortable
 * table over figures this link's payload already holds, with money columns present only when the
 * link carries money (a link that hides spend has no spend column and no cost column).
 */
export function TablesView({
  payload,
  reader,
  locale,
  onOpenContent,
}: {
  payload: LivePayload
  reader: Reader
  locale: 'ar' | 'en'
  onOpenContent: (content: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const money = !spendHiddenByLink(payload)
  // NASEL 2026-10-29 — the link's own words for Results; with none defined, no column is named after them.
  const words = resultWords(payload, ar)
  const results = words.result
  const cost = words.cost
  const dash = '—'

  const days = (payload.timeseries ?? []) as Row[]
  const platforms = (payload.platforms ?? []) as Row[]
  const accounts = ((payload as { accounts?: Row[] }).accounts ?? []) as Row[]
  // The roster nests each creative's figures under `metrics`; the ranked lists carry them at the top level.
  // Read flat, every figure was undefined and the whole table showed dashes (Owner defect, live link 2026-10-19).
  const roster = (payload.ads_roster ?? []).map(asContent)

  const moneyCell = (v: number | null) => (v === null ? dash : reader.asMoney(v))
  const costCell = (v: number | null) => (v === null || v <= 0 ? dash : reader.asExactMoney(v))
  const movement = (row: Row, key: string): number | null => {
    const mov = (row.movement ?? {}) as Record<string, number | null>

    return typeof mov[key] === 'number' ? mov[key] : null
  }
  const delta = (v: number | null) => (v === null ? dash : `${v > 0 ? '+' : ''}${(v * 100).toFixed(1)}%`)

  /*
   * REPORT-TABLES-OWNER-COLUMNS-001 — the Owner's column choice, per table, on top of the money rule: a link
   * that hides spend never gets a spend or cost column however the picker was set, and the first column (the
   * row's name) is never a choice. An absent table means every column.
   */
  type Col<T> = { key: string; head: string; cell: (item: T) => React.ReactNode; value: (item: T) => string | number | null }
  const chosenFor = (table: string): string[] | undefined => {
    const all = (payload as { columns?: Record<string, string[]> | string[] }).columns

    return Array.isArray(all) ? undefined : all?.[table]
  }
  const build = <T,>(table: string, cols: Array<Col<T> | null>, items: T[]) => {
    const chosen = chosenFor(table)
    const shown = cols.filter((c): c is Col<T> => c !== null).filter((c, i) => i === 0 || chosen === undefined || chosen.includes(c.key))

    return {
      head: shown.map((c) => c.head),
      rows: items.map((item) => shown.map((c) => c.cell(item))),
      values: items.map((item): SortValues => shown.map((c) => c.value(item))),
      keys: shown.map((c) => c.key),
    }
  }
  const sortIndex = (keys: string[], preferred: string): number => Math.max(0, keys.indexOf(preferred))
  const spendCol = <T,>(read: (item: T) => Row): Col<T> | null => (money ? { key: 'spend', head: ar ? 'الإنفاق' : 'Spend', cell: (x) => moneyCell(num(read(x), 'spend')), value: (x) => num(read(x), 'spend') } : null)
  const metricCol = <T,>(key: string, head: string, field: string, read: (item: T) => Row): Col<T> => ({ key, head, cell: (x) => compact(num(read(x), field)), value: (x) => num(read(x), field) })
  /*
   * NASEL 2026-10-29 — with Results configured, every table reads them from the one resolver: a platform's configured events and their cost
   * (`results.by_platform`), a content's `verified_result`. Days and accounts have no figure of their own there, so their Results read «—»
   * rather than the platform's generic `conversions` (TikTok's 1,124 page loads beside 13 real leads).
   */
  const configured = payload.results?.configured === true
  const byPlatform = (p: Row) => payload.results?.by_platform?.[String(p.provider ?? '')]
  const resultsCol = <T,>(read: (item: T) => number | null | undefined): Col<T> => ({ key: 'results', head: results, cell: (x) => compact(read(x) ?? null), value: (x) => read(x) ?? null })
  // Configured: the resolver. «None»: no column (the clicks column says it). Never defined: the platform's own result, as before.
  const configuredResults = <T,>(field: (item: T) => Row, verified: (item: T) => number | null | undefined): Col<T> | null =>
    configured ? resultsCol<T>(verified) : words.off ? null : metricCol<T>('results', results, 'conversions', field)
  const costCol = <T,>(read: (item: T) => Row): Col<T> | null => (money && words.legacy ? { key: 'cost', head: cost, cell: (x) => costCell(num(read(x), 'cpa')), value: (x) => num(read(x), 'cpa') } : null)

  const dayTable = build<Row>('days', [
    { key: 'day', head: ar ? 'اليوم' : 'Day', cell: (d) => String(d.date ?? ''), value: (d) => String(d.date ?? '') },
    metricCol('impressions', ar ? 'الظهور' : 'Impressions', 'impressions', (d) => d),
    metricCol('clicks', ar ? 'النقرات' : 'Clicks', 'clicks', (d) => d),
    configuredResults<Row>((d) => d, () => null),
    spendCol<Row>((d) => d),
  ], days)

  const platTable = build<Row>('platforms', [
    { key: 'name', head: ar ? 'المنصة' : 'Platform', cell: (p) => <ProviderName provider={String(p.provider ?? '')} locale={locale} size={16} />, value: (p) => String(p.provider ?? '') },
    configuredResults<Row>((p) => p, (p) => byPlatform(p)?.results ?? null),
    ...(words.legacy ? [{ key: 'results', head: ar ? 'تغيّر النتائج' : 'Results vs previous', cell: (p: Row) => delta(movement(p, 'conversions')), value: (p: Row) => movement(p, 'conversions') }] : []),

    metricCol('impressions', ar ? 'الظهور' : 'Impressions', 'impressions', (p) => p),
    { key: 'impressions', head: ar ? 'تغيّر الظهور' : 'Impressions vs previous', cell: (p) => delta(movement(p, 'impressions')), value: (p) => movement(p, 'impressions') },
    { key: 'ctr', head: ar ? 'نسبة النقر' : 'CTR', cell: (p) => percent(num(p, 'ctr'), 2), value: (p) => num(p, 'ctr') },
    spendCol<Row>((p) => p),
    // With Results, their cost from the resolver; without, the cost per click (named «تكلفة النقرة» by `resultWords`).
    money
      ? (configured
        ? { key: 'cost', head: cost, cell: (p: Row) => costCell(byPlatform(p)?.cost ?? null), value: (p: Row) => byPlatform(p)?.cost ?? null }
        : words.off
          ? { key: 'cost', head: cost, cell: (p: Row) => costCell(num(p, 'cpc')), value: (p: Row) => num(p, 'cpc') }
          : { key: 'cost', head: cost, cell: (p: Row) => costCell(num(p, 'cpa')), value: (p: Row) => num(p, 'cpa') })
      : null,
  ], platforms)

  const accTable = build<Row>('accounts', [
    // REPORT-CLIENT-ACCOUNT-NAMES-OFF-001 — a withheld name arrives as «حساب ميتا 1» with an English twin; the real name only when the Owner enabled it.
    { key: 'name', head: ar ? 'الحساب الإعلاني' : 'Ad account', cell: (a) => String((!ar && a.account_name_withheld ? a.account_name_en : null) ?? a.account_name ?? (ar ? 'حساب بلا اسم' : 'Unnamed account')), value: (a) => String(a.account_name ?? '') },
    { key: 'platform', head: ar ? 'المنصة' : 'Platform', cell: (a) => <ProviderName provider={String(a.provider ?? '')} locale={locale} size={16} />, value: (a) => String(a.provider ?? '') },
    configuredResults<Row>((a) => a, () => null),
    metricCol('impressions', ar ? 'الظهور' : 'Impressions', 'impressions', (a) => a),
    metricCol('clicks', ar ? 'النقرات' : 'Clicks', 'clicks', (a) => a),
    spendCol<Row>((a) => a),
    costCol<Row>((a) => a),
  ], accounts)

  const conRow = (c: ReportAd) => c as unknown as Row
  const conTable = build<ReportAd>('content', [
    { key: 'name', head: ar ? 'المحتوى' : 'Content', cell: (c) => <button key="n" type="button" className="max-w-[16rem] truncate text-start font-semibold text-brand-600 hover:underline" onClick={() => onOpenContent(c)}>{c.name ?? '—'}</button>, value: (c) => String(c.name ?? '') },
    { key: 'platform', head: ar ? 'المنصة' : 'Platform', cell: (c) => <ProviderName provider={String(c.provider ?? '')} locale={locale} size={16} />, value: (c) => String(c.provider ?? '') },
    configuredResults<ReportAd>(conRow, (c) => c.verified_result?.value ?? null),
    metricCol('impressions', ar ? 'الظهور' : 'Impressions', 'impressions', conRow),
    metricCol('clicks', ar ? 'النقرات' : 'Clicks', 'clicks', conRow),
    spendCol<ReportAd>(conRow),
    costCol<ReportAd>(conRow),
  ], roster)

  const Block = ({ title, testid, empty, children }: { title: string; testid: string; empty: boolean; children: React.ReactNode }) => (
    <ChartCard title={title}>
      <div data-testid={testid} className="min-w-0">
        {empty ? <p className="text-sm text-text-muted">{ar ? 'لا صفوف في هذه الفترة.' : 'No rows in this period.'}</p> : children}
      </div>
    </ChartCard>
  )

  return (
    <div data-testid="live-mode-tables" className="flex flex-col gap-5">
      <p className="text-sm text-text-secondary">{ar ? 'جداول تحليلية قابلة للفرز — كل صف من أرقام هذا التقرير نفسه.' : 'Sortable analytical tables — every row from this report’s own figures.'}</p>

      {/* NASEL 2026-10-29 — a table of the «detailed tables» component: the switch that names it is the one that hides it. */}
      {sectionShown(payload, 'detailed_tables') && (
        <Block title={ar ? 'كل منصة مقابل الفترة السابقة' : 'Every platform against the previous period'} testid="tables-platforms" empty={platforms.length === 0}>
          <MetricTable head={platTable.head} rows={platTable.rows} values={platTable.values} initialSort={{ column: sortIndex(platTable.keys, 'results'), dir: 'desc' }} />
        </Block>
      )}

      {sectionShown(payload, 'account_breakdown') && (
        <Block title={ar ? 'الحسابات الإعلانية' : 'Ad accounts'} testid="tables-accounts" empty={accounts.length === 0}>
          <MetricTable head={accTable.head} rows={accTable.rows} values={accTable.values} initialSort={{ column: sortIndex(accTable.keys, 'results'), dir: 'desc' }} />
        </Block>
      )}

      {/* REPORT-COMPOSITION-CONTROL-001 — the ledger is its own component, off for a new client report. */}
      {sectionShown(payload, 'daily_records') && (
        <Block title={ar ? 'السجل اليومي' : 'Day-by-day record'} testid="tables-days" empty={days.length === 0}>
          <MetricTable head={dayTable.head} rows={dayTable.rows} values={dayTable.values} initialSort={{ column: 0, dir: 'desc' }} />
        </Block>
      )}

      {sectionShown(payload, 'content_performance') && (
        <Block title={ar ? 'كل المحتوى الذي عُرض' : 'All content that ran'} testid="tables-content" empty={roster.length === 0}>
          <MetricTable head={conTable.head} rows={conTable.rows} values={conTable.values} initialSort={{ column: sortIndex(conTable.keys, 'results'), dir: 'desc' }} />
        </Block>
      )}
    </div>
  )
}
