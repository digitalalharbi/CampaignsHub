import { useMemo } from 'react'
import { CircleCheck, CircleAlert, Download, FileSpreadsheet, Lightbulb, Printer, Search, CalendarDays, Clapperboard } from 'lucide-react'
import type { CreativeDetail } from './api'
import { Num } from '@/components/ui/Num'
import { readCreative, readingCsv, type Line } from './creativeVerdict'

/**
 * CONTENT-WHY-DETAIL-001 — «لماذا نجح · لماذا لم ينجح» on the content page, in the report's own voice.
 *
 * One large verdict card, then the metric-by-metric bars against the path, then the reading in short blocks: strengths,
 * what needs work, why, the creative, the days, what to do. Every sentence is written by `readCreative` from the page's
 * own payload; a block with nothing real to say is not drawn. Downloadable as CSV, printable as PDF, and the ad itself.
 */
const VERDICT_TONE = {
  win: 'border-success/40 bg-[var(--positive-background)] text-success',
  par: 'border-border bg-surface-secondary text-text-primary',
  lose: 'border-danger/40 bg-[var(--warning-background)] text-danger',
  insufficient: 'border-border bg-surface-secondary text-text-muted',
} as const

function Block({ title, icon, items, testid }: { title: string; icon: React.ReactNode; items: Line[]; testid: string }) {
  if (items.length === 0) return null
  return (
    <div className="rounded-2xl border border-border bg-surface p-3" data-testid={testid}>
      <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary">{icon}{title}</h3>
      <ul className="space-y-1.5 text-sm leading-relaxed">
        {items.map((l) => (
          <li key={l.id} className="flex gap-1.5" data-tone={l.tone} data-testid={`why-line-${l.id}`}>
            {l.tone === 'good' ? <CircleCheck size={15} className="mt-0.5 shrink-0 text-success" aria-hidden />
              : l.tone === 'bad' ? <CircleAlert size={15} className="mt-0.5 shrink-0 text-danger" aria-hidden />
                : <span aria-hidden className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-text-muted" />}
            <span>{l.text}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

export function CreativeVerdict({ data, ar, mediaHref }: { data: CreativeDetail; ar: boolean; mediaHref: string | null }) {
  const r = useMemo(() => readCreative(data, ar), [data, ar])
  const L = (a: string, e: string) => (ar ? a : e)

  const downloadCsv = () => {
    const blob = new Blob([readingCsv(data, r, ar)], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${(data.creative.name || 'creative').replace(/[\\/:*?"<>|]+/g, '_').slice(0, 80)} — ${data.period.from}_${data.period.to}.csv`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  }

  const btn = 'inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover print:hidden'

  return (
    <section className="space-y-3 rounded-lg border border-border bg-surface p-4" data-testid="creative-why">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold text-text-primary">{L('لماذا نجح · لماذا لم ينجح', 'Why it worked · why it did not')}</h2>
        <div className="flex flex-wrap items-center gap-1.5" data-testid="creative-why-downloads">
          <button type="button" className={btn} onClick={downloadCsv} data-testid="creative-why-csv"><FileSpreadsheet size={14} aria-hidden />{L('تحميل التحليل (Excel)', 'Download analysis (Excel)')}</button>
          <button type="button" className={btn} onClick={() => window.print()} data-testid="creative-why-print"><Printer size={14} aria-hidden />{L('PDF / طباعة', 'PDF / print')}</button>
          {mediaHref && <a className={btn} href={mediaHref} download target="_blank" rel="noopener noreferrer" data-testid="creative-why-media"><Download size={14} aria-hidden />{L('تحميل الإعلان', 'Download the ad')}</a>}
        </div>
      </div>

      {/* The large card: the verdict and the one sentence that carries it. */}
      <div className={`rounded-2xl border p-4 ${VERDICT_TONE[r.verdict]}`} data-testid="creative-why-verdict" data-verdict={r.verdict}>
        <div className="text-2xl font-extrabold">{r.verdictLabel}</div>
        <p className="mt-1 text-sm font-semibold text-text-primary" data-testid="creative-why-headline">{r.headline}</p>
        {r.basis && <p className="mt-1 text-xs text-text-muted">{r.basis}</p>}
      </div>

      {/* The small cards: each metric against the path, the bar's length its gap. */}
      {r.comparisons.length > 0 && (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" data-testid="creative-why-comparisons">
          {r.comparisons.map((c) => (
            <div key={c.key} className="rounded-xl border border-border bg-surface px-3 py-2" data-testid={`why-cmp-${c.key}`}>
              <div className="truncate text-xs font-semibold text-text-muted">{c.label}</div>
              <div className="text-base font-extrabold text-text-primary"><Num>{c.value}</Num></div>
              <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                <span className={`block h-full rounded-full ${c.better ? 'bg-success' : 'bg-danger'}`} style={{ width: `${Math.min(100, Math.abs(c.gap) * 100)}%` }} />
              </div>
              <div className="mt-1 flex items-center justify-between gap-1 text-[11px] text-text-muted">
                <span>{L('المسار', 'Path')} <bdi dir="ltr" className="tnum">{c.average}</bdi></span>
                <bdi dir="ltr" className={`tnum font-bold ${c.better ? 'text-success' : 'text-danger'}`}>{c.gap >= 0 ? '+' : '−'}{Math.round(Math.abs(c.gap) * 100)}%</bdi>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="grid gap-3 lg:grid-cols-2">
        <Block title={L('نقاط القوة', 'Strengths')} icon={<CircleCheck size={15} className="text-success" />} items={r.strengths} testid="why-strengths" />
        <Block title={L('تحتاج تحسينًا', 'Needs work')} icon={<CircleAlert size={15} className="text-danger" />} items={r.weaknesses} testid="why-weaknesses" />
        <Block title={L('لماذا', 'Why')} icon={<Search size={15} className="text-text-muted" />} items={r.diagnosis} testid="why-diagnosis" />
        <Block title={L('قراءة الإبداع', 'The creative')} icon={<Clapperboard size={15} className="text-text-muted" />} items={r.creative} testid="why-creative" />
        <Block title={L('الأيام', 'The days')} icon={<CalendarDays size={15} className="text-text-muted" />} items={r.timeline} testid="why-timeline" />
        <Block title={L('ماذا نفعل', 'What to do')} icon={<Lightbulb size={15} className="text-warning" />} items={r.actions} testid="why-actions" />
      </div>
      <p className="text-[11px] text-text-muted">{L('القراءة مبنية على أرقام المنصة لهذه الفترة فقط؛ الأرقام الرسمية للمحاسبة هي فواتير المنصات.', 'Read from the platform figures for this period only; the platforms\' invoices are the reference for accounting.')}</p>
    </section>
  )
}
