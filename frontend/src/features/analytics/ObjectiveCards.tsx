import { Area, AreaChart, ResponsiveContainer, Tooltip } from 'recharts'
import { ArrowDownRight, ArrowUpRight } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { campaigns as countedCampaigns } from '@/lib/counted'
import type { PathLeaders, PathTrend } from './api'

/**
 * OBJECTIVE-CARDS-001 — Owner 2026-11-20 on Analytics › «الأهداف»: «لا أريد جداول … بطاقات صغيرة على حسب كل هدف + رسم بياني
 * مثل رسم بياني سناب شات وحجمها جميعها صغير لأنها تحليلات».
 *
 * One small card per objective, in the report's card style: the objective's colour strip and name, its campaigns and spend,
 * the three or four figures that objective is bought for, a small area chart of its path's days (Snapchat's dashboard
 * shape: no axes, a soft fill, the figure on hover), and its strongest and weakest campaign as two marked chips. No table,
 * no sentence. The comparison never crosses objectives: each card is its own.
 */
export interface ObjectiveCardData {
  key: string
  name: string
  campaigns: number
  spend: string
  kpis: { label: string; text: string; exact?: string }[]
  /** The path the objective belongs to — the trend and the leaders are read per path. */
  path: string | null
}

const PATH_OF: Record<string, string> = {
  sales: 'conversion', leads: 'conversion', app: 'conversion',
  traffic: 'traffic', engagement: 'traffic',
  awareness: 'awareness', video: 'awareness',
}
export const pathOfFamily = (family: string): string | null => PATH_OF[family] ?? null

const STRIP: Record<string, string> = { conversion: 'bg-success', traffic: 'bg-info', awareness: 'bg-purple' }
const STROKE: Record<string, string> = { conversion: 'var(--success)', traffic: 'var(--info)', awareness: 'var(--purple)' }

export function ObjectiveCards({ cards, trends, leaders, format, ar, onDrill }: {
  cards: ObjectiveCardData[]
  trends: PathTrend[]
  leaders: PathLeaders[]
  /** The leader's figure, formatted by the page's own metric specs. */
  format: (metric: string, value: number | null) => string
  ar: boolean
  /** A leader chip opens its campaign's ad sets (the analytics drill), never a new page. */
  onDrill?: (campaignId: string, name: string | null) => void
}) {
  if (cards.length === 0) return null

  return (
    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3" data-testid="objective-cards">
      {cards.map((c) => {
        const trend = c.path ? trends.find((t) => t.path === c.path) ?? null : null
        const lead = c.path ? leaders.find((l) => l.path === c.path) ?? null : null
        const useResults = trend?.days.some((d) => (d.results ?? 0) > 0) ?? false
        const series = (trend?.days ?? []).map((d) => ({ date: d.date, v: (useResults ? d.results : d.spend) ?? 0 }))
        // A long window of empty days draws a flat line with a spike at the end; the chart starts at the first measured day.
        const first = series.findIndex((d) => d.v > 0)
        const points = first > 0 ? series.slice(Math.max(0, first - 1)) : series
        const stroke = (c.path && STROKE[c.path]) || 'var(--brand-600)'

        return (
          <article key={c.key} data-testid={`objective-card-${c.key}`} className="relative flex min-w-0 flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-surface p-3 pt-4">
            <span aria-hidden className={`absolute inset-x-0 top-0 h-1 ${(c.path && STRIP[c.path]) || 'bg-border-strong'}`} />
            <header className="flex items-center justify-between gap-2">
              <h3 className="truncate text-sm font-bold text-text-primary">{c.name}</h3>
              <span className="flex shrink-0 items-center gap-1.5 text-xs text-text-muted">
                <span className="rounded-full bg-surface-secondary px-2 py-0.5 font-semibold">{countedCampaigns(c.campaigns, ar ? 'ar' : 'en')}</span>
                <Num className="font-bold text-text-primary">{c.spend}</Num>
              </span>
            </header>

            <dl className="grid grid-cols-2 gap-1.5">
              {c.kpis.slice(0, 4).map((k) => (
                <div key={k.label} className="rounded-xl bg-surface-secondary/60 px-2 py-1.5">
                  <dt className="truncate text-xs text-text-muted">{k.label}</dt>
                  <dd className="text-base font-extrabold text-text-primary" title={k.exact}><Num>{k.text}</Num></dd>
                </div>
              ))}
            </dl>

            {points.some((d) => d.v > 0) && (
              <div className="h-14" data-testid={`objective-card-chart-${c.key}`}>
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={points} margin={{ top: 4, right: 0, bottom: 0, left: 0 }}>
                    <defs>
                      <linearGradient id={`og-${c.key}`} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={stroke} stopOpacity={0.35} />
                        <stop offset="100%" stopColor={stroke} stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <Tooltip cursor={false} contentStyle={{ fontSize: 11, borderRadius: 8 }} labelStyle={{ direction: 'ltr' }} formatter={(v) => [String(Math.round(Number(v) * 100) / 100), '']} />
                    <Area type="monotone" dataKey="v" stroke={stroke} strokeWidth={2} fill={`url(#og-${c.key})`} isAnimationActive={false} dot={false} />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            )}

            {lead && lead.comparable && (lead.strongest || lead.weakest) && (
              <div className="grid gap-1" data-testid={`objective-card-leaders-${c.key}`}>
                {lead.strongest && (
                  <button type="button" data-testid={`family-drill-${lead.strongest.id}`} onClick={() => onDrill?.(lead.strongest!.id, lead.strongest!.name)} className="flex min-w-0 items-center gap-1.5 rounded-lg bg-[var(--positive-background)] px-2 py-1 text-start text-xs hover:opacity-90" title={lead.strongest.name}>
                    <ArrowUpRight size={13} className="shrink-0 text-success" aria-hidden />
                    <span className="min-w-0 flex-1 truncate text-text-primary">{lead.strongest.name.replace(/\s*·\s*\d{6,}\s*$/, '')}</span>
                    <Num className="shrink-0 font-bold text-success">{format(lead.strongest.metric, lead.strongest.value)}</Num>
                  </button>
                )}
                {lead.weakest && lead.weakest.id !== lead.strongest?.id && (
                  <button type="button" data-testid={`family-drill-${lead.weakest.id}`} onClick={() => onDrill?.(lead.weakest!.id, lead.weakest!.name)} className="flex min-w-0 items-center gap-1.5 rounded-lg bg-[var(--warning-background)] px-2 py-1 text-start text-xs hover:opacity-90" title={lead.weakest.name}>
                    <ArrowDownRight size={13} className="shrink-0 text-danger" aria-hidden />
                    <span className="min-w-0 flex-1 truncate text-text-primary">{lead.weakest.name.replace(/\s*·\s*\d{6,}\s*$/, '')}</span>
                    <Num className="shrink-0 font-bold text-danger">{format(lead.weakest.metric, lead.weakest.value)}</Num>
                  </button>
                )}
              </div>
            )}
          </article>
        )
      })}
    </div>
  )
}
