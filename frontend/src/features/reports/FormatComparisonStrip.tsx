import { Num } from '@/components/ui/Num'
import { money, num, percent, ratio } from '@/features/analytics/format'
import { kindName } from '@/features/content/ContentTypesComparison'
import type { FormatComparisonPayload } from '@/features/content/api'
import { drawnTable, rowWinner, verdictOf, type DrawnTable } from './formatStrip'

export { drawnTable, tableHasFigures } from './formatStrip'

/**
 * REPORT-FORMAT-COMPARE-TABLE-001 (Owner 2026-11-13) — the report's «مقارنة أنواع المحتوى»: ONE professional table per report.
 * A column per content type, side by side; a row per KPI of the report's objective (its KPI cards' list); the best type of each
 * row carries a quiet highlight and a thin bar; one line under the table states the verdict, only where the data and the sample
 * allow it. No row or column of nothing, no 0, no «—». On a phone the table scrolls inside its own box, never the page.
 */
function figure(kind: string | undefined, value: number, currency: string | null): string {
  switch (kind) {
    case 'money': return money(value, currency)
    case 'rate': return percent(value, 1)
    case 'multiplier': return ratio(value)
    case 'ratio': return value.toFixed(2)
    default: return num(value)
  }
}

function Verdict({ t, ar, currency }: { t: DrawnTable; ar: boolean; currency: string | null }) {
  const v = verdictOf(t)
  if (!v) return null
  const label = t.table.labels?.[v.row] ?? { ar: v.row, en: v.row }
  const kind = t.table.kinds?.[v.row]
  const better = t.table.better?.[v.row]
  const word = ar ? (better === 'lower' ? 'أقل' : 'أعلى') : (better === 'lower' ? 'lowest' : 'highest')

  return (
    <p data-testid="format-table-verdict" className="text-xs text-text-secondary">
      <span className="font-semibold text-text-primary">{kindName(v.winner, ar)}</span>
      {ar ? ` ${word} ${label.ar}: ` : ` has the ${word} ${label.en}: `}
      <Num>{`${figure(kind, v.winnerValue, currency)} ${ar ? 'مقابل' : 'vs'} ${figure(kind, v.runnerValue, currency)}`}</Num>
      {` (${kindName(v.runnerUp, ar)})`}
    </p>
  )
}

export function FormatComparisonStrip({ data, ar, client, title = true }: { data: FormatComparisonPayload; ar: boolean; client: boolean; title?: boolean }) {
  const hidesMoney = client || data.spend_hidden === true
  const t = drawnTable(data, hidesMoney)
  if (!t) return null
  const currency = hidesMoney ? null : data.currency

  return (
    <section data-testid="report-format-strip" className="flex min-w-0 flex-col gap-2">
      {title && <h3 className="text-sm font-extrabold tracking-tight text-text-primary">{ar ? 'مقارنة أنواع المحتوى' : 'Content types'}</h3>}
      <div className="min-w-0 overflow-x-auto rounded-xl border border-border" data-testid="format-table-scroll">
        <table data-testid="format-table" className="w-full border-collapse text-xs">
          <thead>
            <tr className="bg-surface-hover/60">
              <th scope="col" className="px-2.5 py-2 text-start font-semibold text-text-secondary">{ar ? 'المؤشر' : 'KPI'}</th>
              {t.formats.map((f) => (
                <th key={f.format} scope="col" data-testid={`format-col-${f.format}`} className="px-2.5 py-2 text-center font-bold text-text-primary">
                  {kindName(f.format, ar)}
                  <span className="ms-1 font-normal text-text-muted"><Num>{`(${f.creatives})`}</Num></span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {t.rows.map((row) => {
              const winner = rowWinner(t, row)
              const values = t.formats.map((f) => t.cell(row, f.format)).filter((v): v is number => v !== null)
              const top = Math.max(...values)
              const label = t.table.labels?.[row] ?? { ar: row, en: row }

              return (
                <tr key={row} data-testid={`format-row-${row}`} className="border-t border-border">
                  <th scope="row" className="whitespace-nowrap px-2.5 py-1.5 text-start font-medium text-text-secondary">{ar ? label.ar : label.en}</th>
                  {t.formats.map((f) => {
                    const v = t.cell(row, f.format)
                    const wins = winner === f.format

                    return (
                      <td key={f.format} data-testid={`format-cell-${row}-${f.format}`} data-winner={wins ? 'true' : undefined} className={`px-2.5 py-1.5 text-center align-middle ${wins ? 'bg-success/10 font-bold text-success' : 'text-text-primary'}`}>
                        {v === null ? null : (
                          <>
                            <Num>{figure(t.table.kinds?.[row], v, currency)}</Num>
                            {winner !== null && <div aria-hidden className={`mx-auto mt-0.5 h-0.5 rounded-full ${wins ? 'bg-success' : 'bg-border'}`} style={{ width: `${Math.max(8, Math.round((v / top) * 100))}%` }} />}
                          </>
                        )}
                      </td>
                    )
                  })}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <Verdict t={t} ar={ar} currency={currency} />
    </section>
  )
}
