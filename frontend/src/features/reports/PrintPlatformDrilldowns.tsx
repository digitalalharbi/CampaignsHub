import { moneyExact } from '@/features/analytics/format'
import { providerLabel } from '@/features/campaigns/labels'
import { platformColor } from '@/features/analytics/components'
import { canonicalPlatform } from '@/lib/platforms'
import { clientAbsence, posterSource, readPreview, type PreviewReading } from '@/features/content/adPreview'
import type { CreativePreview } from '@/features/content/api'
import type { LiveObjectiveBlock, LivePlatformPayload, LiveShare } from './api'
import type { ReportAd } from './ReportAdsSection'

/**
 * REPORT-DRILLDOWN-001 — the PDF's optional platform drill-down section.
 *
 * Present only when the operator enabled it on the report; the server sends `platform_drilldowns`
 * then and never otherwise, so this renders nothing by default. One block per platform: the objective
 * KPIs, a trend, spend share against outcome share, and best/weakest content with the real picture or
 * the absence wording. It names no campaign — the payload carries none.
 *
 * Static markup only (an inline SVG trend, CSS bars): a printed page has no hover, and a Recharts chart
 * would be one more thing Chromium has to wait on before it may print.
 */

export type PrintPlatformDrilldown = Omit<LivePlatformPayload, 'breakdowns'>

const METRIC_LABELS: Record<string, { ar: string; en: string; kind: 'money' | 'count' | 'ratio' | 'percent' }> = {
  spend: { ar: 'الإنفاق', en: 'Spend', kind: 'money' },
  revenue: { ar: 'الإيرادات', en: 'Revenue', kind: 'money' },
  orders: { ar: 'النتائج', en: 'Results', kind: 'count' },
  cpa: { ar: 'تكلفة النتيجة', en: 'Cost per result', kind: 'money' },
  roas: { ar: 'العائد على الإنفاق', en: 'ROAS', kind: 'ratio' },
  impressions: { ar: 'الظهور', en: 'Impressions', kind: 'count' },
  cpm: { ar: 'تكلفة الألف ظهور', en: 'CPM', kind: 'money' },
  clicks: { ar: 'النقرات', en: 'Clicks', kind: 'count' },
  ctr: { ar: 'نسبة النقر', en: 'CTR', kind: 'percent' },
  cpc: { ar: 'تكلفة النقرة', en: 'CPC', kind: 'money' },
  landing_page_views: { ar: 'زيارات الصفحة', en: 'Landing page views', kind: 'count' },
  cost_per_lpv: { ar: 'تكلفة الزيارة', en: 'Cost per visit', kind: 'money' },
}

const OUTCOME: Record<string, { ar: string; en: string }> = {
  revenue: { ar: 'من الإيرادات', en: 'of revenue' },
  conversions: { ar: 'من النتائج', en: 'of results' },
  clicks: { ar: 'من النقرات', en: 'of clicks' },
  impressions: { ar: 'من الظهور', en: 'of impressions' },
}

function formatMetric(key: string, value: number | null | undefined, currency: string): string {
  if (value === null || value === undefined) return '—'
  const kind = METRIC_LABELS[key]?.kind ?? 'count'
  if (kind === 'money') return moneyExact(value, currency || null)
  if (kind === 'ratio') return `${value.toFixed(2)}×`
  if (kind === 'percent') return `${(value * 100).toFixed(2)}%`

  return value.toLocaleString('en-US', { maximumFractionDigits: 0 })
}

/**
 * A missing picture in the CLIENT's words — never the server's operator note, never how a link failed.
 *
 * SEAM — #453 adds `clientAbsence()` to `adPreview.ts` with these same sentences; once it is on main,
 * this function becomes a call to it. Shape readings (a video with no cover, a catalog ad) describe the
 * ad itself and keep the library's own sentence.
 */
export function printAbsence(reading: PreviewReading, ar: boolean): string {
  /*
   * ONE rule, not a second copy of it.
   *
   * This was `clientAbsence` written out again — the same withheld, expired and default sentences,
   * word for word, and the same delegation to `absenceLabel` above them. A rule kept in two places
   * drifts, and this one had: `clientAbsence` grew an arm for a film with no cover frame and this
   * copy did not, so a printed drilldown rendered its absence span EMPTY for exactly the ads the
   * arm was written for — a blank rectangle with nothing beside it, on a page a client keeps.
   *
   * `clientAbsence` returns a short label as well; a drilldown row has space for the sentence, which
   * is the half it takes.
   */
  return clientAbsence(reading, ar).sentence
}

function Trend({ points, color }: { points: Array<Record<string, unknown>>; color: string }) {
  const key = points.every((p) => typeof p.spend === 'number') ? 'spend' : 'clicks'
  const values = points.map((p) => Number(p[key] ?? 0))
  if (values.length < 2) return null
  const max = Math.max(...values, 1)
  const w = 300
  const h = 60
  const d = values.map((v, i) => `${i === 0 ? 'M' : 'L'}${((i / (values.length - 1)) * w).toFixed(1)},${(h - (v / max) * (h - 4) - 2).toFixed(1)}`).join(' ')

  return (
    <svg data-testid="print-drilldown-trend" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" className="pdd-trend" aria-hidden>
      <path d={d} fill="none" stroke={color} strokeWidth={2} vectorEffect="non-scaling-stroke" />
    </svg>
  )
}

function ShareRow({ label, share, color, testid }: { label: string; share: LiveShare; color: string; testid: string }) {
  const pct = share.share === null ? null : Math.round(share.share * 1000) / 10

  return (
    <div className="pdd-share" data-testid={testid}>
      <span className="pdd-share-pct">{pct === null ? '—' : `${pct}%`}</span>
      <span className="pdd-share-bar">{pct !== null && <span style={{ width: `${Math.max(1, pct)}%`, background: color }} />}</span>
      <span className="pdd-share-label">{label}</span>
    </div>
  )
}

function ContentRow({ ad, ar, currency }: { ad: ReportAd; ar: boolean; currency: string }) {
  const reading = readPreview(ad.preview as CreativePreview | null | undefined, ar)
  const src = posterSource(reading)
  const absence = printAbsence(reading, ar)

  return (
    <li className="pdd-ad" data-testid="print-drilldown-ad">
      <span className="pdd-ad-thumb">
        {src
          ? (
            <img
              src={src}
              alt=""
              referrerPolicy="no-referrer"
              onError={(e) => {
                const note = e.currentTarget.ownerDocument.createElement('span')
                note.className = 'pdd-ad-absent'
                note.textContent = absence
                e.currentTarget.replaceWith(note)
              }}
            />
          )
          : <span className="pdd-ad-absent" data-testid="print-drilldown-absence">{absence}</span>}
      </span>
      <span className="pdd-ad-name">{ad.name ?? '—'}</span>
      <span className="pdd-ad-fig"><bdi dir="ltr">{ad.spend === null || ad.spend === undefined ? '—' : moneyExact(Number(ad.spend), currency || null)}</bdi></span>
      <span className="pdd-ad-fig"><bdi dir="ltr">{ad.conversions === null || ad.conversions === undefined ? '—' : Number(ad.conversions).toLocaleString('en-US')}</bdi></span>
    </li>
  )
}

function ObjectiveKpis({ block, ar, currency }: { block: LiveObjectiveBlock; ar: boolean; currency: string }) {
  const keys = Object.keys(block.metrics).filter((k) => METRIC_LABELS[k])

  return (
    <div className="pdd-kpis" data-testid={`print-drilldown-kpis-${block.path}`}>
      <div className="pdd-kpis-title">{ar ? block.label_ar : block.label_en}</div>
      <dl>
        {keys.map((k) => (
          <div key={k}>
            <dt>{ar ? METRIC_LABELS[k].ar : METRIC_LABELS[k].en}</dt>
            <dd><bdi dir="ltr">{formatMetric(k, block.metrics[k], currency)}</bdi></dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

export function PrintPlatformDrilldown({ block, locale, currency }: { block: PrintPlatformDrilldown; locale: 'ar' | 'en'; currency: string }) {
  const ar = locale === 'ar'
  const platform = canonicalPlatform(block.provider)
  const color = platformColor(platform)
  const name = providerLabel(platform, locale)
  const outcome = OUTCOME[block.shares.outcome.metric] ?? OUTCOME.conversions

  return (
    <section className="pdd" data-testid={`print-drilldown-${platform}`} dir={ar ? 'rtl' : 'ltr'}>
      <style>{PDD_CSS}</style>
      <h2 className="pdd-title"><span className="pdd-dot" style={{ background: color }} />{ar ? `تفصيل ${name}` : `${name} in detail`}</h2>
      <div className="pdd-grid">
        <div>
          {block.objectives.map((b) => <ObjectiveKpis key={b.path} block={b} ar={ar} currency={currency} />)}
        </div>
        <div>
          <div className="pdd-shares">
            {block.shares.spend && <ShareRow testid="print-drilldown-share-spend" label={ar ? 'من الإنفاق' : 'of spend'} share={block.shares.spend} color={color} />}
            <ShareRow testid="print-drilldown-share-outcome" label={ar ? outcome.ar : outcome.en} share={block.shares.outcome} color="#16a34a" />
          </div>
          <Trend points={block.timeseries} color={color} />
        </div>
      </div>
      {[
        { key: 'top', items: block.ads, title: ar ? 'الأفضل أداءً' : 'Best performing' },
        { key: 'weakest', items: block.ads_weakest, title: ar ? 'الأضعف أداءً' : 'Weakest performing' },
      ].filter((list) => list.items.length > 0).map((list) => (
        <div key={list.key} className="pdd-list" data-testid={`print-drilldown-${list.key}`}>
          <div className="pdd-kpis-title">{list.title}</div>
          <ul>{list.items.map((ad, i) => <ContentRow key={`${ad.name}-${i}`} ad={ad} ar={ar} currency={currency} />)}</ul>
        </div>
      ))}
    </section>
  )
}

/** Every enabled platform, in the server's order (largest spend first). Nothing at all when not enabled. */
export function PrintPlatformDrilldowns({ blocks, locale, currency }: { blocks: PrintPlatformDrilldown[] | undefined; locale: 'ar' | 'en'; currency: string }) {
  if (!blocks || blocks.length === 0) return null

  return (
    <div data-testid="print-platform-drilldowns">
      {blocks.map((b) => <PrintPlatformDrilldown key={b.provider} block={b} locale={locale} currency={currency} />)}
    </div>
  )
}

const PDD_CSS = `
.pdd { break-inside: avoid; margin-top: 14pt; font-variant-numeric: tabular-nums; }
.pdd-title { font-size: 13pt; font-weight: 700; margin: 0 0 6pt; display: flex; align-items: center; gap: 6pt; }
.pdd-dot { display: inline-block; width: 9pt; height: 9pt; border-radius: 50%; }
.pdd-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 10pt; }
.pdd-kpis { border: 1px solid #e5e7eb; border-radius: 6pt; padding: 6pt 8pt; margin-bottom: 6pt; }
.pdd-kpis-title { font-size: 9pt; font-weight: 700; color: #334155; margin-bottom: 4pt; }
.pdd-kpis dl { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4pt 8pt; margin: 0; }
.pdd-kpis dt { font-size: 7.5pt; color: #6b7280; }
.pdd-kpis dd { margin: 0; font-size: 10.5pt; font-weight: 800; }
.pdd-shares { display: grid; gap: 5pt; margin-bottom: 6pt; }
.pdd-share { display: grid; grid-template-columns: 38pt 1fr 62pt; align-items: center; gap: 6pt; font-size: 8.5pt; }
.pdd-share-pct { font-weight: 800; }
.pdd-share-bar { height: 7pt; background: #eef2f6; border-radius: 4pt; overflow: hidden; }
.pdd-share-bar span { display: block; height: 100%; border-radius: 4pt; }
.pdd-share-label { color: #6b7280; }
.pdd-trend { width: 100%; height: 60pt; border-bottom: 1px solid #e5e7eb; }
.pdd-list { margin-top: 8pt; }
.pdd-list ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 4pt; }
.pdd-ad { display: grid; grid-template-columns: 70pt 1fr 70pt 40pt; align-items: center; gap: 6pt; font-size: 8.5pt; break-inside: avoid; }
.pdd-ad-thumb img { width: 66pt; height: 38pt; object-fit: cover; border-radius: 3pt; display: block; }
.pdd-ad-absent { display: inline-block; font-size: 7pt; line-height: 1.25; color: #6b7280; }
.pdd-ad-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
`
