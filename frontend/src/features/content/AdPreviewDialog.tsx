import { useEffect, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { AdPoster } from './AdPoster'
import { CreativeCarousel } from './CreativeCarousel'
import { CreativeVideoPlayer } from './CreativeVideoPlayer'
import { readPreview } from './adPreview'
import { canonicalObjectiveLabel, canonicalOfRaw } from '@/features/campaigns/canonicalObjectives'
import { providerLabel } from '@/features/campaigns/labels'
import type { CreativeCard } from './api'
import type { Locale } from '@/stores/ui'

/**
 * AD-PREVIEW-001 — one ad, opened where the reader is standing.
 *
 * ## Why a dialog and not a route
 *
 * The surfaces that list ads are comparison surfaces: a ranked grid, a sortable table. A reader
 * asking «what does this one look like» is in the middle of comparing eight of them, and a
 * navigation costs them the comparison, the sort, the scroll position and the filters — so they
 * stop asking, and decide from a name and a number what the ad actually shows.
 *
 * ## What it carries
 *
 * The still, then the facts that answer «which ad is this?» — platform, format, status — then
 * whatever figures the surface that opened it already has. The figures are passed in rather than
 * fetched: the row the reader clicked is the figure they are looking at, and a second query here
 * would eventually disagree with it over a window boundary.
 *
 * ## The media goes through the same reader as everywhere else — CONTENT-DETAIL-MODAL-001
 *
 * This dialog drew every ad as a still. A film showed its poster frame with no way to play it and a
 * carousel showed one card of five — on ANALYTICS, which is the surface that opens it. The content
 * library (`CreativeViewer`) and the shared report (`ReportAdDetail`) both play the film and page
 * the carousel; this one did not, so the same ad looked like two different things depending on which
 * screen a reader opened it from, and a poster frame of a video is a plausible-looking picture of
 * the wrong thing.
 *
 * `readPreview` decides the shape and the same three components render it: the player for a film,
 * the carousel for cards, `AdPoster` for a still or for a stated absence. Nothing here invents a
 * picture — no placeholder, no frame from a sibling ad — and `object-contain` means a 9:16 story is
 * shown whole, since seeing the whole frame is what the reader opened this for.
 */
export function AdPreviewDialog({
  creative,
  locale,
  figures,
  detailsTo,
  onClose,
}: {
  creative: CreativeCard
  locale: Locale
  /** The row's own numbers, already formatted by the surface that owns them. */
  figures?: { label: string; value: string }[]
  /** Where the full page is, when the reader does want to leave. */
  detailsTo?: string
  onClose: () => void
}) {
  const ar = locale === 'ar'
  const reading = readPreview(creative.preview, ar)

  /*
   * The objective, through the canonical map — and the RAW value when the map has not been taught it.
   *
   * `creative.objective` is a raw `CampaignObjective`, not a canonical key, and the label lookup
   * throws on a key it does not hold. Falling back to the provider's own word is the honest answer:
   * a reader seeing `store_visits` learns something, and a reader seeing a crash learns nothing.
   */
  const canonical = creative.objective === null ? null : canonicalOfRaw(creative.objective)
  const objectiveLabel = creative.objective === null
    ? null
    : canonical === null
      ? creative.objective
      : canonicalObjectiveLabel(canonical, locale)

  /*
   * Escape closes it — CONTENT-DETAIL-MODAL-001.
   *
   * A modal a keyboard cannot leave is a trap, and this one is reached from a grid people navigate
   * with the keyboard. The report's detail had this from the day it shipped; the library's did not.
   */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }

    document.addEventListener('keydown', onKey)

    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={creative.name}
      data-testid="ad-preview-dialog"
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center sm:p-6"
      onClick={onClose}
    >
      {/*
        Full height on a phone, a centred sheet above it.

        `h-full` rather than `max-h-[90vh]` on mobile: a detail that leaves a strip of the grid
        showing behind it reads as a popup to be dismissed, and the reader came here to look at the
        media. Above `sm` it is the centred modal the requirement asks for.
      */}
      <div
        className="flex h-full w-full max-w-2xl flex-col gap-3 overflow-y-auto border-border bg-surface p-4 sm:h-auto sm:max-h-[92vh] sm:rounded-2xl sm:border"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-3 flex items-start justify-between gap-3">
          <h3 className="text-sm font-bold text-text-primary">{creative.name}</h3>
          <button
            type="button"
            data-testid="ad-preview-dialog-close"
            onClick={onClose}
            className="shrink-0 text-xs font-semibold text-text-muted hover:text-text-primary"
          >
            {ar ? 'إغلاق' : 'Close'}
          </button>
        </div>

        {reading.kind === 'video' ? (
          <CreativeVideoPlayer src={reading.src} poster={reading.poster} aspect={creative.preview?.aspect ?? null} />
        ) : (
          <AdPoster
            preview={creative.preview}
            name={creative.name}
            className="h-72 w-full bg-surface-secondary object-contain"
            testid="ad-preview-dialog-poster"
            width={creative.width}
            height={creative.height}
            aspectRatio={creative.aspect_ratio}
          />
        )}

        {creative.preview?.kind === 'carousel' && <CreativeCarousel preview={creative.preview} locale={locale} />}

        <dl className="mt-3 grid grid-cols-2 gap-2 text-[11px]">
          <Fact label={ar ? 'المنصة' : 'Platform'} value={providerLabel(creative.provider, locale)} />
          <Fact label={ar ? 'النوع' : 'Format'} value={creative.format} />
          <Fact label={ar ? 'الحالة' : 'Status'} value={creative.status} />
          {creative.campaign_name && <Fact label={ar ? 'الحملة' : 'Campaign'} value={creative.campaign_name} />}
          {/*
            What it was bought FOR — CONTENT-DETAIL-MODAL-001.

            The figures below are chosen by this objective (`headline_metrics`), so a reader looking
            at «CTR 0.4%» without knowing the ad was bought for reach is judging it against a target
            nobody set. Absent rather than «unknown» when the campaign has no objective recorded.
          */}
          {objectiveLabel !== null && <Fact label={ar ? 'الهدف' : 'Objective'} value={objectiveLabel} />}
          {/*
            Its place in the hierarchy. The ad-set id is what the card carries, and an id is a poor
            label — but it is the honest one, and it is what a reader pastes into the platform.
          */}
          {creative.ad_set_id && (
            <Fact label={ar ? 'المجموعة الإعلانية' : 'Ad set'} value={creative.ad_set_id} />
          )}
          {creative.ads.length > 0 && (
            <Fact
              label={ar ? 'الإعلانات' : 'Ads'}
              value={String(creative.ads.length)}
            />
          )}
        </dl>

        {figures && figures.length > 0 && (
          <div data-testid="ad-preview-dialog-figures" className="mt-3 grid grid-cols-3 gap-1.5 text-center">
            {figures.map((f) => (
              <div key={f.label} className="rounded-lg bg-surface-secondary p-2">
                <div className="text-[11px] font-semibold leading-tight text-text-muted">{f.label}</div>
                <div dir="ltr" className="tnum text-sm font-bold text-text-primary">{f.value}</div>
              </div>
            ))}
          </div>
        )}

        {detailsTo && (
          <Link
            to={detailsTo}
            data-testid="ad-preview-dialog-details"
            className="mt-3 inline-block text-xs font-semibold text-brand-600 underline underline-offset-2"
          >
            {ar ? 'صفحة الإعلان' : 'Open the ad’s page'}
          </Link>
        )}
      </div>
    </div>
  )
}

function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="rounded-lg bg-surface-secondary px-2 py-1.5">
      <dt className="text-text-muted">{label}</dt>
      <dd className="truncate font-semibold text-text-primary">{value}</dd>
    </div>
  )
}
