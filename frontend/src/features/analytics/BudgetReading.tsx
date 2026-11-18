import { useState } from 'react'

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
    ar: 'لا يمكن حساب سرعة أي خط في هذه الفترة: الإنفاق محجوب أو بعملة غير عملة الخطة.',
    en: 'No line in this window could be paced: the spend is withheld, or it is in a different currency from the plan.',
  },
  no_budgets_set: {
    ar: 'لم تُضبط ميزانية لأي حملة في هذه الفترة.',
    en: 'No campaign in this window has a budget set.',
  },
}

const EVIDENCE: Record<string, { ar: string; en: string }> = {
  budget: { ar: 'الميزانية', en: 'Budget' },
  spent: { ar: 'الإنفاق', en: 'Spent' },
  pace: { ar: 'السرعة', en: 'Pace' },
}

export function BudgetReading({ reading, locale }: { reading: BudgetExplanationPayload | undefined | null; locale: Locale }) {
  const ar = locale === 'ar'
  // Before the early return: a hook cannot be called conditionally.
  const [open, setOpen] = useState(false)
  /*
   * `null` as well as `undefined` — an ABSENT reading, however it arrives.
   *
   * The guard read `=== undefined`, so a payload of `null` fell through and the next line
   * dereferenced it: `reading.signal` on null throws, and React unmounts the whole Budget tab
   * rather than the one panel. An endpoint answering `null` is not hypothetical — `ApiResponse`
   * carries whatever the service returned, and an install answering from before this panel existed
   * sends exactly that. A panel with nothing to say renders nothing; it does not take the tab down.
   */
  if (reading === undefined || reading === null) return null

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
          {/* ANALYTICS-REPORT-LAYOUT-001 — the two ends of the pace range as two tiles: the figure large, coloured against plan. */}
          <div className="grid gap-2 sm:grid-cols-2" data-testid="budget-pace-bars">
            {[
              { key: 'fastest', row: reading.signal.fastest, label: ar ? 'الأسرع صرفًا' : 'Fastest' },
              { key: 'slowest', row: reading.signal.slowest, label: ar ? 'الأبطأ صرفًا' : 'Slowest' },
            ].map(({ key, row, label }) => {
              const tone = row.value > 1.05 ? 'text-danger' : row.value >= 0.8 ? 'text-success' : 'text-warning'
              const fill = row.value > 1.05 ? 'bg-danger' : row.value >= 0.8 ? 'bg-success' : 'bg-warning'
              return (
                <div key={key} className="flex flex-col gap-1.5 rounded-xl bg-surface p-3" data-testid={`budget-pace-${key}`}>
                  <span className="text-xs font-semibold text-text-muted">{label}</span>
                  <span className="truncate text-sm font-semibold text-text-primary" title={row.campaign}>{row.campaign}</span>
                  <span dir="ltr" className={`tnum text-xl font-extrabold ${tone}`}>{pace(row.value)}</span>
                  <span className="relative h-2 overflow-hidden rounded-full bg-surface-secondary">
                    <span className={`block h-full rounded-full ${fill}`} style={{ width: `${Math.min(100, Math.max(2, (row.value / paceCeiling) * 100))}%` }} />
                    <span aria-hidden className="absolute inset-y-0 w-0.5 bg-text-primary/60" style={{ insetInlineStart: `${(1 / paceCeiling) * 100}%` }} />
                  </span>
                </div>
              )
            })}
          </div>
          <p className="text-xs text-text-muted">{ar ? 'الخط = على الخطة (1.00×)' : 'The tick = on plan (1.00×)'}</p>

          {reading.action && (
            <p data-testid="budget-reading-action" className="rounded-lg bg-brand-600/10 px-3 py-2 text-sm font-semibold text-text-primary">
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
            ? `${reading.unmeasured_lines} خطًا لا يمكن حساب سرعته (إنفاق محجوب أو عملة مختلفة) وليس ضمن هذه القراءة.`
            : `${reading.unmeasured_lines} line(s) could not be paced (withheld spend or a different currency) and are outside this reading.`}
        </p>
      )}
    </div>
  )
}
