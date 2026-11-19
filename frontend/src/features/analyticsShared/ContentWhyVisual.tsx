import { useMemo, useState } from 'react'
import { CartesianGrid, ReferenceLine, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis, ZAxis } from 'recharts'
import type { CreativeCard } from '@/features/content/api'
import { providerLabel } from '@/features/campaigns/labels'
import { platformColor } from '@/lib/colors'
import { percent, ratio } from '@/features/analytics/format'
import { bestAndWeakest, contentWhy, type WhyRow } from './contentWhy'

/**
 * ANALYTICS-CONTENT-WHY-002 — «ليش نجح / ليش ما نجح», drawn.
 *
 * No sentences: a matrix (each content item against its same-objective, same-platform average, by how much of the group's
 * spend it carried) and, under it, the best and the weakest of each group as bars with the fatigue read-out — frequency and
 * the click-rate change over time. Hover for the figures, press a bar or a dot to open the content. Every number comes from
 * `contentWhy` (real figures only); nothing is drawn for a group too small to compare.
 */
const FATIGUE: Record<string, { ar: string; en: string; tone: string }> = {
  improving: { ar: 'يتحسن', en: 'Improving', tone: 'text-success' },
  stable: { ar: 'مستقر', en: 'Stable', tone: 'text-text-secondary' },
  watch: { ar: 'مراقبة', en: 'Watch', tone: 'text-warning' },
  fatigued: { ar: 'متعب', en: 'Fatigued', tone: 'text-danger' },
  insufficient_data: { ar: 'بيانات قليلة', en: 'Little data', tone: 'text-text-muted' },
}

const T = {
  ar: { matrix: 'مصفوفة المحتوى', x: 'حصة الإنفاق', y: 'الفرق عن المتوسط', best: 'الأقوى', weakest: 'الأضعف', freq: 'التكرار', ctr: 'نسبة النقر' },
  en: { matrix: 'Content matrix', x: 'Spend share', y: 'Gap to average', best: 'Strongest', weakest: 'Weakest', freq: 'Frequency', ctr: 'CTR' },
}

function Bar({ row, ar, onOpen, good }: { row: WhyRow; ar: boolean; onOpen: () => void; good: boolean }) {
  const gap = row.gap ?? 0
  const width = Math.min(100, Math.abs(gap) * 100)
  const f = FATIGUE[row.fatigue] ?? FATIGUE.insufficient_data
  const t = ar ? T.ar : T.en

  return (
    <button type="button" onClick={onOpen} data-testid={`content-why-${good ? 'win' : 'lag'}-${row.id}`}
      className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-xl border border-border bg-surface px-3 py-2 text-start hover:bg-surface-hover">
      <span className="flex min-w-0 items-center gap-1.5 text-sm font-semibold text-text-primary">
        <span aria-hidden className="h-2 w-2 shrink-0 rounded-full" style={{ background: platformColor(row.provider) }} />
        <span className="truncate" title={row.name}>{row.name}</span>
      </span>
      <span dir="ltr" className={`tnum text-sm font-extrabold ${good ? 'text-success' : 'text-danger'}`} data-testid="content-why-gap">
        {row.gap === null ? '—' : `${row.gap >= 0 ? '+' : ''}${Math.round(row.gap * 100)}%`}
      </span>
      <span aria-hidden className="col-span-2 h-1.5 overflow-hidden rounded-full bg-surface-secondary">
        <span className={`block h-full rounded-full ${good ? 'bg-success' : 'bg-danger'}`} style={{ width: `${width}%` }} />
      </span>
      <span className="col-span-2 flex flex-wrap items-center gap-1.5 text-[11px] text-text-muted">
        <span className="rounded-full border border-border px-2 py-0.5 tnum">{t.ctr} <span dir="ltr">{percent(row.ctr, 2)}</span> / <span dir="ltr">{percent(row.groupAvg, 2)}</span></span>
        {row.share !== null && <span className="rounded-full border border-border px-2 py-0.5 tnum">{t.x} <span dir="ltr">{percent(row.share, 0)}</span></span>}
        {row.frequency !== null && <span className="rounded-full border border-border px-2 py-0.5 tnum">{t.freq} <span dir="ltr">{ratio(row.frequency, '')}</span></span>}
        {row.ctrChange !== null && <span className="rounded-full border border-border px-2 py-0.5 tnum">{t.ctr} <span dir="ltr">{row.ctrChange >= 0 ? '+' : ''}{Math.round(row.ctrChange * 100)}%</span></span>}
        <span className={`rounded-full border border-border px-2 py-0.5 font-semibold ${f.tone}`}>{ar ? f.ar : f.en}</span>
      </span>
    </button>
  )
}

export function ContentWhyVisual({ rows: cards, ar, onOpen }: { rows: CreativeCard[]; ar: boolean; onOpen: (cr: CreativeCard) => void }) {
  const t = ar ? T.ar : T.en
  const why = useMemo(() => contentWhy(cards), [cards])
  const picks = useMemo(() => bestAndWeakest(why), [why])
  const [hover, setHover] = useState<string | null>(null)
  const byId = useMemo(() => new Map(cards.map((c) => [c.id, c])), [cards])
  if (why.length === 0) return null

  const points = why.filter((r) => r.gap !== null && r.share !== null).map((r) => ({ id: r.id, x: Math.round((r.share as number) * 1000) / 10, y: Math.round((r.gap as number) * 1000) / 10, name: r.name, provider: r.provider }))
  const open = (id: string) => { const c = byId.get(id); if (c) onOpen(c) }

  return (
    <section className="space-y-3" data-testid="content-why">
      <div className="rounded-2xl border border-border bg-surface p-3" data-testid="content-matrix" role="img" aria-label={t.matrix}>
        <div className="mb-1 flex items-center justify-between text-xs font-bold text-text-primary">
          <span>{t.matrix}</span>
          <span className="font-normal text-text-muted">{t.y} · {t.x}</span>
        </div>
        <div className="[&_.recharts-cartesian-axis-tick_text]:[direction:ltr]">
          <ResponsiveContainer width="100%" height={220}>
            <ScatterChart margin={{ top: 8, right: 12, bottom: 18, left: 4 }}>
              <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" />
              <XAxis type="number" dataKey="x" name={t.x} unit="%" tick={{ fontSize: 11, fill: 'var(--color-text-muted)' }} />
              <YAxis type="number" dataKey="y" name={t.y} unit="%" tick={{ fontSize: 11, fill: 'var(--color-text-muted)' }} width={44} />
              <ZAxis range={[70, 70]} />
              <ReferenceLine y={0} stroke="var(--color-text-muted)" />
              <Tooltip cursor={{ strokeDasharray: '3 3' }} contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 12, fontSize: 12 }}
                formatter={(v: number, k: string) => [`${v}%`, k]} labelFormatter={() => ''} />
              <Scatter data={points} onClick={(p: { id?: string }) => p.id && open(p.id)} onMouseEnter={(p: { id?: string }) => setHover(p.id ?? null)} onMouseLeave={() => setHover(null)}
                shape={(props: { cx?: number; cy?: number; payload?: { id: string; provider: string } }) => (
                  <circle cx={props.cx} cy={props.cy} r={props.payload?.id === hover ? 8 : 6} fill={platformColor(props.payload?.provider ?? '')} fillOpacity={0.85} style={{ cursor: 'pointer' }} />
                )} />
            </ScatterChart>
          </ResponsiveContainer>
        </div>
        <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-text-muted" data-testid="content-matrix-legend">
          {[...new Set(why.map((r) => r.provider))].map((p) => (
            <span key={p} className="inline-flex items-center gap-1"><span aria-hidden className="h-2 w-2 rounded-full" style={{ background: platformColor(p) }} />{providerLabel(p, ar ? 'ar' : 'en')}</span>
          ))}
        </div>
      </div>
      <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">
        <div className="space-y-2" data-testid="content-why-best">
          <div className="text-xs font-bold text-success">{t.best}</div>
          {picks.best.map((r) => <Bar key={r.id} row={r} ar={ar} good onOpen={() => open(r.id)} />)}
        </div>
        <div className="space-y-2" data-testid="content-why-weakest">
          <div className="text-xs font-bold text-danger">{t.weakest}</div>
          {picks.weakest.map((r) => <Bar key={r.id} row={r} ar={ar} good={false} onOpen={() => open(r.id)} />)}
        </div>
      </div>
    </section>
  )
}
