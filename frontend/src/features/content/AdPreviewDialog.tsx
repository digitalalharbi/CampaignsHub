import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { AdPoster } from './AdPoster'
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
 * The media itself is `AdPoster`, which is the one reader for this in the product: an ad that shows
 * here shows everywhere, and an ad that cannot gives the same reason everywhere. Nothing in this
 * dialog invents a picture — no placeholder, no frame from a sibling ad.
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

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={creative.name}
      data-testid="ad-preview-dialog"
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center sm:p-6"
      onClick={onClose}
    >
      <div
        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-2xl border border-border bg-surface p-4 sm:rounded-2xl"
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

        <AdPoster
          preview={creative.preview}
          name={creative.name}
          className="h-64 w-full"
          testid="ad-preview-dialog-poster"
        />

        <dl className="mt-3 grid grid-cols-2 gap-2 text-[11px]">
          <Fact label={ar ? 'المنصة' : 'Platform'} value={providerLabel(creative.provider, locale)} />
          <Fact label={ar ? 'النوع' : 'Format'} value={creative.format} />
          <Fact label={ar ? 'الحالة' : 'Status'} value={creative.status} />
          {creative.campaign_name && <Fact label={ar ? 'الحملة' : 'Campaign'} value={creative.campaign_name} />}
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
