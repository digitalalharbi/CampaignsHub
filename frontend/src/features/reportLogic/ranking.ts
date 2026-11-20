import type { LivePayload } from '@/features/reports/api'
import { platformRows, type RankRow } from './platforms'
import type { ResultSource } from './results'

export type RankSort = 'result' | 'impressions' | 'clicks' | 'ctr' | 'name'

/** The platform comparison, sortable by any column. Ties break on the name so the order is stable; a missing figure sorts last whichever way the column runs. */
export function rankPlatforms(payload: LivePayload, source: ResultSource, ar: boolean, sort: RankSort = 'result', dir: 'asc' | 'desc' = 'desc'): RankRow[] {
  const pick = (r: RankRow): number | string | null => (sort === 'name' ? r.name : r[sort])
  const sign = dir === 'asc' ? 1 : -1

  return platformRows(payload, source, ar).sort((a, b) => {
    const va = pick(a)
    const vb = pick(b)
    // «Not reported» is never the best or the worst.
    if (va === null && vb === null) return a.name.localeCompare(b.name)
    if (va === null) return 1
    if (vb === null) return -1
    const diff = typeof va === 'string' ? va.localeCompare(String(vb)) : Number(va) - Number(vb)

    return diff === 0 ? a.name.localeCompare(b.name) : diff * sign
  })
}
