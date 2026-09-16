import { describe, expect, it } from 'vitest'

import { clientAbsence, readPreview } from './adPreview'
import type { CreativePreview } from './api'

/*
 * CLIENT-DIAGNOSTIC-SEPARATION-001 — on a client surface a missing picture never names our pipeline.
 *
 * The operator's four absence states stay four («Never fetched», «Link expired», …) because each is a
 * different next move for somebody who runs the sync. A client has no such move: «لم يُجلب» on their
 * report is our fetching described to them. What survives is what is TRUE for them — this link does
 * not show it, the preview is unavailable for now, or there is none — never why, in our words.
 */
const PIPELINE = /fetch|sync|جُ?لب|مزامن|مُستنتج|inferred|derived|pipeline|connector/i

const none = (state: string, over: Partial<CreativePreview> = {}): CreativePreview => ({
  state, kind: 'image', image_url: null, video_url: null, thumbnail_url: null, expires_at: null,
  note_ar: 'هذا الصف مُستنتج من أداء الإعلان، ولم يُجلب الإعلان نفسه من المنصة — فلا يوجد أصل لعرضه.',
  note_en: 'This row is inferred from the ad’s performance and the ad itself was never fetched from the platform.',
  cards: null, ...over,
}) as CreativePreview

describe('a missing preview, as a client reads it', () => {
  const states = ['withheld', 'expired', 'unavailable', 'never_fetched', 'shape_not_fetched', 'available']

  for (const ar of [true, false]) {
    it(`never names our pipeline (${ar ? 'ar' : 'en'})`, () => {
      for (const state of states) {
        const said = clientAbsence(readPreview(none(state), ar), ar)
        expect(said.short, `${state}: short`).not.toMatch(PIPELINE)
        expect(said.sentence, `${state}: sentence`).not.toMatch(PIPELINE)
        expect(said.short, `${state}: says nothing`).not.toBe('')
      }
    })

    it(`keeps withheld and expired apart from «no preview» (${ar ? 'ar' : 'en'})`, () => {
      const [withheld, expired, never] = ['withheld', 'expired', 'never_fetched'].map((s) => clientAbsence(readPreview(none(s), ar), ar).short)
      expect(new Set([withheld, expired, never]).size).toBe(3)
      expect(clientAbsence(readPreview(none('unavailable'), ar), ar).short).toBe(never)
    })
  }
})
