import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, TrendingDown, TrendingUp } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { formatFixed, formatNumber } from '@/lib/numerals'
import { platformLabel, sortPlatforms } from '@/lib/platforms'
import { getContentOverview, type OverviewCell, type OverviewWhy } from './intake/api'
import { FAMILY, tr } from './intake/copy'
import { creativeKindLabel } from './creativeKind'
import { metricLabel } from './metrics'
import type { LibraryQuery } from './api'

/*
 * CONTENT-LIBRARY-V2-001 — the library reads like the report's content view: chart first, every mark clickable.
 *
 *  - the MATRIX is platform x objective; a cell's size is how many items it holds, a dot says how many are tired, and a click
 *    narrows the whole page to that cell (providers + objectives), a second click lifts it;
 *  - BEST and WEAKEST per cell, with the measured gap to the cell's average and the share of its spend — «why», never a cause;
 *  - FATIGUE as counts that filter the page, over the one assessment the cards already use.
 *
 * Nothing here ranks or judges by itself: the server orders by the canonical ranker and withholds a verdict under three items.
 * A cell it could not rank says how many items it holds and nothing else.
 */

const COPY = {
  ar: {
    title: 'نظرة على المحتوى', matrix: 'المنصة والهدف', best: 'الأقوى', worst: 'الأضعف', vsAvg: 'مقابل متوسط الخلية', share: (p: string) => `أخذ ${p} من إنفاق خليته`,
    noRank: 'أقل من ثلاثة محتويات مقاسة، فلا ترتيب.', read: (r: number, n: number) => `قُرئ ${r} من ${n}`, fatigue: 'الإرهاق', fatigued: 'مرهق', watch: 'تحت المراقبة', stable: 'مستقر', improving: 'يتحسن',
    clear: 'إزالة التحديد', empty: 'لا يوجد محتوى في هذا النطاق.', prev: 'الفترة السابقة', trend: { up: 'أفضل من الفترة السابقة', down: 'أضعف من الفترة السابقة' },
    flagged: 'محتوى يحتاج نظرة', open: 'فتح',
  },
  en: {
    title: 'Content overview', matrix: 'Platform and objective', best: 'Strongest', worst: 'Weakest', vsAvg: 'against the cell average', share: (p: string) => `carried ${p} of its cell’s spend`,
    noRank: 'Fewer than three measured items, so no ranking.', read: (r: number, n: number) => `Read ${r} of ${n}`, fatigue: 'Fatigue', fatigued: 'Fatigued', watch: 'Watch', stable: 'Stable', improving: 'Improving',
    clear: 'Clear selection', empty: 'No content in this scope.', prev: 'previous period', trend: { up: 'Better than the previous period', down: 'Weaker than the previous period' },
    flagged: 'Content to look at', open: 'Open',
  },
}

export function LibraryOverview({ query, ar, axes, setAxis, health, setHealth }: {
  query: LibraryQuery
  ar: boolean
  axes: Record<string, string[]>
  setAxis: (key: string, values: string[]) => void
  health: string
  setHealth: (v: string) => void
}) {
  const t = COPY[ar ? 'ar' : 'en']
  const [hover, setHover] = useState<string | null>(null)
  const q = useQuery({ queryKey: ['content-overview', query], queryFn: () => getContentOverview(query), retry: false })
  const d = q.data

  const cells = useMemo(() => d?.matrix ?? [], [d])
  const platforms = useMemo(() => sortPlatforms([...new Set(cells.map((c) => c.provider))]), [cells])
  const families = useMemo(() => [...new Set(cells.map((c) => c.family))], [cells])
  const max = Math.max(1, ...cells.map((c) => c.count))
  const cellOf = (p: string, f: string) => cells.find((c) => c.provider === p && c.family === f)
  const selected = (c: OverviewCell) => (axes.providers ?? []).join() === c.provider && c.objectives.length > 0 && (axes.objectives ?? []).join() === c.objectives.join()

  const pick = (c: OverviewCell) => {
    if (selected(c)) { setAxis('providers', []); setAxis('objectives', []); return }
    setAxis('providers', [c.provider])
    setAxis('objectives', c.objectives)
  }

  // A failure is silent: the library under it is what the page is for.
  if (q.isError) return null
  if (q.isPending) return <div className="h-48 animate-pulse rounded-2xl bg-surface-secondary" data-testid="overview-loading" />
  if (!d || cells.length === 0) return <p data-testid="overview-empty" className="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-text-muted">{t.empty}</p>

  const ranked = cells.filter((c) => c.best && c.worst).slice(0, 6)
  const fatigueEntries: Array<[string, number, string]> = [
    ['fatigued', d.fatigue.fatigued ?? 0, 'bg-danger'], ['watch', d.fatigue.watch ?? 0, 'bg-warning'],
    ['stable', d.fatigue.stable ?? 0, 'bg-success'], ['improving', d.fatigue.improving ?? 0, 'bg-info'],
  ]
  const fatigueTotal = Math.max(1, fatigueEntries.reduce((a, [, n]) => a + n, 0))

  return (
    <section className="flex flex-col gap-4" data-testid="library-overview">
      <div className="flex flex-wrap items-baseline gap-2">
        <h2 className="text-base font-bold text-text-primary">{t.title}</h2>
        <span className="text-xs text-text-muted" dir="ltr">{t.read(d.read, d.in_scope)}</span>
        {(axes.providers?.length || axes.objectives?.length || health) && (
          <button type="button" className="ms-auto text-xs font-semibold text-brand-600 underline" data-testid="overview-clear"
            onClick={() => { setAxis('providers', []); setAxis('objectives', []); setHealth('') }}>{t.clear}</button>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        {/* The matrix. */}
        <div className="overflow-x-auto rounded-2xl border border-border bg-surface p-3" data-testid="overview-matrix">
          <p className="mb-2 text-xs font-semibold text-text-muted">{t.matrix}</p>
          {/* A heat grid, not an analytical table: one mark per cell, every mark a button. */}
          <div className="grid gap-1 text-xs" role="grid" style={{ gridTemplateColumns: `minmax(5.5rem,auto) repeat(${families.length}, minmax(4.5rem,1fr))` }}>
            <div role="row" className="contents">
              <span />
              {families.map((f) => <span key={f} role="columnheader" className="px-1 pb-1 text-center font-semibold text-text-secondary">{tr(FAMILY[f], ar, f)}</span>)}
            </div>
            {platforms.map((p) => (
              <div key={p} role="row" className="contents">
                <span role="rowheader" className="flex items-center pe-2 font-semibold text-text-primary">{platformLabel(p, ar)}</span>
                {families.map((f) => {
                  const c = cellOf(p, f)
                  if (!c) return <span key={f} role="gridcell" className="h-12 rounded-lg bg-surface-secondary/40" />
                  const k = `${p}|${f}`
                  const on = selected(c)
                  const trend = c.average !== null && c.previous_average !== null && c.previous_average !== 0 ? (c.average - c.previous_average) / Math.abs(c.previous_average) : null
                  const better = trend === null ? null : (c.higher_is_better ? trend > 0 : trend < 0)
                  return (
                    <span key={f} role="gridcell" className="contents">
                      <button type="button" onClick={() => pick(c)} onMouseEnter={() => setHover(k)} onMouseLeave={() => setHover(null)} onFocus={() => setHover(k)} onBlur={() => setHover(null)}
                        aria-pressed={on} data-testid={`matrix-${p}-${f}`}
                        style={{ backgroundColor: `color-mix(in srgb, var(--color-brand-600) ${Math.round(12 + (c.count / max) * 55)}%, transparent)` }}
                        className={`relative flex h-12 w-full flex-col items-center justify-center rounded-lg text-text-primary transition ${on ? 'ring-2 ring-brand-600' : 'hover:ring-1 hover:ring-brand-600/50'}`}>
                        <span className="text-sm font-bold"><Num>{formatNumber(c.count)}</Num></span>
                        {(c.fatigued > 0 || c.watch > 0) && (
                          <span className="absolute end-1 top-1 flex items-center gap-0.5 text-[10px] font-bold text-danger"><span className="h-1.5 w-1.5 rounded-full bg-danger" /><Num>{formatNumber(c.fatigued + c.watch)}</Num></span>
                        )}
                        {better !== null && (better ? <TrendingUp className="absolute bottom-0.5 end-1 h-3 w-3 text-success" aria-label={t.trend.up} /> : <TrendingDown className="absolute bottom-0.5 end-1 h-3 w-3 text-danger" aria-label={t.trend.down} />)}
                      </button>
                    </span>
                  )
                })}
              </div>
            ))}
          </div>
          <p className="mt-2 min-h-4 text-xs text-text-muted" data-testid="matrix-hover">
            {hover && (() => {
              const [p, f] = hover.split('|')
              const c = cellOf(p, f)
              if (!c || c.metric === null || c.average === null) return c ? t.noRank : ''
              const prev = c.previous_average !== null ? ` · ${t.prev} ${formatFixed(c.previous_average, 2)}` : ''
              return `${platformLabel(p, ar)} · ${tr(FAMILY[f], ar, f)} · ${metricLabel(c.metric, ar ? 'ar' : 'en')} ${formatFixed(c.average, 2)}${prev}`
            })()}
          </p>
        </div>

        {/* Fatigue as a bar that filters. */}
        <div className="rounded-2xl border border-border bg-surface p-3" data-testid="overview-fatigue">
          <p className="mb-2 text-xs font-semibold text-text-muted">{t.fatigue}</p>
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-surface-secondary" role="img" aria-label={t.fatigue}>
            {fatigueEntries.map(([key, n, tone]) => n > 0 && <div key={key} className={tone} style={{ width: `${(n / fatigueTotal) * 100}%` }} />)}
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            {fatigueEntries.map(([key, n, tone]) => (
              <button key={key} type="button" aria-pressed={health === key} onClick={() => setHealth(health === key ? '' : key)} data-testid={`fatigue-chip-${key}`}
                className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold ${health === key ? 'border-brand-600 bg-brand-600/10' : 'border-border hover:bg-surface-hover'}`}>
                <span className={`h-2 w-2 rounded-full ${tone}`} />{t[key as 'fatigued']} <span dir="ltr">{formatNumber(n)}</span>
              </button>
            ))}
          </div>
          {d.flagged.length > 0 && (
            <ul className="mt-3 flex flex-col gap-1.5" data-testid="overview-flagged">
              <li className="text-xs font-semibold text-text-muted">{t.flagged}</li>
              {d.flagged.slice(0, 4).map((f) => (
                <li key={f.id} className="flex items-start gap-1.5 text-xs">
                  <AlertTriangle className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${f.status === 'fatigued' ? 'text-danger' : 'text-warning'}`} aria-hidden />
                  <Link to={f.id} className="min-w-0 text-text-primary hover:underline">
                    <span className="block truncate font-semibold">{f.name}</span>
                    <span className="block text-text-muted">{ar ? f.reason_ar : f.reason_en}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {/* Best and weakest per cell, as a measured gap. */}
      {ranked.length > 0 && (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="overview-why">
          {ranked.map((c) => (
            <div key={`${c.provider}|${c.family}`} className="rounded-2xl border border-border bg-surface p-3">
              <p className="mb-2 text-xs font-bold text-text-primary">{platformLabel(c.provider, ar)} · {tr(FAMILY[c.family], ar, c.family)} <span className="font-normal text-text-muted">· {c.metric ? metricLabel(c.metric, ar ? 'ar' : 'en') : ''}</span></p>
              {c.best && <WhyRow why={c.best} good ar={ar} t={t} />}
              {c.worst && <WhyRow why={c.worst} good={false} ar={ar} t={t} />}
            </div>
          ))}
        </div>
      )}
    </section>
  )
}

function WhyRow({ why, good, ar, t }: { why: OverviewWhy; good: boolean; ar: boolean; t: (typeof COPY)['ar'] }) {
  const peak = Math.max(why.value, why.average ?? 0, 0.000001)
  return (
    <Link to={why.id} data-testid={`why-${good ? 'best' : 'worst'}-${why.id}`} className="mb-2 block rounded-xl p-2 last:mb-0 hover:bg-surface-hover">
      <p className={`text-xs font-bold ${good ? 'text-success' : 'text-danger'}`}>{good ? t.best : t.worst}</p>
      <p className="truncate text-sm font-semibold text-text-primary">{why.name}{why.kind ? <span className="ms-1 text-xs font-normal text-text-muted">{creativeKindLabel(why.kind, ar)}</span> : null}</p>
      <div className="mt-1 flex flex-col gap-1" aria-hidden>
        <div className="h-1.5 rounded-full bg-surface-secondary"><div className={`h-full rounded-full ${good ? 'bg-success' : 'bg-danger'}`} style={{ width: `${(why.value / peak) * 100}%` }} /></div>
        {why.average !== null && <div className="h-1.5 rounded-full bg-surface-secondary"><div className="h-full rounded-full bg-text-muted/50" style={{ width: `${(why.average / peak) * 100}%` }} /></div>}
      </div>
      <p className="mt-1 text-xs text-text-secondary">
        <span dir="ltr">{formatFixed(why.value, 2)}</span>
        {why.delta_percent !== null && <> · <span dir="ltr">{why.delta_percent > 0 ? '+' : ''}{formatFixed(why.delta_percent, 1)}%</span> {t.vsAvg}</>}
        {why.spend_share !== null && <> · {t.share(`${formatNumber(Math.round(why.spend_share * 100))}%`)}</>}
      </p>
    </Link>
  )
}
