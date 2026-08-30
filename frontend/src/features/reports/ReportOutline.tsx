import { Check, Minus } from 'lucide-react'
import type { ReportSection } from './InteractiveReport'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — the report's contents, including what is not in it.
 *
 * ## Why the absences are printed
 *
 * A client who opens a report and finds no objective breakdown has two readings available: the
 * agency did not do that analysis, or there was nothing to break down. Only one is true, and until
 * this strip existed the report gave them no way to tell — which is the same failure as a «Findings»
 * heading over an empty state, in the opposite direction.
 *
 * So a section the evidence does not support is listed, greyed, with the reason beside it. The
 * reasons are a fixed set decided server-side from the assembled snapshot, never free text written
 * here: the contents page and the report have to be reading the same document.
 *
 * ## It is a contents page, not navigation
 *
 * No links. A snapshot renders as slides or as a page depending on what the reader chose, and a
 * contents entry that scrolled somewhere in one mode and did nothing in the other would be worse
 * than no entry. What this answers is «what is in this report», before the reader starts.
 */
export function ReportOutline({ outline, ar }: { outline: ReportSection[] | undefined; ar: boolean }) {
  if (!outline || outline.length === 0) return null

  const present = outline.filter((s) => s.present)
  const absent = outline.filter((s) => !s.present)

  return (
    <section
      data-testid="report-outline"
      aria-label={ar ? 'محتويات التقرير' : 'Report contents'}
      className="mb-4 rounded-2xl border border-border bg-surface-secondary/40 p-3.5"
    >
      <h2 className="mb-2 text-[13px] font-semibold leading-tight text-text-secondary">
        {ar ? 'محتويات التقرير' : 'What is in this report'}
      </h2>

      <ol className="flex flex-wrap gap-x-4 gap-y-1.5">
        {present.map((section, index) => (
          <li
            key={section.key}
            data-testid={`report-outline-${section.key}`}
            className="inline-flex items-center gap-1.5 text-sm font-semibold text-text-primary"
          >
            <Check size={14} aria-hidden className="shrink-0 text-success" />
            <span className="tnum text-text-muted">{index + 1}.</span>
            {ar ? section.title_ar : section.title_en}
          </li>
        ))}
      </ol>

      {absent.length > 0 && (
        <ul data-testid="report-outline-absent" className="mt-2.5 flex flex-col gap-1 border-t border-border pt-2.5">
          {absent.map((section) => (
            <li
              key={section.key}
              data-testid={`report-outline-${section.key}`}
              className="flex flex-wrap items-baseline gap-x-1.5 text-xs text-text-muted"
            >
              <Minus size={12} aria-hidden className="shrink-0" />
              <span className="font-semibold">{ar ? section.title_ar : section.title_en}:</span>
              <span>{ar ? section.absent_reason_ar : section.absent_reason_en}</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
