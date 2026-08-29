import { diagnose, type DiagnosticInput } from './diagnose'
import { recommendedActions } from './recommendedActions'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — the dashboard's one line, from the SAME engine.
 *
 * The requirement asks for one reasoning layer read by Analytics, the campaigns workspace and a
 * concise dashboard line. «Concise» is the trap: a second, smaller rule set that decides what the
 * headline says would eventually disagree with the panel a click away, and the reader would have no
 * way to know which of the two was lying. So this does no reasoning of its own — it calls `diagnose`
 * and then CHOOSES, and choosing is a presentation decision rather than an analytical one.
 *
 * ## What it chooses
 *
 * The earliest weakness along the journey. A campaign that is not delivering also converts nothing,
 * and reporting «no conversions» to somebody whose ads never ran points them at the wrong end of their
 * own funnel. Delivery precedes attraction precedes visit precedes conversion precedes value, and the
 * first one that broke is the one worth a single line.
 *
 * ## What it refuses to say
 *
 * Nothing at all unless the layer produced a finding. «Could not be examined» and «examined and
 * healthy» both yield null here: a headline is the wrong place to explain an absence, and a dashboard
 * that prints «no problems found» over an account it could not read is the exact failure the panel
 * version was built to avoid.
 */
export interface ConciseFinding {
  code: string
  confidence: 'observed' | 'probable'
  /** True when the layer also had an action to propose — the line may then say there is one. */
  hasAction: boolean
}

/*
 * The chain's own order, and `quality` sits at the end beside `value`.
 *
 * The line names the EARLIEST weakness, because fixing a later one first is wasted work: leads that
 * nobody qualified is a real problem and not the one to act on while the ads are not being delivered
 * at all. Leaving `quality` out of this list would sort it to index -1 and make it always earliest —
 * the loudest finding on the page for the wrong reason.
 */
const ORDER = ['delivery', 'attraction', 'visit', 'conversion', 'value', 'quality'] as const

export function conciseFinding(input: DiagnosticInput): ConciseFinding | null {
  const d = diagnose(input)

  if (d.state !== 'diagnosed' || d.findings.length === 0) {
    return null
  }

  const earliest = [...d.findings].sort(
    (a, b) => ORDER.indexOf(a.stage) - ORDER.indexOf(b.stage),
  )[0]

  const actions = recommendedActions(d)

  return {
    code: earliest.code,
    confidence: earliest.confidence,
    hasAction: actions.some((a) => a.code === earliest.code),
  }
}
