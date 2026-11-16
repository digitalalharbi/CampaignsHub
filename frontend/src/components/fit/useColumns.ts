import { useEffect, useState } from 'react'

/**
 * AGENCY-FIT-001 — how many columns a card grid has at the width it is in, read in JS.
 *
 * A page of cards that has to fit is a count of WHOLE ROWS times the columns on each, so the column count must be the
 * one CSS is drawing. `steps` lists the breakpoints widest first — `[[1536, 4], [1280, 3], [640, 2]]` is
 * `sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4` — and `base` is the count below all of them.
 */
export function useColumns(steps: ReadonlyArray<readonly [number, number]>, base = 1): number {
  const read = () => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return steps[Math.floor(steps.length / 2)]?.[1] ?? base
    for (const [min, cols] of steps) if (window.matchMedia(`(min-width: ${min}px)`).matches) return cols

    return base
  }
  const [cols, setCols] = useState(read)

  useEffect(() => {
    const on = () => setCols(read())
    const queries = steps.map(([min]) => window.matchMedia?.(`(min-width: ${min}px)`)).filter(Boolean) as MediaQueryList[]
    queries.forEach((q) => q.addEventListener('change', on))

    return () => queries.forEach((q) => q.removeEventListener('change', on))
    // `steps` is a literal at every call site; its content, not its identity, is what matters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return cols
}
