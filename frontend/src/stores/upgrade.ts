import { create } from 'zustand'

/**
 * The refusal the customer is currently looking at — PAY-AUDIT-004.
 *
 * Two middlewares refuse for commercial reasons: `EnsureWithinPlanLimit` when a cap is reached, and
 * `EnsureEntitlement` when the plan does not carry a section at all. Both now answer with the same
 * shaped `meta`, and both used to arrive at the interface as an anonymous red toast — the backend
 * named an `upgrade_path` that nothing in `frontend/src` ever read.
 *
 * Held in a store rather than handled at each call site because there are hundreds of mutations and
 * one answer: whatever you were trying to do, your plan does not currently allow it, and here is the
 * way through.
 */
export interface UpgradeRefusal {
  /** Already translated by the server, in the reader's language. */
  message: string
  /** `plan_limit` — a cap was reached. `entitlement` — the section is not on this plan at all. */
  reason: 'plan_limit' | 'entitlement'
  /** «projects», «team_members» … or the capability key for an entitlement refusal. */
  subject: string | null
  /** Present only for a cap: how many are in use, and how many are allowed. */
  used: number | null
  limit: number | null
  /** The plan in force, so the prompt can say what it is upgrading FROM. */
  plan: string | null
  upgradePath: string
}

interface UpgradeState {
  refusal: UpgradeRefusal | null
  show: (refusal: UpgradeRefusal) => void
  dismiss: () => void
}

export const useUpgrade = create<UpgradeState>()((set) => ({
  refusal: null,
  show: (refusal) => set({ refusal }),
  dismiss: () => set({ refusal: null }),
}))

const asNumber = (v: unknown): number | null => (typeof v === 'number' ? v : null)
const asString = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null)

/**
 * Read a commercial refusal out of an API error's `meta`, or null when it is an ordinary failure.
 *
 * Deliberately strict about the flags: a 403 is usually a PERMISSION refusal, and telling somebody
 * to upgrade their plan when a colleague simply has not granted them a role would be worse than
 * saying nothing. Only the two flags the two middlewares set count.
 */
export function upgradeRefusalFrom(error: { message: string; status?: number; meta: Record<string, unknown> | null }): UpgradeRefusal | null {
  const meta = error.meta
  if (error.status !== 403 || meta === null) return null

  const isLimit = meta.plan_limit === true
  const isEntitlement = meta.entitlement === true
  if (!isLimit && !isEntitlement) return null

  return {
    message: error.message,
    reason: isLimit ? 'plan_limit' : 'entitlement',
    subject: asString(meta.metric) ?? asString(meta.capability),
    used: asNumber(meta.used),
    limit: asNumber(meta.limit),
    plan: asString(meta.plan),
    upgradePath: asString(meta.upgrade_path) ?? '/app/subscriptions',
  }
}
