import { countWords, forbiddenWordsIn, hasEmoji, hasHashtag, LIMITS, type StudioCopy } from './copyRules'
import { marginViolations, type FormatKey, type Layer } from './safeZone'
import { timelineProblems, type VideoTimeline } from './timeline'

/**
 * CONTENT-STUDIO-001 — the hard checks of the readiness score: ANY of these failing means the output cannot be saved until it is
 * corrected. They are facts about the file, not opinions, so they carry no weight and no partial credit.
 */
export type HardCode =
  | 'layer_in_margin'
  | 'video_too_long'
  | 'hook_not_first_3s'
  | 'cta_too_short'
  | 'cta_missing'
  | 'hashtag'
  | 'emoji'
  | 'forbidden_word'
  | 'hook_too_long'
  | 'message_too_long'
  | 'cta_too_long'

export interface HardFailure {
  code: HardCode
  /** The layer, field or word the failure points at, so «fix» can take the person straight to it. */
  subject?: string
}

export interface HardCheckInput {
  format: FormatKey
  layers: ReadonlyArray<Layer>
  copy: StudioCopy
  /** Present for a video; an image has none. */
  timeline?: VideoTimeline
  forbiddenWords?: ReadonlyArray<string>
}

export function hardChecks(input: HardCheckInput): HardFailure[] {
  const out: HardFailure[] = []
  const { copy } = input

  for (const v of marginViolations(input.layers, input.format)) out.push({ code: 'layer_in_margin', subject: v.layerId })

  if (input.timeline) {
    for (const p of timelineProblems(input.timeline)) {
      if (p === 'too_long') out.push({ code: 'video_too_long' })
      else if (p === 'hook_not_first_3s') out.push({ code: 'hook_not_first_3s' })
      else if (p === 'cta_too_short' || p === 'cta_not_last') out.push({ code: 'cta_too_short' })
    }
  }

  if (copy.cta.trim() === '') out.push({ code: 'cta_missing' })
  if (countWords(copy.hook) > LIMITS.hook) out.push({ code: 'hook_too_long', subject: 'hook' })
  if (countWords(copy.message) > LIMITS.message) out.push({ code: 'message_too_long', subject: 'message' })
  if (countWords(copy.cta) > LIMITS.cta) out.push({ code: 'cta_too_long', subject: 'cta' })

  for (const [field, text] of Object.entries({ hook: copy.hook, message: copy.message, cta: copy.cta, support: copy.support ?? '' })) {
    if (hasHashtag(text)) out.push({ code: 'hashtag', subject: field })
    if (hasEmoji(text)) out.push({ code: 'emoji', subject: field })
    for (const word of forbiddenWordsIn(text, input.forbiddenWords ?? [])) out.push({ code: 'forbidden_word', subject: word })
  }

  return out
}
