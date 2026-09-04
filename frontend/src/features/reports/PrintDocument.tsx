import { useEffect } from 'react'
import type { ReportData } from './InteractiveReport'
import { moneyExact } from '@/features/analytics/format'

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
type AdRow = {
  name?: string | null
  provider?: string | null
  spend?: number | null
  conversions?: number | null
  preview?: unknown
}

/** Why a picture is not here, in the words the library uses for the same state. */
const PRINT_ABSENCE: Record<string, string> = {
  withheld: 'Preview link carries a credential',
  expired: 'Platform link expired',
  unavailable: 'Platform does not expose the file',
  no_media: 'Platform sent no file',
}

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

const KPI_LABELS: Record<string, string> = {
  spend: 'Spend', revenue: 'Revenue', results: 'Results', roas: 'ROAS',
  cpa: 'Cost per Result', ctr: 'CTR', impressions: 'Impressions', clicks: 'Clicks',
}

function Table({ head, rows }: { head: string[]; rows: (string | number)[][] }) {
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

export function PrintDocument({
  data,
  reportName,
  currency,
  clientName,
}: {
  data: ReportData
  reportName: string
  currency: string
  clientName?: string
}) {
  useEffect(() => {
    document.documentElement.setAttribute('dir', 'ltr')
    document.documentElement.setAttribute('lang', 'en')
    document.title = `CampaignsHub — ${currency} Report`
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
  const kpiRows = Object.entries(kpis)
    .filter(([, v]) => v != null)
    .map(([k, v]) => [
      KPI_LABELS[k] ?? k,
      k === 'spend' || k === 'revenue' || k === 'cpa' ? money(v as number, currency)
        : k === 'roas' ? `${nfmt(v as number, { maximumFractionDigits: 2 })}×`
        : k === 'ctr' ? `${nfmt(v as number, { maximumFractionDigits: 2 })}%`
        : nfmt(v as number),
    ] as [string, string])

  const platformRows = (data.platforms ?? []).map((p: Row) => [
    String(p.platform ?? p.name ?? '—'),
    money(Number(p.spend ?? 0), currency),
    money(Number(p.revenue ?? 0), currency),
    nfmt(Number(p.results ?? 0)),
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
      p.result_metrics_apply && p.cpa != null ? money(Number(p.cpa), currency) : '—',
    ])

  /*
    The same rows the interactive deck shows, read the same way: `available` is the only state that
    carries a picture, and the other three carry their own sentence rather than an empty frame.
  */
  const adRows = ((data.ads ?? []) as AdRow[]).slice(0, 12).map((ad) => {
    const preview = (ad.preview ?? null) as { state?: string; thumbnail_url?: string | null; image_url?: string | null; note_en?: string | null } | null
    const usable = preview?.state === 'available' ? (preview.thumbnail_url ?? preview.image_url ?? null) : null

    return {
      thumb: usable,
      absence: preview?.note_en ?? PRINT_ABSENCE[preview?.state ?? 'unavailable'] ?? PRINT_ABSENCE.unavailable,
      name: String(ad.name ?? '—'),
      provider: String(ad.provider ?? '—'),
      spend: ad.spend === null || ad.spend === undefined ? '—' : money(Number(ad.spend), currency),
      results: ad.conversions === null || ad.conversions === undefined ? '—' : nfmt(Number(ad.conversions)),
    }
  })

  const adsAbsence = PRINT_ADS_ABSENT[String(data.ads_absent_reason ?? 'no_ads_to_show')] ?? PRINT_ADS_ABSENT.no_ads_to_show

  // Keyed by PLATFORM since CLIENT-REPORT-ENTITY-BOUNDARY-001; an old snapshot's per-campaign rows
  // never arrive here, because `ClientReportView` empties them rather than printing them anonymous.
  const budgetRows = (data.budget ?? []).map((b: Row) => [
    String(b.provider ?? '—'),
    money(Number(b.budget ?? 0), currency),
    money(Number(b.spend ?? 0), currency),
    money(Number(b.budget ?? 0) - Number(b.spend ?? 0), currency),
    `${nfmt((Number(b.spend ?? 0) / Math.max(1, Number(b.budget ?? 0))) * 100, { maximumFractionDigits: 0 })}%`,
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
  /** The printed number of a section — counted over what is actually printed, never over the list. */
  const numbers = new Map<string, number>()
  outline.filter((s) => s.present).forEach((s, i) => numbers.set(s.key, i + 1))
  const heading = (key: string, fallback: string): string => {
    const s = section(key)
    const title = s ? s.title_en : fallback
    const n = numbers.get(key)

    return n === undefined ? title : `${n}. ${title}`
  }
  /** An absent section prints WHY, in the words the generator chose. */
  const Absent = ({ sectionKey, fallback }: { sectionKey: string; fallback: string }) => {
    const s = section(sectionKey)
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

      {/* Title block */}
      <header className="doc-cover">
        <div className="doc-brand">CampaignsHub</div>
        <h1>{reportName}</h1>
        <div className="doc-sub">{clientName ?? 'Client Report'}</div>
        <dl className="doc-facts">
          <div><dt>Period</dt><dd>{dateFmt(data.period?.from)} → {dateFmt(data.period?.to)}</dd></div>
          <div><dt>Currency</dt><dd>{currency}</dd></div>
          <div><dt>Objective</dt><dd>{data.objective ?? '—'}</dd></div>
          <div><dt>Platforms</dt><dd>{(data.platforms ?? []).map((p: Row) => String(p.platform ?? p.name)).join(', ') || '—'}</dd></div>
        </dl>
      </header>

      {/* Executive summary */}
      {section('executive_summary')?.present !== false && (
        <section className="doc-section">
          <h2>{heading('executive_summary', 'Executive Summary')}</h2>
          {(data.summary ?? []).map((s, i) => <p key={i}>{s}</p>)}
          <h3>Key metrics</h3>
          <Table head={['Metric', 'Value']} rows={kpiRows} />
        </section>
      )}
      <Absent sectionKey="executive_summary" fallback="No summary could be composed from this period’s figures." />

      {/* Platform performance */}
      {section('platforms')?.present !== false && (
        <section className="doc-section">
          <h2>{heading('platforms', 'Platform Performance')}</h2>
          <Table head={['Platform', 'Spend', 'Revenue', 'Results', 'ROAS']} rows={platformRows} />
        </section>
      )}
      <Absent sectionKey="platforms" fallback="No platform reported figures in this window." />

      {/* Breakdown by objective — the same spend, divided by what it was bought for. */}
      {section('objectives')?.present !== false && objectiveRows.length > 0 && (
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
        CLIENT-REPORT-ENTITY-BOUNDARY-001 — the campaign table is gone from the printed document.

        It listed every campaign by internal name, with its status, across as many pages as it took —
        in the PDF a client forwards. The platform table above and the objective figures below carry
        what the money did; the roster carried how it was arranged, which is ours.
      */}
      {/* Budget */}
      {budgetRows.length > 0 && (
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
      {adRows.length > 0 ? (
        <section className="doc-section">
          <h2>{heading('ads', 'Ads')}</h2>
          <table className="doc-table doc-ads">
            <thead>
              {/* No «Campaign» column — CLIENT-REPORT-ENTITY-BOUNDARY-001. */}
              <tr><th>Preview</th><th>Ad</th><th>Platform</th><th>Spend</th><th>Results</th></tr>
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
                  <td>{ad.provider}</td>
                  <td>{ad.spend}</td>
                  <td>{ad.results}</td>
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

      {/* Recommendations */}
      {recs.length > 0 && (
        <section className="doc-section">
          <h2>{heading('recommendations', 'Recommendations')}</h2>
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
        </section>
      )}

      {/* Appendix */}
      <section className="doc-section doc-appendix">
        <h2>Appendix A — Methodology &amp; Notes</h2>
        <p>
          Figures are aggregated from connected advertising platforms for the stated period. ROAS is
          revenue divided by spend; Cost per Result is spend divided by attributed results. Currency
          is {currency}. Numbers use Western digits.
        </p>
        {data.attribution_window && <p>Attribution window: {data.attribution_window}.</p>}
        {data.data_source && <p>Data source: {data.data_source}.</p>}
        <p className="doc-disclaimer">
          This document is prepared for the client named above and reflects the campaign data available
          at generation time. Past performance does not guarantee future results.
        </p>
      </section>
    </div>
  )
}

const DOC_CSS = `
@page { size: A4 portrait; margin: 18mm 16mm; }
.doc-root { font-family: Inter, system-ui, sans-serif; color: #1a1a1a; font-size: 10.5pt; line-height: 1.5;
  /* Disable ligatures/contextual alternates: Chromium can emit a ligature glyph without a
     complete ToUnicode, which drops letters from copy/search. Individual glyphs keep their map. */
  font-variant-ligatures: none; font-feature-settings: "liga" 0, "calt" 0, "dlig" 0; }
.doc-cover { padding-bottom: 18pt; margin-bottom: 18pt; border-bottom: 2px solid #2563eb; }
.doc-brand { font-weight: 700; color: #2563eb; letter-spacing: .04em; text-transform: uppercase; font-size: 10pt; }
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
