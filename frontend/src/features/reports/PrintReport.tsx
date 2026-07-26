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

  // Readiness protocol — Chromium waits on these before printing.
  useEffect(() => {
    if (!payload) return
    let cancelled = false
    const w = window as Window & { __REPORT_DATA_READY__?: boolean; __REPORT_CHARTS_READY__?: boolean; __REPORT_IMAGES_READY__?: boolean }
    ;(async () => {
      w.__REPORT_DATA_READY__ = true
      const fonts = (document as Document & { fonts?: FontFaceSet }).fonts
      if (fonts?.ready) await fonts.ready.catch(() => {})
      // Settle time for ResponsiveContainer + recharts (animations disabled). Uses timers, not rAF,
      // so it completes even if the tab is backgrounded during rendering.
      await new Promise((r) => setTimeout(r, 500))
      const imgs = Array.from(document.images)
      await Promise.all(imgs.map((img) => (img.complete ? Promise.resolve() : new Promise((res) => {
        img.onload = img.onerror = () => res(null)
        setTimeout(() => res(null), 4000) // never hang on a stalled asset
      }))))
      if (cancelled) return
      w.__REPORT_CHARTS_READY__ = true
      w.__REPORT_IMAGES_READY__ = true
    })()
    return () => { cancelled = true }
  }, [payload, slides.length])

  if (failed) return <div className="p-10 text-center text-danger">تعذّر تحميل بيانات الطباعة (رمز غير صالح أو منتهٍ).</div>
  if (!payload) return <div className="p-10 text-center text-text-muted">جارٍ التحضير…</div>

  const d = payload.data
  const meta: Meta = { reportName: payload.name, platforms: (d.platforms ?? []).map((p) => String(p.provider)), isDemo: payload.is_demo, agencyName: 'CampaignsHub' }
  const landscape = type === 'presentation'

  return (
    <div className={`report-print ${type}`} data-theme={theme} dir="rtl" lang="ar">
      <style>{printCss(landscape)}</style>
      {slides.map((s) => (
        <section key={s.id} className="report-slide">
          <div className="report-slide-inner">
            <SlideBody slide={s} data={d} meta={meta} />
          </div>
          {s.type !== 'cover' && d.disclaimer && (
            <footer className="report-slide-footer">
              <PerformanceNotice data={d.disclaimer} variant="footer" />
              <span className="report-slide-brand">CampaignsHub{payload.is_demo ? ' · Demo' : ''}</span>
            </footer>
          )}
        </section>
      ))}
    </div>
  )
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
  .report-slide-inner { flex: 1 1 auto; min-height: 0; }
  .report-slide-footer {
    position: absolute; left: 34px; right: 34px; bottom: 14px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    border-top: 1px solid var(--border); padding-top: 6px;
  }
  .report-slide-brand { font-size: 10px; color: var(--text-muted); white-space: nowrap; }
  /* Never split a chart, card, or table row across pages. */
  .recharts-wrapper, table, .rounded-2xl, .rounded-xl { break-inside: avoid; page-break-inside: avoid; }
  `
}
