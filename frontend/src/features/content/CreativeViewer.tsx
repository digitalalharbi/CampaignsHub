import { useEffect, useState } from 'react'
import { CreativeCarousel } from './CreativeCarousel'
import { ChevronLeft, ChevronRight, Minus, Plus, RotateCcw, X } from 'lucide-react'
import { CreativeVideoPlayer } from './CreativeVideoPlayer'
import { CreativeQuickFacts } from './CreativeQuickFacts'
import { formatBytes } from './format'
import { providerLabel } from '@/features/campaigns/labels'
import { useUi } from '@/stores/ui'
import type { CreativeCard } from './api'

/**
 * §15.3 — a creative at full size: zoom, its real dimensions, and the arrow keys to the next one.
 *
 * ## Why the placeholder says which kind of nothing it is
 *
 * A missing preview has four causes and they are not interchangeable: the link carried a credential
 * and was withheld, the platform's signed URL expired, the platform never exposes the asset, or
 * there is no asset. Rendering one grey box for all four tells a reviewer «this creative is broken»
 * when the truthful sentence is «this needs a fresh sync» or «Snapchat does not hand this out». The
 * note travels with the preview from the backend precisely so this component does not have to guess.
 *
 * ## Arrow keys, and what happens to the video
 *
 * Left/right move between creatives without closing the viewer, which is what makes reviewing forty
 * of them bearable. `CreativeVideoPlayer` is keyed by its source, so moving unmounts the old player
 * — that is what stops the previous video rather than leaving it playing audio underneath the new
 * one. Escape closes. The handler is bound to the dialog and ignores keys typed into a control, so
 * the zoom slider still takes its own arrow keys.
 */

const COPY = {
  ar: {
    close: 'إغلاق',
    prev: 'السابق',
    next: 'التالي',
    zoomIn: 'تكبير',
    zoomOut: 'تصغير',
    reset: 'الحجم الأصلي',
    dimensions: 'الأبعاد',
    ratio: 'النسبة',
    size: 'حجم الملف',
    duration: 'المدة',
    platform: 'المنصة',
    campaign: 'الحملة',
    noPreview: 'لا تتوفر معاينة',
    of: 'من',
    viewer: 'معاينة الإعلان',
    notProvided: 'غير مُرسَل',
  },
  en: {
    close: 'Close',
    prev: 'Previous',
    next: 'Next',
    zoomIn: 'Zoom in',
    zoomOut: 'Zoom out',
    reset: 'Actual size',
    dimensions: 'Dimensions',
    ratio: 'Ratio',
    size: 'File size',
    duration: 'Duration',
    platform: 'Platform',
    campaign: 'Campaign',
    noPreview: 'No preview available',
    of: 'of',
    viewer: 'Ad preview',
    notProvided: 'Not provided',
  },
}

const ZOOM_MIN = 0.5
const ZOOM_MAX = 4
const ZOOM_STEP = 0.25

export function CreativeViewer({
  creatives,
  index,
  onIndexChange,
  onClose,
  canZoom = true,
  analysis,
}: {
  creatives: CreativeCard[]
  index: number
  onIndexChange: (next: number) => void
  onClose: () => void
  /**
   * §15.12 — a client link may forbid zooming, and then the controls are not drawn at all.
   *
   * Defaults to true, so every operator surface is untouched. It gates the KEYBOARD as well as the
   * buttons: a hidden control whose shortcut still works is a control that is merely invisible.
   *
   * It withholds an affordance, not a picture — a reader can save any image their browser has drawn.
   * The bound that genuinely withholds a creative is excluding it from the link.
   */
  canZoom?: boolean
  /**
   * UX-CONTENT-001 — the analysis pane beside the asset. Off by default, and that matters.
   *
   * `SharedCreativeSection` renders this same viewer on a client's `/r/<token>` link, which has no
   * session and must never acquire one. The pane fetches `/creatives/{id}`, an authenticated
   * endpoint, so switching it on by default would have put a 401 on every public report the moment
   * a client opened a picture — the exact regression PUBLIC-REPORT-NOAUTH exists to prevent.
   * Only the operator library passes it.
   */
  analysis?: { window: { from: string; to: string }; detailsTo: (creative: CreativeCard) => string }
}) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']

  const [zoom, setZoom] = useState(1)
  const creative = creatives[index]

  // A new creative always opens at its own size: inheriting 3× from the last one shows a corner of
  // an image and reads as a rendering fault.
  useEffect(() => setZoom(1), [index])

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null
      const typing = target?.tagName === 'INPUT' || target?.tagName === 'TEXTAREA' || target?.tagName === 'SELECT'
      if (typing) return

      if (event.key === 'Escape') onClose()
      // In RTL the arrow that points at the next card is the LEFT one. Following the visual
      // direction rather than the key's name is what makes this feel native in Arabic.
      if (event.key === (ar ? 'ArrowLeft' : 'ArrowRight')) {
        if (index < creatives.length - 1) onIndexChange(index + 1)
      }
      if (event.key === (ar ? 'ArrowRight' : 'ArrowLeft')) {
        if (index > 0) onIndexChange(index - 1)
      }
      // Gated with the buttons: a hidden control whose shortcut still works is merely invisible.
      if (canZoom) {
        if (event.key === '+' || event.key === '=') setZoom((z) => Math.min(z + ZOOM_STEP, ZOOM_MAX))
        if (event.key === '-') setZoom((z) => Math.max(z - ZOOM_STEP, ZOOM_MIN))
        if (event.key === '0') setZoom(1)
      }
    }

    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [ar, canZoom, index, creatives.length, onClose, onIndexChange])

  if (!creative) return null

  const preview = creative.preview
  const note = ar ? preview.note_ar : preview.note_en
  const bytes = formatBytes(creative.file_size)

  /*
   * What is actually on screen — decided once, and read by both the stage and the footer.
   *
   * The zoom controls used to key off `preview.image_url`, which a VIDEO also carries (its poster is
   * an image). So a video was shown with a working-looking zoom control that moved nothing: a dead
   * control, in the sense the contract forbids. Deriving both from one value makes the two agree by
   * construction rather than by two conditions being kept in step.
   */
  const showing: 'video' | 'image' | 'cards' | 'none' =
    preview.state !== 'available'
      ? 'none'
      : preview.video_url
        ? 'video'
        : preview.image_url
          ? 'image'
          /*
           * CONTENT-VIEWER-CARDS-001 — a Story Ad is its cards, and the modal never showed them.
           *
           * `CreativePresenter` builds `cards` for multi-card formats — Snapchat Story Ads and
           * carousels — and `CreativeCarousel` renders them. It was mounted only on the full detail
           * PAGE, so opening one of these from the library gave a single asset or an empty «no
           * preview» box, and its actual content was reachable only by leaving the library. A
           * carousel with no single hero image is not a creative without a preview; it is a creative
           * whose preview is a sequence.
           */
          : (preview.cards?.length ?? 0) > 0
            ? 'cards'
            : 'none'

  return (
    <div
      data-testid="creative-viewer"
      role="dialog"
      aria-modal="true"
      aria-label={t.viewer}
      className="fixed inset-0 z-50 flex flex-col bg-slate-950/95 backdrop-blur"
    >
      <header className="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3 text-white">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold">{creative.name}</p>
          <p className="text-xs text-white/60" dir="ltr">
            {index + 1} {t.of} {creatives.length}
          </p>
        </div>
        <button type="button" onClick={onClose} aria-label={t.close} className="rounded p-2 hover:bg-white/10">
          <X className="h-5 w-5" aria-hidden />
        </button>
      </header>

      <div className="flex min-h-0 flex-1 flex-col overflow-auto lg:flex-row">
      <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto p-4">
        <button
          type="button"
          aria-label={t.prev}
          disabled={index === 0}
          onClick={() => onIndexChange(index - 1)}
          className="shrink-0 rounded-full p-2 text-white disabled:opacity-30 hover:bg-white/10"
        >
          {ar ? <ChevronRight className="h-6 w-6" aria-hidden /> : <ChevronLeft className="h-6 w-6" aria-hidden />}
        </button>

        <div className="flex max-h-full min-w-0 flex-1 items-center justify-center overflow-auto">
          {showing === 'video' && preview.video_url ? (
            <CreativeVideoPlayer
              /*
               * Keyed by the CREATIVE, not by the file.
               *
               * It was keyed by `video_url`, and two creatives that share one asset — the same cut
               * reused across campaigns, or a whole demo set pointing at one sample — produced an
               * IDENTICAL key. React then reused the component across the navigation, so the player
               * kept the previous creative's armed state and playback position, and arrowing forward
               * landed on a new creative already mid-play. Caught live by moving between two video
               * fixtures that share a file.
               */
              key={creative.id}
              src={preview.video_url}
              poster={preview.thumbnail_url}
              durationHint={creative.duration_seconds}
              className="w-full max-w-4xl"
            />
          ) : showing === 'image' && preview.image_url ? (
            <img
              src={preview.image_url}
              alt={creative.name}
              style={{ transform: `scale(${zoom})`, transformOrigin: 'center' }}
              className="max-h-[70vh] max-w-full object-contain transition-transform"
            />
          ) : showing === 'cards' ? (
            <div className="w-full max-w-3xl">
              <CreativeCarousel preview={preview} locale={ar ? 'ar' : 'en'} />
            </div>
          ) : (
            <div className="max-w-md rounded-lg border border-white/15 bg-white/5 p-6 text-center text-sm text-white/80">
              <p className="font-medium">{t.noPreview}</p>
              {/* The REASON, not a shrug: «expired» and «the platform does not expose this» call
                  for completely different actions, and a single grey box asks for neither. */}
              {note && <p className="mt-2 text-xs text-white/60">{note}</p>}
            </div>
          )}
        </div>

        <button
          type="button"
          aria-label={t.next}
          disabled={index >= creatives.length - 1}
          onClick={() => onIndexChange(index + 1)}
          className="shrink-0 rounded-full p-2 text-white disabled:opacity-30 hover:bg-white/10"
        >
          {ar ? <ChevronLeft className="h-6 w-6" aria-hidden /> : <ChevronRight className="h-6 w-6" aria-hidden />}
        </button>
      </div>

        {/*
          The facts, beside the asset — so «should we keep running this» is answerable here.

          A fixed column on a wide screen and a band underneath on a narrow one: on a phone the
          picture has to come first, because scrolling past a metrics table to see the creative is
          the opposite of what somebody opened.
        */}
        {analysis && (
          <aside
            data-testid="creative-quick-facts"
            className="w-full shrink-0 overflow-auto border-white/10 bg-slate-950/60 ltr:lg:border-l rtl:lg:border-r lg:w-96 lg:border-t-0 border-t"
          >
            <CreativeQuickFacts
              /* Keyed by the creative so arrowing forward mounts a fresh pane rather than showing
                 the previous creative's figures until the next request settles. */
              key={creative.id}
              creativeId={creative.id}
              objective={creative.objective}
              window={analysis.window}
              detailsTo={analysis.detailsTo(creative)}
            />
          </aside>
        )}
      </div>

      <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 px-4 py-3 text-xs text-white/80">
        <dl className="flex flex-wrap items-center gap-x-5 gap-y-1">
          <div className="flex gap-1">
            <dt className="text-white/50">{t.platform}</dt>
            <dd>{providerLabel(creative.provider, locale)}</dd>
          </div>
          {creative.campaign_name && (
            <div className="flex gap-1">
              <dt className="text-white/50">{t.campaign}</dt>
              <dd className="max-w-40 truncate">{creative.campaign_name}</dd>
            </div>
          )}
          {creative.width !== null && creative.height !== null && (
            <div className="flex gap-1">
              <dt className="text-white/50">{t.dimensions}</dt>
              <dd dir="ltr">{creative.width}×{creative.height}</dd>
            </div>
          )}
          {creative.aspect_ratio && (
            <div className="flex gap-1">
              <dt className="text-white/50">{t.ratio}</dt>
              <dd dir="ltr">{creative.aspect_ratio}</dd>
            </div>
          )}
          <div className="flex gap-1">
            <dt className="text-white/50">{t.duration}</dt>
            {/* «Not provided» rather than «0s»: a still image has no duration, and a video whose
                platform omitted it has none either — neither of them lasts zero seconds. */}
            <dd dir="ltr">{creative.duration_seconds === null ? t.notProvided : `${creative.duration_seconds}s`}</dd>
          </div>
          {bytes && (
            <div className="flex gap-1">
              <dt className="text-white/50">{t.size}</dt>
              <dd dir="ltr">{bytes}</dd>
            </div>
          )}
        </dl>

        {showing === 'image' && canZoom && (
          <div className="flex items-center gap-1">
            <button type="button" aria-label={t.zoomOut} onClick={() => setZoom((z) => Math.max(z - ZOOM_STEP, ZOOM_MIN))} className="rounded p-1.5 hover:bg-white/10">
              <Minus className="h-4 w-4" aria-hidden />
            </button>
            <span className="w-12 text-center tabular-nums" dir="ltr">{Math.round(zoom * 100)}%</span>
            <button type="button" aria-label={t.zoomIn} onClick={() => setZoom((z) => Math.min(z + ZOOM_STEP, ZOOM_MAX))} className="rounded p-1.5 hover:bg-white/10">
              <Plus className="h-4 w-4" aria-hidden />
            </button>
            <button type="button" aria-label={t.reset} onClick={() => setZoom(1)} className="rounded p-1.5 hover:bg-white/10">
              <RotateCcw className="h-4 w-4" aria-hidden />
            </button>
          </div>
        )}
      </footer>
    </div>
  )
}
