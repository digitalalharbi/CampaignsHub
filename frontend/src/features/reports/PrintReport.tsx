import { useEffect, useMemo, useState } from 'react'
import { fmtDate } from '@/lib/datetime'
import { useParams, useSearchParams } from 'react-router-dom'
import { getData } from '@/lib/api/client'
import { SlideBody, isClientAudience, type Meta, type ReportData, type Slide } from './InteractiveReport'
import { PrintDocument } from './PrintDocument'
import { headerIdentity, type SharedBranding } from './sharedBranding'
import { PerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

interface PrintPayload {
  report_id: string
  name: string
  type: 'presentation' | 'document'
  theme: 'light' | 'dark'
  audience: string
  currency: string
  is_demo: boolean
  checksum: string | null
  data_version: number | null
  data: ReportData
  /**
   * BRANDING-HIERARCHY-001 — whose document this is, resolved server-side.
   *
   * Resolved there and not here because this route is sessionless by design: headless Chromium
   * fetches it with a short-lived token and no cookie, so it has no tenant to resolve anything with.
   */
  branding?: SharedBranding
}

/**
 * Headless-print route (/reports/print/:token). Renders the SAME slide components as the interactive
 * deck — one visible slide per printed page — then signals readiness so Chromium prints only after the
 * data, fonts, charts and images are all settled. Never shown in the app shell.
 */
export function PrintReport() {
  const { token = '' } = useParams()
  const [sp] = useSearchParams()
  const [payload, setPayload] = useState<PrintPayload | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    getData<PrintPayload>(`/reports/print/${token}`).then(setPayload).catch(() => {
      setFailed(true)
      ;(window as Window & { __REPORT_ERROR__?: boolean }).__REPORT_ERROR__ = true
    })
  }, [token])

  const type = (sp.get('type') as 'presentation' | 'document') || payload?.type || 'presentation'
  const theme = (sp.get('theme') as 'light' | 'dark') || payload?.theme || 'light'

  const slides = useMemo<Slide[]>(() => {
    const d = payload?.data
    if (!d) return []
    const visible = (d.slides ?? [])
      .filter((s) => s.visible)
      .filter((s) => s.type !== 'next_steps' || (d.next_steps?.length ?? 0) > 0)
      .sort((a, b) => a.order - b.order)
    if (d.disclaimer) visible.push({ id: '__methodology', type: '__methodology', order: 9999, visible: true })
    return visible
  }, [payload])

  // Apply the report theme + Arabic direction to the document root for the whole print.
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme)
    document.documentElement.setAttribute('dir', 'rtl')
    document.documentElement.setAttribute('lang', 'ar')
  }, [theme])

  // Document title → PDF /Title metadata. INTERNAL audience carries verifiable provenance (report id
  // + checksum + data version) so an audit tool can confirm the snapshot. CLIENT/EXECUTIVE files must
  // NOT leak those internal identifiers into metadata — they get a clean, client-safe title.
  useEffect(() => {
    if (!payload) return
    /*
     * The identity in the PDF's own metadata — BRANDING-HIERARCHY-001.
     *
     * This was «CampaignsHub» for every report, so an agency's client PDF announced the product in
     * the file's title bar and beside the attachment in a mail client. The internal arm keeps its
     * provenance exactly as before: rid/checksum/data-version are what make an internal snapshot
     * auditable, and they are still withheld from client and executive files.
     */
    const who = headerIdentity(payload.branding).name

    document.title =
      isClientAudience(payload.audience)
        ? `${who} — ${payload.currency} Report`
        : `${who} | rid=${payload.report_id} | cs=${payload.checksum ?? ''} | dv=${payload.data_version ?? ''} | cur=${payload.currency}`
  }, [payload])

  // Readiness protocol — Chromium waits on these before printing. Also publishes a per-page layout
  // audit (utilization / overflow / empty / footer) that the print script uses as a hard gate.
  useEffect(() => {
    // The document layout publishes its own readiness/layout signals (see PrintDocument).
    if (!payload || type === 'document') return
    let cancelled = false
    const w = window as Window & {
      __REPORT_DATA_READY__?: boolean; __REPORT_CHARTS_READY__?: boolean; __REPORT_IMAGES_READY__?: boolean; __REPORT_LAYOUT__?: unknown
    }
    ;(async () => {
      w.__REPORT_DATA_READY__ = true
      const fonts = (document as Document & { fonts?: FontFaceSet }).fonts
      if (fonts?.ready) await fonts.ready.catch(() => {})
      await new Promise((r) => setTimeout(r, 500))
      const imgs = Array.from(document.images)
      await Promise.all(imgs.map((img) => (img.complete ? Promise.resolve() : new Promise((res) => {
        img.onload = img.onerror = () => res(null)
        setTimeout(() => res(null), 4000)
      }))))
      if (cancelled) return
      w.__REPORT_CHARTS_READY__ = true
      fitSlides() // scale any slightly-too-tall slide to fit exactly one page (readable floor 0.82)
      await new Promise((r) => setTimeout(r, 120))
      w.__REPORT_LAYOUT__ = measureLayout()
      w.__REPORT_IMAGES_READY__ = true
    })()
    return () => { cancelled = true }
  }, [payload, slides.length])

  if (failed) return <div className="p-10 text-center text-danger">تعذّر تحميل بيانات الطباعة (رمز غير صالح أو منتهٍ).</div>
  if (!payload) return <div className="p-10 text-center text-text-muted">جارٍ التحضير…</div>

  // English, LTR, A4-portrait document flow (distinct from the RTL slide deck).
  if (type === 'document') {
    return (
      <PrintDocument
        data={payload.data}
        reportName={payload.name}
        currency={payload.currency}
      />
    )
  }

  const d = payload.data
  const meta: Meta = { reportName: payload.name, platforms: (d.platforms ?? []).map((p) => String(p.provider)), isDemo: payload.is_demo, agencyName: headerIdentity(payload.branding).name }
  const landscape = type === 'presentation'
  const period = d.period ? `${d.period.from} → ${d.period.to}` : ''
  const mode = (d.mode as string) === 'live' ? 'Live' : 'Snapshot'
  const updated = d.generated_at ? fmtDate(String(d.generated_at)) : ''
  const total = slides.length

  return (
    <div className={`report-print ${type}`} data-theme={theme} dir="rtl" lang="ar">
      <style>{printCss(landscape)}</style>
      {slides.map((s, i) => (
        <section key={s.id} className="report-slide" data-print-page={i + 1} data-slide-type={s.type}>
          <div className="report-slide-inner">
            <SlideBody slide={s} data={d} meta={meta} />
            {/*
              Verifiable provenance on the methodology page — INTERNAL only.

              It read `audience !== 'client'`, which put `checksum`, `data_version`, `daily_metrics`
              and `attribution_window` on the page of the EXECUTIVE file — the one a client's own
              management reads. Twenty lines above, the same file already treats executive as
              client-facing and withholds exactly these fields from the PDF's title, so one document
              was making both statements at once: the metadata said «a client file», the methodology
              page printed the checksum.

              CLIENT-DIAGNOSTIC-SEPARATION-001 settles it, and both places now ask the SAME question:
              an audience that is client-facing gets provenance in the PDF's metadata, where an
              auditor can still find it, and never as visible engineering text.
            */}
            {s.type === '__methodology' && !isClientAudience(payload.audience) && (
              <div className="report-provenance" dir="ltr">
                <bdi>Report {payload.report_id}</bdi> · <bdi>checksum {(payload.checksum ?? '').slice(0, 16)}</bdi> · <bdi>data_version {payload.data_version ?? '—'}</bdi>
                {' · '}<bdi>{d.data_source ?? 'daily_metrics'}</bdi> · <bdi>{d.attribution_window ?? 'default'}</bdi> · <bdi>{payload.currency}</bdi> · <bdi>{d.timezone ?? 'Asia/Riyadh'}</bdi> · <bdi>{mode}</bdi>
              </div>
            )}
          </div>
          {/* Professional per-page footer — never on the cover; one per page, inside the safe area. */}
          {s.type !== 'cover' && (
            <footer className="report-slide-footer">
              <div className="report-footer-note">
                {d.disclaimer && <PerformanceNotice data={d.disclaimer} variant="footer" />}
              </div>
              <div className="report-footer-meta">
                {/* The footer names whoever the report belongs to, for the same reason the title does. */}
                <span className="report-footer-brand">{headerIdentity(payload.branding).name}{payload.is_demo ? ' · Demo' : ''}{period ? ` · ${period}` : ''}</span>
                <span className="report-footer-page">{mode}{updated ? ` · آخر تحديث ${updated}` : ''} · <bdi dir="ltr">{i + 1} / {total}</bdi></span>
              </div>
            </footer>
          )}
        </section>
      ))}
    </div>
  )
}

/**
 * SlideContentFitter: scales a slide's content to fit exactly one page when it is slightly too tall,
 * so nothing bleeds onto a near-empty extra page. Has a readable floor (0.82) — a slide needing more
 * shrink than that is left alone so the layout gate flags it for a real content fix.
 */
function fitSlides() {
  document.querySelectorAll<HTMLElement>('.report-slide').forEach((el) => {
    const inner = el.querySelector<HTMLElement>('.report-slide-inner')
    if (!inner) return
    const cs = getComputedStyle(el)
    const footer = el.querySelector<HTMLElement>('.report-slide-footer')
    const avail = el.clientHeight - (parseFloat(cs.paddingTop) || 0) - (parseFloat(cs.paddingBottom) || 0) - (footer ? footer.offsetHeight + 8 : 0)
    inner.style.transform = ''
    delete inner.dataset.fitScale
    const needed = inner.scrollHeight
    if (needed > avail + 2) {
      const ideal = avail / needed
      // Readable floor: never shrink below 0.85. If the content would need more than that, it is NOT
      // silently crammed — we cap the scale and the layout gate flags "scaledBelowReadableLimit" so the
      // content gets split / trimmed to an Appendix instead.
      const scale = Math.max(0.85, ideal)
      inner.style.transformOrigin = 'top center'
      inner.style.transform = `scale(${scale})`
      inner.dataset.fitScale = String(Math.round((ideal < 0.85 ? ideal : scale) * 1000) / 1000)
    }
  })
}

/**
 * Per-page layout audit read by the print script as a hard gate. Utilization is measured over ACTUAL
 * visible content (text + charts + images) via grid sampling of the safe content area — NOT the page
 * background or a container that stretches to fill. Decorative/absolute/hidden elements and the footer
 * are excluded, so a full-bleed cover gradient does not inflate the ratio.
 */
function measureLayout() {
  const COLS = 48
  const ROWS = 32
  const pages: Array<Record<string, unknown>> = []

  document.querySelectorAll<HTMLElement>('.report-slide').forEach((el, i) => {
    const isCover = !!el.querySelector('.report-cover')
    // Methodology is a legitimate text page; like the cover it is exempt from the "sparse" heuristic.
    const isTextPage = el.getAttribute('data-slide-type') === '__methodology'
    const pr = el.getBoundingClientRect()
    const cs = getComputedStyle(el)
    const padT = parseFloat(cs.paddingTop) || 0
    const padB = parseFloat(cs.paddingBottom) || 0
    const padL = parseFloat(cs.paddingLeft) || 0
    const padR = parseFloat(cs.paddingRight) || 0
    const footer = el.querySelector<HTMLElement>('.report-slide-footer')
    const fr = footer?.getBoundingClientRect()
    // Safe content area: page minus padding, minus the footer band.
    const top = pr.top + padT
    const bottom = (fr ? fr.top - 6 : pr.bottom - padB)
    const left = pr.left + padL
    const right = pr.right - padR
    const areaW = Math.max(1, right - left)
    const areaH = Math.max(1, bottom - top)

    // Collect real content rects (leaf text / charts / images), excluding decoration + footer.
    const scope = el.querySelector<HTMLElement>('.report-slide-inner') ?? el
    const rects: Array<[number, number, number, number, boolean]> = [] // x0,y0,x1,y1,isChart
    scope.querySelectorAll<HTMLElement>('*').forEach((n) => {
      const s = getComputedStyle(n)
      if (s.visibility === 'hidden' || s.display === 'none' || parseFloat(s.opacity) === 0) return
      if (s.position === 'absolute' || s.position === 'fixed') return
      const tag = n.tagName.toLowerCase()
      const isChart = n.classList.contains('recharts-surface') || tag === 'svg' || tag === 'canvas' || tag === 'img'
      const hasText = Array.from(n.childNodes).some((c) => c.nodeType === 3 && (c.textContent ?? '').trim().length > 0)
      if (!isChart && !hasText) return
      const r = n.getBoundingClientRect()
      const x0 = Math.max(r.left, left), y0 = Math.max(r.top, top), x1 = Math.min(r.right, right), y1 = Math.min(r.bottom, bottom)
      if (x1 - x0 > 3 && y1 - y0 > 3) rects.push([x0, y0, x1, y1, isChart])
    })

    // Rasterise onto a grid: 0 empty, 1 text, 2 chart.
    const grid = new Uint8Array(COLS * ROWS)
    const mark = (rc: [number, number, number, number, boolean]) => {
      const c0 = Math.floor(((rc[0] - left) / areaW) * COLS), c1 = Math.ceil(((rc[2] - left) / areaW) * COLS)
      const r0 = Math.floor(((rc[1] - top) / areaH) * ROWS), r1 = Math.ceil(((rc[3] - top) / areaH) * ROWS)
      for (let r = Math.max(0, r0); r < Math.min(ROWS, r1); r++) for (let c = Math.max(0, c0); c < Math.min(COLS, c1); c++) {
        grid[r * COLS + c] = rc[4] ? 2 : Math.max(grid[r * COLS + c], 1)
      }
    }
    rects.forEach(mark)
    let covered = 0, chart = 0, text = 0
    for (let k = 0; k < grid.length; k++) { if (grid[k]) covered++; if (grid[k] === 2) chart++; if (grid[k] === 1) text++ }
    const total = COLS * ROWS
    const contentUtilization = Math.round((covered / total) * 100) / 100
    const chartCoverage = Math.round((chart / total) * 100) / 100
    const textDensity = Math.round((text / total) * 100) / 100
    const largestEmptyRegion = Math.round(largestEmptyRect(grid, COLS, ROWS) * 100) / 100

    // Overflow measured from the POST-fit rendered box (getBoundingClientRect reflects the scale
    // transform), so a fitted slide that now fits is not falsely flagged, but one that hit the
    // readable floor and still spills is.
    const innerRect = scope.getBoundingClientRect()
    const overflow = innerRect.bottom > bottom + 4
    const overflowX = el.scrollWidth > el.clientWidth + 2
    const footerCoverage = fr ? Math.round(((fr.height) / pr.height) * 100) / 100 : 0
    const empty = !isCover && !isTextPage && contentUtilization < 0.1
    const sparse = !isCover && !isTextPage && largestEmptyRegion > 0.42

    // Fit / clipping integrity — overflow:hidden must never HIDE important content.
    const fitScale = scope.dataset.fitScale ? parseFloat(scope.dataset.fitScale) : 1
    const scaledBelowReadableLimit = fitScale < 0.85
    // Count real content elements whose painted box escapes the page content box (would be clipped).
    let clipped = 0
    scope.querySelectorAll<HTMLElement>('*').forEach((n) => {
      const s2 = getComputedStyle(n)
      if (s2.position === 'absolute' || s2.position === 'fixed' || s2.visibility === 'hidden' || s2.display === 'none') return
      const tag = n.tagName.toLowerCase()
      const isChart = n.classList.contains('recharts-surface') || tag === 'svg' || tag === 'canvas' || tag === 'img'
      const hasText = Array.from(n.childNodes).some((c) => c.nodeType === 3 && (c.textContent ?? '').trim().length > 0)
      if (!isChart && !hasText) return
      const r = n.getBoundingClientRect()
      if (r.width < 3 || r.height < 3) return
      if (r.bottom > bottom + 6 || r.top < top - 6 || r.right > right + 6 || r.left < left - 6) clipped++
    })

    pages.push({
      page: i + 1, isCover, contentUtilization, chartCoverage, textDensity, largestEmptyRegion,
      footerCoverage, overflow, overflowX, empty, sparse, footerDetected: !!footer || isCover,
      fitScale, scaledBelowReadableLimit, clippedElements: clipped,
    })
  })
  return pages
}

/** Largest all-empty axis-aligned rectangle area as a fraction of the grid (maximal-rectangle histogram). */
function largestEmptyRect(grid: Uint8Array, cols: number, rows: number): number {
  const heights = new Array(cols).fill(0)
  let best = 0
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) heights[c] = grid[r * cols + c] ? 0 : heights[c] + 1
    // largest rectangle in histogram
    const stack: number[] = []
    for (let c = 0; c <= cols; c++) {
      const h = c === cols ? 0 : heights[c]
      while (stack.length && heights[stack[stack.length - 1]] >= h) {
        const height = heights[stack.pop()!]
        const width = stack.length ? c - stack[stack.length - 1] - 1 : c
        best = Math.max(best, height * width)
      }
      stack.push(c)
    }
  }
  return best / (cols * rows)
}

function printCss(landscape: boolean): string {
  return `
  @page { size: A4 ${landscape ? 'landscape' : 'portrait'}; margin: 0; }
  html, body { background: var(--surface); }
  .report-print { background: var(--surface); color: var(--text-primary); }
  .report-slide {
    position: relative;
    box-sizing: border-box;
    width: 100%;
    /* Exactly one printable page each — height (not min-height) + hidden overflow prevents a slide
       from bleeding a sliver onto a second (near-empty) page. The layout gate guarantees content fits
       before printing, so nothing is clipped in a passing report. */
    height: 100vh;
    padding: ${landscape ? '26px 34px 46px' : '30px 30px 50px'};
    break-after: page; page-break-after: always;
    break-inside: avoid; page-break-inside: avoid;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .report-slide:last-child { break-after: auto; page-break-after: auto; }
  .report-slide-inner { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
  .report-slide-inner > * { flex: 1 1 auto; }
  /* Cover fills its page (no large empty band under it). */
  .report-cover { height: 100%; }
  .report-slide-footer {
    position: absolute; left: 34px; right: 34px; bottom: 12px;
    display: flex; flex-direction: column; gap: 3px;
    border-top: 1px solid var(--border); padding-top: 6px;
  }
  .report-footer-note { font-size: 10px; line-height: 1.4; color: var(--text-muted); }
  .report-footer-note p { margin: 0; }
  .report-footer-meta { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .report-footer-brand, .report-footer-page { font-size: 9.5px; color: var(--text-muted); white-space: nowrap; }
  .report-provenance { margin-top: 14px; padding-top: 8px; border-top: 1px dashed var(--border);
    font-size: 9px; color: var(--text-muted); word-break: break-all; line-height: 1.6; }
  /* Never split a chart, card, or table row across pages. */
  .recharts-wrapper, table, .rounded-2xl, .rounded-xl { break-inside: avoid; page-break-inside: avoid; }
  `
}
