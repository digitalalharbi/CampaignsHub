import { AdPoster } from '@/features/content/AdPoster'
import { providerLabel } from '@/features/campaigns/labels'
import { objectiveLabel } from '@/features/campaigns/labels'
import type { CreativePreview } from '@/features/content/api'
import type { Locale } from '@/stores/ui'

/**
 * REPORT-AD-PREVIEW-001 — the ads that ran, in the document the client keeps.
 *
 * ## What the report showed before
 *
 * A medal card per CAMPAIGN with a coloured gradient where the picture belongs. The generator has
 * carried ad-level rows and their media for a while — `ads`, `ads_level`, `ads_absent_reason` — and
 * nothing rendered them, so the part of the report a client actually recognises was a rank number on
 * an orange square.
 *
 * ## One reader for the media, everywhere
 *
 * The still is `AdPoster`, the same component the library uses. An ad whose file the platform
 * withheld, expired, or never sent says the same sentence here as it does there, and nothing in this
 * section invents a picture — no placeholder, no frame borrowed from a sibling ad. That is what makes
 * the section evidence rather than decoration.
 *
 * ## The absent case is a sentence, not an empty grid
 *
 * An empty gallery under «الإعلانات» reads as «your ads were so bad there is nothing to show». The
 * generator says WHY — no creatives in the window, or no metric this objective can rank on — and the
 * section prints that reason instead of a hole.
 */
export type ReportAd = {
  id?: string
  name?: string | null
  provider?: string | null
  objective?: string | null
  preview?: CreativePreview | null
  spend?: number | null
  impressions?: number | null
  clicks?: number | null
  conversions?: number | null
  ctr?: number | null
  cpa?: number | null
  roas?: number | null
  /** The ranker's own sentence: why this ad is in this list. */
  reason?: string | null
}

/** The reasons the generator can give, said in the reader's language. */
const ABSENT: Record<string, { ar: string; en: string }> = {
  no_creatives_in_window: {
    ar: 'لم تُسجَّل إعلانات على مستوى الإعلان في هذه الفترة — الأرقام أعلاه على مستوى الحملة.',
    en: 'No ad-level rows were recorded in this window — the figures above are at campaign level.',
  },
  no_rankable_metric_for_this_objective: {
    ar: 'لم تُبلِّغ المنصات عن مقياس يصلح لترتيب إعلانات هذا الهدف، فلا تُعرض قائمة «الأفضل».',
    en: 'The platforms reported no metric this objective can be ranked on, so no «best» list is shown.',
  },
  no_ads_to_show: {
    ar: 'لا إعلانات لعرضها في هذه الفترة.',
    en: 'There are no ads to show for this window.',
  },
}

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the range the grid implies, said once above it.
 *
 * The ranked grid is the signal. This is what it is measured on, why the distance exists, and the
 * one action the evidence supports — comparing the two ends. Absent where the server could not read
 * a range: one ad is not two ends, and two ends measured on different metrics are not a comparison.
 */
export type AdsReading = {
  signal: { metric: string; best: { ad: string | null; value: number | null }; worst: { ad: string | null; value: number | null } } | null
  explanation: { ar: string; en: string } | null
  action: { ar: string; en: string } | null
  silent_reason: string | null
}

/** One objective's ads, ranked on that objective's own metric — or shown with no claim. */
export type AdGroup = {
  family: string
  label_ar: string
  label_en: string
  metric: string | null
  metric_label_ar: string | null
  metric_label_en: string | null
  ads: ReportAd[]
  ranked: boolean
}

export function ReportAdsSection({
  ads,
  groups,
  currency,
  absentReason,
  level,
  locale,
  title,
  limit = 6,
  reading,
  onOpen,
}: {
  ads: ReportAd[] | undefined
  /**
   * REPORT-AD-PREVIEW-001 §A — the ads grouped by the objective they were bought for.
   *
   * A single list across objectives can only be ordered by something they share, and what they
   * share is spend — which is how production came to print «الإعلانات التي عملت … أعلى الإنفاق».
   * Grouped, each list is ordered on the metric its own objective is judged by, and says so.
   */
  groups?: AdGroup[]
  /** MONEY-USD-001 — the currency these figures were measured in. Null prints no currency at all. */
  currency?: string | null
  absentReason?: string | null
  /** `ad` when the rows are ads; `campaign` when the generator could only reach campaigns. */
  level?: string | null
  locale: Locale
  title?: string
  limit?: number
  /** The five-step reading of the grid, where the server could produce one. */
  reading?: AdsReading
  /** Opens this ad's own detail. Absent on surfaces that cannot open one, e.g. the printed page. */
  onOpen?: (ad: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const rows = (ads ?? []).slice(0, limit)
  /*
   * «الإعلانات الأعلى أداءً», not «الإعلانات التي عملت».
   *
   * The section is a claim about performance, so it says so — and where the ranking basis is known
   * it is printed beside the heading, because «best» without «by what» is the sentence that let a
   * spend order pass for a performance order.
   */
  const heading = title ?? (ar ? 'الإعلانات الأعلى أداءً' : 'Top performing ads')

  if (rows.length === 0) {
    const reason = ABSENT[absentReason ?? 'no_ads_to_show'] ?? ABSENT.no_ads_to_show

    return (
      <section data-testid="report-ads" data-state="absent" className="flex flex-col gap-2">
        <h3 className="text-base font-bold text-text-primary">{heading}</h3>
        <p data-testid="report-ads-absent" className="text-sm text-text-secondary">{ar ? reason.ar : reason.en}</p>
      </section>
    )
  }

  return (
    <section data-testid="report-ads" data-state="present" data-level={level ?? 'ad'} className="flex flex-col gap-3">
      <h3 className="text-base font-bold text-text-primary">{heading}</h3>

      {reading?.signal && (
        <div data-testid="report-ads-reading" className="rounded-xl border border-border bg-surface-secondary/40 p-3">
          <p className="text-sm text-text-primary">
            {ar ? 'الأفضل ' : 'Best '}
            <span className="font-bold">{reading.signal.best.ad ?? '—'}</span>
            {' · '}
            {ar ? 'الأضعف ' : 'weakest '}
            <span className="font-bold">{reading.signal.worst.ad ?? '—'}</span>
          </p>
          {reading.explanation && (
            <p className="mt-0.5 text-xs leading-relaxed text-text-secondary">
              {ar ? reading.explanation.ar : reading.explanation.en}
            </p>
          )}
          {reading.action && (
            <p data-testid="report-ads-action" className="mt-1 text-sm font-medium text-text-primary">
              {ar ? reading.action.ar : reading.action.en}
            </p>
          )}
        </div>
      )}

      {/*
        Grouped by objective where the server could group them.

        One list across objectives can only be ordered by something they all have, and what they all
        have is spend — which is how «الإعلانات التي عملت … أعلى الإنفاق (578)» reached a client's
        report. Each group is ordered on the metric its own objective is judged by and says so; a
        group whose objective reported none of its metrics shows its ads and claims no order.
      */}
      {(groups ?? []).length > 0
        ? (groups ?? []).map((group) => (
          <div key={group.family} data-testid={`report-ads-group-${group.family}`} className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline gap-2">
              <span className="text-sm font-bold text-text-primary">{ar ? group.label_ar : group.label_en}</span>
              <span data-testid={`report-ads-basis-${group.family}`} className="text-[11px] text-text-muted">
                {group.ranked && group.metric !== null
                  ? (ar
                      ? `مرتّبة حسب ${group.metric_label_ar ?? group.metric}`
                      : `ranked by ${group.metric_label_en ?? group.metric}`)
                  : (ar
                      ? 'لم تُبلِّغ المنصات عن مقياس يصلح لترتيب إعلانات هذا الهدف — معروضة دون ترتيب.'
                      : 'the platforms reported no metric this objective can be ranked on — shown without an order.')}
              </span>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {group.ads.slice(0, limit).map((ad, i) => (
                <AdCard
                  key={ad.id ?? `${group.family}-${i}`}
                  ad={ad}
                  locale={locale}
                  currency={currency ?? null}
                  testidPrefix={`report-ad-poster-${group.family}-${i}`}
                  onOpen={onOpen}
                />
              ))}
            </div>
          </div>
        ))
        : (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {rows.map((ad, i) => (
              <AdCard
                key={ad.id ?? `${ad.name}-${i}`}
                ad={ad}
                locale={locale}
                currency={currency ?? null}
                testidPrefix={`report-ad-poster-${i}`}
                onOpen={onOpen}
              />
            ))}
          </div>
        )}
    </section>
  )
}

/**
 * One ad's card. Openable where the surface can open one, inert where it cannot.
 *
 * A card that looks pressable and does nothing is worse than a card that does not: production
 * rendered these as `<article>` with no handler and `cursor: auto`, and a client clicking their best
 * ad got silence.
 */
function AdCard({
  ad, locale, currency, testidPrefix, onOpen,
}: {
  ad: ReportAd
  locale: Locale
  currency: string | null
  testidPrefix: string
  onOpen?: (ad: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const body = (
    <>
      <AdPoster preview={ad.preview ?? null} name={ad.name ?? ''} className="h-32 w-full" testid={testidPrefix} />

      <div className="min-w-0">
        <div className="truncate text-sm font-bold text-text-primary" title={ad.name ?? undefined}>
          {ad.name ?? '—'}
        </div>
        <div className="truncate text-[11px] text-text-muted">
          {[
            /*
             * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the platform and the objective, never the campaign.
             *
             * This line read «ميتا · Meta — White Friday (seasonal) · مبيعات» under every ad, on the
             * one section a client is entitled to: the work itself, with the picture that ran. The
             * ad's own name and the objective say what it is; the campaign it sat in is the agency's
             * filing system, and this section is shared with the live link and the printed document,
             * so it was the same leak in three places.
             */
            ad.provider ? providerLabel(ad.provider, locale) : null,
            ad.objective ? objectiveLabel(ad.objective, locale) : null,
          ].filter(Boolean).join(' · ')}
        </div>
      </div>

      <dl className="grid grid-cols-3 gap-1.5 text-center">
        {figuresFor(ad, ar, currency).map((f) => (
          <div key={f.label} className="rounded-lg bg-surface-secondary p-1.5">
            <dt className="text-[10px] font-semibold text-text-muted">{f.label}</dt>
            <dd dir="ltr" className="tnum text-xs font-bold text-text-primary">{f.value}</dd>
          </div>
        ))}
      </dl>

      {ad.reason && <p className="text-[11px] leading-snug text-text-secondary">{ad.reason}</p>}
    </>
  )

  const shell = 'flex flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-surface p-3 text-start'

  return onOpen === undefined
    ? <article data-testid="report-ad-card" data-openable="false" className={shell}>{body}</article>
    : (
      <button
        type="button"
        data-testid="report-ad-card"
        data-openable="true"
        onClick={() => onOpen(ad)}
        aria-label={ar ? `تفاصيل ${ad.name ?? ''}` : `Details for ${ad.name ?? ''}`}
        className={`${shell} transition-colors hover:border-brand-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500`}
      >
        {body}
      </button>
    )
}

/**
 * Three figures, chosen by what the ad reported rather than by a fixed set.
 *
 * A brand ad has no ROAS and a sales ad's impressions are not the point. Printing four columns and
 * filling the ones the platform never measured with «—» is how a report teaches a client that half
 * its numbers are missing; these are the three that exist, in the order that answers «did it work».
 */
function figuresFor(ad: ReportAd, ar: boolean, currency: string | null): { label: string; value: string }[] {
  const out: { label: string; value: string }[] = []
  const n = (v: number | null | undefined, digits = 0) =>
    v === null || v === undefined ? null : v.toLocaleString('en-US', { maximumFractionDigits: digits })
  /*
   * MONEY-USD-001 — money carries its currency here too.
   *
   * Production printed «الإنفاق 578» with no unit at all on a USD account, in the one section a
   * client reads to decide which ad to keep paying for.
   */
  const cash = (v: number | null | undefined, digits = 0) => {
    const text = n(v, digits)

    return text === null ? null : (currency ? `${text} ${currency}` : text)
  }

  const spend = cash(ad.spend)
  if (spend !== null) out.push({ label: ar ? 'الإنفاق' : 'Spend', value: spend })

  /*
   * A return of «0.00×» is a claim that the ad returned nothing. Production printed it on every
   * conversion ad in the section, because revenue is not attributed at ad level there — «nobody
   * measured this» became «this earned zero», on the ads a client is deciding about.
   */
  if (ad.roas !== null && ad.roas !== undefined && ad.roas > 0) {
    out.push({ label: ar ? 'العائد' : 'ROAS', value: `${ad.roas.toFixed(2)}×` })
  } else if (ad.cpa !== null && ad.cpa !== undefined && ad.cpa > 0) {
    out.push({ label: ar ? 'تكلفة النتيجة' : 'CPA', value: cash(ad.cpa, 2) ?? '—' })
  } else if (ad.conversions !== null && ad.conversions !== undefined) {
    out.push({ label: ar ? 'النتائج' : 'Results', value: n(ad.conversions) ?? '—' })
  }

  if (ad.ctr !== null && ad.ctr !== undefined) {
    out.push({ label: ar ? 'نسبة النقر' : 'CTR', value: `${(ad.ctr * 100).toFixed(2)}%` })
  } else {
    const impressions = n(ad.impressions)
    if (impressions !== null) out.push({ label: ar ? 'الظهور' : 'Impressions', value: impressions })
  }

  return out.slice(0, 3)
}
