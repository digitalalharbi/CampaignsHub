/**
 * CONTENT-KIND-VOCABULARY-001 — ONE set of words for what a creative IS, in both languages.
 *
 * `CreativeKind` (PHP) already decides what a creative is, once, for the card and the filter alike.
 * Naming it was the part still done twice, and the two tables disagreed:
 *
 *  - `formatVerdict.FORMAT_WORDS` said «المجموعة» and «الدوارة»;
 *  - `ContentSummary.formatLabel` said «تشكيلة» and «دوّار».
 *
 * So the pie slice, the comparison and the verdict sentence named the same five shapes with
 * different words on the same screen — and «المجموعة» reads as the name of a GROUP rather than a
 * kind of ad, which is exactly how it was read: «لاحظت اسم مجموعة، صورة، وكلمة انجليزية».
 *
 * The words here are the ones media buyers actually use, which is the owner's own vocabulary:
 * «كاروسيل», «كولكشن», «كتالوج». A transliteration beats a translation nobody says out loud.
 *
 * ## Two forms, because Arabic needs two
 *
 * A chip is a noun on its own — «صورة». A verdict is a sentence about a set — «الصور أفضل في هذه
 * الفترة». Collapsing them produced «صورة أفضل في هذه الفترة», which is not Arabic. They are kept
 * as two forms of ONE entry rather than two tables, so the surfaces can differ in FORM while being
 * unable to differ in WORD.
 */

/** The kinds `CreativeKind::ALL` names — the vocabulary the filter and the card share. */
export const CREATIVE_KINDS = ['image', 'video', 'carousel', 'collection', 'catalog'] as const

export type CreativeKindName = (typeof CREATIVE_KINDS)[number]

interface KindWords {
  /** On its own: a chip, a legend entry, a «النوع» row. */
  label: { ar: string; en: string }
  /** As a sentence's subject: «الصور أفضل…» / «Images perform better…». */
  subject: { ar: string; en: string }
}

const WORDS: Record<string, KindWords> = {
  image: {
    label: { ar: 'صورة', en: 'Image' },
    subject: { ar: 'الصور', en: 'Images' },
  },
  video: {
    label: { ar: 'فيديو', en: 'Video' },
    subject: { ar: 'الفيديو', en: 'Video' },
  },
  carousel: {
    label: { ar: 'كاروسيل', en: 'Carousel' },
    subject: { ar: 'الكاروسيل', en: 'Carousel' },
  },
  collection: {
    label: { ar: 'كولكشن', en: 'Collection' },
    subject: { ar: 'الكولكشن', en: 'Collection' },
  },
  catalog: {
    label: { ar: 'كتالوج', en: 'Catalog' },
    subject: { ar: 'الكتالوج', en: 'Catalog' },
  },
  /*
   * The producer's own word for «none of the above», and the labellers did not have it.
   *
   * `CreativeKind::of()` returns `other` when neither the platform's label nor any asset settles the
   * shape; `formatVerdict` knew `unlabelled`, which nothing ever emits, and `ContentSummary` knew
   * neither. Both then fell through to their fallback and printed the key — so a real row rendered
   * the bare English word «other» on an Arabic screen. Both spellings are carried here because the
   * aggregate endpoints use `unlabelled` for the same idea, and a reader should never meet either.
   */
  other: {
    label: { ar: 'غير مصنّف', en: 'Unclassified' },
    subject: { ar: 'غير المصنّف', en: 'Unclassified' },
  },
  unlabelled: {
    label: { ar: 'غير مصنّف', en: 'Unclassified' },
    subject: { ar: 'غير المصنّف', en: 'Unclassified' },
  },
}

/**
 * A kind this table does not know — a provider's own token, never a reader's word.
 *
 * `external_creatives.format` holds whatever the platform called it: Snapchat's importer maps the
 * types it knows and stores `strtolower($type)` for everything else, so `story_ad` and
 * `collection_dynamic` are real values, as are X's `text` and the chat card's `chat_card`. Printing
 * the key is how «ستوري اد» reached a reader as the ad's type.
 *
 * Saying «غير مصنّف» is a weaker claim than the token and a true one; the platform's own label is
 * still shown beside it, named as the platform's, for an operator who needs it.
 */
function words(kind: string): KindWords {
  return WORDS[kind.toLowerCase()] ?? WORDS.other
}

/**
 * «—», kept: «the platform sent no kind at all» is not «none of our kinds fit».
 *
 * CONTENT-KIND-LABEL-001 drew that line and it is the right one. An absent kind is a gap in what we
 * were told; `other` is a classification we made. Collapsing them would report a silence as a
 * verdict.
 */
export const KIND_ABSENT = '—'

/** Is this one of the kinds the product classifies, as opposed to a platform token? */
export function isCreativeKind(kind: string | null | undefined): kind is CreativeKindName {
  return typeof kind === 'string' && (CREATIVE_KINDS as readonly string[]).includes(kind.toLowerCase())
}

/** The chip form: a noun on its own. */
export function creativeKindLabel(kind: string | null | undefined, ar: boolean): string {
  if (typeof kind !== 'string' || kind === '') return KIND_ABSENT

  const w = words(kind).label

  return ar ? w.ar : w.en
}

/** The sentence form: the subject of a claim about a set of creatives. */
export function creativeKindSubject(kind: string | null | undefined, ar: boolean): string {
  if (typeof kind !== 'string' || kind === '') return KIND_ABSENT

  const w = words(kind).subject

  return ar ? w.ar : w.en
}
