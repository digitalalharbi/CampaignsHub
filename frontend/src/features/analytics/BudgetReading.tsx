import { useState } from 'react'

import { METRIC_LABEL } from '@/styles/scale'
import type { Locale } from '@/stores/ui'

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the pacing table, read back in the funnel's own shape.
 *
 * The table underneath this is the SIGNAL and nothing else: a column of percentages whose meaning a
 * reader reconstructs every time they look at it. What is 1.6 measured against? Why is it 1.6?
 * Which figures say so? And what, if anything, should anybody do?
 *
 * The five steps come from the server, over the same rows the table reads, so the two cannot
 * disagree about which line is spending fastest.
 *
 * ## Absent steps stay absent
 *
 * There is no signal without two paced lines, and no action without a signal. Where a step is
 * missing the REASON takes its place — «only one line has a pace», «nothing here could be paced at
 * all» — because those are different situations and an operator acts on them differently: the first
 * waits, the second is a withheld figure or a currency somebody has to go and look at.
 */
export type BudgetExplanationPayload = {
  signal: { metric: string; fastest: { campaign: string; value: number }; slowest: { campaign: string; value: number } } | null
  context: { scope: string; lines: number; from: string; to: string } | null
  explanation: { ar: string; en: string } | null
  evidence: string[]
  action: { ar: string; en: string } | null
  silent_reason: string | null
  unmeasured_lines: number
}

const SILENT: Record<string, { ar: string; en: string }> = {
  only_one_line_has_a_pace: {
    ar: 'خط واحد فقط أمكن حساب سرعته — رقم، وليس مدى.',
    en: 'Only one line could be paced — a figure, not a range.',
  },
  no_line_could_be_paced: {
    ar: 'لا يمكن حساب سرعة أي خط في هذه الفترة: المصروف محجوب أو بعملة غير عملة الخطة.',
    en: 'No line in this window could be paced: the spend is withheld, or it is in a different currency from the plan.',
  },
  no_budgets_set: {
    ar: 'لم تُضبط ميزانية لأي حملة في هذه الفترة.',
    en: 'No campaign in this window has a budget set.',
  },
}

const EVIDENCE: Record<string, { ar: string; en: string }> = {
  budget: { ar: 'الميزانية', en: 'Budget' },
  spent: { ar: 'المصروف', en: 'Spent' },
  pace: { ar: 'السرعة', en: 'Pace' },
}

export function BudgetReading({ reading, locale }: { reading: BudgetExplanationPayload | undefined; locale: Locale }) {
  const ar = locale === 'ar'
  // Before the early return: a hook cannot be called conditionally.
  const [open, setOpen] = useState(false)
  if (reading === undefined) return null

  const pace = (n: number) => `${n.toFixed(2)}×`

  /*
   * The shared scale for both bars. At least 1.2 so on-pace sits inside the track even when every
   * line is behind — otherwise the reference tick lands at the far edge and the bars read as though
   * the slower line were nearly on target.
   */
  const paceCeiling = Math.max(1.2, reading.signal?.fastest.value ?? 0, reading.signal?.slowest.value ?? 0)

  return (
    <div data-testid="budget-reading" className="rounded-xl border border-border bg-surface-secondary/40 p-3.5">
      {reading.signal === null ? (
        <p data-testid="budget-reading-silent" className="text-sm text-text-secondary">
          {ar
            ? SILENT[reading.silent_reason ?? 'no_budgets_set']?.ar
            : SILENT[reading.silent_reason ?? 'no_budgets_set']?.en}
        </p>
      ) : (
        <div className="flex flex-col gap-2">
          {/*
            VISUAL-FIRST-001 — «BUDGET → consumed / remaining / pacing / projection visual.»

            This was a SENTENCE: «Fastest <name> 0.22× · slowest <name> 0.10×», followed by a context
            line, an explanation paragraph, an evidence line and an action — five stacked runs of
            text for one comparison between two numbers on one scale.

            Pace is a ratio against 1.00×, which is exactly what a bar with a reference line says at
            a glance and a sentence cannot: whether a line is ahead or behind, and by how far. Both
            bars share one scale, so the DISTANCE between them is the finding. The reference tick is
            drawn at on-pace, because 0.22× means nothing without knowing what «right» looks like.
          */}
          <span className={`block text-text-secondary ${METRIC_LABEL}`}>{ar ? 'سرعة الصرف' : 'Spend pace'}</span>

          <div className="flex flex-col gap-1.5" data-testid="budget-pace-bars">
            {[
              { key: 'fastest', row: reading.signal.fastest, tone: 'bg-warning' },
              { key: 'slowest', row: reading.signal.slowest, tone: 'bg-brand-500' },
            ].map(({ key, row, tone }) => (
              <div key={key} className="flex items-center gap-2" data-testid={`budget-pace-${key}`}>
                <span className="min-w-0 flex-1 truncate text-xs text-text-secondary" title={row.campaign}>{row.campaign}</span>
                <span className="relative h-2 w-32 shrink-0 overflow-hidden rounded-full bg-surface sm:w-44">
                  <span
                    className={`block h-full rounded-full ${tone}`}
                    style={{ width: `${Math.min(100, Math.max(2, (row.value / paceCeiling) * 100))}%` }}
                  />
                  {/* On pace. The one mark that makes every other position readable. */}
                  <span
                    aria-hidden
                    className="absolute inset-y-0 w-px bg-text-muted"
                    style={{ insetInlineStart: `${(1 / paceCeiling) * 100}%` }}
                  />
                </span>
                <span dir="ltr" className="tnum w-14 shrink-0 text-end text-xs font-semibold text-text-primary">{pace(row.value)}</span>
              </div>
            ))}
          </div>

          <p className="text-[11px] text-text-muted">
            {ar ? 'الخط الرأسي = على السرعة المخطّطة (1.00×)' : 'The tick marks on-pace (1.00×)'}
          </p>

          {reading.action && (
            <p data-testid="budget-reading-action" className="text-sm font-medium text-text-primary">
              {ar ? reading.action.ar : reading.action.en}
            </p>
          )}

          {/*
            Context, reasoning and provenance move behind a disclosure. They are what a reader opens
            after deciding the signal matters; printing them first is what made the block a wall.
          */}
          {open && (
            <div className="space-y-1 border-t border-border pt-2">
              {reading.context && (
                <p className="text-xs text-text-secondary">
                  {ar
                    ? `على ${reading.context.lines} خط ميزانية، بين ${reading.context.from} و${reading.context.to}.`
                    : `Across ${reading.context.lines} budget lines, ${reading.context.from} to ${reading.context.to}.`}
                </p>
              )}
              {reading.explanation && (
                <p className="text-xs leading-relaxed text-text-secondary">{ar ? reading.explanation.ar : reading.explanation.en}</p>
              )}
              {reading.evidence.length > 0 && (
                <p className="text-[11px] text-text-muted">
                  {ar ? 'مبنيّة على: ' : 'Read from: '}
                  {reading.evidence.map((key) => (ar ? EVIDENCE[key]?.ar ?? key : EVIDENCE[key]?.en ?? key)).join(' · ')}
                </p>
              )}
            </div>
          )}

          <button
            onClick={() => setOpen((v) => !v)}
            data-testid="budget-reading-toggle"
            className="inline-flex w-fit items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
          >
            {open ? (ar ? 'إخفاء' : 'Hide') : (ar ? 'كيف حُسبت' : 'How this was read')}
          </button>
        </div>
      )}

      {/*
        Stated whether or not there is a range: a reading over four of nine lines is a reading over
        four of nine lines, and the count is what tells an operator how much of the page it covers.
      */}
      {reading.unmeasured_lines > 0 && (
        <p data-testid="budget-reading-unmeasured" className="mt-2 text-[11px] text-text-muted">
          {ar
            ? `${reading.unmeasured_lines} خطًا لا يمكن حساب سرعته (مصروف محجوب أو عملة مختلفة) وليس ضمن هذه القراءة.`
            : `${reading.unmeasured_lines} line(s) could not be paced (withheld spend or a different currency) and are outside this reading.`}
        </p>
      )}
    </div>
  )
}
