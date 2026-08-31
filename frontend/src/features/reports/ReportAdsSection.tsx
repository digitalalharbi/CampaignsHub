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
  campaign_name?: string | null
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

export function ReportAdsSection({
  ads,
  absentReason,
  level,
  locale,
  title,
  limit = 6,
  reading,
}: {
  ads: ReportAd[] | undefined
  absentReason?: string | null
  /** `ad` when the rows are ads; `campaign` when the generator could only reach campaigns. */
  level?: string | null
  locale: Locale
  title?: string
  limit?: number
  /** The five-step reading of the grid, where the server could produce one. */
  reading?: AdsReading
}) {
  const ar = locale === 'ar'
  const rows = (ads ?? []).slice(0, limit)
  const heading = title ?? (ar ? 'الإعلانات' : 'The ads')

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

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((ad, i) => (
          <article
            key={ad.id ?? `${ad.name}-${i}`}
            data-testid="report-ad-card"
            className="flex flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-surface p-3"
          >
            <AdPoster
              preview={ad.preview ?? null}
              name={ad.name ?? ''}
              className="h-32 w-full"
              testid={`report-ad-poster-${i}`}
            />

            <div className="min-w-0">
              <div className="truncate text-sm font-bold text-text-primary" title={ad.name ?? undefined}>
                {ad.name ?? '—'}
              </div>
              <div className="truncate text-[11px] text-text-muted">
                {[
                  ad.provider ? providerLabel(ad.provider, locale) : null,
                  ad.campaign_name ?? null,
                  ad.objective ? objectiveLabel(ad.objective, locale) : null,
                ].filter(Boolean).join(' · ')}
              </div>
            </div>

            {/*
              The indicators that fit what the ad was BUYING, passed in already formatted by the
              surface that owns the currency — this component never decides how a number reads.
            */}
            <dl className="grid grid-cols-3 gap-1.5 text-center">
              {figuresFor(ad, ar).map((f) => (
                <div key={f.label} className="rounded-lg bg-surface-secondary p-1.5">
                  <dt className="text-[10px] font-semibold text-text-muted">{f.label}</dt>
                  <dd dir="ltr" className="tnum text-xs font-bold text-text-primary">{f.value}</dd>
                </div>
              ))}
            </dl>

            {ad.reason && <p className="text-[11px] leading-snug text-text-secondary">{ad.reason}</p>}
          </article>
        ))}
      </div>
    </section>
  )
}

/**
 * Three figures, chosen by what the ad reported rather than by a fixed set.
 *
 * A brand ad has no ROAS and a sales ad's impressions are not the point. Printing four columns and
 * filling the ones the platform never measured with «—» is how a report teaches a client that half
 * its numbers are missing; these are the three that exist, in the order that answers «did it work».
 */
function figuresFor(ad: ReportAd, ar: boolean): { label: string; value: string }[] {
  const out: { label: string; value: string }[] = []
  const n = (v: number | null | undefined, digits = 0) =>
    v === null || v === undefined ? null : v.toLocaleString('en-US', { maximumFractionDigits: digits })

  const spend = n(ad.spend)
  if (spend !== null) out.push({ label: ar ? 'الإنفاق' : 'Spend', value: spend })

  if (ad.roas !== null && ad.roas !== undefined) {
    out.push({ label: ar ? 'العائد' : 'ROAS', value: `${ad.roas.toFixed(2)}×` })
  } else if (ad.cpa !== null && ad.cpa !== undefined) {
    out.push({ label: ar ? 'تكلفة النتيجة' : 'CPA', value: n(ad.cpa, 2) ?? '—' })
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
