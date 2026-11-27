const today = (): string => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * BUDGET-CARRY-001 — the end a «ترحيل للشهر الجديد» moves the period to: the last day of the month after the period
 * closes (or of this month, when it has already closed). Null when the period is open: nothing needs to move.
 */
export function carryTarget(periodEnd: string | null | undefined, now: string = today()): string | null {
  if (!periodEnd) return null
  const [y, m, d] = (periodEnd >= now ? periodEnd : now).split('-').map(Number)
  // The day after the base date: a period ending on the 30th of November carries into December.
  const next = periodEnd >= now ? new Date(Date.UTC(y, m - 1, d + 1)) : new Date(Date.UTC(y, m - 1, d))
  const last = new Date(Date.UTC(next.getUTCFullYear(), next.getUTCMonth() + 1, 0))

  return `${last.getUTCFullYear()}-${String(last.getUTCMonth() + 1).padStart(2, '0')}-${String(last.getUTCDate()).padStart(2, '0')}`
}
