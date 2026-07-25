import { useMemo } from 'react'
import { lastNDays } from './api'
import type { Range } from './api'

export * from './api'

/** Stable date range for the last `days` days (memoized so it doesn't thrash query keys). */
export function useLastNDaysRange(days: number): Range {
  return useMemo(() => lastNDays(days), [days])
}
