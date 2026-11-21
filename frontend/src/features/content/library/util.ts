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

