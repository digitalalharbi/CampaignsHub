import type { LivePayload } from '@/features/reports/api'

/** The funnel's stages — impressions down to the result — without their cost: how far people went, not what each step cost. Unreported stages are left out. */
export function funnelStages(payload: LivePayload) {
  return (payload.funnel ?? []).filter((s) => s.reported).map((s) => ({ ...s, cost_per: null }))
}

/** A funnel is drawn only when at least two stages were reported — one stage is a number, not a path. */
export const funnelHasShape = (payload: LivePayload): boolean => (payload.funnel ?? []).filter((s) => s.reported).length >= 2
