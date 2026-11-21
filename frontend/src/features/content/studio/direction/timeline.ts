/**
 * CONTENT-STUDIO-001 — the video's time structure: Hook (the first 3 seconds), Message, and a Call to action that holds still for
 * its last 2.5–3 seconds. 15 seconds is the ceiling the agency set; 12 is the default. Enforced here, in the schema, and again by
 * the exporter, so no path produces a longer one.
 */

export const MAX_DURATION_MS = 15_000
export const DEFAULT_DURATION_MS = 12_000
export const HOOK_MS = 3_000
export const CTA_MIN_MS = 2_500
export const CTA_MAX_MS = 3_000

export interface Span {
  start: number
  end: number
}

export interface VideoTimeline {
  duration: number
  hook: Span
  message: Span
  cta: Span
}

export function buildTimeline(durationMs: number = DEFAULT_DURATION_MS, ctaMs: number = CTA_MIN_MS): VideoTimeline {
  const duration = Math.min(Math.max(durationMs, HOOK_MS + CTA_MIN_MS + 500), MAX_DURATION_MS)
  const cta = Math.min(Math.max(ctaMs, CTA_MIN_MS), CTA_MAX_MS)

  return {
    duration,
    hook: { start: 0, end: HOOK_MS },
    message: { start: HOOK_MS, end: duration - cta },
    cta: { start: duration - cta, end: duration },
  }
}

export type TimelineProblem = 'too_long' | 'hook_not_first_3s' | 'cta_too_short' | 'cta_not_last' | 'message_missing' | 'gap_or_overlap'

export function timelineProblems(t: VideoTimeline): TimelineProblem[] {
  const out: TimelineProblem[] = []

  if (t.duration > MAX_DURATION_MS) out.push('too_long')
  if (t.hook.start !== 0 || t.hook.end > HOOK_MS) out.push('hook_not_first_3s')
  if (t.cta.end - t.cta.start < CTA_MIN_MS) out.push('cta_too_short')
  if (t.cta.end !== t.duration) out.push('cta_not_last')
  if (t.message.end - t.message.start <= 0) out.push('message_missing')
  if (t.message.start !== t.hook.end || t.cta.start !== t.message.end) out.push('gap_or_overlap')

  return out
}
