import { SYSTEM_FAMILIES } from './index'
import type { Family } from './familySchema'

/**
 * CONTENT-STUDIO-001 batch 4 — choosing which families make up one batch. The diversity rule: no family and no preferred anchor
 * repeats inside a batch, so no two outputs share a composition or put their text in the same place. Families that only suit one
 * kind are placed first so a 3-video + 4-image batch over seven families can still use each exactly once.
 */
export interface BatchPick {
  family: Family
  as: 'video' | 'image'
  /** True only when the pool was too small and a family had to be used again. */
  repeated: boolean
}

export interface BatchRequest {
  videos?: number
  images?: number
  /** Learned or liked families score higher; unknown ones score 0. */
  scores?: Readonly<Record<string, number>>
  /** «New batch» rotates the order of equal scores so the same picture gives other combinations. */
  round?: number
}

const fits = (f: Family, as: 'video' | 'image'): boolean => f.kind === 'both' || f.kind === as

export function pickBatch(request: BatchRequest = {}, families: ReadonlyArray<Family> = SYSTEM_FAMILIES): BatchPick[] {
  const videos = request.videos ?? 3
  const images = request.images ?? 4
  const round = request.round ?? 0
  const score = (f: Family) => request.scores?.[f.key] ?? 0
  const order = [...families].sort((a, b) => score(b) - score(a) || a.key.localeCompare(b.key))
  const rotate = (list: Family[]): Family[] => {
    // Rotate within each group of equal score, so the order between different scores is never disturbed.
    const out: Family[] = []
    let i = 0

    while (i < list.length) {
      let j = i
      while (j < list.length && score(list[j]!) === score(list[i]!)) j++
      const group = list.slice(i, j)
      const k = group.length === 0 ? 0 : round % group.length
      out.push(...group.slice(k), ...group.slice(0, k))
      i = j
    }

    return out
  }
  const pool = rotate(order)

  const wants: Array<'video' | 'image'> = [...Array<'video'>(videos).fill('video'), ...Array<'image'>(images).fill('image')]
  // Only as many outputs as there are distinct families can be unique; the rest are repeats and are flagged as such.
  const unique = Math.min(wants.length, pool.length)
  const slots = wants.slice(0, unique)
  const chosen: Family[] = []
  const usedKeys = new Set<string>()
  const usedAnchors = new Set<string>()

  // Depth-first in score order: the best family goes to the earliest output, and a choice that would leave a later output with
  // nothing that suits it (detail cuts is video-only, the editorial frame image-only) is undone and the next one tried.
  const assign = (i: number): boolean => {
    if (i === slots.length) return true

    for (const f of pool) {
      if (!fits(f, slots[i]!) || usedKeys.has(f.key) || usedAnchors.has(f.anchor)) continue

      usedKeys.add(f.key)
      usedAnchors.add(f.anchor)
      chosen.push(f)

      if (assign(i + 1)) return true

      chosen.pop()
      usedKeys.delete(f.key)
      usedAnchors.delete(f.anchor)
    }

    return false
  }

  const picks: BatchPick[] = []

  if (assign(0)) slots.forEach((as, i) => picks.push({ family: chosen[i]!, as, repeated: false }))

  // Anything left (a pool smaller than the request, or no unique matching): reuse the best fitting family, flagged.
  for (const as of wants.slice(picks.length)) {
    const f = pool.find((c) => fits(c, as))

    if (f) picks.push({ family: f, as, repeated: true })
  }

  return picks.sort((a, b) => (a.as === b.as ? 0 : a.as === 'video' ? -1 : 1))
}
