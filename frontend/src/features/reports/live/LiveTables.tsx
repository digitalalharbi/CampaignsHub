import { providerLabel } from '@/features/campaigns/labels'
import { compact, percent } from '@/features/analytics/format'
import { canonicalPlatform } from '@/lib/platforms'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { ChartCard } from '@/features/analytics/charts'
import type { LivePayload } from '../api'
import type { ReportAd } from '../ReportAdsSection'
import { spendHiddenByLink, type useLiveMetricReader } from './liveMetrics'
import { sectionShown } from '../reportSections'

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
  const results = ar ? 'النتائج' : 'Results'
  const cost = ar ? 'تكلفة النتيجة' : 'Cost per result'
  const dash = '—'

  const days = (payload.timeseries ?? []) as Row[]
  const platforms = (payload.platforms ?? []) as Row[]
  const accounts = ((payload as { accounts?: Row[] }).accounts ?? []) as Row[]
  const roster = (payload.ads_roster ?? []) as unknown as ReportAd[]

  const moneyCell = (v: number | null) => (v === null ? dash : reader.asMoney(v))
  const costCell = (v: number | null) => (v === null || v <= 0 ? dash : reader.asExactMoney(v))
  const movement = (row: Row, key: string): number | null => {
    const mov = (row.movement ?? {}) as Record<string, number | null>

    return typeof mov[key] === 'number' ? mov[key] : null
  }
  const delta = (v: number | null) => (v === null ? dash : `${v > 0 ? '+' : ''}${(v * 100).toFixed(1)}%`)

  const dayHead = [ar ? 'اليوم' : 'Day', ar ? 'الظهور' : 'Impressions', ar ? 'النقرات' : 'Clicks', results, ...(money ? [ar ? 'الإنفاق' : 'Spend'] : [])]
  const dayRows = days.map((d) => [
    String(d.date ?? ''), compact(num(d, 'impressions')), compact(num(d, 'clicks')), compact(num(d, 'conversions')),
    ...(money ? [moneyCell(num(d, 'spend'))] : []),
  ])
  const dayValues = days.map((d): SortValues => [String(d.date ?? ''), num(d, 'impressions'), num(d, 'clicks'), num(d, 'conversions'), ...(money ? [num(d, 'spend')] : [])])

  const platHead = [ar ? 'المنصة' : 'Platform', results, ar ? 'تغيّر النتائج' : 'Results vs previous', ar ? 'الظهور' : 'Impressions', ar ? 'تغيّر الظهور' : 'Impressions vs previous', ar ? 'نسبة النقر' : 'CTR', ...(money ? [ar ? 'الإنفاق' : 'Spend', cost] : [])]
  const platRows = platforms.map((p) => [
    providerLabel(canonicalPlatform(String(p.provider ?? '')), locale), compact(num(p, 'conversions')), delta(movement(p, 'conversions')),
    compact(num(p, 'impressions')), delta(movement(p, 'impressions')), percent(num(p, 'ctr'), 2),
    ...(money ? [moneyCell(num(p, 'spend')), costCell(num(p, 'cpa'))] : []),
  ])
  const platValues = platforms.map((p): SortValues => [String(p.provider ?? ''), num(p, 'conversions'), movement(p, 'conversions'), num(p, 'impressions'), movement(p, 'impressions'), num(p, 'ctr'), ...(money ? [num(p, 'spend'), num(p, 'cpa')] : [])])

  const accHead = [ar ? 'الحساب الإعلاني' : 'Ad account', ar ? 'المنصة' : 'Platform', results, ar ? 'الظهور' : 'Impressions', ar ? 'النقرات' : 'Clicks', ...(money ? [ar ? 'الإنفاق' : 'Spend', cost] : [])]
  const accRows = accounts.map((a) => [
    String(a.account_name ?? (ar ? 'حساب بلا اسم' : 'Unnamed account')), providerLabel(canonicalPlatform(String(a.provider ?? '')), locale),
    compact(num(a, 'conversions')), compact(num(a, 'impressions')), compact(num(a, 'clicks')),
    ...(money ? [moneyCell(num(a, 'spend')), costCell(num(a, 'cpa'))] : []),
  ])
  const accValues = accounts.map((a): SortValues => [String(a.account_name ?? ''), String(a.provider ?? ''), num(a, 'conversions'), num(a, 'impressions'), num(a, 'clicks'), ...(money ? [num(a, 'spend'), num(a, 'cpa')] : [])])

  const conRow = (c: ReportAd) => c as unknown as Row
  const conHead = [ar ? 'المحتوى' : 'Content', ar ? 'المنصة' : 'Platform', results, ar ? 'الظهور' : 'Impressions', ar ? 'النقرات' : 'Clicks', ...(money ? [ar ? 'الإنفاق' : 'Spend', cost] : [])]
  const conRows = roster.map((c) => [
    <button key="n" type="button" className="max-w-[16rem] truncate text-start font-semibold text-brand-600 hover:underline" onClick={() => onOpenContent(c)}>{c.name ?? '—'}</button>,
    providerLabel(canonicalPlatform(String(c.provider ?? '')), locale),
    compact(num(conRow(c), 'conversions')), compact(num(conRow(c), 'impressions')), compact(num(conRow(c), 'clicks')),
    ...(money ? [moneyCell(num(conRow(c), 'spend')), costCell(num(conRow(c), 'cpa'))] : []),
  ])
  const conValues = roster.map((c): SortValues => [String(c.name ?? ''), String(c.provider ?? ''), num(conRow(c), 'conversions'), num(conRow(c), 'impressions'), num(conRow(c), 'clicks'), ...(money ? [num(conRow(c), 'spend'), num(conRow(c), 'cpa')] : [])])

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

      {sectionShown(payload, 'platform_comparison') && (
        <Block title={ar ? 'كل منصة مقابل الفترة السابقة' : 'Every platform against the previous period'} testid="tables-platforms" empty={platforms.length === 0}>
          <MetricTable head={platHead} rows={platRows} values={platValues} initialSort={{ column: 1, dir: 'desc' }} />
        </Block>
      )}

      {sectionShown(payload, 'account_breakdown') && (
        <Block title={ar ? 'الحسابات الإعلانية' : 'Ad accounts'} testid="tables-accounts" empty={accounts.length === 0}>
          <MetricTable head={accHead} rows={accRows} values={accValues} initialSort={{ column: 2, dir: 'desc' }} />
        </Block>
      )}

      {sectionShown(payload, 'trends') && (
        <Block title={ar ? 'السجل اليومي' : 'Day-by-day record'} testid="tables-days" empty={days.length === 0}>
          <MetricTable head={dayHead} rows={dayRows} values={dayValues} initialSort={{ column: 0, dir: 'desc' }} />
        </Block>
      )}

      {sectionShown(payload, 'content_performance') && (
        <Block title={ar ? 'كل المحتوى الذي عُرض' : 'All content that ran'} testid="tables-content" empty={roster.length === 0}>
          <MetricTable head={conHead} rows={conRows} values={conValues} initialSort={{ column: 2, dir: 'desc' }} />
        </Block>
      )}
    </div>
  )
}
