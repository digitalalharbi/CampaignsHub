import { Num } from '@/components/ui/Num'
import { moneyExact, rowMoney } from '@/features/analytics/format'
import { formatMoneyReading, readCostPer, type MoneyTotals } from '@/lib/money/contract'

/**
 * REPORT-SECTION-STREAMS-001 — advanced segmentation, in the operator's own words.
 *
 * Shown only where an operator enabled advanced segmentation and defined streams; the server removes
 * the block everywhere else. Each stream carries the report's own sums narrowed to the platforms or
 * accounts the operator mapped, so its cost per result is recomputed from its own spend and results.
 * A platform or ad account belongs to one stream only, so streams never double-count — and their sum is
 * called the total only when the server says every in-scope account is mapped.
 */
export interface BusinessStreamRow {
  key: string
  label: string
  figures: Record<string, number | string | null>
  share_of_spend: number | null
}

export function BusinessStreamsSection({ streams, coverTotal = false, currency, ar }: { streams: BusinessStreamRow[] | undefined; coverTotal?: boolean; currency: string; ar: boolean }) {
  const rows = streams ?? []
  if (rows.length === 0) return null

  return (
    <section data-testid="business-streams">
      <h3 className="mb-3 text-base font-bold tracking-tight text-text-primary">{ar ? 'الأداء حسب مسار العمل' : 'Performance by business stream'}</h3>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {rows.map((stream) => {
          const totals = stream.figures as unknown as MoneyTotals
          const results = stream.figures.conversions
          const cost = formatMoneyReading(readCostPer(totals, 'cpa', 'conversions', currency, ar), (v) => moneyExact(v, currency))

          return (
            <div key={stream.key} data-testid={`business-stream-${stream.key}`} className="min-w-0 rounded-2xl border border-border bg-surface p-4">
              <div className="truncate text-sm font-bold text-text-primary">{stream.label}</div>
              <dl className="mt-2 grid grid-cols-3 gap-2 text-xs">
                <div className="min-w-0">
                  <dt className="text-text-muted">{ar ? 'الإنفاق' : 'Spend'}</dt>
                  <dd className="tnum truncate font-extrabold text-text-primary"><Num>{rowMoney(totals, 'spend', currency)}</Num></dd>
                </div>
                <div className="min-w-0">
                  <dt className="text-text-muted">{ar ? 'النتائج' : 'Results'}</dt>
                  <dd className="tnum truncate font-extrabold text-text-primary">
                    <Num>{results === null || results === undefined ? '—' : Number(results).toLocaleString('en-US')}</Num>
                  </dd>
                </div>
                <div className="min-w-0">
                  <dt className="text-text-muted">{ar ? 'تكلفة النتيجة' : 'Cost per result'}</dt>
                  <dd className="tnum truncate font-extrabold text-text-primary"><Num>{cost}</Num></dd>
                </div>
              </dl>
              {stream.share_of_spend !== null && (
                <div className="mt-3" aria-label={ar ? 'حصة الإنفاق' : 'Share of spend'}>
                  <span className="block h-1.5 overflow-hidden rounded-full bg-surface-secondary">
                    <span className="block h-full rounded-full bg-brand-600" style={{ width: `${Math.min(100, Math.round(stream.share_of_spend * 100))}%` }} />
                  </span>
                  <span className="tnum mt-1 block text-[11px] text-text-muted">
                    <Num>{`${Math.round(stream.share_of_spend * 100)}%`}</Num> {ar ? 'من إجمالي الإنفاق' : 'of total spend'}
                  </span>
                </div>
              )}
            </div>
          )
        })}
      </div>
      <p data-testid="business-streams-coverage" className="mt-2 text-[11px] text-text-muted">
        {coverTotal
          ? (ar ? 'مجموع المسارات يساوي إجمالي الإنفاق.' : 'The streams add up to the total spend.')
          : (ar ? 'لا تغطي المسارات كل الحسابات، فمجموعها ليس إجمالي الإنفاق.' : 'The streams do not cover every account, so their sum is not the total spend.')}
      </p>
    </section>
  )
}
