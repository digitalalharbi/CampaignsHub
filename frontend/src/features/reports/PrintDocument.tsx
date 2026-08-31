import { useEffect } from 'react'
import type { ReportData } from './InteractiveReport'

/**
 * English, LTR, A4-portrait DOCUMENT rendering of a report (distinct from the RTL 16:9 slide
 * deck). Continuous document flow with multi-page tables: repeated table headers
 * (`thead { display: table-header-group }`), rows never split (`break-inside: avoid`), an
 * appendix, and an English footer/disclaimer. Used when the print route is asked for
 * `type=document`. Publishes the same readiness + layout signals Chromium waits on.
 */

type Row = Record<string, number | string | null | undefined>

/** One ad as the generator emits it — the fields this document prints, and its preview block. */
type AdRow = {
  name?: string | null
  provider?: string | null
  campaign_name?: string | null
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

const money = (n: number | null | undefined, currency: string) =>
  n == null ? '—' : new Intl.NumberFormat('en-US', { style: 'currency', currency, maximumFractionDigits: 0 }).format(n)

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
      campaign: String(ad.campaign_name ?? '—'),
      spend: ad.spend === null || ad.spend === undefined ? '—' : money(Number(ad.spend), currency),
      results: ad.conversions === null || ad.conversions === undefined ? '—' : nfmt(Number(ad.conversions)),
    }
  })

  const adsAbsence = PRINT_ADS_ABSENT[String(data.ads_absent_reason ?? 'no_ads_to_show')] ?? PRINT_ADS_ABSENT.no_ads_to_show

  const campaignRows = (data.campaigns ?? []).map((c: Row) => [
    String(c.name ?? c.client_display_name ?? '—'),
    String(c.platform ?? '—'),
    String(c.status ?? '—'),
    money(Number(c.spend ?? 0), currency),
    nfmt(Number(c.results ?? 0)),
    c.cpa == null ? '—' : money(Number(c.cpa), currency),
  ])

  const budgetRows = (data.budget ?? []).map((b: Row) => [
    String(b.name ?? b.platform ?? '—'),
    money(Number(b.budget ?? 0), currency),
    money(Number(b.spend ?? 0), currency),
    money(Number(b.budget ?? 0) - Number(b.spend ?? 0), currency),
    `${nfmt((Number(b.spend ?? 0) / Math.max(1, Number(b.budget ?? 0))) * 100, { maximumFractionDigits: 0 })}%`,
  ])

  const recs = (data.recommendations ?? []).filter((r) => (r as { status?: string }).status === 'approved' || !(r as { status?: string }).status)

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
      <section className="doc-section">
        <h2>1. Executive Summary</h2>
        {(data.summary ?? []).map((s, i) => <p key={i}>{s}</p>)}
        <h3>Key metrics</h3>
        <Table head={['Metric', 'Value']} rows={kpiRows} />
      </section>

      {/* Platform performance */}
      <section className="doc-section">
        <h2>2. Platform Performance</h2>
        <Table head={['Platform', 'Spend', 'Revenue', 'Results', 'ROAS']} rows={platformRows} />
      </section>

      {/* Campaigns — the multi-page table */}
      <section className="doc-section">
        <h2>3. Campaigns</h2>
        <Table head={['Campaign', 'Platform', 'Status', 'Spend', 'Results', 'CPA']} rows={campaignRows} />
      </section>

      {/* Budget */}
      {budgetRows.length > 0 && (
        <section className="doc-section">
          <h2>4. Budget Pacing</h2>
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
          <h2>5. Ads</h2>
          <table className="doc-table doc-ads">
            <thead>
              <tr><th>Preview</th><th>Ad</th><th>Platform</th><th>Campaign</th><th>Spend</th><th>Results</th></tr>
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
                  <td>{ad.campaign}</td>
                  <td>{ad.spend}</td>
                  <td>{ad.results}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ) : (
        <section className="doc-section">
          <h2>5. Ads</h2>
          <p>{adsAbsence}</p>
        </section>
      )}

      {/* Recommendations */}
      {recs.length > 0 && (
        <section className="doc-section">
          <h2>6. Recommendations</h2>
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
