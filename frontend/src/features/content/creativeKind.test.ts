import { describe, expect, it } from 'vitest'

import { creativeKindLabel } from './CreativesPage'
import { CREATIVE_KINDS, KIND_ABSENT, isCreativeKind } from './creativeKind'

/**
 * CONTENT-KIND-LABEL-001 — «نوع المحتوى video» on the one screen dedicated to describing an asset.
 *
 * The library badge said «فيديو»; the detail page rendered `preview.kind` straight.
 *
 * ## Widened by CONTENT-KIND-VOCABULARY-001
 *
 * The map this file guarded held three kinds — image, video, carousel — and `CreativeKind::ALL` has
 * five. The filter offers all five, so filtering by «كولكشن» produced a badge reading the bare
 * English «collection»: the test below asserted the map matched its own three-item list, which is
 * true and says nothing about the two shapes with no word at all. It now reads the kinds from the
 * same constant the rest of the product does, so a kind added to the vocabulary cannot be left
 * unnamed here.
 */
describe('creative kind labels', () => {
  it.each([...CREATIVE_KINDS])('labels %s in both languages', (kind) => {
    expect(creativeKindLabel(kind, true)).not.toBe(kind)
    expect(creativeKindLabel(kind, false)).not.toBe(kind)
  })

  it('is the same map every surface reads, not a second copy', () => {
    /*
      There were three copies. The badge read this one, the verdict read `formatVerdict`'s and the
      pie read `ContentSummary`'s, and they disagreed on two of the five shapes. The map now lives
      in `creativeKind.ts` and this module re-exports the labeller, so «the same map» is something
      the import graph enforces rather than something a test can only hope for.
    */
    for (const kind of CREATIVE_KINDS) {
      expect(isCreativeKind(kind)).toBe(true)
      expect(creativeKindLabel(kind, true)).not.toBe('')
    }
  })

  /*
   * This used to assert `creativeKindLabel('playable', true) === 'playable'`.
   *
   * The intent was «do not HIDE a shape we failed to classify», and that intent is kept. What it
   * also did was present a provider's own token AS the ad's type: `external_creatives.format`
   * carries whatever the platform called it — Snapchat's importer stores `strtolower($type)` for
   * every type it does not map, so `story_ad` is a real value — and the owner duly read «ستوري اد»
   * under «النوع» on an Arabic screen.
   *
   * So the type now answers in the reader's language, and the token is shown beside it, attributed
   * to the platform. Nothing is hidden and nothing is mislabelled.
   */
  it('does not present an unrecognised platform token as the ad’s type', () => {
    expect(creativeKindLabel('playable', true)).toBe('غير مصنّف')
    expect(creativeKindLabel('story_ad', true)).toBe('غير مصنّف')
    expect(creativeKindLabel('playable', false)).toBe('Unclassified')
  })

  it('says «—» when the platform sent no kind at all', () => {
    /* A silence is not a classification — see KIND_ABSENT. */
    expect(creativeKindLabel(null, true)).toBe(KIND_ABSENT)
    expect(creativeKindLabel(undefined, true)).toBe(KIND_ABSENT)
    expect(creativeKindLabel('', true)).toBe(KIND_ABSENT)
  })
})
