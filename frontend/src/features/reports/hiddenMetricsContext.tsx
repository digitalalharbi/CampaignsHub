import { createContext, useContext, useMemo, type ReactNode } from 'react'

/**
 * REPORT-KPI-VISIBILITY-001 — the metrics the report on screen hides, readable anywhere under it.
 *
 * The server has already removed their figures; a component asks this only to leave out the LABEL, the column, the chart option or the
 * sentence that would otherwise name a metric the client is not meant to see. Provided once at each report root (the live link, the
 * shared snapshot, the print document).
 */
const Ctx = createContext<ReadonlySet<string>>(new Set())

export function HiddenMetricsProvider({ hidden, children }: { hidden: readonly string[] | null | undefined; children: ReactNode }) {
  const key = (hidden ?? []).join('|')
  const value = useMemo(() => new Set(hidden ?? []), [key]) // eslint-disable-line react-hooks/exhaustive-deps

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

/** The hidden metrics of the enclosing report. Empty outside one, so a component used elsewhere hides nothing. */
export const useHiddenMetrics = (): ReadonlySet<string> => useContext(Ctx)

/** Whether one metric (by catalog key) is hidden on the enclosing report. */
export const useMetricHidden = (key: string): boolean => useContext(Ctx).has(key)
