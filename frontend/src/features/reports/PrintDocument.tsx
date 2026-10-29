import { REPORT_NOTE, reportFooterItems } from './reportFooter'
import { selectColumns } from './reportColumns'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { hasPlatformMark } from '@/components/brand/PlatformMark'
import { ProviderName } from '@/components/ProviderMark'
import { useEffect, type ReactNode } from 'react'
import type { ReportData } from './InteractiveReport'
import { moneyExact } from '@/features/analytics/format'
import { mixedResultsNote, type ResultPart } from './reportMetrics'
import { brand } from '@/lib/brand'
import { ReportWatermark } from './ReportWatermark'
import { drawableFamilies, formatKpi, formatRankingValue, rankingMetricLabel, type ObjectiveRanking, type RankingEnd } from './objectiveAnalytics'
import { AttentionBlocks } from './AttentionBlocks'
import { dailyRecordsChosen, sectionShown, type PrintEvidence, type ReportSectionKey } from './reportSections'
import { CampaignsHubMark } from '@/components/brand/CampaignsHubMark'
import { clientAbsence, posterSource, readPreview } from '@/features/content/adPreview'
import { metricState } from '@/features/content/metrics'
import type { CreativeMetrics, CreativePreview } from '@/features/content/api'
import { PrintPlatformDrilldowns } from './PrintPlatformDrilldowns'
import { reportPageTitle } from './sharedBranding'

/**
 * English, LTR, A4-portrait DOCUMENT rendering of a report (distinct from the RTL 16:9 slide
 * deck). Continuous document flow with multi-page tables: repeated table headers
 * (`thead { display: table-header-group }`), rows never split (`break-inside: avoid`), an
 * appendix, and an English footer/disclaimer. Used when the print route is asked for
 * `type=document`. Publishes the same readiness + layout signals Chromium waits on.
 */

type Row = Record<string, number | string | null | undefined>

/**
 * One section of the report's own outline, as `ReportStructure` emits it.
 *
 * `absent_reason` is always present as a key and null when the section is — a renderer that has to
 * ask whether the key exists before reading it is one that will print «undefined» to a client.
 */
type OutlineSection = {
  key: string
  title_ar: string
  title_en: string
  present: boolean
  absent_reason: string | null
  absent_reason_en?: string | null
  absent_reason_ar?: string | null
}

/** One ad as the generator emits it — the fields this document prints, and its preview block. */
/** REPORT-LINK-SECTION-001 — one short link, as the generator wrote it down. */
type LinkRow = {
  short_url: string
  destination: string
  /** Measured INSIDE this report's window. */
  follows: number
  /** The link's lifetime counter — carried for context, never summed with the above. */
  clicks_all_time: number
}

type AdRow = {
  name?: string | null
  provider?: string | null
  spend?: number | null
  conversions?: number | null
  /**
   * The figures bag, where this row carries one — CONTENT-RESULT-AVAILABILITY-001.
   *
   * The generator builds these rows through `CreativeRows`, so the availability the Content library
   * reads is already in the payload. This type did not admit it, which is why the document printed
   * the raw `conversions` column and could show a measured 0 where the card shows a dash.
   */
  metrics?: CreativeMetrics | null
  preview?: unknown
}

/** Why a picture is not here, in the words the library uses for the same state. */
const PRINT_ADS_ABSENT: Record<string, string> = {
  no_creatives_in_window: 'No ad-level rows were recorded in this window — the figures above are at campaign level.',
  no_rankable_metric_for_this_objective: 'The platforms reported no metric this objective can be ranked on, so no ranked ads are shown.',
  no_ads_to_show: 'There are no ads to show for this window.',
}

const nfmt = (n: number | null | undefined, opts?: Intl.NumberFormatOptions) =>
  n == null ? '—' : new Intl.NumberFormat('en-US', opts).format(n)

/*
 * The printed document states money the way the screen does — MONEY-SCOPE-TRUTH-001.
 *
 * This printed «$7,420» through `style: 'currency'` while the report it is a copy OF printed
 * «7.75K USD». A PDF that a client files beside the link they were sent must not restate the same
 * figure in a different notation — and `$` is the symbol of a dozen currencies, so the reader cannot
 * even confirm they match. `moneyExact` is used rather than the compact form because a document is
 * read once and kept: the precise figure is what somebody checks against an invoice.
 */
const money = (n: number | null | undefined, currency: string) => moneyExact(n, currency || null)

const dateFmt = (s?: string) =>
  s ? new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(s)) : '—'

// RESULT-EVENTS-001 — the platform's generic conversion and its cost carry their own names; «Results» is the link's defined event only.
const KPI_LABELS: Record<string, string> = {
  spend: 'Spend', revenue: 'Revenue', results: 'Results', roas: 'ROAS',
  cpa: 'Cost per platform conversion', conversions: 'Platform conversions', ctr: 'CTR', impressions: 'Impressions', clicks: 'Clicks',
  cost_per_result: 'Cost per result',
}

/**
 * Owner, 2026-10-19 — a platform in the printed document is its logo and its name. A string that is not a
 * known platform (an ad account's name, «—») stays text; nothing is invented to fill a mark.
 */
function platformCell(raw: unknown): ReactNode {
  const text = String(raw ?? '—')
  const key = canonicalPlatform(text)

  return hasPlatformMark(key) ? <ProviderName provider={key} locale="en" size={14} /> : text
}

/** WIDGET-REGISTRY-001 — a link's widgets as print tables. Fractions are kept: they are credit shares, not whole orders. */
function printedWidgets(data: ReportData): Array<{ key: string; title: string; head: string[]; rows: ReactNode[][]; note: string }> {
  const list = (data as unknown as { widgets?: Array<{ key: string; data: Record<string, unknown> }> }).widgets ?? []
  const out: Array<{ key: string; title: string; head: string[]; rows: ReactNode[][]; note: string }> = []

  for (const w of list) {
    if (w.key === 'link_opens') {
      const series = (w.data.series as Array<{ period: string; opens: number }> | undefined) ?? []
      out.push({
        key: w.key,
        title: 'Link Activity',
        head: ['Period', 'Opens'],
        rows: [...series.map((p) => [p.period, p.opens] as ReactNode[]), ['Total', Number(w.data.opens ?? 0)]],
        note: `Measured since ${String(w.data.tracking_since ?? '—')}. Link opens, not sales.`,
      })
    }
    if (w.key === 'attribution_credit') {
      const models = (w.data.models as string[] | undefined) ?? []
      const channels = (w.data.channels as Array<{ channel: string; models: Record<string, { orders: number }> }> | undefined) ?? []
      const cov = (w.data.coverage as Record<string, number> | undefined) ?? {}
      out.push({
        key: w.key,
        title: 'Attribution by Channel',
        head: ['Channel', ...models],
        rows: channels.map((c) => [c.channel, ...models.map((m) => c.models[m]?.orders ?? '')] as ReactNode[]),
        note: `Orders tied to verified evidence only; ${cov.orders_without_evidence ?? 0} order(s) have none and stay unattributed. A click is not a purchase.`,
      })
    }
  }

  return out
}

function Table({ head, rows }: { head: string[]; rows: ReactNode[][] }) {
  return (
    <table className="doc-table">
      <thead>
        <tr>{head.map((h) => <th key={h}>{h}</th>)}</tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i}>{r.map((c, j) => <td key={j} className={j === 0 ? 'lead' : 'num'}>{c}</td>)}</tr>
        ))}
      </tbody>
    </table>
  )
}

/**
 * Has the branding chain fallen through to the platform itself?
 *
 * Compared against the brand's own name rather than a literal, so renaming the product in one place
 * cannot leave this comparing against a word nobody uses any more.
 */
function isPlatformIdentity(name?: string | null): boolean {
  /*
   * BOTH spellings of the product's name, because it now has two.
   *
   * This compared against the English name and the locale-agnostic one. Once the platform's fallback
   * started answering «كامبينز هب» on an Arabic document — which is the point of localising it —
   * that name would no longer have been recognised as the PLATFORM's, and the document would have
   * treated the product's own name as though it were a client's agency branding.
   *
   * A second-order break from this unit's own change, which is why it is listed here beside the
   * others rather than left for the renderer to get subtly wrong.
   */
  const own = [brand.lockup.nameEn, brand.lockup.nameAr, brand.name]

  return !name || own.includes(name.trim())
}

/**
 * A result cell for the printed page, read through the availability the server stated.
 *
 * CONTENT-RESULT-AVAILABILITY-001 §14 — «one creative + same account + same period must tell the
 * same factual story everywhere», and the forwarded PDF is the copy that gets quoted and filed. It
 * read the raw column, so a zero the Content card shows as «—» printed here as a measured 0: the
 * same number on the two surfaces, meaning opposite things, in the document a client keeps.
 *
 * `metrics` is absent on the ranked ad rows and present on the roster, so both shapes are accepted
 * and the flat value is the fallback rather than the rule.
 */
function printedResult(
  metrics: CreativeMetrics | null | undefined,
  flat: number | null | undefined,
  key = 'conversions',
): string {
  if (metrics) {
    const state = metricState(metrics, key)

    return state.kind === 'value' ? nfmt(state.value) : '—'
  }

  return flat === null || flat === undefined ? '—' : nfmt(Number(flat))
}

export function PrintDocument({
  data,
  reportName,
  currency,
  clientName,
  identity,
  watermark = false,
  trace = null,
  reportLocale,
}: {
  data: ReportData
  reportName: string
  /** The report's own language — the file is titled in it (REPORT BRANDING). */
  reportLocale?: string
  currency: string
  clientName?: string
  /** Asked for by the share and minted by the server; see `ReportWatermark`. */
  watermark?: boolean
  /** IP-TRACE-001 — see `ReportWatermark`. */
  trace?: string | null
  /**
   * BRANDING-RENDER-EVIDENCE-001 — whose report this is, resolved by `headerIdentity()`.
   *
   * The cover printed the word «CampaignsHub», hard-coded, on every document PDF ever generated —
   * so an agency's white-labelled client report announced the product across the top of the first
   * page. The payload has carried the resolved identity all along and `PrintReport` was already
   * using it for the PDF's metadata title and for the slide deck; the document layout was simply
   * never given it. That is the row's own warning made real: code containing `logo_url` is not the
   * same thing as a logo rendering.
   *
   * Optional, and falling back to the product's name, because `headerIdentity()` ends the
   * client → agency → CampaignsHub chain there — this is the last link of that chain, not a second
   * default competing with it.
   */
  identity?: { name: string; logoUrl: string | null; by: string | null; byLogoUrl?: string | null }
}) {
  useEffect(() => {
    document.documentElement.setAttribute('dir', 'ltr')
    document.documentElement.setAttribute('lang', 'en')
    // The file's own title — «CampaignsHub» here put the product in the title bar and beside the
    // attachment in a mail client, on a report an agency sends to its own client under its own name.
    // REPORT BRANDING (Owner): the report's name ending with the product's, in the report's language.
    document.title = reportPageTitle(reportName, reportLocale)
    const w = window as Window & {
      __REPORT_DATA_READY__?: boolean; __REPORT_CHARTS_READY__?: boolean
      __REPORT_IMAGES_READY__?: boolean; __REPORT_LAYOUT__?: unknown
    }
    // No live charts in the document layout; data is inline. Signal readiness after fonts settle.
    void document.fonts.ready.then(() => {
      w.__REPORT_DATA_READY__ = true
      w.__REPORT_CHARTS_READY__ = true
      w.__REPORT_IMAGES_READY__ = true
      const overflowX = document.documentElement.scrollWidth > document.documentElement.clientWidth + 2
      w.__REPORT_LAYOUT__ = [
        { page: 1, overflow: false, overflowX, empty: false, clippedElements: 0, scaledBelowReadableLimit: false, fitScale: 1 },
      ]
    })
  }, [currency])

  const kpis = data.kpis ?? {}
  // RESULT-EVENTS-001 — Results exist in the file only where the link defines them, under its own name; the file lists only the KPIs the
  // link shows (the same allowlist the CSV and XLSX use), so a figure the link hides is not printed.
  const resultsDefined = (data.results as { configured?: boolean } | undefined)?.configured === true
  const resultsLabel = typeof data.results_label === 'string' && data.results_label.trim() !== '' ? data.results_label.trim() : 'Results'
  const allow = Array.isArray(data.kpi_allowlist) ? (data.kpi_allowlist as string[]) : null
  const kpiRows = Object.entries(kpis)
    .filter(([, v]) => v != null)
    .filter(([k]) => resultsDefined || (k !== 'results' && k !== 'cost_per_result'))
    .filter(([k]) => allow === null || allow.includes(k))
    .map(([k, v]) => [
      k === 'results' ? resultsLabel : k === 'cost_per_result' ? `Cost per ${resultsLabel}` : KPI_LABELS[k] ?? k,
      k === 'spend' || k === 'revenue' || k === 'cpa' || k === 'cost_per_result' ? money(v as number, currency)
        : k === 'roas' ? `${nfmt(v as number, { maximumFractionDigits: 2 })}×`
        : k === 'ctr' ? `${nfmt(v as number, { maximumFractionDigits: 2 })}%`
        : nfmt(v as number),
    ] as [string, string])

  // The platform's DEFINED result from the resolver; no Results column at all where the link defines none (never generic conversions).
  const definedFor = (p: Row) => (data.results as { by_platform?: Record<string, { results?: number | null }> } | undefined)?.by_platform?.[String(p.provider ?? p.platform ?? '').toLowerCase()]?.results
  const platformRows = (data.platforms ?? []).map((p: Row) => [
    platformCell(p.platform ?? p.name ?? '—'),
    money(Number(p.spend ?? 0), currency),
    money(Number(p.revenue ?? 0), currency),
    ...(resultsDefined ? [definedFor(p) === null || definedFor(p) === undefined ? '—' : nfmt(Number(definedFor(p)))] : []),
    `${nfmt(Number(p.roas ?? 0), { maximumFractionDigits: 2 })}×`,
  ])

  /*
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the depth that replaces the roster, in the printed document.
   *
   * The outline has listed an «objectives» section since REPORT-ANALYTICAL-DEPTH-001 and this
   * document never drew one: the PDF numbered a heading it did not print. It mattered less while a
   * campaign table sat below it. With that gone, the objective split is what carries the answer to
   * «what was the money bought for, and what did that cost» — and it is the axis a client's spend is
   * actually judged on, so it belongs in the copy they keep.
   *
   * Only the paths money was spent on: a path at zero is an absence, not a row of zeroes. The cost
   * per result is stated only where the path was bought for a result — an awareness path prints «—»
   * rather than being ranked on a sales metric it was never asked about.
   */
  const objectiveRows = ((data.objective_performance as { paths?: Row[] } | undefined)?.paths ?? [])
    .filter((p) => Number(p.spend ?? 0) > 0)
    .map((p: Row) => [
      String(p.label_en ?? p.path ?? '—'),
      money(Number(p.spend ?? 0), currency),
      nfmt(Number(p.impressions ?? 0)),
      nfmt(Number(p.clicks ?? 0)),
      nfmt(Number(p.orders ?? 0)),
      /*
       * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — on paper the blend has to be written out.
       *
       * The conversion path is Leads, App installs, Add to cart, Sales, Conversions and Purchases
       * together, so this cost per result can be a lead's price averaged with a sale's — always
       * downwards. The screen can carry the reason in a `title`; a printed page cannot be hovered,
       * so the parts are spelled into the cell itself and the reader sees what was averaged.
       */
      p.result_metrics_apply && p.cpa != null
        ? money(Number(p.cpa), currency)
          + (((p as Record<string, unknown>).cpa_mixes_result_types === true
            && mixedResultsNote((p as Record<string, unknown>).result_composition as ResultPart[] | undefined, false) !== null)
            ? ` (mixed: ${mixedResultsNote((p as Record<string, unknown>).result_composition as ResultPart[] | undefined, false)!.parts})`
            : '')
        : '—',
    ])

  /*
    The same rows the interactive deck shows, read the same way: `available` is the only state that
    carries a picture, and the other three carry their own sentence rather than an empty frame.
  */

  /*
    REPORT-LINK-SECTION-001 — read straight off the payload, like every other row in this document.
    A renderer that recomputed would be a second answer, and the first time it disagreed with the
    interactive report the client would be holding the evidence.
  */
  const linkRows = ((data.links ?? []) as LinkRow[]).slice(0, 20)

  const adRows = ((data.ads ?? []) as AdRow[]).slice(0, 12).map((ad) => {
    /*
      CONTENT-PREVIEW-FIT-001 §14 — the SAME resolver the library asks, not a second chain.

      This read `thumbnail_url ?? image_url` off the envelope, and `readPreview` knows three things
      that chain does not: a video whose poster is the only thing that arrived, a collection whose
      hero is a film, and a catalog ad that is missing nothing by design. So the printed deck could
      show an empty cell for a creative the library draws a picture of — «one creative, same period,
      same scope» failing on the one document a client keeps.

      The absence sentence below has always come from the shared reading. Only the picture did not.
    */
    const reading = readPreview(ad.preview as CreativePreview | null | undefined, false)

    return {
      thumb: posterSource(reading),
      /*
       * CLIENT-DIAGNOSTIC-SEPARATION-001 — the forwarded file says a missing picture in the client's
       * words, as every other client surface does: never the server's operator note, and never how
       * our platform link failed.
       */
      absence: clientAbsence(reading, false).sentence,
      name: String(ad.name ?? '—'),
      provider: String(ad.provider ?? '—'),
      spend: ad.spend === null || ad.spend === undefined ? '—' : money(Number(ad.spend), currency),
      results: printedResult(ad.metrics as CreativeMetrics | null | undefined, ad.conversions),
    }
  })

  const adsAbsence = PRINT_ADS_ABSENT[String(data.ads_absent_reason ?? 'no_ads_to_show')] ?? PRINT_ADS_ABSENT.no_ads_to_show

  /*
   * REPORT-CREATIVE-TRUTH-001 §B — the inventory, beside the ranking, in the forwarded document.
   *
   * The table above is the TOP twelve and is meant to be: the section is «Ads» and it answers which
   * ones worked. What the printed report had no answer for at all was «what did we run», and a PDF
   * is the copy that gets forwarded, quoted and filed — the worst surface on which to leave a client
   * assuming twelve was the whole month.
   *
   * A summary prints the count and not the list: the deck form is curated on purpose, and sixty-five
   * rows in a five-page document would be the opposite of what it is for.
   */
  const isSummary = String(data.form ?? 'detailed') === 'executive_summary'
  const inScope = Number(data.creatives_in_scope ?? 0)
  const withheld = Number(data.creatives_withheld ?? 0)
  const hiddenByOwner = Number(data.creatives_hidden ?? 0)
  const rosterRows = isSummary
    ? []
    : (data.ads_roster ?? []).map((row) => ({
      name: String(row.name ?? '—'),
      provider: String(row.provider ?? '—'),
      spend: row.metrics?.spend === null || row.metrics?.spend === undefined ? '—' : money(Number(row.metrics.spend), currency),
      impressions: row.metrics?.impressions === null || row.metrics?.impressions === undefined ? '—' : nfmt(Number(row.metrics.impressions)),
      clicks: row.metrics?.clicks === null || row.metrics?.clicks === undefined ? '—' : nfmt(Number(row.metrics.clicks)),
      results: printedResult(row.metrics as CreativeMetrics | null | undefined, row.metrics?.conversions),
    }))

  // Keyed by PLATFORM since CLIENT-REPORT-ENTITY-BOUNDARY-001; an old snapshot's per-campaign rows
  // never arrive here, because `ClientReportView` empties them rather than printing them anonymous.
  /*
   * The server's own verdict is printed, not a second arithmetic.
   *
   * These rows come from `budgetPacingByProvider`, which emits `spent` — this read `b.spend`, a key
   * that is never sent, so `?? 0` applied on EVERY row: the document a client receives showed a
   * spend of zero against every platform, a remaining equal to the whole budget, and 0% utilization,
   * for accounts that had spent the money.
   *
   * The utilization was recomputed here over `Math.max(1, budget)`, which turned a platform with
   * spend and no budget into a percentage in the hundreds of thousands rather than a refusal. The
   * aggregator already states `remaining` and `consumed_pct` and already withholds both — as null —
   * when the figures are not comparable, so printing that null as a dash is both simpler and the
   * only version that cannot disagree with the deck and the client's live link.
   */
  const budgetCell = (v: unknown) => (v === null || v === undefined ? '—' : money(Number(v), currency))
  const budgetRows = (data.budget ?? []).map((b: Row) => [
    String(b.provider ?? '—'),
    budgetCell(b.budget),
    budgetCell(b.spent),
    budgetCell(b.remaining),
    b.consumed_pct === null || b.consumed_pct === undefined
      ? '—'
      : `${nfmt(Number(b.consumed_pct) * 100, { maximumFractionDigits: 0 })}%`,
  ])

  const recs = (data.recommendations ?? []).filter((r) => (r as { status?: string }).status === 'approved' || !(r as { status?: string }).status)

  /*
   * REPORT-ANALYTICAL-DEPTH-001 — the document's sections come from the report's OWN outline.
   *
   * The numbering here was hardcoded — «1. Executive Summary», «2. Platform Performance» — and the
   * order with it, so a report whose generator had nothing to say about platforms still printed the
   * heading with an empty table under it, and the numbers stepped over whatever was skipped.
   *
   * `outline` is derived from the assembled snapshot by `ReportStructure`, which also says WHY a
   * section is absent. Reading it here means the deck, the client's link and this document cannot
   * disagree about what the report contains — and an absent section prints its reason instead of an
   * empty table, which is the difference between «there were no campaigns in this window» and a
   * document that looks broken.
   */
  const outline = (data.outline ?? []) as OutlineSection[]
  const section = (key: string): OutlineSection | undefined => outline.find((s) => s.key === key)
  const shown = (key: ReportSectionKey) => sectionShown(data, key)

  /*
   * Which of the outline's sections THIS document prints as a numbered section — decided once, and
   * read by both the headings below and the numbering here, so the two cannot disagree.
   *
   * The generator's outline lists more than this document prints: `performance` is emitted second
   * whenever a window has figures and `findings` seventh, and neither is a numbered section on this
   * page — the KPI block sits under the executive summary, and findings travel with the
   * recommendations. Counting over the outline therefore printed «1. Executive Summary» and then
   * «3. Platform Performance» on every real report: the «stepped over» defect this numbering was
   * written against, in its other direction, and invisible to a fixture whose outline carried only
   * the keys the page prints. The operator's section switches and an empty table are part of the
   * same decision — a section switched off is not printed, so it is not counted either.
   */
  const prints: Record<string, boolean> = {
    executive_summary: section('executive_summary')?.present !== false,
    platforms: shown('platform_comparison') && section('platforms')?.present !== false,
    objectives: shown('advanced_segmentation') && section('objectives')?.present !== false && objectiveRows.length > 0,
    ads: shown('content_performance') && adRows.length > 0,
    links: linkRows.length > 0,
    recommendations: shown('recommendations') && (recs.length > 0 || (data.attention?.length ?? 0) > 0),
  }

  /** The printed number of a section — counted over what is actually printed, never over the list. */
  const numbers = new Map<string, number>()
  outline.filter((s) => s.present && prints[s.key] === true).forEach((s, i) => numbers.set(s.key, i + 1))
  const heading = (key: string, fallback: string): string => {
    const s = section(key)
    const title = s ? s.title_en : fallback
    const n = numbers.get(key)

    return n === undefined ? title : `${n}. ${title}`
  }
  /** An absent section prints WHY, in the words the generator chose. */
  /*
   * REPORT-SECTION-SURFACES-001 — a section the report does not carry is not printed, and its
   * absence is not explained either: the page, the link and this document all leave it out.
   */
  const OUTLINE_SECTION: Record<string, ReportSectionKey> = { platforms: 'platform_comparison', objectives: 'advanced_segmentation', ads: 'content_performance' }

  const Absent = ({ sectionKey, fallback }: { sectionKey: string; fallback: string }) => {
    const s = section(sectionKey)
    if (OUTLINE_SECTION[sectionKey] && !shown(OUTLINE_SECTION[sectionKey])) return null
    if (s === undefined || s.present) return null

    return (
      <section className="doc-section" data-absent={sectionKey}>
        <h2>{s.title_en}</h2>
        <p>{s.absent_reason_en ?? fallback}</p>
      </section>
    )
  }

  return (
    <div className="doc-root">
      <style>{DOC_CSS}</style>
      {/*
        `flow`, not `page` — this layout is one continuous document Chromium paginates, so there is
        no per-page element to anchor to and an absolute overlay would mark sheet one alone.

        `ar` on a document this file sets `dir="ltr"` on is deliberate, not an oversight. This layout
        is bilingual rather than English — its own caller already passes `headerIdentity(branding,
        'ar')` for the same reason recorded there: with no locale the identity answers «CampaignsHub»,
        so the document a client keeps credits the platform in Latin. The watermark follows the
        identity it sits behind; a mark in one script over a credit in another would read as two
        products.
      */}
      {watermark && <ReportWatermark locale="ar" mode="flow" trace={trace} />}

      {/* Title block */}
      <header className="doc-cover">
        {/*
          The mark where there is one, the name where there is not — never both, and never an
          `<img src="">`, which re-requests the page in some browsers and draws a broken icon in
          others. On a client's report a broken icon reads as «the report failed», which is worse
          than showing no mark at all.
        */}
        {identity?.logoUrl ? (
          <img src={identity.logoUrl} alt={identity.name} className="doc-logo" data-testid="print-document-logo" />
        ) : isPlatformIdentity(identity?.name) ? (
          /*
            BRAND-LOCKUP-001 — when the hierarchy reaches ITS last link, draw the mark.

            `headerIdentity` resolves client → agency → CampaignsHub, so a name of «CampaignsHub»
            here means no tenant branding was found, and this report is the product's own. Printing
            the word where the logo belongs was the last place the identity was text pretending to
            be a mark. A tenant's name still prints as a name: their brand is not ours to redraw.
          */
          <div className="doc-brand inline-flex items-center gap-2" data-testid="print-document-brand">
            {/*
              The MARK beside the name, not instead of it.

              `printDocumentBranding.test.tsx` requires this cover to SAY «CampaignsHub» when the
              chain falls through to the product — it is the last link of client → agency →
              CampaignsHub and a cover with no words is not a cover. So the name stays exactly as it
              was and gains the symbol it was standing in for.
            */}
            <CampaignsHubMark size={22} className="text-brand-mark" />
            <span>{identity?.name ?? brand.lockup.nameEn}</span>
          </div>
        ) : (
          <div className="doc-brand" data-testid="print-document-brand">{identity?.name ?? brand.lockup.nameEn}</div>
        )}
        {/*
          The agency is named secondarily, never in place of the client — the same rule the shared
          link follows, so a reader moving between the link and its PDF meets one hierarchy.

          REPORT-IDENTITY-001 — «Prepared by» rather than «by». A printed document that a client
          files or forwards has to say which of the two names on its cover made it; «by» is a byline
          and reads as an author credit on a page whose subject is somebody else.
        */}
        {identity?.by && (
          <div className="doc-by inline-flex items-center gap-1.5" data-testid="print-document-by">
            Prepared by {identity.by}
            {identity.byLogoUrl && (
              <img src={identity.byLogoUrl} alt="" data-testid="print-document-agency-logo" className="doc-by-logo" />
            )}
          </div>
        )}
        <h1>{reportName}</h1>
        {/* And the subject is named as the subject — the other half of the same question. */}
        <div className="doc-sub" data-testid="print-document-for">
          {clientName === undefined ? 'Client Report' : `Prepared for ${clientName}`}
        </div>
        <dl className="doc-facts">
          <div><dt>Period</dt><dd>{dateFmt(data.period?.from)} → {dateFmt(data.period?.to)}</dd></div>
          <div><dt>Currency</dt><dd>{currency}</dd></div>
          <div><dt>Objective</dt><dd>{data.objective ?? '—'}</dd></div>
          <div><dt>Platforms</dt><dd>{(data.platforms ?? []).map((p: Row) => String(p.provider ?? p.platform ?? p.name ?? '')).filter(Boolean).join(', ') || '—'}</dd></div>
        </dl>
      </header>

      {/* Executive summary */}
      {prints.executive_summary && (
        <section className="doc-section">
          <h2>{heading('executive_summary', 'Executive Summary')}</h2>
          {(data.summary ?? []).map((s, i) => <p key={i}>{s}</p>)}
          {shown('kpis') && (
            <>
              <h3>Key metrics</h3>
              <Table head={['Metric', 'Value']} rows={kpiRows} />
            </>
          )}
        </section>
      )}
      <Absent sectionKey="executive_summary" fallback="No summary could be composed from this period’s figures." />

      {/* Breakdown by objective — the same spend, divided by what it was bought for. */}
      {prints.objectives && (
        <section className="doc-section">
          <h2>{heading('objectives', 'Breakdown by Objective')}</h2>
          <Table
            head={['Objective', 'Spend', 'Impressions', 'Clicks', 'Results', 'Cost per result']}
            rows={objectiveRows}
          />
        </section>
      )}
      <Absent sectionKey="objectives" fallback="Nothing was spent on any objective in this window." />
      {/*
        REPORT-OBJECTIVE-ANALYTICS-001 — one block per objective family, from the same snapshot section
        the screen draws: its own KPIs, strongest and weakest platform and content above a minimum
        volume, and each platform's share of the family's outcome. No figure spans two families.
      */}
      {section('objectives')?.present !== false && drawableFamilies(data.objective_analytics).map((block) => {
        const tiles = block.kpis
        const ends = (ranking: ObjectiveRanking | null | undefined, content: boolean): string | null => {
          if (!ranking?.best || !ranking.weakest) return null
          const who = (e: RankingEnd) => content
            ? `${e.name || 'Content'} (${String(e.provider ?? '')})`
            : String(e.provider ?? '')
          const metric = rankingMetricLabel(ranking.metric, false)

          return `Strongest: ${who(ranking.best)} — ${metric} ${formatRankingValue(ranking.metric, ranking.best.value, currency)} · `
            + `Weakest: ${who(ranking.weakest)} — ${metric} ${formatRankingValue(ranking.metric, ranking.weakest.value, currency)}`
        }
        const platformEnds = ends(block.platform_ranking, false)
        const contentEnds = ends(block.content_ranking, true)
        const contribution = block.contribution && block.contribution.rows.some((r) => r.share !== null) ? block.contribution : null

        return (
          <section key={block.family} className="doc-section" data-testid={`print-objective-family-${block.family}`}>
            <h3>{block.label_en}</h3>
            <Table head={tiles.map((k) => k.label_en)} rows={[tiles.map((k) => formatKpi(k, currency))]} />
            {platformEnds && <p>Platforms — {platformEnds}</p>}
            {contentEnds && <p>Content — {contentEnds}</p>}
            {contribution && (
              <Table
                head={['Platform', `Share of ${contribution.label_en.toLowerCase()}`]}
                rows={contribution.rows.map((r) => [platformCell(r.provider), r.share === null ? '—' : `${(r.share * 100).toFixed(1)}%`])}
              />
            )}
          </section>
        )
      })}

      {/*
        Platform performance — AFTER the objective split, as the outline orders it.

        CLIENT-FACING-PRESENTATION-001: «at what cost, really» (the objective split) is read before
        «where» (this table). The deck and the live link have read them in that order since the owner
        decided it; this page numbered from an outline that said the opposite, and so printed the
        platform table above the split it is supposed to follow.
      */}
      {prints.platforms && (
        <section className="doc-section">
          <h2>{heading('platforms', 'Platform Performance')}</h2>
          <Table {...selectColumns(data.report_columns?.platforms, resultsDefined ? ['', 'spend', '', 'results', ''] : ['', 'spend', '', ''], resultsDefined ? ['Platform', 'Spend', 'Revenue', resultsLabel, 'ROAS'] : ['Platform', 'Spend', 'Revenue', 'ROAS'], platformRows)} />
        </section>
      )}
      <Absent sectionKey="platforms" fallback="No platform reported figures in this window." />

      {/* REPORT-DRILLDOWN-001 — each platform in detail, only when the operator enabled the section. */}
      {(data.platform_drilldowns?.length ?? 0) > 0 && (
        <section className="doc-section">
          <h2>Platforms in Detail</h2>
          <PrintPlatformDrilldowns blocks={data.platform_drilldowns} locale="en" currency={currency} />
        </section>
      )}

      {/*
        REPORT-ACCOUNT-BREAKDOWN-001 — the provider → ad-account split, printed only when the report's own
        section settings carry it (the same switch the page, the link and the deck read).
      */}
      {shown('account_breakdown') && ((data as { accounts?: Array<Record<string, unknown>> }).accounts ?? []).length > 0 && (
        <section className="doc-section" data-testid="print-accounts">
          <h2>Ad Accounts</h2>
          <Table
            // An account has no defined Results of its own: no Results column, and never the account's generic conversions under that name.
            head={['Ad account', 'Platform', 'Spend']}
            rows={((data as { accounts?: Array<Record<string, unknown>> }).accounts ?? []).map((a) => [
              String(a.account_name ?? 'Unnamed account'),
              platformCell(a.provider),
              moneyExact(typeof a.spend === 'number' ? a.spend : null, currency),
            ])}
          />
        </section>
      )}

      {/* REPORT-COMPOSITION-CONTROL-001 — the daily ledger, only where the report's section list names it. */}
      {dailyRecordsChosen(data) && (data.timeseries ?? []).length > 0 && (
        <section className="doc-section" data-testid="print-daily-records">
          <h2>Daily Records</h2>
          <Table
            {...selectColumns(
              data.report_columns?.days,
              ['', 'impressions', 'clicks', 'results', 'spend'],
              ['Day', 'Impressions', 'Clicks', 'Results', ...((data.timeseries ?? []).some((d: Row) => typeof d.spend === 'number') ? ['Spend'] : [])],
              [...(data.timeseries ?? [])].sort((a: Row, b: Row) => String(a.date ?? '').localeCompare(String(b.date ?? ''))).map((d: Row) => [
              String(d.date ?? '—'),
              typeof d.impressions === 'number' ? d.impressions.toLocaleString('en-US') : '—',
              typeof d.clicks === 'number' ? d.clicks.toLocaleString('en-US') : '—',
              typeof d.conversions === 'number' ? d.conversions.toLocaleString('en-US') : '—',
              ...((data.timeseries ?? []).some((x: Row) => typeof x.spend === 'number') ? [moneyExact(typeof d.spend === 'number' ? d.spend : null, currency)] : []),
              ]),
            )}
          />
        </section>
      )}

      {/* REPORT-SECTION-STREAMS-001 — advanced segmentation, in the operator's own stream names. */}
      {shown('advanced_segmentation') && (data.business_streams ?? []).length > 0 && (
        <section className="doc-section">
          <h2>Performance by Business Stream</h2>
          <Table
            head={['Stream', 'Spend', 'Results', 'Share of spend']}
            rows={(data.business_streams ?? []).map((s) => [
              s.label,
              moneyExact(typeof s.figures.spend === 'number' ? s.figures.spend : null, currency),
              typeof s.figures.conversions === 'number' ? s.figures.conversions.toLocaleString('en-US') : '—',
              s.share_of_spend === null ? '—' : `${Math.round(s.share_of_spend * 100)}%`,
            ])}
          />
          <p>{data.business_streams_cover_total ? 'The streams add up to the total spend.' : 'The streams do not cover every account, so their sum is not the total spend.'}</p>
        </section>
      )}

      {/*
        CLIENT-REPORT-ENTITY-BOUNDARY-001 — the campaign table is gone from the printed document.

        It listed every campaign by internal name, with its status, across as many pages as it took —
        in the PDF a client forwards. The platform table above and the objective figures below carry
        what the money did; the roster carried how it was arranged, which is ours.
      */}
      {/* Budget */}
      {shown('budget_pacing') && budgetRows.length > 0 && (
        <section className="doc-section">
          <h2>Budget Pacing</h2>
          <Table head={['Line', 'Budget', 'Spent', 'Remaining', 'Pacing']} rows={budgetRows} />
        </section>
      )}

      {/*
        REPORT-AD-PREVIEW-001 — the ads, in the document that gets forwarded.

        A printed page cannot use the interactive section: `AdPoster` renders states, hover and a
        dialog, and the PDF is produced by Chromium from static markup. What it CAN do — and what
        parity means here — is print the same ads, in the same order, with the same figures and the
        same sentence when a picture cannot be shown. The thumbnail is an `<img>` only when the
        preview says `available`; every other state prints its reason, because a grey box in a
        client's PDF reads as a broken export.
      */}
      {!shown('content_performance') ? null : prints.ads ? (
        <section className="doc-section">
          <h2>{heading('ads', 'Ads')}</h2>
          <table className="doc-table doc-ads">
            <thead>
              {/* No «Campaign» column — CLIENT-REPORT-ENTITY-BOUNDARY-001. */}
              <tr><th>Preview</th><th>Ad</th><th>Platform</th><th>Spend</th></tr>
            </thead>
            <tbody>
              {adRows.map((ad, i) => (
                <tr key={i}>
                  <td className="doc-ad-thumb">
                    {ad.thumb
                      ? (
                        /*
                          A picture that fails becomes the sentence, not a broken frame: an expired
                          signed URL is the ordinary case here, and a grey box in a document a client
                          keeps reads as a failed export rather than as the honest absence it is.
                        */
                        <img
                          src={ad.thumb}
                          alt=""
                          referrerPolicy="no-referrer"
                          onError={(e) => {
                            const img = e.currentTarget
                            const note = img.ownerDocument.createElement('span')
                            note.className = 'doc-ad-absent'
                            note.textContent = ad.absence
                            img.replaceWith(note)
                          }}
                        />
                      )
                      : <span className="doc-ad-absent">{ad.absence}</span>}
                  </td>
                  <td>{ad.name}</td>
                  <td>{platformCell(ad.provider)}</td>
                  <td>{ad.spend}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ) : (
        <section className="doc-section">
          <h2>{section('ads')?.title_en ?? 'Ads'}</h2>
          {/* The generator's own reason, not this file's guess at one. */}
          <p>{section('ads')?.absent_reason_en ?? adsAbsence}</p>
        </section>
      )}

      {/*
        REPORT-LINK-SECTION-001 — the addresses the money pointed at, printed where they belong.

        A short link is the one artefact of a campaign that LEAVES this product, and a report could
        describe a month of spend without ever naming one. It prints after the ads because a link is
        where an ad SENT somebody.

        Two columns of counting, never summed: «Follows» is what was measured INSIDE this report's
        window, and «All time» is the link's lifetime counter. Adding them would count a year of
        clicks into a month. Where the window closed before follows were recorded, the generator
        reports the section absent with its own reason and this prints that sentence instead of a
        column of zeroes — see the absent branch below.
      */}
      {prints.links ? (
        <section className="doc-section">
          <h2>{heading('links', 'Short links')}</h2>
          <table className="doc-table">
            <thead>
              <tr><th>Link</th><th>Destination</th><th>Follows</th><th>All time</th></tr>
            </thead>
            <tbody>
              {linkRows.map((l, i) => (
                <tr key={i}>
                  {/*
                    No `dir="ltr"` on the cell: this document is already laid out LTR, and a `dir` on
                    a block box re-bases the whole box rather than isolating the value inside it —
                    which is what `ltrNumeralAlignment` guards and what the first version of these
                    two cells got wrong.
                  */}
                  <td>{l.short_url}</td>
                  <td>{l.destination}</td>
                  <td>{l.follows.toLocaleString('en-US')}</td>
                  <td>{l.clicks_all_time.toLocaleString('en-US')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ) : section('links') !== undefined && section('links')?.present === false ? (
        <section className="doc-section">
          <h2>{section('links')?.title_en ?? 'Short links'}</h2>
          {/* The generator's own reason — «not recorded» and «none in scope» are different facts. */}
          <p>{section('links')?.absent_reason_en}</p>
        </section>
      ) : null}

      {/*
        CONTENT-FORMAT-ROAS-REMOVED-001 — «Content format performance» is out of the client's copy.

        Two reasons, and either alone is enough.

        The figure was not sound: it divided creative-grain revenue by creative-grain spend, and
        while spend is attributed to every creative in full, revenue at that grain is reported by the
        platform only sometimes. The ratio was therefore a fraction of the truth over all of it — it
        printed «0.02» in a document whose own campaign figures read 5x, with nothing reconciling
        them.

        And it is the wrong KIND of thing for this document: «جميع التقارير مقدمة للعميل — يجب أن
        يرى البيانات التي تخص نتائج الحملة، وليست تحليلية». A table ranking formats by a derived
        ratio is the agency's working analysis, not the client's result.
      */}

      {/*
        REPORT-CREATIVE-TRUTH-001 §B — everything that ran, under the twelve that worked best.
      */}
      {shown('content_performance') && inScope > 0 && (
        <section className="doc-section">
          <h2>Everything That Ran</h2>
          <p>
            {inScope} {inScope === 1 ? 'creative' : 'creatives'} ran in this period
            {withheld > 0 ? ` — ${inScope - withheld} listed here, ${withheld} not shown` : ''}
            {isSummary ? '. This is an executive summary; the full list is in the detailed report.' : '.'}
          </p>
          {hiddenByOwner > 0 && (
            <p data-testid="print-content-hidden">
              {hiddenByOwner} {hiddenByOwner === 1 ? 'creative' : 'creatives'} hidden by the report owner on this link — the totals in this document include them.
            </p>
          )}
          {rosterRows.length > 0 && (
            <Table
              {...selectColumns(
                data.report_columns?.content,
                // A content's generic conversion is not its Result; the file prints none per content (the page reads its verified one).
                ['', 'platform', 'spend', 'impressions', 'clicks'],
                ['Creative', 'Platform', 'Spend', 'Impressions', 'Clicks'],
                rosterRows.map((r) => [r.name, platformCell(r.provider), r.spend, r.impressions, r.clicks]),
              )}
            />
          )}
        </section>
      )}

      {/*
        WIDGET-REGISTRY-001 — the widgets the link shows on the web, as the same tables the spreadsheet carries.
        Only what the server put in the document: a link whose owner switched nothing on prints nothing here.
      */}
      {printedWidgets(data).map((w) => (
        <section key={w.key} className="doc-section" data-testid={`print-widget-${w.key}`}>
          <h2>{w.title}</h2>
          <Table head={w.head} rows={w.rows} />
          <p>{w.note}</p>
        </section>
      ))}

      {/* Recommendations */}
      {prints.recommendations && (
        <section className="doc-section">
          <h2>{heading('recommendations', 'Recommendations')}</h2>
          {/* REPORT-RECOMMENDATION-BLOCKS-001 — the same blocks as the deck and the links, laid out open. */}
          <AttentionBlocks items={data.attention} ar={false} />
          {recs.length > 0 && (
          <ol className="doc-recs">
            {recs.map((r, i) => {
              const body = (r as { detail?: string; body?: string }).detail ?? (r as { body?: string }).body
              return (
                <li key={i}>
                  <strong>{r.title}</strong>
                  {body && <p>{body}</p>}
                </li>
              )
            })}
          </ol>
          )}
        </section>
      )}

      {/*
        REPORT-FOOTER-001 — no appendix section: one small muted footer, outside the numbered body, with the
        report's facts under it. It is not counted as a section and has no heading.
      */}
      {/* WD-EVIDENCE-001 — the platform screenshots the Owner included, as the document's appendix. */}
      {((data as { platform_evidence?: PrintEvidence[] }).platform_evidence ?? []).length > 0 && (
        <section className="doc-section" data-testid="print-evidence">
          <h2>Appendix: Platform Screenshots</h2>
          <p>Shown for reference. Every figure in this report comes from the platforms' structured data, not from these images.</p>
          {((data as { platform_evidence?: PrintEvidence[] }).platform_evidence ?? []).map((e) => (
            <figure key={e.id} data-testid={`print-evidence-${e.id}`} style={{ breakInside: 'avoid', margin: '12px 0' }}>
              {e.image && e.url ? <img src={e.url} alt={`${providerLabel(e.provider, 'en')} screenshot`} style={{ maxWidth: '100%', maxHeight: '180mm', objectFit: 'contain' }} /> : <p>PDF attachment: {e.name ?? '—'}</p>}
              <figcaption>
                {providerLabel(e.provider, 'en')}
                {e.period_start && e.period_end ? ` · ${e.period_start} → ${e.period_end}` : ''}
                {e.note ? ` · ${e.note}` : ''}
              </figcaption>
            </figure>
          ))}
        </section>
      )}

      <footer className="doc-footnote" data-testid="print-document-footer">
        <p>{REPORT_NOTE.en}</p>
        <p>
          {reportFooterItems({
            updatedAt: typeof data.generated_at === 'string' ? data.generated_at : null,
            period: data.period ?? null,
            currency,
            timezone: typeof data.timezone === 'string' ? data.timezone : 'Asia/Riyadh',
            objective: typeof data.objective === 'string' ? data.objective : null,
          }, 'en').map(([k, v]) => `${k}: ${v}`).join(' | ')}
        </p>
      </footer>
    </div>
  )
}

const DOC_CSS = `
.doc-footnote { margin: 18pt auto 0; max-width: 150mm; text-align: center; padding: 6pt 10pt; border: 0.5pt solid #d4d4d8; border-radius: 6pt; font-size: 8pt; line-height: 1.45; color: #71717a; }
.doc-footnote p { margin: 0 0 2pt; }
@page { size: A4 portrait; margin: 18mm 16mm; }
.doc-root { font-family: Inter, system-ui, sans-serif; color: #1a1a1a; font-size: 10.5pt; line-height: 1.5;
  /* Disable ligatures/contextual alternates: Chromium can emit a ligature glyph without a
     complete ToUnicode, which drops letters from copy/search. Individual glyphs keep their map. */
  font-variant-ligatures: none; font-feature-settings: "liga" 0, "calt" 0, "dlig" 0; }
.doc-cover { padding-bottom: 18pt; margin-bottom: 18pt; border-bottom: 2px solid #2563eb; }
.doc-brand { font-weight: 700; color: #2563eb; letter-spacing: .04em; text-transform: uppercase; font-size: 10pt; }
/* Bounded, so a tall or very wide upload cannot push the title off the cover page. */
/*
  REPORT-IDENTITY-PROPORTION-001 — a PLATE, not a ceiling.

  max-height/max-width bounded the artwork without giving it a shape, so a tall crest printed as a
  few millimetres of colour beside a wordmark that filled the line. The height is fixed now and the
  width is bounded at both ends, so every mark prints in the same band and a narrow one keeps a
  readable footprint instead of a hairline. Same scale as BrandMark on screen.
*/
.doc-logo { height: 48px; min-width: 56px; max-width: 200px; object-fit: contain; object-position: left center; display: block; }
.doc-by-logo { height: 20px; min-width: 28px; max-width: 96px; object-fit: contain; object-position: left center; display: inline-block; }
.doc-by { font-size: 8pt; color: #6b7280; letter-spacing: .02em; margin-top: 2px; }
.doc-cover h1 { font-size: 22pt; font-weight: 700; margin: 6pt 0 2pt; }
.doc-sub { color: #555; font-size: 12pt; }
.doc-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 4pt 24pt; margin-top: 14pt; }
.doc-facts div { display: flex; gap: 8pt; }
.doc-facts dt { font-weight: 700; color: #444; min-width: 64pt; }
.doc-facts dd { margin: 0; }
.doc-section { margin-top: 16pt; break-inside: auto; }
.doc-section h2 { font-size: 14pt; font-weight: 700; margin: 0 0 8pt; padding-bottom: 4pt; border-bottom: 1px solid #e5e7eb; break-after: avoid; }
.doc-section h3 { font-size: 11pt; font-weight: 700; margin: 10pt 0 4pt; break-after: avoid; }
.doc-section p { margin: 0 0 6pt; }
.doc-table { width: 100%; border-collapse: collapse; margin: 6pt 0 4pt; font-size: 9.5pt; }
.doc-table thead { display: table-header-group; }
.doc-ads td.doc-ad-thumb { width: 84pt; }
.doc-ads td.doc-ad-thumb img { width: 78pt; height: 44pt; object-fit: cover; border-radius: 3pt; }
.doc-ad-absent { display: inline-block; max-width: 78pt; font-size: 7.5pt; line-height: 1.25; color: #6b7280; }  /* repeat header on every page */
.doc-table tr { break-inside: avoid; }             /* never split a row across pages */
.doc-table th { text-align: left; background: #f1f5f9; color: #334155; font-weight: 700; padding: 5pt 7pt; border-bottom: 1.5px solid #cbd5e1; }
.doc-table td { padding: 4.5pt 7pt; border-bottom: 1px solid #eef2f6; }
.doc-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.doc-table td.lead { font-weight: 600; }
.doc-recs { margin: 0; padding-left: 18pt; }
.doc-recs li { margin-bottom: 8pt; break-inside: avoid; }
.doc-recs p { margin: 2pt 0 0; color: #444; }
.doc-appendix { break-before: page; }
.doc-disclaimer { margin-top: 10pt; font-size: 9pt; color: #666; font-style: italic; }
`
