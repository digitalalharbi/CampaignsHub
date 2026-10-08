import { creativeKindSubject } from './creativeKind'

import type { ContentIntelligence, FormatIntelligencePayload } from './api'

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 — the sentence, derived from the figures on the screen.
 *
 * The verdict is the one thing a reader takes away, so it must be computable from what they can see
 * and from nothing else. No prose is generated: this picks one of four shapes from the comparison
 * the server already returned, and every number in it is a number already drawn beside it.
 *
 * ## It never recommends moving money
 *
 * «الفيديو أكثر كفاءة في هذه الفترة» is a reading. «انقل 80% من الميزانية إلى الفيديو» is an
 * allocation, and this module is decision intelligence rather than an allocator — the requirement is
 * explicit, and a format that currently wins on one period's figures is not evidence for a budget
 * move.
 */
export type Evidence = 'high' | 'moderate' | 'insufficient'

export interface Verdict {
  /** `none` when the evidence does not permit a winner — the state that must survive. */
  kind: 'winner' | 'split' | 'none'
  winner: string | null
  ar: string
  en: string
}

/**
 * The sentence form, from the one vocabulary — see CONTENT-KIND-VOCABULARY-001.
 *
 * This file used to carry its own table. It said «المجموعة» and «الدوارة» where `ContentSummary`
 * said «تشكيلة» and «دوّار», so the verdict and the pie beside it named the same shape differently,
 * and both fell through to printing the raw key for anything they did not list.
 */
export const formatWord = (format: string, ar: boolean): string => creativeKindSubject(format, ar)

/**
 * One objective's verdict.
 *
 * `insufficient` is the only state that forbids a winner, and preserving it is the point: the
 * current behaviour of saying «لا توجد بيانات كافية» rather than crowning a format on two assets is
 * the behaviour this generalises, not one it replaces.
 */
export function verdictFor(comparison: ContentIntelligence, evidence: Evidence): Verdict {
  if (comparison.refusal !== null || evidence === 'insufficient' || comparison.best === null) {
    return {
      kind: 'none',
      winner: null,
      ar: 'لا توجد أدلة كافية للحكم بعد.',
      en: 'Not enough evidence to call this yet.',
    }
  }

  const best = formatWord(comparison.best, true)
  const bestEn = formatWord(comparison.best, false)

  /*
   * A dead heat is a real answer and a common one. Calling it for whichever row sorted first would
   * be a verdict manufactured by a tie-break, which is the kind of false confidence this whole
   * module is built to avoid.
   */
  const values = comparison.formats.map((f) => f.value)
  const spread = values.length > 1 ? Math.abs(values[0] - values[values.length - 1]) : 0
  const scale = Math.max(...values.map((v) => Math.abs(v)), 0)

  if (scale > 0 && spread / scale < 0.05) {
    return {
      kind: 'split',
      winner: null,
      ar: 'لا يوجد فرق واضح بين الأنواع في هذه الفترة.',
      en: 'No clear difference between the formats in this period.',
    }
  }

  const hedge = evidence === 'moderate'

  /*
   * Each sentence takes its OWN language's word for the format. Reading one variable into both put
   * «الفيديو performs better in this period» on an English screen — the kind of seam a single shared
   * local hides until something asserts on the other language.
   */
  return {
    kind: 'winner',
    winner: comparison.best,
    ar: hedge
      ? `${best} أفضل في هذه الفترة، والأدلة ما زالت محدودة.`
      : `${best} أفضل في هذه الفترة.`,
    en: hedge
      ? `${bestEn} performs better in this period, on limited evidence.`
      : `${bestEn} performs better in this period.`,
  }
}

/** The objective a compact surface should lead with: the one most of the scope was bought for. */
export function leadObjective(payload: FormatIntelligencePayload | undefined): FormatIntelligencePayload['objectives'][number] | null {
  /*
   * Tolerant of a body that is not this answer.
   *
   * A proxy error page, an older server, a stubbed client — this reads a key off whatever came back,
   * and `payload.objectives.length` on a response without one is a crash on an analytics page, which
   * is a far worse outcome than a missing block. The absence is reported as «nothing ran», which is
   * what the caller draws for an empty scope anyway.
   */
  const objectives = Array.isArray(payload?.objectives) ? payload.objectives : []

  if (objectives.length === 0) return null

  return objectives.find((o) => o.evidence !== 'insufficient') ?? objectives[0]
}
