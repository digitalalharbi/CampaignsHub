import type { FatigueStatus } from '@/features/content/api'

/**
 * What `contentWhy` reads of a content item — a card, or the light row `/content-standing` sends for the WHOLE scope.
 * The library page holds one page of cards; a group average over one page is not the group's average, so the library asks
 * the server for these few fields over the whole scope and runs this same function on them.
 */
export interface WhyInput {
  id: string
  name: string
  provider: string
  objective: string | null
  metrics: { impressions?: number | null; clicks?: number | null; spend?: number | null; frequency?: number | null } | null
  fatigue?: { status: FatigueStatus; signals?: Array<{ metric: string; direction: string; change: number | null }> } | null
}

/**
 * ANALYTICS-CONTENT-WHY-002 — «ليش نجح / ليش ما نجح», as figures a chart can draw.
 *
 * Every number here is read or derived from what the server already sent for the content item (`metrics`, `fatigue`):
 *   • the item's click rate against the AVERAGE of the content that shares its objective AND its platform;
 *   • the share of that group's spend the item carried (its contribution);
 *   • fatigue — the frequency, and the click-rate change the server's own fatigue signals report over time.
 * It invents nothing: a group needs at least three items with a thousand impressions each, otherwise there is nothing
 * fair to compare against and the item is not drawn at all.
 */
export const MIN_IMPRESSIONS = 1000
export const MIN_GROUP = 3

export interface WhyRow {
  id: string
  name: string
  provider: string
  objective: string
  ctr: number
  /** The simple average click rate of the group (same objective, same platform). */
  groupAvg: number
  /** (ctr − avg) ÷ avg — null when the group's average is zero. */
  gap: number | null
  /** The item's spend ÷ the group's spend — null when the group spent nothing. */
  share: number | null
  spend: number
  frequency: number | null
  fatigue: FatigueStatus
  /** The click-rate change the server's fatigue signals report (a fraction, −0.2 = fell 20%), or null when it sent none. */
  ctrChange: number | null
  groupSize: number
  verdict: 'above' | 'below' | 'level'
}

const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null)

export function contentWhy(cards: readonly WhyInput[]): WhyRow[] {
  const groups = new Map<string, Array<{ cr: WhyInput; ctr: number; spend: number }>>()

  for (const cr of cards) {
    const imp = num(cr.metrics?.impressions) ?? 0
    const clicks = num(cr.metrics?.clicks) ?? 0
    if (!(imp >= MIN_IMPRESSIONS)) continue
    const key = `${cr.objective ?? 'other'}|${cr.provider}`
    groups.set(key, [...(groups.get(key) ?? []), { cr, ctr: clicks / imp, spend: num(cr.metrics?.spend) ?? 0 }])
  }

  const out: WhyRow[] = []

  for (const g of groups.values()) {
    if (g.length < MIN_GROUP) continue
    const avg = g.reduce((a, r) => a + r.ctr, 0) / g.length
    const total = g.reduce((a, r) => a + r.spend, 0)

    for (const r of g) {
      const gap = avg > 0 ? (r.ctr - avg) / avg : null
      const change = r.cr.fatigue?.signals?.find((s) => s.metric === 'ctr')?.change ?? null
      out.push({
        id: r.cr.id,
        name: r.cr.name,
        provider: r.cr.provider,
        objective: r.cr.objective ?? 'other',
        ctr: r.ctr,
        groupAvg: avg,
        gap,
        share: total > 0 ? r.spend / total : null,
        spend: r.spend,
        frequency: num(r.cr.metrics?.frequency),
        fatigue: r.cr.fatigue?.status ?? 'insufficient_data',
        ctrChange: change,
        groupSize: g.length,
        verdict: gap === null || Math.abs(gap) < 0.05 ? 'level' : gap > 0 ? 'above' : 'below',
      })
    }
  }

  return out
}

/** The best and the weakest of each group (objective × platform): two above, one below — the order the cards read in. */
export function bestAndWeakest(rows: readonly WhyRow[], best = 2, weakest = 1): { best: WhyRow[]; weakest: WhyRow[] } {
  const groups = new Map<string, WhyRow[]>()
  for (const r of rows) groups.set(`${r.objective}|${r.provider}`, [...(groups.get(`${r.objective}|${r.provider}`) ?? []), r])
  const b: WhyRow[] = []
  const w: WhyRow[] = []

  for (const g of groups.values()) {
    const sorted = [...g].sort((x, y) => y.ctr - x.ctr)
    b.push(...sorted.slice(0, best).filter((r) => r.verdict !== 'below'))
    w.push(...sorted.slice(-weakest).filter((r) => r.verdict !== 'above' && !b.includes(r)))
  }

  return { best: b, weakest: w }
}
