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
  if (reading === undefined) return null

  const pace = (n: number) => `${n.toFixed(2)}×`

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
          <div>
            <span className={`block text-text-secondary ${METRIC_LABEL}`}>{ar ? 'الإشارة' : 'Signal'}</span>
            <p className="mt-0.5 text-sm text-text-primary">
              {ar ? 'الأسرع' : 'Fastest'}{' '}
              <span className="font-bold">{reading.signal.fastest.campaign}</span>{' '}
              <span dir="ltr" className="tnum">{pace(reading.signal.fastest.value)}</span>
              {' · '}
              {ar ? 'الأبطأ' : 'slowest'}{' '}
              <span className="font-bold">{reading.signal.slowest.campaign}</span>{' '}
              <span dir="ltr" className="tnum">{pace(reading.signal.slowest.value)}</span>
            </p>
          </div>

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

          {reading.action && (
            <p data-testid="budget-reading-action" className="text-sm font-medium text-text-primary">
              {ar ? reading.action.ar : reading.action.en}
            </p>
          )}
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
