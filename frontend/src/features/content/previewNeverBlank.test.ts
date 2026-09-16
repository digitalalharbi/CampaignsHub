import { describe, expect, it } from 'vitest'
import { absenceLabel, absenceShort, posterSource, readPreview } from './adPreview'
import type { CreativePreview } from './api'

/**
 * Owner rows 9 and 10 — «no broken image» and «no unexplained blank rectangle», as a PROPERTY.
 *
 * ## Why this is not another case
 *
 * `adPreview.test.ts` covers the shapes somebody thought of, one at a time, and the module's whole
 * reason for existing is the case nobody thought of: its own docblock says «a broken image and a
 * fabricated one are the two failures this exists to prevent». Per-case coverage cannot make that
 * claim — it proves the states that were listed, and the blank box the owner reported five times was
 * always a state nobody listed. `absenceLabel` records exactly that history: for two months a video
 * reading returned the empty string, «and every surface drew a grey box with nothing written in it».
 *
 * So the invariant is asserted over EVERY combination the payload can take — six states, six kinds,
 * and each of the three urls present or absent — in both languages. 576 readings, and the promise is
 * one sentence: **something is drawn, or something is said. Never neither.**
 *
 * A combination that is impossible in practice is included deliberately. The payload is built by a
 * presenter from provider data, and «the provider cannot send that» is the assumption that produced
 * every one of these blanks.
 *
 * ## And the four states stay four
 *
 * The brief is explicit that withheld, expired, unavailable and no-media must not collapse into one
 * generic failure. Asserted on the SENTENCE, which is where an operator learns whether to re-sync,
 * to wait, or to do nothing. The compact badge is allowed to collapse two of them and the reason is
 * stated below — it is a deliberate decision, not drift, and pinning it here is what keeps it one.
 */
const STATES: CreativePreview['state'][] = [
  'available', 'withheld', 'expired', 'unavailable', 'never_fetched', 'shape_not_fetched',
]

const KINDS: CreativePreview['kind'][] = ['image', 'video', 'carousel', 'collection', 'catalog', 'other']

const URL = 'https://cdn.test/asset'

function preview(
  state: CreativePreview['state'],
  kind: CreativePreview['kind'],
  image: boolean,
  video: boolean,
  thumb: boolean,
): CreativePreview {
  return {
    state,
    kind,
    image_url: image ? `${URL}.jpg` : null,
    video_url: video ? `${URL}.mp4` : null,
    thumbnail_url: thumb ? `${URL}-thumb.jpg` : null,
    expires_at: null,
    note_ar: null,
    note_en: null,
  } as CreativePreview
}

/** Every shape the payload can take, named so a failure says which one. */
function everyPreview(): Array<{ name: string; preview: CreativePreview }> {
  const out: Array<{ name: string; preview: CreativePreview }> = []

  for (const state of STATES) {
    for (const kind of KINDS) {
      for (const image of [true, false]) {
        for (const video of [true, false]) {
          for (const thumb of [true, false]) {
            out.push({
              name: `${state}/${kind} image=${image} video=${video} thumb=${thumb}`,
              preview: preview(state, kind, image, video, thumb),
            })
          }
        }
      }
    }
  }

  return out
}

describe('a preview is drawn or explained, never neither', () => {
  for (const ar of [false, true]) {
    const locale = ar ? 'ar' : 'en'

    it(`states an absence for every shape it cannot draw (${locale})`, () => {
      const silent: string[] = []

      for (const { name, preview: p } of everyPreview()) {
        const reading = readPreview(p, ar)

        /*
         * No exemption for a playable film, and the first draft of this test had one — which made it
         * unable to catch the very defect the module records having shipped.
         *
         * The reasoning was «the player draws it». Two surfaces draw an ad and only one has a player:
         * a CARD draws a poster or an absence, so a video whose platform sent no cover frame has
         * nothing at all to put in its box. That is precisely the state `absenceLabel` describes as
         * «every surface drew a grey box with nothing written in it», and re-injecting it left this
         * test green until the exemption came out.
         */
        const drawn = posterSource(reading) !== null
        const said = absenceShort(reading, ar).trim() !== ''

        if (!drawn && !said) {
          silent.push(name)
        }
      }

      expect(
        silent,
        `these shapes would draw an empty box and say nothing: ${silent.join(' · ')}`,
      ).toEqual([])
    })

    /**
     * And the long sentence is there too, for the surfaces that have room for it.
     *
     * The compact badge goes in a 128-pixel poster box; the sentence is what an operator reads to
     * decide what to do. A shape with a badge and no sentence would leave the detail view blank
     * while the card looked fine.
     */
    it(`states a full sentence for every shape it cannot draw (${locale})`, () => {
      const silent: string[] = []

      for (const { name, preview: p } of everyPreview()) {
        const reading = readPreview(p, ar)

        if (posterSource(reading) !== null) continue

        if (absenceLabel(reading, ar).trim() === '') silent.push(name)
      }

      expect(silent, `no sentence for: ${silent.join(' · ')}`).toEqual([])
    })

    /**
     * A drawn frame carries NO absence badge — or the card contradicts itself.
     *
     * The inverse of the invariant above, and the cheaper failure to ship: a picture with «No file»
     * printed over it reads as a rendering bug rather than as the picture it is.
     */
    it(`says nothing about an absence when there is a frame to draw (${locale})`, () => {
      const contradictory: string[] = []

      for (const { name, preview: p } of everyPreview()) {
        const reading = readPreview(p, ar)

        if (posterSource(reading) === null) continue
        /* A collection's hero IS drawn and the shape still needs saying — see `absenceShort`. */
        if (reading.kind === 'collection') continue

        if (absenceShort(reading, ar).trim() !== '') contradictory.push(name)
      }

      expect(contradictory, `a frame and an absence at once: ${contradictory.join(' · ')}`).toEqual([])
    })
  }

  /**
   * The four absences the brief names stay four distinct sentences, in both languages.
   *
   * «Withheld», «expired», «unavailable» and «no media» lead to four different actions — do nothing,
   * re-sync, accept it, investigate — and collapsing any two into one generic failure is the thing
   * the owner asked not to happen. Asserted on the sentence rather than on the badge because the
   * sentence is where the action is decided.
   */
  it('keeps every absence reason a distinct sentence', () => {
    for (const ar of [false, true]) {
      const sentences = new Map<string, string>()

      for (const reason of ['withheld', 'expired', 'unavailable', 'never_fetched', 'shape_not_fetched', 'no_media'] as const) {
        const text = absenceLabel({ kind: 'none', reason, note: null }, ar)

        expect(text.trim(), `${reason} has no sentence in ${ar ? 'ar' : 'en'}`).not.toBe('')

        const already = sentences.get(text)
        expect(already, `«${reason}» and «${already}» say the same thing in ${ar ? 'ar' : 'en'}`).toBeUndefined()

        sentences.set(text, reason)
      }
    }
  })

  /**
   * The COMPACT badge is allowed to collapse exactly one pair, and no more.
   *
   * `unavailable` and `no_media` both read «No file» in the poster box, deliberately: to an operator
   * scanning a wall of cards both mean the platform gave nothing, and the sentence beneath is where
   * they are told apart. Pinned so the collapse stays that one pair — a badge vocabulary that
   * quietly loses a second distinction is how four states become one generic failure.
   */
  it('collapses only the one pair of badges it means to', () => {
    for (const ar of [false, true]) {
      const badges = new Map<string, string[]>()

      for (const reason of ['withheld', 'expired', 'unavailable', 'never_fetched', 'shape_not_fetched', 'no_media'] as const) {
        const text = absenceShort({ kind: 'none', reason, note: null }, ar)

        expect(text.trim(), `${reason} has no badge in ${ar ? 'ar' : 'en'}`).not.toBe('')
        badges.set(text, [...(badges.get(text) ?? []), reason])
      }

      const shared = [...badges.values()].filter((reasons) => reasons.length > 1)

      expect(shared, `badges shared by more than the stated pair in ${ar ? 'ar' : 'en'}`).toEqual([
        ['unavailable', 'no_media'],
      ])
    }
  })

  /**
   * The platform's OWN note wins where it sent one, and is never dropped.
   *
   * It is more specific than anything written here and it is what the presenter composed for exactly
   * this moment — so a reading that carries one must show it rather than the generic sentence.
   */
  it('prefers the note the platform sent', () => {
    const text = absenceLabel({ kind: 'none', reason: 'unavailable', note: 'Snapchat withdrew this asset.' }, false)

    expect(text).toBe('Snapchat withdrew this asset.')
  })
})
