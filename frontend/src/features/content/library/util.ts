import type { CreativeCard } from '../api'
import type { LibCopy } from './copy'

export function isoDaysAgo(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() - days)

  return d.toISOString().slice(0, 10)
}

export function formatLabel(kind: string | null | undefined, t: LibCopy): string {
  if (kind === 'image') return t.image
  if (kind === 'video') return t.video
  if (kind === 'carousel') return t.carousel

  return t.other
}

/** The platform's own word for the ad's state, reduced to the three a reader acts on. */
export function deliveryOf(c: Pick<CreativeCard, 'status' | 'ad_delivered'>): 'delivering' | 'paused' | 'ended' {
  const s = (c.status ?? '').toLowerCase()
  // «inactive» and «disabled» contain «active» and «enable», so the stopped words are read first.
  if (/(pause|disable|inactive)/.test(s)) return 'paused'
  if (/(active|enable|running|deliver)/.test(s)) return 'delivering'

  return c.ad_delivered ? 'delivering' : 'ended'
}


/**
 * «In N campaigns · N delivering», the platforms' usage cell. Counted from the creative's own ads (the canonical relation); a creative
 * whose ads were not sent still counts its one campaign. Never the campaign's raw name, which can carry a platform id.
 */
export function usageOf(c: Pick<CreativeCard, 'ads' | 'campaign_id' | 'status' | 'ad_delivered'>): { campaigns: number; running: number } {
  const ads = c.ads ?? []
  const campaigns = new Set(ads.map((a) => a.external_campaign_id).filter((id): id is string => !!id)).size || (c.campaign_id ? 1 : 0)
  const running = ads.length > 0
    ? ads.filter((a) => deliveryOf({ status: a.status ?? '', ad_delivered: false }) === 'delivering').length
    : deliveryOf(c) === 'delivering' && campaigns > 0 ? 1 : 0

  return { campaigns, running }
}

/** The day this creative first appeared; where the sync never recorded one, the earliest date it did record. */
export function createdOf(c: Pick<CreativeCard, 'freshness'>): string | null {
  const f = c.freshness

  return f.first_seen_at ?? f.source_updated_at ?? f.last_synced_at ?? null
}
