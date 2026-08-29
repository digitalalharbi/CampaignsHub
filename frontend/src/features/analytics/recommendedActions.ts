import type { Diagnosis, DiagnosticFinding } from './diagnose'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — what to do about it, gated on the evidence that says so.
 *
 * `diagnose()` locates a weakness. This turns a located weakness into an action, and its whole job is
 * to REFUSE most of the time: advice is the point at which a diagnosis stops being a description and
 * starts costing somebody money.
 *
 * ## An inference may not become an instruction
 *
 * A finding carries `confidence`. `observed` means a measurement said it; `probable` means a ratio
 * suggested it. A probable finding may only ever produce something to CHECK — never something to
 * change. «Your click-through is low, so raise the bid» spends real money on an inference, and the
 * reader cannot tell from the sentence that the ground under it was a ratio.
 *
 * ## Nothing is proposed about a stage that was not examined
 *
 * `missing` names the metrics no platform reported, so the stages depending on them were never read.
 * Advice about those stages would be invented outright — and it is the most convincing kind of
 * invention, because it arrives beside real findings about real stages.
 *
 * ## This proposes; it never acts
 *
 * Nothing here changes budget, bid, status, targeting or creative. Every action names a change the
 * operator makes themselves, in the platform, deliberately. A product that adjusts live advertising on
 * its own reading of a ratio needs a permission boundary, an audit trail and a reversible workflow
 * before it needs a recommendation engine.
 */

export type ActionKind = 'investigate' | 'adjust'

export interface RecommendedAction {
  /** The finding this answers, so the reader can see what it was derived from. */
  code: string
  kind: ActionKind
  /** The metric keys the advice stands on. All of them were reported, or there is no action. */
  evidence: string[]
  confidence: DiagnosticFinding['confidence']
}

/**
 * What each finding justifies.
 *
 * `adjust` is only ever paired with an OBSERVED finding below; the gate re-checks that at runtime
 * rather than trusting this table, because the table is the easy thing to edit carelessly.
 */
const FOR_CODE: Record<string, ActionKind> = {
  not_delivering: 'investigate',
  weak_attraction: 'investigate',
  clicks_not_arriving: 'investigate',
  visits_lost: 'investigate',
  no_conversions: 'investigate',
  conversions_without_value: 'adjust',
  /*
   * `investigate`, not `adjust`, even though the finding is OBSERVED.
   *
   * The observation is solid — leads arrived and none qualified — but the fix is not: it may be the
   * targeting, the form's questions, the offer, or a sales team that has not been passing feedback
   * back. «Adjust» would name a change to make, and this product does not know which one. What it
   * knows is where to look.
   */
  leads_none_qualified: 'investigate',
}

/**
 * The actions this diagnosis supports — often none.
 *
 * Returns nothing at all when the account could not be examined: «we could not read your account, and
 * here is what to do about it» is advice with no ground under it.
 */
export function recommendedActions(diagnosis: Diagnosis): RecommendedAction[] {
  if (diagnosis.state !== 'diagnosed') {
    return []
  }

  const unread = new Set(diagnosis.missing)
  const out: RecommendedAction[] = []

  for (const finding of diagnosis.findings) {
    const kind = FOR_CODE[finding.code]

    if (kind === undefined) {
      // A finding this table has not been taught. Silence is correct: an unknown weakness with a
      // confidently generic action attached is worse than no action.
      continue
    }

    // Every metric the finding stands on must have been reported. A finding derived partly from a
    // stage nobody reported cannot carry advice, whatever the diagnosis concluded.
    if (finding.evidence.some((key) => unread.has(key))) {
      continue
    }

    out.push({
      code: finding.code,
      // An inference is downgraded to something to check, whatever the table says.
      kind: finding.confidence === 'probable' ? 'investigate' : kind,
      evidence: finding.evidence,
      confidence: finding.confidence,
    })
  }

  return out
}
