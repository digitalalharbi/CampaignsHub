import { money } from '@/features/analytics/format'
import { formatMoneyReading, readCostPer, readMoney, readRoas, type MoneyTotals } from '@/lib/money/contract'


/**
 * VISUAL-DECISION-001 — the secondary figures, at the density they deserve.
 *
 * Budget, remaining, forecast, cost per result and ROAS are portfolio facts an operator needs and
 * does not open this page to read first. They were five oversized KPI cards, which put them at the
 * same weight as «what is running» and pushed the campaigns themselves below the fold.
 *
 * ## The guarantees moved with the figures; none of them was dropped
 *
 * Every value here is read through the SAME canonical money contract the cards used — `readMoney`,
 * `readCostPer`, `readRoas` — so the rules are unchanged and are not restated in a second place:
 * a converted amount renders converted; an original-only amount renders with its own currency; a
 * figure that cannot be formed renders «—» with the contract's own sentence beside it; a reported
 * zero renders `0`. Nothing is fabricated and nothing is inferred here, because nothing is computed
 * here.
 *
 * The disclosure is the part a compact strip could quietly lose, so it is deliberate: where the
 * contract refuses a figure, its reason is rendered as visible text rather than a `title` attribute
 * — a tooltip is not a disclosure to a reader on a phone, and this is money.
 */
export function CampaignSecondaryStrip({
  totals,
  budget,
  currency,
  paused,
  ar,
}: {
  totals: MoneyTotals
  /*
   * The page's own budget reading, passed in rather than recomputed.
   *
   * It applies a stricter rule for `spent` than the pacing block does — every campaign must be one
   * spend figure AND they must agree on a currency — and that strictness is the guarantee the card
   * this strip replaced was carrying. Recomputing it here would be a second opinion about money.
   */
  budget: {
    total: number
    spent: number | null
    remaining: number | null
    projected: number | null
    currency: string | null
    spentCurrency: string | null
    currencyCount: number
    known: boolean
  }
  currency: string | null
  paused: number
  ar: boolean
}) {
  const costPer = readCostPer(totals, 'cpa', 'conversions', currency, ar)
  const roas = readRoas(totals, ar)
  const spend = readMoney(totals, 'spend', currency, ar)

  /* The budget's own refusals, in the wording the card carried before this strip existed. */
  const budgetNote = !budget.known
    ? (ar ? 'لم تُحدَّد ميزانية لأي حملة' : 'No campaign has a budget set')
    : budget.currencyCount > 1
      ? (ar ? 'ميزانيات بعملات مختلفة — لا تُجمع' : 'Budgets in different currencies — not summed')
      : budget.spent === null
        ? (ar ? 'المصروف غير متاح — مبالغ جزئية أو بعملات متعددة' : 'Spend unavailable — partial or multi-currency')
        : null

  const budgetValue = !budget.known
    ? '—'
    : budget.currencyCount > 1
      ? (ar ? `${budget.currencyCount} عملات` : `${budget.currencyCount} currencies`)
      : money(budget.total, budget.currency ?? undefined)

  return (
    <div
      data-testid="campaigns-secondary-strip"
      className="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl border border-border bg-surface px-3 py-2 text-xs"
    >
      <Cell testid="campaigns-budget-total" label={ar ? 'الميزانية' : 'Budget'} value={budgetValue} note={budgetNote} />
      <Cell
        label={ar ? 'المتبقي' : 'Remaining'}
        value={budget.remaining === null ? '—' : money(budget.remaining, budget.currency ?? undefined)}
      />
      <Cell
        label={ar ? 'المتوقع' : 'Forecast'}
        value={budget.projected === null ? '—' : money(budget.projected, budget.currency ?? undefined)}
      />
      {/*
        * The spent figure the budget rows report, which is NOT the metrics window's spend.
        * Two measurements over two sets of rows; naming both «spend» is how they come to be
        * confused, so this one says what it is against.
        */}
      <Cell
        label={ar ? 'المصروف من الميزانية' : 'Spent against budget'}
        value={budget.spent === null ? '—' : money(budget.spent, budget.spentCurrency ?? budget.currency ?? undefined)}
      />
      <Cell testid="campaigns-cost-per-result" label={ar ? 'تكلفة النتيجة' : 'Cost / result'} value={formatMoneyReading(costPer, money)} note={costPer.note} />
      <Cell testid="campaigns-roas" label="ROAS" value={roas.value === null ? '—' : `${roas.value.toFixed(2)}×`} note={roas.note} />
      {/*
        * Paused belongs here rather than among the four: «how many are stopped» is a fact, and
        * «what needs me» is the question. The caption follows the count — a «needs a look» under a
        * zero asserted both that nothing needed reviewing and that it did.
        */}
      <Cell
        testid="campaigns-paused"
        label={ar ? 'متوقفة' : 'Paused'}
        value={String(paused)}
        note={paused > 0 ? (ar ? 'تحتاج مراجعة' : 'Need a look') : null}
      />
      {spend.kind === 'unavailable' && spend.note !== null && (
        <span data-testid="campaigns-spend-note" className="text-text-muted">{spend.note}</span>
      )}
    </div>
  )
}

function Cell({ label, value, note, testid }: { label: string; value: string; note?: string | null; testid?: string }) {
  return (
    <span className="inline-flex items-baseline gap-1.5" data-testid={testid}>
      <span className="text-text-muted">{label}</span>
      <span className="tnum font-semibold text-text-primary">{value}</span>
      {note !== null && note !== undefined && <span className="text-[11px] text-text-muted">· {note}</span>}
    </span>
  )
}
