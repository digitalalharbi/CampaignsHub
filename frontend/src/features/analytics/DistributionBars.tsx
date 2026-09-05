import { readMoney, type MoneyTotals } from '@/lib/money/contract'
import { money, percent } from './format'

/**
 * VISUAL-FIRST-001 / clause D — «CAMPAIGN + AD-SET DISTRIBUTION → contribution/distribution bars».
 *
 * ## The question this answers, and how it differs from the block above it
 *
 * `ChangeDiagnosis` decomposes what MOVED: which campaign drove the account's change. This shows
 * where the money SITS right now. They are different questions and a reader needs both — an account
 * can be perfectly stable and still have 80% of its spend behind one campaign, which is a
 * concentration a change decomposition will never surface because nothing changed.
 *
 * Ranked bars rather than the table beneath, because concentration is a shape: «one campaign holds
 * most of this» is read from bar lengths at a glance and from a sorted column only by doing the
 * arithmetic.
 *
 * ## The money contract decides whether this may be drawn at all
 *
 * A share needs a total, and a total needs every part. `readMoney` refuses a figure it cannot state
 * in the reporting currency — a row whose spend is withheld has real money behind it that this
 * component cannot add. Summing the rest would produce shares over an incomplete denominator, every
 * one of them too large, and they would look exactly like correct ones.
 *
 * So a withheld row makes the whole block DECLINE and say how many rows it could not read. That is
 * the same rule the account-level readings follow, one grain down.
 */
export function DistributionBars({
  rows,
  currency,
  ar,
  title,
  testid,
}: {
  rows: Array<{ key: string; label: string; totals: MoneyTotals }>
  currency: string | null
  ar: boolean
  title: string
  testid: string
}) {
  if (rows.length < 2) return null

  /*
   * Three outcomes, and collapsing any two of them is how a share starts lying.
   *
   *   converted / zero  — a figure in the reporting currency. Addable.
   *   absent            — this row recorded no spend at all. It is simply not part of the
   *                       distribution, and excluding it changes no other row's share.
   *   withheld /        — REAL money this component cannot state in one unit. Summing the rest
   *   unavailable         would divide by an incomplete total and every share would be too large,
   *                       while looking exactly like a correct one. Fail closed.
   */
  const read = rows.map((r) => {
    const reading = readMoney(r.totals, 'spend', currency, ar)

    return {
      ...r,
      amount: reading.kind === 'converted' || reading.kind === 'zero' ? reading.amount : null,
      unstatable: reading.kind === 'withheld' || reading.kind === 'unavailable',
    }
  })

  const unreadable = read.filter((r) => r.unstatable)
  const known = read.filter((r): r is typeof r & { amount: number } => r.amount !== null)
  const total = known.reduce((sum, r) => sum + r.amount, 0)

  if (unreadable.length > 0 || total <= 0) {
    return (
      <div className="rounded-2xl border border-border bg-surface p-4" data-testid={`${testid}-declined`}>
        <h3 className="text-sm font-semibold text-text">{title}</h3>
        <p className="mt-1.5 text-xs text-text-secondary">
          {unreadable.length > 0
            ? (ar
                ? `تعذّر قراءة إنفاق ${unreadable.length} من الصفوف بعملة التقارير، ونسبة محسوبة على مجموع ناقص تبالغ في نفسها.`
                : `${unreadable.length} row${unreadable.length === 1 ? '' : 's'} could not be read in the reporting currency, and a share over an incomplete total overstates itself.`)
            : (ar ? 'لا يوجد إنفاق في هذه الفترة لتوزيعه.' : 'There is no spend in this period to distribute.')}
        </p>
      </div>
    )
  }

  const ranked = [...known].sort((a, b) => b.amount - a.amount)
  const top = ranked[0].amount

  return (
    <div className="rounded-2xl border border-border bg-surface p-4" data-testid={testid}>
      <h3 className="text-sm font-semibold text-text">{title}</h3>
      {/*
        The headline is CONCENTRATION, because that is the decision in this picture: one line holding
        most of the budget is a risk whether or not it is performing, and it is the thing a reader
        would otherwise have to compute from the column beneath.
      */}
      <p className="mt-0.5 text-xs text-text-secondary" data-testid={`${testid}-concentration`}>
        {ar
          ? `الأعلى يستحوذ على ${percent(ranked[0].amount / total, 0)} من الإنفاق`
          : `The largest holds ${percent(ranked[0].amount / total, 0)} of spend`}
      </p>

      <ul className="mt-3 space-y-1.5">
        {ranked.slice(0, 8).map((r) => (
          <li key={r.key} className="flex items-center gap-2" data-testid={`${testid}-row`}>
            <span className="w-28 shrink-0 truncate text-xs text-text-secondary sm:w-40" title={r.label}>{r.label}</span>
            <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
              {/* Scaled to the LARGEST so the smallest is still visible; floored so real spend never draws as none. */}
              <span
                className="block h-full rounded-full bg-brand-500"
                style={{ width: `${Math.max(2, (r.amount / top) * 100)}%` }}
              />
            </span>
            <span className="tnum w-20 shrink-0 text-end text-xs text-text-primary" dir="ltr">{money(r.amount, currency)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
