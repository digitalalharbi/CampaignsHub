import { AdPoster } from '@/features/content/AdPoster'
import { providerLabel } from '@/features/campaigns/labels'
import { objectiveLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { ReportPlatformSummary } from './ReportPlatformSummary'
import type { CreativePreview } from '@/features/content/api'
import type { Locale } from '@/stores/ui'
import { Num } from '@/components/ui/Num'
import { formatMoneyReading, readMoney, type MoneyTotals } from '@/lib/money/contract'

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
  /** The share-bound handle the content drilldown opens this ad by — never an internal id. */
  content_key?: string
  name?: string | null
  provider?: string | null
  objective?: string | null
  preview?: CreativePreview | null
  spend?: number | null
  /*
   * CLIENT-REPORT-MONEY-REDACTION-001 — the withheld half of the money travels with the ad.
   *
   * `ReportAds` used to write `spend => metrics.spend ?? 0`, so an unconvertible amount arrived here
   * as a zero and the card printed «0 USD» for an ad that had really spent 412.50. It now leaves
   * `spend` null and carries these beside it, under the same key names `readMoney` already reads, so
   * this card and every other money surface make one set of decisions rather than two.
   *
   * A link that hides spend has none of these keys — `ShareService` removes them — so the figure is
   * simply not built, which is how the permission is enforced here.
   */
  spend_original?: number | null
  spend_withheld_rows?: number | null
  money_original_currency?: string | null
  money_original_currencies?: number | null
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
  /*
   * REPORT-CREATIVE-TRUTH-001 §B — how many this group chose from, and how many it shows.
   *
   * The group published three ads and nothing else. «الأعلى أداءً» over three cards is a claim about
   * a list the reader cannot see the length of: three of forty and three of three are different
   * reports, and a truncated list makes the second claim by default.
   *
   * Optional because a stored snapshot generated before this existed carries neither, and a report
   * already sent must not start printing «best 3 of 3» about a list nobody counted.
   */
  candidates?: number
  shown?: number
}

/**
 * REPORT-DETAIL-PARITY-001 — one platform's creatives, grouped by what each was bought for.
 *
 * The owner's words: «best-performing creatives per platform», not one merged top list. The nesting
 * is the rule, not a layout: platform, then objective INSIDE it, because ranking a brand film against
 * a sales ad for sharing a platform is the defect the objective grouping exists to remove.
 */
export type AdPlatformGroup = {
  provider: string
  groups: AdGroup[]
  candidates?: number
}

/**
 * How many objective groups a DECK SLIDE may carry — measured, not chosen.
 *
 * A slide is a fixed landscape page and the renderer fails it below a 0.85 fit. At five groups of
 * three cards the fit measured 0.355 with 108 elements clipped; this number is the one that renders
 * inside the floor on the largest shape audited (six platforms, five objectives, 240 creatives).
 */
const PAGED_GROUPS = 1

/*
 * Reducing the CARDS inside a group was tried and reverted, because it bought nothing.
 *
 * Two groups of three measured 0.80; two groups of two measured 0.80 — identical. The cards sit in a
 * three-column grid, so two and three occupy the same single row and the same height. What drives
 * this slide's height is the number of GROUP BLOCKS, each carrying its own heading, its ordering
 * reason and a card row. So the bound is on groups, and each kept group keeps its full complement of
 * cards rather than being thinned for no gain.
 *
 * That is the second time on this slide family that a change which looked like it must reduce height
 * reduced nothing — the first was removing a chart that shared a row with another. The fitScale
 * reading is the authority; the reasoning about it is not.
 */

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
  paged = false,
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
  /**
   * A PAGINATED surface — a deck slide — which cannot grow to fit its content.
   *
   * This section renders one block per objective group, each with up to `limit` cards. On an
   * account that actually has creatives that is five groups of three, and on one fixed landscape
   * page the renderer's auto-fit measured 0.355 with 108 elements clipped — which fails its own
   * readable floor and blocks the PDF export for BOTH forms.
   *
   * It was invisible until now because the demo estate's creatives carry no figures, so the section
   * rendered its absent-state and the slide was nearly empty. The first fix for this export was
   * therefore verified against data where this block did not exist — which is exactly why the deck
   * needed auditing at more than one data shape.
   *
   * The grouping itself is NOT flattened to make room. One list across objectives can only be
   * ordered by something they all share, and that is spend — REPORT-OBJECTIVE-001, and the defect
   * that put «الإعلانات التي عملت … أعلى الإنفاق» on a client's report. So whole groups are kept and
   * the number of them is bounded, with the remainder stated rather than dropped in silence.
   */
  paged?: boolean
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
        ? (
          <>
            {(paged ? (groups ?? []).slice(0, PAGED_GROUPS) : (groups ?? [])).map((group) => (
              <AdGroupBlock
                key={group.family}
                group={group}
                locale={locale}
                currency={currency ?? null}
                limit={limit}
                onOpen={onOpen}
              />
            ))}
            {/*
              What a slide could not hold is STATED. A bound that goes quiet turns «the objectives
              this page had room for» into «the objectives that performed», which is a claim about
              the client's advertising made by our page size.
            */}
            {paged && (groups ?? []).length > PAGED_GROUPS && (
              <p data-testid="report-ads-groups-withheld" className="text-xs text-text-secondary">
                {locale === 'ar'
                  ? `${(groups ?? []).length - PAGED_GROUPS} هدف إضافي معروض في التقرير التفاعلي وفي ملفات التصدير.`
                  : `${(groups ?? []).length - PAGED_GROUPS} more objective(s) are shown in the interactive report and the exported files.`}
              </p>
            )}
          </>
        )
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
 * One objective group: its heading, what ordered it, how many it chose from, and its cards.
 *
 * Extracted so the per-platform section renders a group the same way this one does. Two sections
 * drawing «the best creatives» through two components is how they come to disagree about what the
 * order means — the same argument the server makes for ranking them through one service.
 */
function AdGroupBlock({
  group,
  locale,
  currency,
  limit,
  onOpen,
  testidPrefix = 'report-ads',
}: {
  group: AdGroup
  locale: Locale
  currency: string | null
  limit?: number
  onOpen?: (ad: ReportAd) => void
  testidPrefix?: string
}) {
  const ar = locale === 'ar'
  const shown = group.ads.slice(0, limit)

  /*
   * «الأفضل من بين N» — said whenever the group is a CUT of a longer list.
   *
   * `candidates` is optional: a snapshot generated before the server published it carries neither
   * count, and a report already in a client's hands must not start printing a claim about a list
   * nobody counted. Where it is present and larger than what is drawn, the sentence is owed.
   */
  const total = group.candidates ?? null
  const truncated = total !== null && total > shown.length

  return (
    <div data-testid={`${testidPrefix}-group-${group.family}`} className="flex flex-col gap-2">
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="text-sm font-bold text-text-primary">{ar ? group.label_ar : group.label_en}</span>
        <span data-testid={`${testidPrefix}-basis-${group.family}`} className="text-[11px] text-text-muted">
          {group.ranked && group.metric !== null
            ? (ar
                ? `مرتّبة حسب ${group.metric_label_ar ?? group.metric}`
                : `ranked by ${group.metric_label_en ?? group.metric}`)
            : (ar
                ? 'لم تُبلِّغ المنصات عن مقياس يصلح لترتيب إعلانات هذا الهدف — معروضة دون ترتيب.'
                : 'the platforms reported no metric this objective can be ranked on — shown without an order.')}
        </span>
        {truncated && (
          <span data-testid={`${testidPrefix}-of-${group.family}`} className="text-[11px] text-text-muted">
            {ar
              ? `— ${shown.length} من ${total} إعلانًا، وبقيتها في جدول الإعلانات أدناه`
              : `— ${shown.length} of ${total}; the rest are in the creative roster below`}
          </span>
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {shown.map((ad, i) => (
          <AdCard
            key={ad.id ?? `${group.family}-${i}`}
            ad={ad}
            locale={locale}
            currency={currency}
            testidPrefix={`report-ad-poster-${group.family}-${i}`}
            onOpen={onOpen}
          />
        ))}
      </div>
    </div>
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
      <AdPoster preview={ad.preview ?? null} name={ad.name ?? ''} className="h-32 w-full" testid={testidPrefix} forClient />

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
            <dd className="tnum text-xs font-bold text-text-primary"><Num>{f.value}</Num></dd>
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
export function figuresFor(ad: ReportAd, ar: boolean, currency: string | null): { label: string; value: string }[] {
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

  /*
   * Money through the canonical reader, which separates the four states `cash()` cannot see:
   * converted, original-only, a measured zero, and genuinely unreported. `cash(ad.spend)` returned
   * null for a withheld figure and the row was dropped — so real spend vanished off the card — and
   * returned «0» for the coerced zero the builder used to send.
   */
  const spendReading = readMoney(ad as MoneyTotals, 'spend', currency ?? null, ar)
  const spendText = formatMoneyReading(spendReading, (value, unit) => {
    const text = n(value, 0)

    return text === null ? '—' : (unit ? `${text} ${unit}` : text)
  })

  if (spendReading.kind !== 'absent') {
    out.push({ label: ar ? 'الإنفاق' : 'Spend', value: spendText })
  }

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

/**
 * REPORT-DETAIL-PARITY-001 — the creatives, platform by platform.
 *
 * ## What the detailed report was missing
 *
 * The report ended in one gallery of «الأعلى أداءً», grouped by objective across every platform. It
 * answers «what worked» and cannot answer «what works HERE» — and the second is the question an
 * agency takes into next month's plan: the ad to make more of on TikTok is rarely the ad to make
 * more of on Google, and a merged list hands back whichever platform happened to rank highest.
 *
 * ## Why the nesting is platform → objective and never platform alone
 *
 * Ranking a brand film against a sales ad because they ran on the same platform is the defect the
 * objective grouping was written to remove, one axis over: one ordering across objectives can only
 * rest on a metric they share, and what they share is spend. The server groups it that way and this
 * renders what it sends, through the same group component the objective gallery uses — two
 * components drawing «the best creatives» is how two sections come to mean different things by it.
 *
 * ## Detailed only
 *
 * The dashboard is the concise view and already carries the objective gallery. A second gallery of
 * the same creatives on the same page would be the duplication the live report was just cleaned of.
 */
export function ReportPlatformCreatives({
  platforms,
  metrics,
  currency,
  locale,
  limit,
  onOpen,
}: {
  platforms?: AdPlatformGroup[]
  /**
   * The platform ROWS, so each block can open with that platform's own figures.
   *
   * Passed in rather than fetched: these are the same rows the comparison table reads, and a second
   * read of the same section is a second chance for two parts of one page to disagree about one
   * number. Optional, because the printed deck renders this section from a stored snapshot whose
   * platform rows it already holds elsewhere.
   */
  metrics?: Array<Record<string, unknown>>
  currency?: string | null
  locale: Locale
  limit?: number
  onOpen?: (ad: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const rows = platforms ?? []

  /*
   * A single platform gets no section.
   *
   * «Per platform» on an account that runs one platform is the gallery above with a platform name
   * over it — the same creatives, a second time, under a heading that promises a comparison the
   * account cannot make. An empty section says so; a redundant one says nothing and costs a screen.
   */
  if (rows.length < 2) {
    return null
  }

  return (
    <section data-testid="report-platform-creatives" className="flex flex-col gap-4">
      <h3 className="text-base font-bold text-text-primary">
        {ar ? 'الأعلى أداءً في كل منصة' : 'What performed best, platform by platform'}
      </h3>

      {rows.map((platform) => (
        <div
          key={platform.provider}
          data-testid={`report-platform-creatives-${canonicalPlatform(platform.provider)}`}
          className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4"
        >
          <div className="flex flex-wrap items-baseline gap-2">
            <span className="font-heading text-sm font-extrabold text-text-primary">
              {providerLabel(canonicalPlatform(platform.provider), locale)}
            </span>
            {typeof platform.candidates === 'number' && (
              <span className="text-[11px] text-text-muted">
                {ar
                  ? `${platform.candidates} إعلانًا في هذه الفترة`
                  : `${platform.candidates} creative(s) in this window`}
              </span>
            )}
          </div>

          {/*
            The platform's own figures, above its creatives.
            
            «What worked here» is only half an answer without «what did here cost and return» — the
            section is the detailed report's per-platform depth, and a gallery with no figures over it
            is the gallery above with a platform name on top.
          */}
          {(() => {
            const row = (metrics ?? []).find((m) => String(m.provider ?? '') === platform.provider)

            return row === undefined ? null : (
              <ReportPlatformSummary platform={row} currency={currency ?? ''} locale={locale} />
            )
          })()}

          {platform.groups.map((group) => (
            <AdGroupBlock
              key={`${platform.provider}-${group.family}`}
              group={group}
              locale={locale}
              currency={currency ?? null}
              limit={limit}
              onOpen={onOpen}
              testidPrefix={`report-platform-${canonicalPlatform(platform.provider)}`}
            />
          ))}
        </div>
      ))}
    </section>
  )
}
