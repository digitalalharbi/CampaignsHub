import { useEffect } from 'react'
import { AdPoster } from '@/features/content/AdPoster'
import { CreativeCarousel } from '@/features/content/CreativeCarousel'
import { CreativeVideoPlayer } from '@/features/content/CreativeVideoPlayer'
import { readPreview } from '@/features/content/adPreview'
import { providerLabel, objectiveLabel } from '@/features/campaigns/labels'
import type { ReportAd } from './ReportAdsSection'
import type { Locale } from '@/stores/ui'

/**
 * REPORT-AD-PREVIEW-001 §C — an ad in a client's report opens its own detail.
 *
 * ## What production did
 *
 * The cards rendered as `<article>`, with no handler and `cursor: auto`. A client pressing the ad
 * they were deciding about got nothing — and a card that looks pressable and is not is worse than
 * one that plainly is not, because the reader concludes the report is broken rather than finished.
 *
 * ## Read-only, and bounded by the share
 *
 * This shows what the report payload already carries and nothing else: no fetch, no account ids, no
 * diagnostics, no destination the share did not include. A shared link's detail view must not become
 * a way to ask the product questions the share never authorised — the figures are the ones already
 * printed on the card, at more room, and a second query here would eventually disagree with the card
 * over a window boundary anyway.
 *
 * ## The media is the product's one reader
 *
 * `readPreview` decides, and then the same three components the content library uses render it: the
 * carousel for cards, the player for a film, `AdPoster` for a still or for a stated absence. Nothing
 * here invents a picture, borrows one from a sibling ad, or reaches a fourth conclusion about an ad
 * whose file the platform withheld — it says on this screen exactly what it says on the card.
 */
export function ReportAdDetail({
  ad,
  currency,
  locale,
  onClose,
}: {
  ad: ReportAd
  currency: string | null
  locale: Locale
  onClose: () => void
}) {
  const ar = locale === 'ar'
  const preview = ad.preview ?? null
  const reading = readPreview(preview, ar)

  /* Escape closes it: a modal a keyboard cannot leave is a trap, and this one opens on a phone. */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)

    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  const n = (v: number | null | undefined, digits = 0) =>
    v === null || v === undefined ? null : v.toLocaleString('en-US', { maximumFractionDigits: digits })

  /*
   * ANALYTICS-MONEY-TRUTH-001 — the scope's own currency, or no currency word at all. Never a
   * default: a figure captioned SAR because SAR is the reporting currency is a wrong number, and one
   * screen deeper than the card it is a wrong number with more authority.
   */
  const cash = (v: number | null | undefined, digits = 0) => {
    const text = n(v, digits)

    return text === null ? null : currency ? `${text} ${currency}` : text
  }

  /*
   * Only figures the ad actually reported. A «0.00×» return on an ad nobody measured revenue for is
   * the same claim the card used to make, and the modal is where a reader would finally believe it.
   */
  const figures: Array<{ label: string; value: string }> = []
  const add = (label: string, value: string | null) => {
    if (value !== null) figures.push({ label, value })
  }

  add(ar ? 'الإنفاق' : 'Spend', cash(ad.spend))
  add(ar ? 'الظهور' : 'Impressions', n(ad.impressions))
  add(ar ? 'النقرات' : 'Clicks', n(ad.clicks))
  add(
    ar ? 'نسبة النقر' : 'CTR',
    ad.ctr === null || ad.ctr === undefined ? null : `${(ad.ctr * 100).toFixed(2)}%`,
  )
  add(ar ? 'النتائج' : 'Results', n(ad.conversions))
  add(ar ? 'تكلفة النتيجة' : 'Cost per result', ad.cpa && ad.cpa > 0 ? cash(ad.cpa, 2) : null)
  add(ar ? 'العائد على الإنفاق' : 'ROAS', ad.roas && ad.roas > 0 ? `${ad.roas.toFixed(2)}×` : null)

  const facts = [
    ad.provider ? providerLabel(ad.provider, locale) : null,
    ad.campaign_name ?? null,
    ad.objective ? objectiveLabel(ad.objective, locale) : null,
  ].filter(Boolean)

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={ad.name ?? (ar ? 'تفاصيل الإعلان' : 'Ad detail')}
      data-testid="report-ad-detail"
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center sm:p-6"
      onClick={onClose}
    >
      {/* Full height on a phone, a centred sheet above it — the shape the library's dialog uses. */}
      <div
        className="flex max-h-[92vh] w-full max-w-2xl flex-col gap-3 overflow-y-auto rounded-t-2xl border border-border bg-surface p-4 sm:rounded-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h3 className="truncate text-base font-bold text-text-primary">{ad.name ?? '—'}</h3>
            {facts.length > 0 && <p className="truncate text-xs text-text-muted">{facts.join(' · ')}</p>}
          </div>
          <button
            type="button"
            data-testid="report-ad-detail-close"
            aria-label={ar ? 'إغلاق' : 'Close'}
            onClick={onClose}
            className="shrink-0 rounded-lg border border-border px-2 py-1 text-xs font-semibold text-text-muted hover:text-text-primary"
          >
            {ar ? 'إغلاق' : 'Close'}
          </button>
        </div>

        {reading.kind === 'video' ? (
          <CreativeVideoPlayer src={reading.src} poster={reading.poster} />
        ) : (
          /*
           * `object-contain`, not `cover`: a story ad is 9:16 and the card crops it to fit a grid.
           * The modal is where the reader came to see the whole frame, so nothing is cut here.
           */
          <AdPoster
            preview={preview}
            name={ad.name ?? ''}
            className="h-72 w-full bg-surface-secondary object-contain"
            testid="report-ad-detail-poster"
          />
        )}

        {preview?.kind === 'carousel' && <CreativeCarousel preview={preview} locale={locale} />}

        {figures.length > 0 && (
          <dl data-testid="report-ad-detail-figures" className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {figures.map((f) => (
              <div key={f.label} className="rounded-lg bg-surface-secondary p-2 text-center">
                <dt className="text-[11px] font-semibold leading-tight text-text-muted">{f.label}</dt>
                <dd dir="ltr" className="tnum text-sm font-bold text-text-primary">
                  {f.value}
                </dd>
              </div>
            ))}
          </dl>
        )}

        {/* The ranker's own sentence — why this ad is in this list, in the reader's language. */}
        {ad.reason && (
          <p data-testid="report-ad-detail-reason" className="text-xs leading-relaxed text-text-secondary">
            {ad.reason}
          </p>
        )}
      </div>
    </div>
  )
}
