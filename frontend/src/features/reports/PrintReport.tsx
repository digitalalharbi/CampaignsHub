import { useEffect, useMemo, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { getData } from '@/lib/api/client'
import { SlideBody, type Meta, type ReportData, type Slide } from './InteractiveReport'
import { PerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

interface PrintPayload {
  name: string
  type: 'presentation' | 'document'
  theme: 'light' | 'dark'
  currency: string
  is_demo: boolean
  checksum: string | null
  data: ReportData
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
    const visible = (d.slides ?? []).filter((s) => s.visible).sort((a, b) => a.order - b.order)
    if (d.disclaimer) visible.push({ id: '__methodology', type: '__methodology', order: 9999, visible: true })
    return visible
  }, [payload])

  // Apply the report theme + Arabic direction to the document root for the whole print.
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme)
    document.documentElement.setAttribute('dir', 'rtl')
    document.documentElement.setAttribute('lang', 'ar')
  }, [theme])

  // Readiness protocol — Chromium waits on these before printing. Also publishes a per-page layout
  // audit (utilization / overflow / empty / footer) that the print script uses as a hard gate.
  useEffect(() => {
    if (!payload) return
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
      w.__REPORT_LAYOUT__ = measureLayout()
      w.__REPORT_IMAGES_READY__ = true
    })()
    return () => { cancelled = true }
  }, [payload, slides.length])

  if (failed) return <div className="p-10 text-center text-danger">تعذّر تحميل بيانات الطباعة (رمز غير صالح أو منتهٍ).</div>
  if (!payload) return <div className="p-10 text-center text-text-muted">جارٍ التحضير…</div>

  const d = payload.data
  const meta: Meta = { reportName: payload.name, platforms: (d.platforms ?? []).map((p) => String(p.provider)), isDemo: payload.is_demo, agencyName: 'CampaignsHub' }
  const landscape = type === 'presentation'
  const period = d.period ? `${d.period.from} → ${d.period.to}` : ''
  const mode = (d.mode as string) === 'live' ? 'Live' : 'Snapshot'
  const updated = d.generated_at ? new Date(String(d.generated_at)).toLocaleDateString('en-GB') : ''
  const total = slides.length

  return (
    <div className={`report-print ${type}`} data-theme={theme} dir="rtl" lang="ar">
      <style>{printCss(landscape)}</style>
      {slides.map((s, i) => (
        <section key={s.id} className="report-slide" data-print-page={i + 1}>
          <div className="report-slide-inner">
            <SlideBody slide={s} data={d} meta={meta} />
          </div>
          {/* Professional per-page footer — never on the cover; one per page, inside the safe area. */}
          {s.type !== 'cover' && (
            <footer className="report-slide-footer">
              <div className="report-footer-note">
                {d.disclaimer && <PerformanceNotice data={d.disclaimer} variant="footer" />}
              </div>
              <div className="report-footer-meta">
                <span className="report-footer-brand">CampaignsHub{payload.is_demo ? ' · Demo' : ''}{period ? ` · ${period}` : ''}</span>
                <span className="report-footer-page">{mode}{updated ? ` · آخر تحديث ${updated}` : ''} · <bdi dir="ltr">{i + 1} / {total}</bdi></span>
              </div>
            </footer>
          )}
        </section>
      ))}
    </div>
  )
}

/** Per-page layout audit read by the print script as a hard gate. */
function measureLayout() {
  const pages: Array<{ page: number; utilization: number; overflow: boolean; empty: boolean; footerDetected: boolean; overflowX: boolean }> = []
  document.querySelectorAll<HTMLElement>('.report-slide').forEach((el, i) => {
    const pageH = el.clientHeight || 1
    const inner = el.querySelector<HTMLElement>('.report-slide-inner')
    const contentH = inner ? inner.scrollHeight : el.scrollHeight
    const footer = el.querySelector<HTMLElement>('.report-slide-footer')
    const footerTop = footer ? footer.offsetTop : pageH
    // Overflow: inner content taller than the space above the footer.
    const overflow = contentH > footerTop + 4
    const overflowX = el.scrollWidth > el.clientWidth + 2
    const utilization = Math.max(0, Math.min(1.2, contentH / pageH))
    const empty = contentH < pageH * 0.12
    pages.push({ page: i + 1, utilization: Math.round(utilization * 100) / 100, overflow, overflowX, empty, footerDetected: !!footer || i === 0 })
  })
  return pages
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
    min-height: 100vh;
    padding: ${landscape ? '28px 34px 48px' : '32px 30px 52px'};
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
  /* Never split a chart, card, or table row across pages. */
  .recharts-wrapper, table, .rounded-2xl, .rounded-xl { break-inside: avoid; page-break-inside: avoid; }
  `
}
