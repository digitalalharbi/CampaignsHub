import { CalendarDays, Info, LayoutDashboard, TrendingDown, TrendingUp } from 'lucide-react'
import type { HomeCopy } from './homeCopy'

/**
 * The product's own overview, in miniature, inside the hero.
 *
 * It shows what the system actually does once accounts are connected: headline KPIs against the previous
 * period, the best campaigns, a platform-by-platform comparison, where the money went, how budgets are
 * being consumed, and which reports go out and when.
 *
 * The arithmetic holds together on purpose — campaign spend sums to the total, results sum to the total,
 * and the average cost per result is the total spend divided by the total results. A demo whose numbers
 * do not add up quietly teaches a visitor to distrust the real product.
 */

const PLATFORM_COLOR: Record<string, string> = {
  Snapchat: '#FFFC00',
  Meta: '#1877F2',
  TikTok: '#25F4EE',
  'Google Ads': '#EA4335',
  X: '#9AA4B2',
  LinkedIn: '#0A66C2',
}

const color = (name: string) => PLATFORM_COLOR[name] ?? 'var(--brand-400)'

/** A donut drawn from the share percentages — no chart library, no runtime cost. */
function Donut({ slices }: { slices: { name: string; share: number }[] }) {
  const R = 15.9155 // circumference = 100, so a share maps 1:1 onto the dash array
  let offset = 25 // start at 12 o'clock
  return (
    <svg viewBox="0 0 42 42" className="h-[74px] w-[74px] shrink-0" role="img" aria-hidden>
      <circle cx="21" cy="21" r={R} fill="transparent" stroke="rgba(255,255,255,0.08)" strokeWidth="5" />
      {slices.map((s) => {
        const el = (
          <circle
            key={s.name}
            cx="21" cy="21" r={R}
            fill="transparent"
            stroke={color(s.name)}
            strokeWidth="5"
            strokeDasharray={`${s.share} ${100 - s.share}`}
            strokeDashoffset={offset}
          />
        )
        offset -= s.share
        return el
      })}
    </svg>
  )
}

function Panel({ title, children, className = '' }: { title: string; children: React.ReactNode; className?: string }) {
  return (
    <section className={`rounded-lg border border-white/10 bg-white/[0.03] p-2 ${className}`}>
      <h3 className="text-[11px] font-bold text-white/80">{title}</h3>
      <div className="mt-1.5">{children}</div>
    </section>
  )
}

export function HeroDashboard({ c }: { c: HomeCopy }) {
  const d = c.dashboard

  return (
    <div
      data-testid="campaign-overview"
      className="overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[var(--auth-panel-from)] via-[var(--auth-panel-via)] to-[var(--auth-panel-to)] shadow-[var(--shadow-large)]"
    >
      {/* Window chrome: product, period, and an unmistakable demo label. */}
      <div className="flex flex-wrap items-center gap-2 border-b border-white/10 px-2.5 py-1.5">
        <span className="flex items-center gap-1.5 text-[12px] font-bold text-white">
          <LayoutDashboard size={13} className="text-brand-300" /> CampaignsHub
        </span>
        <span className="flex items-center gap-1 rounded-md border border-white/10 bg-white/[0.05] px-2 py-1 text-[10px] text-white/60">
          <CalendarDays size={10} /> {d.dateRange}
        </span>
        {/* Cost-per-result and ROAS only compare like with like — say which objective this is. */}
        <span className="flex items-center gap-1 rounded-md bg-brand-500/15 px-2 py-1 text-[9.5px] font-semibold text-brand-200">
          {d.objectiveLabel}
        </span>
        <span className="ms-auto flex items-center gap-1 rounded-full bg-warning/15 px-2 py-0.5 text-[9.5px] font-semibold text-warning">
          <Info size={10} /> {d.demoBadge}
        </span>
      </div>

      <div className="space-y-1.5 p-2">
        {/* KPI row with period-over-period movement. */}
        <div className="grid grid-cols-2 gap-1.5 lg:grid-cols-4">
          {d.kpis.map((k) => {
            const Trend = k.up ? TrendingUp : TrendingDown
            return (
              <div key={k.label} className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1.5">
                <div className="truncate text-[9.5px] text-white/45">{k.label}</div>
                <div className="tnum mt-0.5 text-[14px] font-extrabold text-white">{k.value}</div>
                <div className={`tnum mt-0.5 flex items-center gap-1 text-[9.5px] ${k.good ? 'text-success' : 'text-danger'}`}>
                  <Trend size={10} /> {k.delta}
                  <span className="truncate text-white/30">{d.vsPrevious}</span>
                </div>
              </div>
            )
          })}
        </div>

        {/* Campaigns · comparison · distribution — the three views a media buyer opens first. */}
        <div className="grid gap-1.5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,0.92fr)]">
          <Panel title={d.panels.campaigns}>
            <table className="w-full text-[10px]">
              <thead>
                <tr className="text-white/35">
                  <th className="pb-1 pe-2 text-start font-medium">{d.cols.campaign}</th>
                  <th className="pb-1 pe-2 text-start font-medium">{d.cols.platform}</th>
                  <th className="pb-1 ps-2 text-end font-medium">{d.cols.results}</th>
                  <th className="pb-1 ps-2 text-end font-medium">{d.cols.cpr}</th>
                </tr>
              </thead>
              <tbody>
                {d.campaigns.map((row) => (
                  <tr key={row.name} className="border-t border-white/5">
                    <td className="max-w-[118px] truncate py-[3px] pe-2 text-white/80">{row.name}</td>
                    <td className="py-[3px]">
                      <span className="flex items-center gap-1 whitespace-nowrap text-white/55">
                        <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: color(row.platform) }} />
                        {row.platform}
                      </span>
                    </td>
                    <td className="tnum py-[3px] ps-2 text-end font-semibold text-white">{row.results}</td>
                    <td className="tnum py-[3px] ps-2 text-end text-white/55">{row.cpr}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>

          <Panel title={d.panels.comparison}>
            <table className="w-full text-[10px]">
              <thead>
                <tr className="text-white/35">
                  <th className="pb-1 pe-2 text-start font-medium">{d.cols.platform}</th>
                  <th className="pb-1 ps-2 text-end font-medium">{d.cols.spend}</th>
                  <th className="pb-1 ps-2 text-end font-medium">{d.cols.results}</th>
                  <th className="pb-1 ps-2 text-end font-medium">{d.cols.roas}</th>
                </tr>
              </thead>
              <tbody>
                {d.platforms.map((row) => (
                  <tr key={row.name} className="border-t border-white/5">
                    <td className="py-[3px]">
                      <span className="flex items-center gap-1 whitespace-nowrap text-white/75">
                        <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: color(row.name) }} />
                        {row.name}
                      </span>
                    </td>
                    <td className="tnum py-[3px] ps-2 text-end text-white/60">{row.spend}</td>
                    <td className="tnum py-[3px] ps-2 text-end text-white/60">{row.results}</td>
                    <td className="py-[3px] ps-1.5">
                      <span className="flex items-center justify-end gap-1.5">
                        {/* Scaled against the strongest return in view, so the bars compare like for like. */}
                        <span className="hidden h-1 w-9 overflow-hidden rounded-full bg-white/10 sm:block">
                          <span className="block h-full rounded-full" style={{ width: `${(row.roas / 3.6) * 100}%`, background: color(row.name) }} />
                        </span>
                        <span className="tnum font-semibold text-white">{row.roas.toFixed(2)}x</span>
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>

          <Panel title={d.panels.distribution}>
            <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-2">
              <Donut slices={d.platforms} />
              <ul className="grid min-w-[112px] flex-1 grid-cols-1 gap-y-0.5">
                {d.platforms.map((row) => (
                  <li key={row.name} className="flex items-center gap-1.5 text-[10px] whitespace-nowrap">
                    <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: color(row.name) }} />
                    <span className="text-white/65">{row.name}</span>
                    <span className="tnum ms-auto font-semibold text-white/85">{row.share}%</span>
                  </li>
                ))}
              </ul>
            </div>
          </Panel>
        </div>

        {/* Budgets and reports still belong in the picture, but as one line rather than a second row —
            the panel has to stay a wide rectangle that fits on screen without scrolling. */}
        <div className="grid gap-1.5 sm:grid-cols-2">
          <div className="flex items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-2 py-1.5">
            <span className="shrink-0 text-[9.5px] text-white/40">{d.panels.budgets}</span>
            <span className="min-w-0 flex-1 truncate text-[10px] text-white/70">{d.budgets[0].name}</span>
            <span className="h-1 w-12 shrink-0 overflow-hidden rounded-full bg-white/10">
              <span className={`block h-full rounded-full ${d.budgets[0].pct >= 90 ? 'bg-warning' : 'bg-brand-400'}`} style={{ width: `${d.budgets[0].pct}%` }} />
            </span>
            <span className="tnum shrink-0 text-[10px] font-semibold text-white/85">{d.budgets[0].pct}%</span>
          </div>
          <div className="flex items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-2 py-1.5">
            <span className="shrink-0 text-[9.5px] text-white/40">{d.panels.reports}</span>
            <span className="min-w-0 flex-1 truncate text-[10px] text-white/70">{d.reports[0].name}</span>
            <span className="shrink-0 rounded bg-white/10 px-1.5 py-0.5 text-[9px] text-white/55">{d.reports[0].when}</span>
          </div>
        </div>

        <p className="flex items-center justify-center gap-1 pt-0.5 text-[9.5px] text-white/30">
          <Info size={9} /> {d.footnote}
        </p>
      </div>
    </div>
  )
}
