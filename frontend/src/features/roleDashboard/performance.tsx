import { Link } from 'react-router-dom'
import { PlatformDonutChart } from '@/features/analytics/charts'
import { platformColor } from '@/lib/colors'
import { platformLabel } from '@/lib/platforms'
import type { CampaignRow, PlatformRow } from './api'
import { Nothing, Panel, times, useBoxHeight, useFmt } from './parts'

/*
 * DASH-PERFORMANCE-PANELS-001 — the three performance panels of a dashboard, read at a glance:
 *
 *   أفضل الحملات            the leading campaigns: platform, results and cost per result
 *   مقارنة أداء المنصات      each platform's spend, results and return (or cost) with a bar to compare by eye
 *   توزيع الإنفاق حسب المنصة  the spend share as a ring with the percentages beside it
 *
 * Every figure comes from the dashboard payload as it is: a withheld spend stays «—», never 0, and a cost or a return
 * is shown only for the objective the server ranked by (results of different objectives are never summed).
 */

const T = {
  ar: { top: 'أفضل الحملات', compare: 'مقارنة أداء المنصات', share: 'توزيع الإنفاق حسب المنصة', campaign: 'الحملة', platform: 'المنصة', results: 'النتائج', cost: 'التكلفة', spend: 'الإنفاق', roas: 'العائد', nothing: 'لا توجد بيانات بعد' },
  en: { top: 'Top campaigns', compare: 'Platform performance', share: 'Spend by platform', campaign: 'Campaign', platform: 'Platform', results: 'Results', cost: 'Cost', spend: 'Spend', roas: 'ROAS', nothing: 'No data yet' },
}

type Fmt = ReturnType<typeof useFmt>

function Dot({ platform }: { platform: string }) {
  return <span aria-hidden className="inline-block h-2 w-2 shrink-0 rounded-full" style={{ background: platformColor(platform) }} />
}

const th = 'px-1.5 pb-1.5 text-start text-[11px] font-semibold text-text-muted'
const td = 'px-1.5 py-1.5 tnum'

/** The campaigns that lead on the ranked objective first, then by spend. At most `limit`. */
export function topCampaigns(rows: CampaignRow[], family: string | null, limit = 6): CampaignRow[] {
  const ranked = rows.filter((r) => family === null || r.family === family)
  const pool = ranked.length > 0 ? ranked : rows

  return [...pool]
    .sort((a, b) => (b.result ?? -1) - (a.result ?? -1) || (b.spend ?? -1) - (a.spend ?? -1))
    .slice(0, limit)
}

export function TopCampaignsPanel({ rows, family, portal, className = '' }: { rows: CampaignRow[]; family: string | null; portal: 'agency' | 'app'; className?: string }) {
  const f = useFmt()
  const t = f.ar ? T.ar : T.en
  const top = topCampaigns(rows, family)

  return (
    <Panel title={t.top} to={`/${portal}/campaigns`} testId="dash-panel-top" className={className}>
      {top.length === 0 ? <Nothing label={t.nothing} /> : (
        <table className="w-full table-fixed text-xs" data-testid="dash-top">
          <thead>
            <tr className="border-b border-border">
              <th className={`${th} w-[42%]`}>{t.campaign}</th>
              <th className={`${th} w-[26%]`}>{t.platform}</th>
              <th className={th}>{t.results}</th>
              <th className={th}>{t.cost}</th>
            </tr>
          </thead>
          <tbody>
            {top.map((c) => (
              <tr key={c.id} className="border-b border-border/60 last:border-0" data-testid={`dash-top-${c.id}`}>
                <td className="truncate px-1.5 py-1.5 font-semibold text-text-primary">
                  <Link to={c.project_id ? `/${portal}/campaigns/${c.project_id}/${c.id}` : `/${portal}/campaigns`} className="hover:underline" title={c.name}>{c.name}</Link>
                </td>
                <td className="truncate px-1.5 py-1.5 text-text-secondary">
                  <span className="inline-flex min-w-0 items-center gap-1.5"><Dot platform={c.platform} /><span className="truncate">{platformLabel(c.platform, f.ar)}</span></span>
                </td>
                <td className={`${td} font-bold text-text-primary`}>{f.short(c.result)}</td>
                <td className={`${td} text-text-secondary`}>{f.short(c.cost_per_result)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Panel>
  )
}

/** The figure a platform is compared on: the return for sales, otherwise the cost per result (lower is better). */
function score(p: PlatformRow, sales: boolean): number | null {
  return sales ? p.roas : p.cost_per_result
}

export function PlatformComparePanel({ platforms, family, portal, className = '' }: { platforms: PlatformRow[]; family: string | null; portal: 'agency' | 'app'; className?: string }) {
  const f = useFmt()
  const t = f.ar ? T.ar : T.en
  const sales = family === 'sales'
  const scores = platforms.map((p) => score(p, sales)).filter((n): n is number => n !== null && n > 0)
  const max = scores.length > 0 ? Math.max(...scores) : 0
  const min = scores.length > 0 ? Math.min(...scores) : 0

  /** A bar's length: the best platform is full either way (highest return, or lowest cost). */
  const width = (n: number | null): number => {
    if (n === null || n <= 0 || max <= 0) return 0
    return sales ? n / max : min / n
  }

  return (
    <Panel title={t.compare} to={`/${portal}/analytics`} testId="dash-panel-compare" className={className}>
      {platforms.length === 0 ? <Nothing label={t.nothing} /> : (
        <table className="w-full table-fixed text-xs" data-testid="dash-compare">
          <thead>
            <tr className="border-b border-border">
              <th className={`${th} w-[30%]`}>{t.platform}</th>
              <th className={th}>{t.spend}</th>
              <th className={th}>{t.results}</th>
              <th className={`${th} w-[34%]`}>{sales ? t.roas : t.cost}</th>
            </tr>
          </thead>
          <tbody>
            {platforms.map((p) => {
              const n = score(p, sales)

              return (
                <tr key={p.platform} className="border-b border-border/60 last:border-0" data-testid={`dash-compare-${p.platform}`}>
                  <td className="truncate px-1.5 py-1.5 text-text-primary">
                    <span className="inline-flex min-w-0 items-center gap-1.5"><Dot platform={p.platform} /><span className="truncate">{platformLabel(p.platform, f.ar)}</span></span>
                  </td>
                  <td className={`${td} text-text-secondary`}>{f.short(p.spend)}</td>
                  <td className={`${td} text-text-secondary`}>{f.short(p.result)}</td>
                  <td className="px-1.5 py-1.5">
                    <span className="flex items-center gap-2">
                      <span className="tnum w-11 shrink-0 font-bold text-text-primary">{n === null ? '—' : sales ? times(n) : f.short(n)}</span>
                      <span aria-hidden className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                        <span className="block h-full rounded-full" style={{ width: `${Math.round(width(n) * 100)}%`, background: platformColor(p.platform) }} />
                      </span>
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </Panel>
  )
}

export function SpendSharePanel({ platforms, currency, portal, className = '' }: { platforms: PlatformRow[]; currency: string | null; portal: 'agency' | 'app'; className?: string }) {
  const f: Fmt = useFmt()
  const t = f.ar ? T.ar : T.en
  const ring = useBoxHeight(120)
  const data = platforms.filter((p) => p.spend !== null && p.spend > 0).map((p) => ({ name: platformLabel(p.platform, f.ar), key: p.platform, value: p.spend as number }))
  const legend = [...platforms].filter((p) => p.share !== null).sort((a, b) => (b.share ?? 0) - (a.share ?? 0))

  return (
    <Panel title={t.share} to={`/${portal}/analytics`} testId="dash-panel-spend-share" className={className}>
      {data.length === 0 ? <Nothing label={t.nothing} /> : (
        <div className="grid h-full min-h-[140px] grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-center gap-2">
          <ul className="flex flex-col gap-1.5 text-xs" data-testid="dash-spend-share-legend">
            {legend.map((p) => (
              <li key={p.platform} className="flex items-center justify-between gap-2">
                <span className="inline-flex min-w-0 items-center gap-1.5 text-text-secondary"><Dot platform={p.platform} /><span className="truncate">{platformLabel(p.platform, f.ar)}</span></span>
                <span className="tnum font-bold text-text-primary">{f.pct(p.share)}</span>
              </li>
            ))}
          </ul>
          <div ref={ring.ref} className="h-full min-h-[120px]" role="img" aria-label={`${t.share}: ${legend.map((p) => `${platformLabel(p.platform, f.ar)} ${f.pct(p.share)}`).join(', ')}`}>
            <PlatformDonutChart legend={false} height={ring.height} currency={currency ?? ''} data={data} />
          </div>
        </div>
      )}
    </Panel>
  )
}
