import { useState } from 'react'
import { ChevronDown } from 'lucide-react'

/**
 * VISUAL-FIRST-001 — a methodology note, disclosed rather than printed.
 *
 * «Keep prose only where language itself adds meaning: observations, recommendations, precise data
 * limitations, required action. Even there: concise and progressively disclosed.»
 *
 * Measured on the data-quality tab: seven separate runs of fourteen words or more, all of them true
 * and all of them the same KIND of true — how reach is counted, how ratios are derived, why a
 * platform's conversions and a store's orders are different numbers. Each one earns its place the
 * first time a reader meets it and costs them a paragraph every time after.
 *
 * So the note keeps its exact words and loses its permanent claim on the page: a quiet labelled row,
 * opened when the reader wants the definition. This is deliberately NOT a tooltip — these are
 * limitations a reader may need to quote, copy or read slowly, and a tooltip that vanishes on
 * mouse-out is the wrong container for something load-bearing.
 *
 * One primitive, reused. The alternative — each panel growing its own collapsible — is how a product
 * ends up with six of them that behave differently.
 */
export function Explainer({
  label,
  children,
  testid,
  className = '',
}: {
  /** What the note is ABOUT, in a few words — this is what stays on the page. */
  label: string
  children: React.ReactNode
  testid?: string
  className?: string
}) {
  const [open, setOpen] = useState(false)

  return (
    <div className={className} data-testid={testid}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        data-testid={testid ? `${testid}-toggle` : undefined}
        className="inline-flex items-center gap-1 text-xs font-semibold text-text-muted hover:text-text-secondary"
      >
        {label}
        <ChevronDown size={13} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div
          data-testid={testid ? `${testid}-body` : undefined}
          className="mt-1.5 text-xs leading-relaxed text-text-secondary"
        >
          {children}
        </div>
      )}
    </div>
  )
}
