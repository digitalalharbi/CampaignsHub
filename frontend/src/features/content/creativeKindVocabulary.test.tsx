import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { CREATIVE_KINDS, creativeKindLabel, creativeKindSubject, isCreativeKind } from './creativeKind'
import { AdPreviewDialog } from './AdPreviewDialog'
import { renderWithProviders } from '@/test/utils'
import type { CreativeCard, CreativePreview } from './api'

/**
 * CONTENT-KIND-VOCABULARY-001 — the shapes have one set of words, and a platform token is not one.
 *
 * The owner read the comparison and saw «اسم مجموعة، صورة، وكلمة انجليزية» — a group's name, a
 * picture, and an English word — and named what they should have been: «صورة، فيديو، ستوري اد،
 * كولكشن، كاروسيل». Three separate defects sat behind that one sentence:
 *
 *  1. two tables of words for the same five shapes, disagreeing («المجموعة»/«تشكيلة»,
 *     «الدوارة»/«دوّار»), so one screen named a shape two ways;
 *  2. «المجموعة», which reads as the name of a GROUP rather than a kind of ad;
 *  3. both tables printing their KEY when they did not recognise a value — and the value they were
 *     given is the provider's own token, so «story_ad» was shown to a reader as the ad's type.
 */

const preview = (over: Partial<CreativePreview> = {}): CreativePreview => ({
  state: 'available',
  kind: 'image',
  image_url: 'https://cdn.example/ad.jpg',
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards: null,
  ...over,
} as CreativePreview)

const creative = (over: Partial<CreativeCard> = {}): CreativeCard => ({
  id: 'cr-1',
  name: 'Ramadan — Story 9:16',
  format: 'video',
  kind: 'video',
  provider: 'meta',
  status: 'active',
  campaign_id: 'cam-1',
  campaign_name: 'Ramadan Sales',
  ad_set_id: null,
  ads: [],
  preview: preview(),
  aspect_ratio: '9:16',
  duration_seconds: null,
  width: null,
  height: null,
  file_size: null,
  grouped: false,
  group_id: null,
  is_demo: false,
  freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: null },
  objective: 'sales',
  path: 'conversion',
  headline_metrics: [],
  ...over,
} as unknown as CreativeCard)

describe('the one vocabulary for what a creative is', () => {
  it('names every kind the filter offers, in both languages and both forms', () => {
    for (const kind of CREATIVE_KINDS) {
      for (const word of [creativeKindLabel(kind, true), creativeKindSubject(kind, true)]) {
        expect(word, `${kind} · ar`).not.toBe('')
        /* In Arabic the key is never a word a reader is shown. */
        expect(word, `${kind} · ar`).not.toBe(kind)
        expect(/[\u0600-\u06FF]/.test(word), `${kind} · ar is Arabic`).toBe(true)
      }

      /*
         English is the case where «not the key» is the wrong test: «Image» IS the English word for
         `image`, and demanding it differ would only force a worse label. What must hold is that it
         is a WORD — capitalised, not the bare lowercase token a fallback would have produced.
      */
      for (const word of [creativeKindLabel(kind, false), creativeKindSubject(kind, false)]) {
        expect(word, `${kind} · en`).not.toBe('')
        expect(word, `${kind} · en`).not.toBe(kind)
        expect(word[0], `${kind} · en is capitalised`).toBe(word[0].toUpperCase())
      }
    }
  })

  it('says the owner’s own words for the shapes he named', () => {
    expect(creativeKindLabel('carousel', true)).toBe('كاروسيل')
    expect(creativeKindLabel('collection', true)).toBe('كولكشن')
    expect(creativeKindLabel('catalog', true)).toBe('كتالوج')
    expect(creativeKindLabel('image', true)).toBe('صورة')
    expect(creativeKindLabel('video', true)).toBe('فيديو')
  })

  it('no longer calls a collection «المجموعة», which reads as a group’s name', () => {
    expect(creativeKindLabel('collection', true)).not.toBe('المجموعة')
    expect(creativeKindSubject('collection', true)).not.toBe('المجموعة')
    /* …nor keeps the second table's word for it, which is the half that made them disagree. */
    expect(creativeKindLabel('collection', true)).not.toBe('تشكيلة')
  })

  /*
    The sentence form used to be asserted here against `formatVerdict.formatWord`, which carried the
    verdict's own table. That verdict — «الكولكشن أفضل في هذه الفترة» — is gone under
    CONTENT-FORMAT-ROAS-REMOVED-001, and so is the second table, so the drift this guarded against
    no longer has two sides to drift between. The subject form itself is still exercised above and
    in `creativeKind.test.ts`, because the chips and the pie still read it.
  */

  /*
   * These are REAL column values, not invented ones: Snapchat's importer maps the types it knows and
   * stores `strtolower($type)` for the rest, X stores `text` and the chat card stores `chat_card`.
   */
  it.each(['story_ad', 'collection_dynamic', 'text', 'chat_card', 'SNAP_AD', 'LONGFORM_VIDEO'])(
    'never shows the platform token %s as if it were a reader’s word',
    (token) => {
      expect(isCreativeKind(token)).toBe(false)

      for (const ar of [true, false]) {
        expect(creativeKindLabel(token, ar).toLowerCase()).not.toContain(token.toLowerCase())
        expect(creativeKindSubject(token, ar).toLowerCase()).not.toContain(token.toLowerCase())
      }

      expect(creativeKindLabel(token, true)).toBe('غير مصنّف')
    },
  )

  /* `CreativeKind::of()` emits `other`; the aggregate endpoints say `unlabelled`. Both are covered. */
  it.each(['other', 'unlabelled'])('has a word for %s, which no table used to have', (kind) => {
    expect(creativeKindLabel(kind, true)).toBe('غير مصنّف')
    expect(creativeKindLabel(kind, false)).toBe('Unclassified')
  })

  /*
    An absent kind keeps its own answer, and it should: CONTENT-KIND-LABEL-001 drew the line between
    «the platform sent no kind at all» and «none of our kinds fit», and reporting a silence as a
    classification would be the same error as reporting a token as a type.
  */
  it('keeps «—» for a kind that was never sent, apart from one we could not place', () => {
    for (const absent of [null, undefined, '']) {
      expect(creativeKindLabel(absent, true)).toBe('—')
      expect(creativeKindSubject(absent, false)).toBe('—')
    }

    expect(creativeKindLabel('other', true)).toBe('غير مصنّف')
  })
})

describe('the ad preview dialog’s «النوع» row', () => {
  it('answers with the shape, not with the platform’s token', async () => {
    renderWithProviders(
      <AdPreviewDialog
        creative={creative({ format: 'story_ad', kind: 'video' })}
        locale="ar"
        onClose={() => {}}
      />,
      { locale: 'ar' },
    )

    const meta = await screen.findByTestId('ad-preview-dialog-meta')

    expect(meta.textContent).toContain('فيديو')
    /* The token is still available to an operator — named as the platform's, not as «النوع». */
    expect(meta.textContent).toContain('تسمية المنصة')
    expect(meta.textContent).toContain('story_ad')
  })

  it('does not repeat the platform’s label when it says nothing the kind does not', async () => {
    renderWithProviders(
      <AdPreviewDialog
        creative={creative({ format: 'video', kind: 'video' })}
        locale="ar"
        onClose={() => {}}
      />,
      { locale: 'ar' },
    )

    const meta = await screen.findByTestId('ad-preview-dialog-meta')

    expect(meta.textContent).not.toContain('تسمية المنصة')
  })

  it('states a status in words rather than its key', async () => {
    renderWithProviders(
      <AdPreviewDialog creative={creative({ status: 'active' })} locale="ar" onClose={() => {}} />,
      { locale: 'ar' },
    )

    const meta = await screen.findByTestId('ad-preview-dialog-meta')

    expect(meta.textContent).toContain('نشطة')
    expect(meta.textContent).not.toContain('active')
  })
})
