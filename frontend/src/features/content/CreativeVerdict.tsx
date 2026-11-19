import { useMemo } from 'react'
import { Download, FileSpreadsheet, Printer } from 'lucide-react'
import type { CreativeDetail } from './api'
import { Num } from '@/components/ui/Num'
import { PlatformMark } from '@/components/brand/PlatformMark'
import { readCreative, readingCsv } from './creativeVerdict'

/**
 * CONTENT-WHY-VISUAL-001 — Owner 2026-11-19: «احتاج جانب ابداعي وبياني فقط … لا اريد نصوص ابدا».
 *
 * «Why it worked / why it did not», DRAWN — no sentences (the rule ANALYTICS-CONTENT-WHY-002 holds for the analytics board):
 *   a verdict pill · one diverging bar per figure against the same platform on the same path (green right, red left, the gap
 *   at the end) · the frame's shape · the watch-through as a ring against its peers · the length against the platform's
 *   sweet spot · the days as bars, a stopped day in red and the best day in green. Labels are metric names only. The written
 *   reading travels in the CSV download, never on the page.
 */
const PILL = {
  win: 'bg-success text-white',
  par: 'bg-surface-secondary text-text-primary',
  lose: 'bg-danger text-white',
  insufficient: 'bg-surface-secondary text-text-muted',
} as const

function Ring({ value, peer, label }: { value: number; peer: number | null; label: string }) {
  const r = 26
  const c = 2 * Math.PI * r
  const good = peer === null ? value >= 0.05 : value >= peer
  const peerAngle = peer === null ? null : Math.min(1, peer) * 360 - 90

  return (
    <figure className="flex flex-col items-center gap-1" data-testid="why-ring">
      <svg width="72" height="72" viewBox="0 0 64 64" role="img" aria-label={`${label} ${(value * 100).toFixed(1)}%`}>
        <circle cx="32" cy="32" r={r} fill="none" stroke="currentColor" strokeOpacity="0.12" strokeWidth="7" />
        <circle cx="32" cy="32" r={r} fill="none" stroke={good ? 'var(--success)' : 'var(--danger)'} strokeWidth="7" strokeLinecap="round"
          strokeDasharray={`${Math.max(0.01, Math.min(1, value)) * c} ${c}`} transform="rotate(-90 32 32)" />
        {peerAngle !== null && (
          <line x1="32" y1="32" x2={32 + 31 * Math.cos((peerAngle * Math.PI) / 180)} y2={32 + 31 * Math.sin((peerAngle * Math.PI) / 180)}
            stroke="var(--text-primary)" strokeWidth="1.5" strokeDasharray="2 2" />
        )}
        <text x="32" y="36" textAnchor="middle" className="fill-current text-[11px] font-extrabold">{(value * 100).toFixed(1)}%</text>
      </svg>
      <figcaption className="text-[11px] font-semibold text-text-muted">{label}</figcaption>
    </figure>
  )
}

/** The length against the vertical platforms' sweet spot (15–25 s): a band, and a marker where this ad sits. */
function Length({ seconds, label }: { seconds: number; label: string }) {
  const max = Math.max(60, seconds)
  const pos = (s: number) => `${(s / max) * 100}%`
  const inBand = seconds >= 15 && seconds <= 25

  return (
    <figure className="flex min-w-[9rem] flex-col gap-1.5" data-testid="why-length">
      <div className="relative h-3 rounded-full bg-surface-secondary">
        <span className="absolute inset-y-0 rounded-full bg-success/30" style={{ insetInlineStart: pos(15), width: `${(10 / max) * 100}%` }} aria-hidden />
        <span className={`absolute -top-1 h-5 w-1.5 rounded-full ${inBand ? 'bg-success' : 'bg-warning'}`} style={{ insetInlineStart: pos(seconds) }} aria-hidden />
      </div>
      <figcaption className="flex items-center justify-between text-[11px] font-semibold text-text-muted">
        <span>{label}</span><Num className="font-extrabold text-text-primary">{`${Math.round(seconds)}s`}</Num>
      </figcaption>
    </figure>
  )
}

/** The frame's shape, drawn to scale. */
function Shape({ w, h }: { w: number; h: number }) {
  const g = (a: number, b: number): number => (b === 0 ? a : g(b, a % b))
  const d = g(w, h)
  const H = 44
  const W = Math.max(14, Math.round((w / h) * H))

  return (
    <figure className="flex flex-col items-center gap-1" data-testid="why-shape">
      <span className="block rounded-md border-2 border-brand-600 bg-brand-600/10" style={{ width: W, height: H }} aria-hidden />
      <figcaption><Num className="text-[11px] font-extrabold text-text-primary">{`${w / d}:${h / d}`}</Num></figcaption>
    </figure>
  )
}

export function CreativeVerdict({ data, ar, mediaHref }: { data: CreativeDetail; ar: boolean; mediaHref: string | null }) {
  const r = useMemo(() => readCreative(data, ar), [data, ar])
  const L = (a: string, e: string) => (ar ? a : e)
  const m = (data.metrics ?? {}) as Record<string, unknown>
  const peers = (data.peers ?? null) as Record<string, unknown> | null
  const num = (v: unknown) => (typeof v === 'number' && Number.isFinite(v) ? v : null)

  const days = (data.trend ?? []).map((d) => ({ date: String(d.date), spend: num(d.spend) ?? 0, revenue: num(d.revenue) ?? 0, orders: num(d.conversions) ?? 0 }))
  const maxSpend = Math.max(1, ...days.map((d) => d.spend))
  const firstSpent = days.findIndex((d) => d.spend >= 1)
  const lastSpent = days.length - 1 - [...days].reverse().findIndex((d) => d.spend >= 1)
  const best = days.filter((d) => d.spend >= 1).sort((a, b) => b.revenue - a.revenue || b.orders - a.orders)[0] ?? null

  const completion = num(m.completion_rate)
  const seconds = num((data.creative as { duration_seconds?: unknown }).duration_seconds)
  const w = data.creative.dimensions?.width ?? null
  const h = data.creative.dimensions?.height ?? null
  const isVideo = data.creative.kind === 'video' || data.creative.format === 'video'

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
  const icon = 'grid h-8 w-8 place-items-center rounded-lg border border-border bg-surface text-text-secondary hover:bg-surface-hover print:hidden'

  return (
    <section className="space-y-3 rounded-2xl border border-border bg-surface p-3" data-testid="creative-why">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className={`rounded-full px-3 py-1 text-sm font-extrabold ${PILL[r.verdict]}`} data-testid="creative-why-verdict" data-verdict={r.verdict}>{r.verdictLabel}</span>
          <span className="flex items-center gap-1 text-xs text-text-muted" title={r.basis ?? undefined} data-testid="creative-why-basis">
            <PlatformMark platform={data.creative.provider} size={14} />
            <Num>{`× ${num(peers?.count) ?? 0}`}</Num>
          </span>
        </div>
        <div className="flex items-center gap-1" data-testid="creative-why-downloads">
          <button type="button" className={icon} onClick={downloadCsv} title={L('تحميل Excel', 'Download Excel')} aria-label={L('تحميل Excel', 'Download Excel')} data-testid="creative-why-csv"><FileSpreadsheet size={15} aria-hidden /></button>
          <button type="button" className={icon} onClick={() => window.print()} title="PDF" aria-label="PDF" data-testid="creative-why-print"><Printer size={15} aria-hidden /></button>
          {mediaHref && <a className={icon} href={mediaHref} download target="_blank" rel="noopener noreferrer" title={L('تحميل الإعلان', 'Download the ad')} aria-label={L('تحميل الإعلان', 'Download the ad')} data-testid="creative-why-media"><Download size={15} aria-hidden /></a>}
        </div>
      </div>

      {/* One diverging bar per figure: the centre is the platform's own average on this path. */}
      {r.comparisons.length > 0 && (
        <div className="grid gap-1.5" data-testid="creative-why-comparisons">
          {r.comparisons.map((c) => {
            const width = `${Math.min(50, Math.abs(c.gap) * 50)}%`
            return (
              <div key={c.key} className="grid grid-cols-[7.5rem_minmax(0,1fr)_4.5rem] items-center gap-2 text-xs" data-testid={`why-cmp-${c.key}`} title={`${c.label}: ${c.value} · ${c.average}`}>
                <span className="truncate font-semibold text-text-secondary">{c.label}</span>
                <span className="relative h-5 rounded-md bg-surface-secondary" aria-hidden>
                  <span className="absolute inset-y-0 start-1/2 w-px bg-border-strong" />
                  <span className={`absolute inset-y-0.5 rounded ${c.better ? 'start-1/2 bg-success' : 'end-1/2 bg-danger'}`} style={{ width }} />
                </span>
                <span className="flex flex-col items-end leading-tight">
                  <Num className="font-extrabold text-text-primary">{c.value}</Num>
                  <Num className={`text-[10px] font-bold ${c.better ? 'text-success' : 'text-danger'}`}>{`${c.gap >= 0 ? '+' : '−'}${Math.round(Math.abs(c.gap) * 100)}%`}</Num>
                </span>
              </div>
            )
          })}
        </div>
      )}

      {/* The creative and its days, drawn. */}
      <div className="flex flex-wrap items-end gap-4 border-t border-border pt-3">
        {w && h && <Shape w={w} h={h} />}
        {isVideo && completion !== null && <Ring value={completion} peer={num(peers?.completion_rate)} label={L('الإكمال', 'Completion')} />}
        {isVideo && seconds !== null && <Length seconds={seconds} label={L('المدة', 'Length')} />}
        {days.length > 1 && (
          <figure className="flex min-w-[12rem] flex-1 flex-col gap-1" data-testid="why-days">
            <div className="flex h-14 items-end gap-0.5">
              {days.map((d, i) => {
                const stopped = i > firstSpent && i < lastSpent && d.spend < 1
                const isBest = best !== null && d.date === best.date
                return (
                  <span key={d.date} title={d.date} data-stopped={stopped || undefined} data-best={isBest || undefined}
                    className={`min-w-[3px] flex-1 rounded-t ${stopped ? 'bg-danger/40' : isBest ? 'bg-success' : 'bg-brand-600/60'}`}
                    style={{ height: stopped ? '100%' : `${Math.max(4, (d.spend / maxSpend) * 100)}%` }} />
                )
              })}
            </div>
            <figcaption className="flex justify-between text-[10px] text-text-muted"><Num>{days[0].date.slice(5)}</Num><Num>{days[days.length - 1].date.slice(5)}</Num></figcaption>
          </figure>
        )}
      </div>
    </section>
  )
}
