import { DataMetricTable } from '@/components/ui/MetricTable'
import { providerLabel } from '@/features/campaigns/labels'
import type { LivePayload } from './api'
import type { Locale } from '@/stores/ui'

/**
 * CLIENT-FACING-PRESENTATION-001 — the last three blocks of the composition.
 *
 * The client link answered «what was spent», «what was achieved», «at what cost», «what moved» and
 * «where», and then stopped. It never answered the sixth question, which is the one a client acts
 * on: **what needs attention.** A report that ends at the figures leaves the reader to work out
 * which of them is a problem, which is the work they were paying somebody else to do.
 *
 * Three blocks, in the order the requirement names them:
 *
 *   1. **budget status** — plan against spend, per PLATFORM, with pace;
 *   2. **alerts that need a decision** — derived from that pacing rather than from a second engine,
 *      because a client alert that disagreed with the agency's own budget screen would be worse than
 *      no alert;
 *   3. **concise actions** — each one carrying the figure it came from.
 *
 * ## Nothing here is invented
 *
 * Every sentence is a restatement of a number already on the page. An «action» with no figure behind
 * it is advice, and advice a client cannot check is advice they are right to ignore. Where the money
 * could not be compared — a withheld or mixed-currency scope — the row simply does not produce an
 * alert, because «this campaign is overspending» computed from a figure the product refused to state
 * is exactly the fabrication the money contract exists to prevent.
 *
 * ## Why the rows are platforms
 *
 * They were campaigns, by internal name, until CLIENT-REPORT-ENTITY-BOUNDARY-001: a client link may
 * state what their money did and not how the agency arranged it. The alert survives the fold — «Meta
 * is spending ahead of plan» is the same decision as «this campaign is», and it is the decision the
 * reader can actually take to the person running it. The pace itself is now computed server-side per
 * platform, and it REFUSES where a platform's spend is not all against a stated plan, so a bucket
 * that would once have produced a confident ratio out of half a plan produces no finding at all.
 */
type BudgetRow = NonNullable<LivePayload['budget']>[number]

/** Ahead of plan by more than a tenth: enough to matter, not so tight that normal variance trips it. */
const AHEAD = 1.1

/** Behind plan by more than a fifth — a campaign that will not spend what was set aside for it. */
const BEHIND = 0.8

/**
 * How much of the plan has to be at stake before a campaign is worth a client's attention.
 *
 * Without it this block listed fourteen findings for fifteen campaigns — every small campaign that
 * drifted a few hundred off plan got its own sentence, and a list that flags everything flags
 * nothing. The bar is the MONEY at stake as a share of the whole plan, not the ratio: a campaign at
 * half its pace on a two-thousand budget is noise beside one at ninety per cent of a forty-thousand
 * one, and the ratio cannot tell them apart.
 */
const MATERIAL = 0.05

/** A client reads a short list. What does not fit is counted, not dropped silently. */
const SHOWN = 5

export function ClientAttention({
  payload,
  currency,
  locale,
}: {
  payload: LivePayload
  currency: string | null
  locale: Locale
}) {
  const ar = locale === 'ar'
  const rows = (payload.budget ?? []).filter((r) => r.budget !== null && r.budget > 0)

  if (rows.length === 0) return null

  const name = (r: BudgetRow): string => providerLabel(r.provider ?? '', locale)

  /*
   * The unit these figures are actually in, read from the rows rather than from the report.
   *
   * A report record carries a currency of its own, and it is not always the one the money was summed
   * in: rows normalised before the canonical basis changed still describe themselves as they were
   * stored (MONEY-USD-002), and a column that took the report's word for it would label a client's
   * money in a currency it is not. Where the rows disagree, no single symbol is true for the column,
   * so it prints bare — one column cannot be two units, and guessing which is exactly the mislabel
   * this avoids.
   */
  const units = [...new Set(rows.map((r) => r.budget_currency ?? r.spent_currency ?? currency))]
  const unit = units.length === 1 ? units[0] : null

  /*
   * A platform only produces a finding when its pace could actually be computed.
   *
   * `pace` is null wherever the money contract refused to compare — a withheld spend, a
   * mixed-currency scope. Producing «overspending» from a figure the product would not print is the
   * fabrication the contract exists to prevent, and a client has no way to catch it.
   */
  /*
   * The money each finding is ABOUT — overspend for a platform running hot, the plan it will not
   * reach for one running cold. It is what ranks the list and what the bar is measured against, so a
   * finding a client reads is always the one with the most of their money behind it.
   */
  const plan = rows.reduce((sum, r) => sum + (r.budget ?? 0), 0)
  const material = (stake: number) => plan > 0 && stake / plan >= MATERIAL

  const ahead = rows
    .filter((r) => r.pace !== null && r.pace > AHEAD)
    .map((r) => ({ row: r, stake: Math.max(0, (r.spent ?? 0) - (r.budget ?? 0)) }))
    .filter((f) => material(f.stake))

  const behind = rows
    .filter((r) => r.pace !== null && r.pace < BEHIND)
    .map((r) => ({ row: r, stake: Math.max(0, (r.budget ?? 0) - (r.spent ?? 0)) }))
    .filter((f) => material(f.stake))

  const byStake = (a: { stake: number }, b: { stake: number }) => b.stake - a.stake

  const findings = [
    ...ahead.sort(byStake).map(({ row: r }) => ({
      key: `ahead-${r.provider}`,
      tone: 'warning' as const,
      text: ar
        ? `الإنفاق على ${name(r)} أسرع من الخطة (${(r.pace ?? 0).toFixed(2)}×) — قد تنفد الميزانية قبل نهاية الفترة.`
        : `Spending on ${name(r)} is ahead of plan (${(r.pace ?? 0).toFixed(2)}×) — its budget may run out before the period ends.`,
    })),
    ...behind.sort(byStake).map(({ row: r }) => ({
      key: `behind-${r.provider}`,
      tone: 'muted' as const,
      text: ar
        ? `الإنفاق على ${name(r)} أبطأ من الخطة (${(r.pace ?? 0).toFixed(2)}×) — قد لا تُستهلك الميزانية.`
        : `Spending on ${name(r)} is behind plan (${(r.pace ?? 0).toFixed(2)}×) — it may not use its budget.`,
    })),
  ]

  const shown = findings.slice(0, SHOWN)
  const rest = findings.length - shown.length

  return (
    <section className="mt-6 flex flex-col gap-4" data-testid="live-attention">
      <div>
        <h3 className="text-base font-bold text-text-primary">{ar ? 'حالة الميزانية' : 'Budget status'}</h3>
        <DataMetricTable
          columns={[
            { key: 'platform', label: ar ? 'المنصة' : 'Platform', kind: 'text' },
            { key: 'budget', label: ar ? 'الميزانية' : 'Budget', kind: 'money', currency: unit },
            { key: 'spent', label: ar ? 'المصروف' : 'Spent', kind: 'money', currency: unit },
            { key: 'remaining', label: ar ? 'المتبقي' : 'Remaining', kind: 'money', currency: unit },
            { key: 'consumed', label: ar ? 'الاستهلاك' : 'Consumed', kind: 'percent', digits: 0 },
          ]}
          rows={rows.map((r) => ({
            platform: name(r),
            budget: r.budget,
            spent: r.spent,
            remaining: r.remaining,
            consumed: r.consumed_pct,
          }))}
          initialSort={{ column: 1, dir: 'desc' }}
        />
      </div>

      {/*
        «Nothing needs you» is a RESULT, and a report that cannot say it teaches its reader that the
        section is decorative. It is said once, here, rather than as an empty table.
      */}
      <div data-testid="live-attention-findings">
        <h3 className="text-base font-bold text-text-primary">
          {ar ? 'ما يحتاج قرارًا' : 'What needs a decision'}
        </h3>
        {findings.length === 0 ? (
          <p className="mt-1 text-sm text-text-secondary" data-testid="live-attention-clear">
            {ar
              ? 'لا شيء يستدعي قرارًا الآن — كل منصة ضمن خطة إنفاقها.'
              : 'Nothing needs a decision right now — every platform is within its spending plan.'}
          </p>
        ) : (
          <ul className="mt-2 flex flex-col gap-2">
            {shown.map((f) => (
              <li
                key={f.key}
                data-testid={`live-attention-${f.tone}`}
                className={`rounded-xl border p-3 text-sm ${
                  f.tone === 'warning'
                    ? 'border-warning/40 bg-[var(--warning-background)] text-warning'
                    : 'border-border bg-surface-secondary text-text-secondary'
                }`}
              >
                {f.text}
              </li>
            ))}
            {rest > 0 && (
              <li className="text-sm text-text-secondary" data-testid="live-attention-rest">
                {ar
                  ? `و${rest} منصة أخرى خارج خطة إنفاقها — التفصيل في الجدول أعلاه.`
                  : `And ${rest} more off their spending plan — the detail is in the table above.`}
              </li>
            )}
          </ul>
        )}
      </div>
    </section>
  )
}
