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
import { Num } from '@/components/ui/Num'

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
  trend,
  detailsTo,
  onClose,
}: {
  creative: CreativeCard
  locale: Locale
  /** The row's own numbers, already formatted by the surface that owns them. */
  figures?: { label: string; value: string }[]
  /**
   * This creative's movement over the window, as a NODE the caller supplies.
   *
   * A node rather than data: the dialog is opened from surfaces with different project scopes and
   * different windows, and each already knows its own. Fetching here would make the dialog decide
   * what «this period» means — a second answer to a question the surface above it has already
   * answered, which is exactly the second pipeline the requirement forbids.
   */
  trend?: ReactNode
  /** Where the full page is, when the reader does want to leave. */
  detailsTo?: string
  onClose: () => void
}) {
  const ar = locale === 'ar'
  const reading = readPreview(creative.preview, ar)

  /*
   * «No figures of its own» — the same test the card makes, from the same field.
   *
   * `metrics` null is the platform having answered nothing for this creative in this window. A
   * creative with a metrics object HAS figures, even where some of them are absent, so it gets the
   * figures and no sentence.
   */
  const metricsAbsent = (creative.metrics ?? null) === null

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
      {/*
        CONTENT-POPUP-SCALE-001 — «larger than the current cramped modal, but NOT full-screen».
        
        It was `max-w-2xl`: 672 pixels of a 1440-pixel screen, one column, with the creative, the
        figures and the chart stacked down it. Measured on the owner's own width, the chart sat
        below the fold — so the panel opened to judge a creative could not show the creative and
        its trend at the same time, which is the whole reason somebody opens it.
        
        A wider single column would not have fixed that: a taller hero pushes the chart further
        down, and the two requirements («the creative visually dominant» and «the chart in the first
        viewport») fight each other in one column at any width. So above `lg` the panel becomes two:
        the media on one side, the reading of it on the other. Below `lg` it stays exactly as it
        was, because on a phone a single column IS the right answer and two would be four.
        
        1024 of 1440 is deliberately short of the screen — the grid behind stays visible, which is
        what keeps this a panel over the library rather than a page the reader navigated to.
      */}
      <div
        className="flex h-full w-full max-w-2xl flex-col gap-3 overflow-y-auto border-border bg-surface p-4 sm:h-auto sm:max-h-[92vh] sm:rounded-2xl sm:border lg:grid lg:max-w-5xl lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:items-start lg:gap-x-5"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-3 flex items-start justify-between gap-3 lg:col-span-2 lg:mb-0">
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

        {/*
          The media column. `min-w-0` because a grid track refuses to shrink below its content
          otherwise, and a wide poster would push the figures off their own side.
        */}
        <div data-testid="ad-preview-dialog-media" className="flex min-w-0 flex-col gap-3 lg:col-start-1 lg:row-start-2">
        {reading.kind === 'video' ? (
          <CreativeVideoPlayer src={reading.src} poster={reading.poster} aspect={creative.preview?.aspect ?? null} />
        ) : (
          <AdPoster
            preview={creative.preview}
            name={creative.name}
            className="h-72 w-full bg-surface-secondary object-contain lg:h-[26rem]"
            testid="ad-preview-dialog-poster"
            width={creative.width}
            height={creative.height}
            aspectRatio={creative.aspect_ratio}
          />
        )}

        {/*
          CONTENT-PREVIEW-SHAPES-001 — the component decides the kind, not the caller.

          `CreativeCarousel` renders a carousel's slides AND a collection's product tiles, and
          returns null for anything else. Gating it on `kind === 'carousel'` out here put the
          collection back where the component's own docblock says it was rescued from: the hero
          drawn and the products dropped, on a client's report and in the panel an operator opens
          from Analytics. Two callers had the fix and two did not.
        */}
        {creative.preview && <CreativeCarousel preview={creative.preview} locale={locale} />}
        </div>

        {/* The reading of it: why the figures are dashes, the figures, the trend, the detail, the way on. */}
        <div className="flex min-w-0 flex-col lg:col-start-2 lg:row-start-2">

        {/*
          CONTENT-POPUP-VISUAL-001 — the order a reader needs, not the order the fields arrived in.

          «Large creative preview at the top, clear key KPIs beside it or directly under the hero,
          prominent chart near the top — not buried at the bottom, compact performance summary,
          concise metadata, clear CTA.»

          This panel used to read: media, then eight metadata facts, then the figures, then the
          trend, then the link. So the chart — the one thing that answers «is this getting better or
          worse» — was below a fold on every phone, under a stack of ids nobody opened the panel to
          read. The order now is hero → figures → chart → metadata → CTA, which is the order of the
          questions somebody actually has.
        */}

        {/*
          CONTENT-METRIC-ABSENCE-DETAIL-001 — why the figures are dashes, above the figures.

          A creative with no figures of its own is «لم يعمل خلال هذه الفترة» only when the AD did not
          run either. When the ad DID run and the platform declined to break the result down per
          creative — 35 creatives on the owner's own account — that sentence is false in the
          expensive direction: an operator reads it and turns off something that is running.
        */}
        {metricsAbsent && (
          <p
            data-testid="ad-preview-dialog-absence"
            className="mt-3 rounded-lg bg-surface-secondary px-3 py-2 text-[11px] leading-relaxed text-text-secondary"
          >
            {creative.ad_delivered
              ? (ar
                  ? 'هذا الإعلان عمل خلال الفترة، لكن المنصة لم تُرجع مؤشرات على مستوى المحتوى — الأرقام موجودة على مستوى الإعلان.'
                  : 'This ad ran during the period, but the platform returned no metrics at content level — the figures exist at ad level.')
              : (ar
                  ? 'لم يعمل هذا المحتوى خلال هذه الفترة.'
                  : 'This content item did not run in this period.')}
          </p>
        )}

        {/* The figures, directly under the hero — the first thing after seeing the ad. */}
        {figures && figures.length > 0 && (
          <div data-testid="ad-preview-dialog-figures" className="mt-3 grid grid-cols-3 gap-1.5">
            {figures.map((f) => (
              <div key={f.label} className="rounded-lg bg-surface-secondary p-2 text-start">
                <div className="text-[11px] font-semibold leading-tight text-text-muted">{f.label}</div>
                <div className="tnum text-sm font-bold text-text-primary"><Num>{f.value}</Num></div>
              </div>
            ))}
          </div>
        )}

        {/*
          And the chart, HIGH — the answer to «is this getting better or worse».

          It was last, under eight metadata rows, which on a phone put it off the screen entirely in
          the panel somebody opened to judge a creative.
        */}
        {trend && (
          <div data-testid="ad-preview-dialog-trend" className="mt-4">
            <h4 className="mb-1 text-xs font-bold text-text-secondary">{ar ? 'الاتجاه الزمني' : 'Trend over time'}</h4>
            {trend}
          </div>
        )}

        {/*
          The metadata LAST, and folded away.

          Platform, format, status, objective and the ad-set id answer «which ad is this» — a
          question a reader has already answered by opening the panel from a row they chose. They are
          kept because an operator does occasionally need the id to paste into the platform, and put
          behind a summary so they cost nothing to the reader who does not.
        */}
        <details data-testid="ad-preview-dialog-meta" className="mt-4">
          <summary className="cursor-pointer text-xs font-semibold text-text-secondary">
            {ar ? 'تفاصيل الإعلان' : 'Ad details'}
          </summary>

          <dl className="mt-2 grid grid-cols-2 gap-2 text-[11px]">
            <Fact label={ar ? 'المنصة' : 'Platform'} value={providerLabel(creative.provider, locale)} />
            <Fact label={ar ? 'النوع' : 'Format'} value={creative.format} />
            <Fact label={ar ? 'الحالة' : 'Status'} value={creative.status} />
            {creative.campaign_name && <Fact label={ar ? 'الحملة' : 'Campaign'} value={creative.campaign_name} />}
            {/*
              What it was bought FOR. The figures above are chosen by this objective, so a reader
              looking at «CTR 0.4%» without knowing the ad was bought for reach is judging it against
              a target nobody set. Absent rather than «unknown» when no objective is recorded.
            */}
            {objectiveLabel !== null && <Fact label={ar ? 'الهدف' : 'Objective'} value={objectiveLabel} />}
            {/* An id is a poor label and the honest one — it is what a reader pastes into the platform. */}
            {creative.ad_set_id && (
              <Fact label={ar ? 'المجموعة الإعلانية' : 'Ad set'} value={creative.ad_set_id} />
            )}
            {/*
              `?? []` because the type says this is always an array and the payloads disagree. The
              dialog took a whole page down the first time the library opened it, because one
              caller's shape had been taken for the contract.
            */}
            {(creative.ads ?? []).length > 0 && (
              <Fact label={ar ? 'الإعلانات' : 'Ads'} value={String((creative.ads ?? []).length)} />
            )}
          </dl>
        </details>

        {/*
          The way onward, as a CONTROL rather than a line of underlined text.

          «A clear CTA to open the full content analytics page if deeper analysis is needed» — this
          was a small link at the bottom of a text sheet, which is how a route nobody takes looks.
        */}
        {detailsTo && (
          <Link
            to={detailsTo}
            data-testid="ad-preview-dialog-details"
            className="mt-4 inline-flex items-center justify-center rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white transition-colors hover:bg-brand-700"
          >
            {ar ? 'فتح تحليلات هذا المحتوى' : 'Open this content’s analytics'}
          </Link>
        )}
        </div>
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
