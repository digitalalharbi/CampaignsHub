import { create } from 'zustand'

/**
 * MEMBER-VISIBILITY-001 — what the server told this session it is withholding.
 *
 * The server strips the money from a project-scoped answer when the membership withholds it and
 * says so in `meta.money_withheld`. The page then owes the reader one sentence — «the money on this
 * project is withheld from you» — rather than a column of dashes that reads like an outage. This
 * store remembers which projects said it; it never decides anything itself.
 */
interface VisibilityState {
  moneyWithheld: Record<string, true>
  markMoneyWithheld: (projectId: string) => void
  clear: () => void
}

export const useVisibility = create<VisibilityState>((set) => ({
  moneyWithheld: {},
  markMoneyWithheld: (projectId) => set((s) => (s.moneyWithheld[projectId] ? s : { moneyWithheld: { ...s.moneyWithheld, [projectId]: true } })),
  clear: () => set({ moneyWithheld: {} }),
}))

/** The project a request was scoped to, read off its URL — `/projects/{id}/…` — or null. */
export function projectIdOf(url: string | undefined): string | null {
  const m = /\/projects\/([0-9a-f-]{36})(?:\/|$|\?)/i.exec(url ?? '')
  return m ? m[1] : null
}
