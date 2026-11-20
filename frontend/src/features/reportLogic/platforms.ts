import { canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'
import type { LivePayload } from '@/features/reports/api'
import { count, shownPlatform, type ResultSource } from './results'

/** One card of the platforms summary; `key` null is the «all platforms» card. */
export type PlatformCardModel = {
  key: string | null
  title: string
  value: number | null
  impressions: number | null
  /** Already a percent text, or null where the row carries no CTR. */
  rate: string | null
  /** Share of the result, 0..1; null when nothing was produced. */
  share: number | null
}

const ctrText = (p: Record<string, unknown>) => (p.ctr === null || p.ctr === undefined ? null : `${(Number(p.ctr) * 100).toFixed(2)}%`)

/** The platforms summary: one card per platform that delivered, and an «all platforms» card ahead of them when there is more than one. */
export function platformCards(payload: LivePayload, source: ResultSource, ar: boolean): PlatformCardModel[] {
  const rows = (payload.platforms ?? []).filter((p) => shownPlatform(p, source))
  if (rows.length === 0) return []
  const total = rows.reduce((s, p) => s + Number(source.of(p) ?? 0), 0)
  const all: PlatformCardModel[] = rows.length > 1
    ? [{ key: null, title: ar ? 'كل المنصات' : 'All platforms', value: source.total, impressions: count(payload.totals?.impressions), rate: ctrText(payload.totals ?? {}), share: total > 0 ? 1 : null }]
    : []

  return [
    ...all,
    ...rows.map((p): PlatformCardModel => {
      const key = canonicalPlatform(p.provider)

      return { key, title: providerLabel(key, ar ? 'ar' : 'en'), value: source.of(p), impressions: count(p.impressions), rate: ctrText(p), share: total > 0 ? Number(source.of(p) ?? 0) / total : null }
    }),
  ]
}

export type SplitRow = { key: string; name: string; value: number; share: number }

/** The donut of «where the results came from»: each platform's share of the result, largest first. Shares sum to 1. */
export function resultsSplit(payload: LivePayload, source: ResultSource, ar: boolean): { rows: SplitRow[]; total: number } {
  const raw = (payload.platforms ?? []).flatMap((p) => {
    const value = Number(source.of(p) ?? 0)

    return value > 0 ? [{ key: canonicalPlatform(p.provider), name: providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'), value }] : []
  })
  const total = raw.reduce((s, r) => s + r.value, 0)

  return { total, rows: raw.map((r) => ({ ...r, share: total > 0 ? r.value / total : 0 })).sort((a, b) => b.value - a.value) }
}

/** Plain panel title: «مصادر الزيارات» for clicks, «مصادر <النتيجة>» for any other result. */
export const splitTitle = (isClicks: boolean, resultName: string, ar: boolean) =>
  isClicks ? (ar ? 'مصادر الزيارات' : 'Traffic sources') : ar ? `مصادر ${resultName}` : `Sources of ${resultName.toLowerCase()}`

export type RankRow = {
  key: string
  name: string
  result: number | null
  impressions: number | null
  clicks: number | null
  ctr: number | null
  /** The platform's own period-over-period ratio for the result, or null where there was no previous window. */
  movement: number | null
  /** The result as a share of the best platform's, 0..1, for a bar. */
  bar: number
}

/** Every platform that delivered, as comparison rows in the payload's own order; `bar` is the result as a share of the best platform's. */
export function platformRows(payload: LivePayload, source: ResultSource, ar: boolean): RankRow[] {
  const rows = (payload.platforms ?? []).filter((p) => shownPlatform(p, source))
  const max = Math.max(0, ...rows.map((p) => Number(source.of(p) ?? 0)))

  return rows.map((p): RankRow => {
    const key = canonicalPlatform(p.provider)
    const result = source.of(p)
    const mov = (p as { movement?: Record<string, number | null> }).movement?.[source.key]

    return {
      key,
      name: providerLabel(key, ar ? 'ar' : 'en'),
      result,
      impressions: count(p.impressions),
      clicks: count(p.clicks),
      ctr: count(p.ctr),
      movement: mov === undefined ? null : mov,
      bar: result === null || max <= 0 ? 0 : result / max,
    }
  })
}
