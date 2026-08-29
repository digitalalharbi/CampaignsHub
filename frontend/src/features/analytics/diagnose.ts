import { canonicalOfRaw } from '@/features/campaigns/canonicalObjectives'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — ONE reasoning layer.
 *
 * One, deliberately: the dashboard says it concisely, the campaigns workspace operationally and
 * Analytics across campaigns, but three engines would eventually disagree about the same account —
 * and a product that explains itself two different ways is worse than one that does not explain
 * itself at all.
 *
 * Two rules govern everything here, and both are about not saying more than the data supports:
 *
 *   1. **No cause without its evidence.** «Your landing page is the problem» is a claim about visits.
 *      `byCampaign` and `totals` coalesce every unreported metric to 0, so a diagnosis that reads
 *      those zeros would send somebody to rebuild a page that was working. A stage is only judged
 *      when its metric was actually REPORTED — a real zero is evidence, an absent metric is not.
 *   2. **Missing evidence is stated, not skipped.** Silence reads as «no problem found», which is a
 *      different statement and a false one. What could not be examined is named.
 *
 * The journey is a DIAGNOSTIC MODEL — الوصول → الاهتمام → الزيارة → التحويل → القيمة — and never
 * another primary filter. It exists to locate a weakness, not to slice the data.
 */
export type DiagnosticStage = 'delivery' | 'attraction' | 'visit' | 'conversion' | 'value'

export interface DiagnosticFinding {
  stage: DiagnosticStage
  /** `observed` — measured directly. `probable` — inferred from a ratio, and said as an inference. */
  confidence: 'observed' | 'probable'
  /** The metric keys this finding was derived from. All of them were reported. */
  evidence: string[]
  code: string
}

export interface DiagnosticInput {
  objective: string | null
  totals: Record<string, number | null | undefined>
  /** Which metric keys the platforms actually sent. Absent or false means «never reported». */
  reported: Record<string, boolean>
}

export interface Diagnosis {
  state: 'diagnosed' | 'not_diagnosable'
  findings: DiagnosticFinding[]
  /** Metric keys the chain needed and did not have. Named so the gap is visible, not inferred. */
  missing: string[]
}

/** The chain each canonical objective is judged along. `value` belongs only to money objectives. */
const CHAIN: Record<string, DiagnosticStage[]> = {
  sales: ['delivery', 'attraction', 'visit', 'conversion', 'value'],
  leads: ['delivery', 'attraction', 'visit', 'conversion'],
  traffic: ['delivery', 'attraction', 'visit'],
  app_promotion: ['delivery', 'attraction', 'conversion'],
  awareness_engagement: ['delivery', 'attraction'],
}

/** What each stage needs before it may be judged at all. */
const EVIDENCE: Record<DiagnosticStage, string[]> = {
  delivery: ['impressions'],
  attraction: ['impressions', 'clicks'],
  visit: ['clicks', 'landing_page_views'],
  conversion: ['clicks', 'conversions'],
  value: ['conversions', 'revenue'],
}

const n = (v: number | null | undefined): number => (typeof v === 'number' ? v : 0)

export function diagnose({ objective, totals, reported }: DiagnosticInput): Diagnosis {
  const spend = n(totals.spend)

  /*
   * Nothing spent is not a diagnosis. There is no weakness to locate in a campaign that has not run,
   * and «no problems found» would read as reassurance about money that was never at risk.
   */
  if (spend <= 0) {
    return { state: 'not_diagnosable', findings: [], missing: ['spend'] }
  }

  /*
   * An objective the taxonomy cannot name is judged on the shortest honest chain rather than another
   * objective's. Diagnosing an unknown campaign against the sales chain would blame it for a return
   * nobody bought it for.
   */
  const canonical = objective === null ? null : resolveObjective(objective)
  const stages = CHAIN[canonical ?? ''] ?? ['delivery', 'attraction']

  const findings: DiagnosticFinding[] = []
  const missing: string[] = []
  /*
   * «Examined and healthy» is NOT «could not be examined», and collapsing the two was the first
   * mistake this file made: a perfectly healthy account came back `not_diagnosable`, which reads as
   * «we cannot tell you anything» about an account where everything is fine.
   */
  let examined = 0

  for (const stage of stages) {
    const needed = EVIDENCE[stage]
    const absent = needed.filter((key) => reported[key] !== true)

    if (absent.length > 0) {
      // Named, not skipped: what could not be examined is part of the answer.
      missing.push(...absent.filter((key) => !missing.includes(key)))
      continue
    }

    examined++

    const finding = judge(stage, totals)
    if (finding !== null) findings.push(finding)
  }

  return {
    // Nothing EXAMINED is «not diagnosable». Nothing FOUND, having examined, is a clean bill of
    // health — a different answer, and the reader is entitled to it.
    state: examined === 0 ? 'not_diagnosable' : 'diagnosed',
    findings,
    missing,
  }
}

/**
 * Callers hold an objective at one of two levels, and this accepts both.
 *
 * `byCampaign` carries the RAW provider objective; the Analytics filter carries an already-CANONICAL
 * key. Canonicalising blindly resolved three of the five canonical keys by coincidence — `sales`,
 * `traffic` and `leads` happen to appear in their own raw lists — and silently returned null for
 * `app_promotion` and `awareness_engagement`, which are named `app_installs` and `awareness` upstream.
 * Those two then fell back to the two-stage chain, so an app-promotion account was never judged on
 * conversion at all and said so nowhere. A partial failure of this kind is worse than a total one: it
 * looks like it works everywhere it is spot-checked.
 */
function resolveObjective(objective: string): string | null {
  // An exact chain key is already canonical. Only a raw provider value needs translating.
  return CHAIN[objective] !== undefined ? objective : canonicalOfRaw(objective)
}

/**
 * One stage, judged only on metrics already proven reported.
 *
 * A ratio is an INFERENCE — a low click-through does not observe a wrong audience, it suggests one —
 * so those findings are `probable` and the copy that renders them must say so. A count is observed.
 */
function judge(stage: DiagnosticStage, totals: DiagnosticInput['totals']): DiagnosticFinding | null {
  const impressions = n(totals.impressions)
  const clicks = n(totals.clicks)
  const visits = n(totals.landing_page_views)
  const conversions = n(totals.conversions)
  const revenue = n(totals.revenue)

  switch (stage) {
    case 'delivery':
      return impressions === 0
        ? { stage, confidence: 'observed', evidence: ['impressions'], code: 'not_delivering' }
        : null

    case 'attraction': {
      if (impressions === 0) return null
      const ctr = clicks / impressions

      return ctr < 0.005
        ? { stage, confidence: 'probable', evidence: ['impressions', 'clicks'], code: 'weak_attraction' }
        : null
    }

    case 'visit':
      if (clicks === 0) return null

      return visits === 0
        ? { stage, confidence: 'observed', evidence: ['clicks', 'landing_page_views'], code: 'clicks_not_arriving' }
        : visits / clicks < 0.7
          ? { stage, confidence: 'probable', evidence: ['clicks', 'landing_page_views'], code: 'visits_lost' }
          : null

    case 'conversion':
      if (clicks === 0) return null

      return conversions === 0
        ? { stage, confidence: 'observed', evidence: ['clicks', 'conversions'], code: 'no_conversions' }
        : null

    case 'value':
      if (conversions === 0) return null

      return revenue === 0
        ? { stage, confidence: 'observed', evidence: ['conversions', 'revenue'], code: 'conversions_without_value' }
        : null
  }
}
