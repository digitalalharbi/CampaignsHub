import { useUi } from '@/stores/ui'
import { Film, Image as ImageIcon, ImageOff, LayoutGrid, ShoppingBag } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { platformColor } from '@/features/analytics/components'
import { absenceLabel, absenceShort, assetAspect, clientAbsence, mediaFit, posterSource, previewShape, readPreview, type MediaAspect } from './adPreview'
import type { CreativePreview } from './api'
import { PosterImage } from './PosterImage'

/**
 * AD-PREVIEW-001 — one still, drawn the same way on every surface that shows an ad.
 *
 * Never a `<video>`: a grid of twenty ads that each preloaded a stream costs a phone tens of
 * megabytes to open a page, and a player belongs in the panel somebody opened on purpose. When there
 * is nothing to draw, the reason is drawn instead — four sentences, never one grey box.
 */
export function AdPoster({
  preview,
  name,
  className = 'h-32 w-full',
  testid,
  width,
  height,
  aspectRatio,
  fit,
  stage,
  forClient = false,
  provider = null,
}: {
  preview: CreativePreview | null | undefined
  name: string
  className?: string
  testid?: string
  /**
   * CONTENT-POPUP-MEDIA-001 — whether this surface may CROP, decided by the surface.
   *
   * This component chose for everybody: portrait was contained and everything else was covered. That
   * is defensible for a grid of uniform tiles and destructive for a review surface, and the quick
   * panel — whose whole job is showing the ad clearly — was the surface paying for it.
   *
   * The panel already passed `object-contain` in `className` and it did nothing: both utilities landed
   * on the element, `rounded-lg object-cover … object-contain`, and Tailwind's own stylesheet order
   * decides which applies rather than the caller. Measured in a browser on the real panel: computed
   * `object-fit` came back `cover`, and a 600×600 creative rendered into a 497×416 box — about 16% of
   * its height clipped away, top and bottom. That is the owner's «important portions are cut off».
   *
   * So the choice is explicit and there is only ever ONE fit utility on the element. `contain` never
   * crops; `cover` fills a fixed tile. Default `undefined` keeps every existing caller on the old
   * shape-derived behaviour, because a grid that suddenly letter-boxes every landscape thumbnail is a
   * different change from the one this row is about.
   */
  fit?: 'contain' | 'cover'
  /**
   * CONTENT-PREVIEW-FIT-001 — the shape of the BOX this is being drawn into.
   *
   * Half of «will covering crop this», and the half nothing was asking for. The fit was derived
   * from the asset alone, which cannot answer the question: a 16:9 film covers a 16:9 tile
   * perfectly and loses a third of itself in a square one.
   *
   * `null`/absent means «a landscape tile», which is what every caller here draws and what the
   * content card falls back to when the platform stated no shape.
   */
  stage?: MediaAspect | null
  /**
   * CONTENT-PREVIEW-SHAPES-001 — the asset's own dimensions, where the platform reported them.
   *
   * A 9:16 story cropped into a landscape card keeps the middle third and throws away the top and
   * the bottom, which on a story is the logo and the call to action. The card then shows a picture
   * the ad never was, and two creatives compared side by side are two crops this product invented.
   */
  width?: number | null
  height?: number | null
  aspectRatio?: string | null
  /**
   * On a client's own surface: a missing picture is said in the reader's words and never
   * names our pipeline (CLIENT-DIAGNOSTIC-SEPARATION-001). The operator surfaces keep the four states.
   */
  forClient?: boolean
  /** The platform the content ran on — names the fallback cover; never invents a picture. */
  provider?: string | null
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const reading = readPreview(preview, ar)
  const src = posterSource(reading)

  /*
   * The stated absence, drawn either because there was never a still to draw or because the browser
   * could not draw the one there was. `data-absence` names which, so the two never blur together.
   */
  const absent = (reason: string) => {
    /*
     * CONTENT-MEDIA-ABSENCE-COMPACT-001 — the reason in three words, the sentence on hover.
     *
     * This box printed the full explanation: in a 128-pixel poster that is eight lines of 11px grey
     * text where the picture belongs, repeated down a grid of twenty-four cards. The sentence is not
     * wrong — an operator needs it to know whether to re-sync, to wait, or to do nothing — so it
     * moves to the `title` rather than being cut down. Both come from the same reading, so the label
     * and the sentence cannot describe different absences.
     */
    if (forClient) {
      const said = reason === 'fetch_failed'
        ? clientAbsence({ kind: 'none', reason: 'expired', note: null }, ar)
        : clientAbsence(reading, ar)
      const key = provider ? canonicalPlatform(provider) : null
      const color = key ? platformColor(key) : 'var(--brand-600)'
      const kindLabel = reading.kind === 'video'
        ? (ar ? 'فيديو' : 'Video')
        : reading.kind === 'catalog'
          ? (ar ? 'كتالوج' : 'Catalog')
          : reading.kind === 'collection'
            ? (ar ? 'مجموعة' : 'Collection')
            : (ar ? 'محتوى إعلاني' : 'Ad content')
      const Icon = reading.kind === 'video' ? Film : reading.kind === 'catalog' ? ShoppingBag : reading.kind === 'collection' ? LayoutGrid : ImageIcon

      // REPORT-COVER-001 — never an empty frame. A typed cover that says WHAT this is and WHERE it
      // ran, and does not pretend to be the creative (no image is invented; the sentence stays on hover).
      return (
        <span
          data-testid={testid ? `${testid}-absent` : undefined}
          data-absence={reason}
          data-cover="fallback"
          title={said.sentence}
          className={`relative flex flex-col items-center justify-center gap-1.5 overflow-hidden rounded-lg border border-border bg-surface-secondary p-2 text-center text-text-muted ${className}`}
        >
          <span aria-hidden className="absolute inset-x-0 top-0 h-1.5" style={{ background: color }} />
          <Icon size={22} aria-hidden className="shrink-0" style={{ color }} />
          <span data-testid={testid ? `${testid}-absent-label` : undefined} className="text-xs font-bold text-text-primary">
            {key ? providerLabel(key, ar ? 'ar' : 'en') : kindLabel}
          </span>
          <span className="text-[11px] font-semibold">{said.short}</span>
          <span className="sr-only">{said.sentence}</span>
        </span>
      )
    }

    const sentence = reason === 'fetch_failed'
      ? ar
        ? 'تعذّر تحميل أصل هذا الإعلان من المنصة — قد يكون الرابط انتهت صلاحيته. يحتاج مزامنة جديدة.'
        : 'This ad’s asset could not be loaded from the platform — the link may have expired. It needs a fresh sync.'
      : absenceLabel(reading, ar)
    const label = reason === 'fetch_failed'
      ? (ar ? 'تعذّر التحميل' : 'Could not load')
      : absenceShort(reading, ar)

    return (
      <span
        data-testid={testid ? `${testid}-absent` : undefined}
        /* Which of the reasons this is, so a surface can tell «there is a film here» from «there is nothing». */
        data-absence={reason}
        title={sentence}
        className={`flex flex-col items-center justify-center gap-1 rounded-lg bg-surface-secondary p-2 text-center text-[11px] leading-tight text-text-muted ${className}`}
      >
        <ImageOff size={16} aria-hidden className="shrink-0 opacity-60" />
        <span data-testid={testid ? `${testid}-absent-label` : undefined} className="font-semibold">{label}</span>
        {/*
          The sentence is still IN the DOM, for a screen reader and for anything that reads the page
          rather than hovers it — `sr-only`, so it costs the grid no space.
        */}
        <span className="sr-only">{sentence}</span>
      </span>
    )
  }

  if (!src) {
    /*
     * The attribute names the shape, and it used to name only one of them. Everything that was not
     * `none` was labelled `video-no-cover`, so a catalog ad and a collection with no hero both
     * reported themselves as a film without a poster — the sentence was right and the attribute a
     * surface or a test reads was wrong.
     */
    return absent(
      reading.kind === 'none'
        ? reading.reason
        : reading.kind === 'catalog'
          ? 'catalog'
          : reading.kind === 'collection'
            ? 'collection-no-hero'
            : 'video-no-cover',
    )
  }

  const portrait = previewShape(width, height, aspectRatio) === 'portrait'

  /*
   * CONTENT-PREVIEW-FIT-001 — contained unless the stage is provably the asset's own shape.
   *
   * This asked «is this portrait», and three shapes were cropped by the answer: a SQUARE creative,
   * which `previewShape` calls landscape and which loses a third of its height in a 16:9 box; a 4:5,
   * for the same reason; and every asset whose dimensions the platform never sent, which was covered
   * into whatever box the caller happened to draw.
   *
   * The caller's own `fit` still wins where it states one — `AdPreviewDialog` passes `contain`
   * because a viewer may never lose anything — and this is the default for everyone else: cover
   * only where the asset's shape is known AND equals `stage`, which is a landscape tile unless the
   * caller says otherwise. So a 16:9 thumbnail still fills its tile exactly as before, and the
   * three shapes that were being cropped stop being cropped.
   *
   * A contained asset gets the surface behind it, because letter-boxing on bare page reads as a
   * broken image and on a surface it reads as deliberate.
   */
  const contained = fit === undefined
    ? mediaFit(assetAspect(width, height, aspectRatio), stage ?? null) === 'contain'
    : fit === 'contain'

  return (
    <PosterImage
      src={src}
      alt={name}
      shape={portrait ? 'portrait' : 'landscape'}
      testid={testid}
      /*
       * A `data:` URI must load eagerly. A lazy one never enters the viewport observer, never
       * decodes, and leaves a blank frame with no error anywhere — which is how the demo library
       * came to render ten empty cards.
       */
      loading={src.startsWith('data:') ? 'eager' : 'lazy'}
      /*
       * A load that fails says so. It used to leave an `<img>` painting nothing over the space where
       * the ad should be — the owner's blank card, with no sentence and no signal anywhere.
       */
      fallback={absent('fetch_failed')}
      className={`rounded-lg ${contained ? 'bg-surface-secondary object-contain' : 'object-cover'} ${className}`}
    />
  )
}
