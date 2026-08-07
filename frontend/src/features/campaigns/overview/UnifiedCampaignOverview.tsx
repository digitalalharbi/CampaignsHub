import type { ReactNode } from 'react'
import { AlertTriangle } from 'lucide-react'
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import { money, num, ratio } from '@/features/analytics/format'

/**
 * UnifiedCampaignOverview — the ONE campaign command-center view, rendered from a props view-model so the
 * SAME component (design + metrics + classification + view-model) is used by BOTH the marketing homepage
 * preview (variant="marketing", labeled DEMO data) and the authenticated dashboard (variant="dashboard",
 * real API data). One component + one logic, differing only in styling/density — never two divergent designs.
 * Platforms are the six paid channels CampaignsHub unifies: Snapchat, TikTok, Meta, Google Ads, X, LinkedIn.
 */

export type DataStatus = 'demo' | 'live' | 'stale'
export type OverviewVariant = 'dashboard' | 'marketing'

export interface OverviewKpi {
  key: string
  label: string
  value: string
  hint?: string
  tone?: 'good' | 'bad' | 'neutral'
}
export interface OverviewPlatform {
  key: string
  name: string
  spend: number
  results: number
  roas: number | null
}
export interface OverviewCampaign {
  id: string
  name: string
  provider: string
  spend: number
  results: number
  cpa: number | null
  roas: number | null
}
export interface OverviewAttention {
  id: string
  name: string
  reason: string
}
export interface OverviewAlert {
  severity: 'critical' | 'high' | 'medium' | 'low' | 'info'
  text: string
}
export interface OverviewCreative {
  id: string
  name: string
  provider: string
  kind: string
  results: number
  cpa: number | null
}
export interface OverviewVM {
  currency?: string
  dataStatus: DataStatus
  lastSyncAt?: string | null
  kpis: OverviewKpi[]
  platforms: OverviewPlatform[]
  spend: { name: string; value: number }[]
  topCampaigns: OverviewCampaign[]
  needsAttention: OverviewAttention[]
  alerts: OverviewAlert[]
  topCreatives?: OverviewCreative[]
}

/** Canonical display name + a theme-safe accent per provider key. */
const PROVIDERS: Record<string, { name: string; color: string }> = {
  snapchat: { name: 'Snapchat', color: '#F4C000' },
  tiktok: { name: 'TikTok', color: '#14B8A6' },
  meta: { name: 'Meta', color: '#3B82F6' },
  google_ads: { name: 'Google Ads', color: '#EA4335' },
  google: { name: 'Google Ads', color: '#EA4335' },
  x: { name: 'X', color: '#94A3B8' },
  linkedin: { name: 'LinkedIn', color: '#0A66C2' },
}
export function providerName(key: string): string {
  return PROVIDERS[key]?.name ?? key
}
export function providerColor(key: string): string {
  return PROVIDERS[key]?.color ?? 'var(--brand-500)'
}

/** Variant-derived class tokens: dashboard = light surfaces; marketing = dark hero surfaces. */
function palette(variant: OverviewVariant) {
  return variant === 'marketing'
    ? {
        card: 'border-white/10 bg-white/5',
        title: 'text-white',
        label: 'text-white/55',
        value: 'text-white',
        muted: 'text-white/45',
        sub: 'text-white/70',
        track: 'bg-white/10',
        rowBorder: 'border-white/10',
      }
    : {
        card: 'border-border bg-surface',
        title: 'text-text-primary',
        label: 'text-text-muted',
        value: 'text-text-primary',
        muted: 'text-text-muted',
        sub: 'text-text-secondary',
        track: 'bg-surface-secondary',
        rowBorder: 'border-border',
      }
}

const tooltip = {
  contentStyle: {
    background: 'var(--surface)',
    border: '1px solid var(--border)',
    borderRadius: 12,
    fontSize: 13,
    color: 'var(--text-primary)',
  },
}

function StatusBadge({ status, variant, words }: {
  status: DataStatus
  variant: OverviewVariant
  /** Passed in rather than read here: this helper renders outside the component that knows the language. */
  words: { demo: string; stale: string; live: string }
}) {
  const label =
    status === 'demo' ? words.demo : status === 'stale' ? words.stale : words.live
  const cls =
    variant === 'marketing'
      ? 'bg-white/10 text-white/80'
      : status === 'live'
        ? 'bg-[var(--positive-background)] text-success'
        : 'bg-[var(--warning-background)] text-warning'
  return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${cls}`}>{label}</span>
}

const SEV: Record<OverviewAlert['severity'], string> = {
  critical: 'text-danger',
  high: 'text-danger',
  medium: 'text-warning',
  low: 'text-text-secondary',
  info: 'text-text-secondary',
}

/**
 * @param vm       the view-model (demo or live)
 * @param variant  'dashboard' (light) | 'marketing' (dark hero)
 * @param compact  tighter density (mobile / marketing hero)
 * @param title    optional heading
 * @param headerRight optional CTA node in the header
 */

/**
 * The overview's own words, in both languages (APP-100).
 *
 * This component was Arabic-only, so the advertiser dashboard rendered Arabic headings under
 * `dir="ltr"` the moment somebody chose English — the interface changed direction and the content
 * did not, which reads as broken rather than as unfinished.
 *
 * `lang` defaults to `ar`: this is an Arabic-first product, and a caller that has not been taught
 * about the switch yet keeps the behaviour it had.
 */
const OVERVIEW_COPY = {
  ar: {
    demo: 'معاينة توضيحية ببيانات تجريبية',
    stale: 'بيانات قديمة',
    live: 'بيانات فعلية',
    lastSync: 'آخر مزامنة',
    platformComparison: 'مقارنة أداء المنصات',
    spendDistribution: 'توزيع الإنفاق',
    topCampaigns: 'أفضل الحملات',
    campaign: 'الحملة',
    platform: 'المنصة',
    spend: 'الإنفاق',
    results: 'النتائج',
    cost: 'التكلفة',
    needsAttention: 'حملات تحتاج تدخلًا',
    nothingNeedsAttention: 'لا شيء يحتاج تدخلًا الآن.',
    topAlerts: 'أهم التنبيهات',
    noCriticalAlerts: 'لا تنبيهات حرجة.',
    topCreatives: 'أفضل المحتويات الإعلانية',
    resultUnit: 'نتيجة',
  },
  en: {
    demo: 'Illustrative preview with demo data',
    stale: 'Data is out of date',
    live: 'Live data',
    lastSync: 'Last sync',
    platformComparison: 'Platform performance',
    spendDistribution: 'Where the spend went',
    topCampaigns: 'Best campaigns',
    campaign: 'Campaign',
    platform: 'Platform',
    spend: 'Spend',
    results: 'Results',
    cost: 'Cost',
    needsAttention: 'Campaigns needing attention',
    nothingNeedsAttention: 'Nothing needs attention right now.',
    topAlerts: 'Top alerts',
    noCriticalAlerts: 'No critical alerts.',
    topCreatives: 'Best creatives',
    resultUnit: 'results',
  },
} as const

export function UnifiedCampaignOverview({
  vm,
  variant = 'dashboard',
  compact = false,
  title,
  headerRight,
  lang = 'ar',
}: {
  vm: OverviewVM
  variant?: OverviewVariant
  compact?: boolean
  title?: string
  headerRight?: ReactNode
  lang?: 'ar' | 'en'
}) {
  const w = OVERVIEW_COPY[lang]
  const currency = vm.currency ?? 'SAR'
  const c = palette(variant)
  const isMarketing = variant === 'marketing'
  const lg = !compact // dashboard = larger, more readable typography; marketing preview stays compact
  const maxSpend = Math.max(1, ...vm.platforms.map((p) => p.spend))
  const topN = isMarketing ? 3 : compact ? 4 : 6
  const donutH = isMarketing ? 150 : compact ? 168 : 208
  // Marketing is a COMPACT preview: 4 KPIs, platforms+donut side-by-side, 3 top campaigns — the deeper
  // needs-attention / alerts / creatives blocks are dashboard-only so the hero stays short and balanced.
  const kpis = isMarketing ? vm.kpis.slice(0, 4) : vm.kpis
  const sevDot = variant === 'marketing' ? 'bg-white/40' : 'bg-border-strong'

  return (
    <div className="space-y-3" data-testid="campaign-overview" data-variant={variant}>
      {/* Header: data status + last sync */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {title && <h2 className={`text-base font-extrabold tracking-tight ${c.title}`}>{title}</h2>}
          <StatusBadge status={vm.dataStatus} variant={variant} words={w} />
        </div>
        <div className={`flex items-center gap-3 text-xs ${c.muted}`}>
          <span>{w.lastSync}: {vm.lastSyncAt ? new Date(vm.lastSyncAt).toLocaleString('en-GB') : '—'}</span>
          {headerRight}
        </div>
      </div>

      {/*
        KPI row — omitted entirely when the caller has none.

        `/app/dashboard` moved its headline figures to the objective-aware `MetricStrip` above this
        component (UX-DASH-001) and passes an empty list. Without the guard this rendered an empty
        grid: invisible, but a real gap in the vertical rhythm between the filters and the first
        chart, which reads as something that failed to load.
      */}
      {kpis.length > 0 && (
      <div className={`grid grid-cols-2 gap-2 ${isMarketing ? 'sm:grid-cols-4' : compact ? 'sm:grid-cols-3' : 'md:grid-cols-3 xl:grid-cols-6'}`}>
        {kpis.map((k) => (
          <div key={k.key} className={`rounded-xl border ${lg ? 'p-4' : 'p-2.5'} ${c.card}`}>
            <div className={`${lg ? 'text-sm' : 'text-[11px]'} font-medium ${c.label}`} title={k.hint}>{k.label}</div>
            <div
              className={`${lg ? 'mt-1' : 'mt-0.5'} font-extrabold tracking-tight tnum ${lg ? 'text-2xl leading-none' : 'text-lg'} ${
                k.tone === 'good' ? 'text-success' : k.tone === 'bad' ? 'text-danger' : c.value
              }`}
            >
              {k.value}
            </div>
          </div>
        ))}
      </div>
      )}

      <div className={`grid gap-3 ${isMarketing ? 'sm:grid-cols-3' : compact ? '' : 'lg:grid-cols-3'}`}>
        {/* Platform comparison (spend bars + ROAS) */}
        <div className={`rounded-xl border p-3 ${c.card} ${isMarketing ? 'sm:col-span-2' : compact ? '' : 'lg:col-span-2'}`}>
          <div className={`mb-2 ${lg ? 'text-base' : 'text-sm'} font-bold ${c.title}`}>{w.platformComparison}</div>
          <div className="space-y-2">
            {vm.platforms.map((p) => (
              <div key={p.key} className="flex items-center gap-2">
                <span className={`flex w-20 shrink-0 items-center gap-1.5 ${lg ? 'text-sm' : 'text-xs'} ${c.sub}`}>
                  <span className="h-2.5 w-2.5 rounded-full" style={{ background: providerColor(p.key) }} />
                  {providerName(p.key)}
                </span>
                <div className={`h-5 flex-1 overflow-hidden rounded-md ${c.track}`}>
                  <div className="h-full rounded-md" style={{ width: `${Math.max(4, (p.spend / maxSpend) * 100)}%`, background: providerColor(p.key) }} />
                </div>
                <span className={`tnum w-20 text-end ${lg ? 'text-sm' : 'text-xs'} ${c.sub}`}>{money(p.spend, currency)}</span>
                <span className={`tnum w-14 text-end text-xs font-semibold ${c.value}`}>{p.roas === null ? '—' : `${ratio(p.roas)}`}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Spend distribution donut */}
        <div className={`rounded-xl border p-3 ${c.card}`}>
          <div className={`mb-1 ${lg ? 'text-base' : 'text-sm'} font-bold ${c.title}`}>{w.spendDistribution}</div>
          <div style={{ height: donutH }}>
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={vm.spend} dataKey="value" nameKey="name" innerRadius={compact ? 40 : 50} outerRadius={compact ? 64 : 80} paddingAngle={2}>
                  {vm.spend.map((d) => (
                    <Cell key={d.name} fill={providerColor(d.name)} stroke={variant === 'marketing' ? 'transparent' : 'var(--surface)'} strokeWidth={2} />
                  ))}
                </Pie>
                <Tooltip {...tooltip} formatter={(v: number) => money(v, currency)} />
              </PieChart>
            </ResponsiveContainer>
          </div>
        </div>
      </div>

      {/*
        `min-w-0` on the grid ITEM is what makes the scroller below work (APP-100).

        A grid item defaults to `min-width: auto`, so it refuses to shrink under its content: the
        table's `min-w-[420px]` pushed this column to 420px on a 375px phone, the whole page went
        with it, and `overflow-x-auto` never got the chance to clip anything. The heading and the
        two panels beside it were dragged along, which is why the offending element looked like a
        title rather than a table.
      */}
      <div className={`grid gap-3 ${compact ? '' : 'lg:grid-cols-3'}`}>
        {/* Top campaigns */}
        <div className={`min-w-0 rounded-xl border p-3 ${c.card} ${compact ? '' : 'lg:col-span-2'}`}>
          <div className={`mb-1 ${lg ? 'text-base' : 'text-sm'} font-bold ${c.title}`}>{w.topCampaigns}</div>
          <div className="overflow-x-auto">
            <table className={`w-full min-w-[420px] ${lg ? 'text-sm' : 'text-xs'}`}>
              <thead>
                <tr className={`border-b ${c.rowBorder} ${c.muted}`}>
                  <th className="py-1.5 text-start font-semibold">{w.campaign}</th>
                  <th className="py-1.5 text-start font-semibold">{w.platform}</th>
                  <th className="py-1.5 text-end font-semibold">{w.spend}</th>
                  <th className="py-1.5 text-end font-semibold">{w.results}</th>
                  <th className="py-1.5 text-end font-semibold">{w.cost}</th>
                  <th className="py-1.5 text-end font-semibold">ROAS</th>
                </tr>
              </thead>
              <tbody>
                {vm.topCampaigns.slice(0, topN).map((cp) => (
                  <tr key={cp.id} className={`border-b last:border-0 ${c.rowBorder}`}>
                    <td className={`py-1.5 pe-2 font-semibold ${c.value}`}>{cp.name}</td>
                    <td className="py-1.5">
                      <span className={`inline-flex items-center gap-1.5 ${c.sub}`}>
                        <span className="h-2 w-2 rounded-full" style={{ background: providerColor(cp.provider) }} />
                        {providerName(cp.provider)}
                      </span>
                    </td>
                    <td className={`tnum py-1.5 text-end ${c.sub}`}>{money(cp.spend, currency)}</td>
                    <td className={`tnum py-1.5 text-end ${c.sub}`}>{num(cp.results)}</td>
                    <td className={`tnum py-1.5 text-end ${c.sub}`}>{cp.cpa === null ? '—' : money(cp.cpa, currency)}</td>
                    <td className={`tnum py-1.5 text-end font-semibold ${c.value}`}>{cp.roas === null ? '—' : ratio(cp.roas)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Needs attention + key alerts + top creatives — dashboard detail only (hidden on the compact marketing preview) */}
        {!isMarketing && (
        <div className="space-y-3">
          <div className={`rounded-xl border p-3 ${c.card}`}>
            <div className={`mb-1.5 ${lg ? 'text-base' : 'text-sm'} font-bold ${c.title}`}>{w.needsAttention}</div>
            {vm.needsAttention.length === 0 ? (
              <p className={`text-xs ${c.muted}`}>{w.nothingNeedsAttention}</p>
            ) : (
              <ul className="space-y-1.5">
                {vm.needsAttention.slice(0, 3).map((n) => (
                  <li key={n.id} className="flex items-start gap-2 text-xs">
                    <AlertTriangle size={13} className="mt-0.5 shrink-0 text-warning" />
                    <span><span className={`font-semibold ${c.value}`}>{n.name}</span> — <span className={c.sub}>{n.reason}</span></span>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div className={`rounded-xl border p-3 ${c.card}`}>
            <div className={`mb-1.5 ${lg ? 'text-base' : 'text-sm'} font-bold ${c.title}`}>{w.topAlerts}</div>
            {vm.alerts.length === 0 ? (
              <p className={`text-xs ${c.muted}`}>{w.noCriticalAlerts}</p>
            ) : (
              <ul className="space-y-1.5">
                {vm.alerts.slice(0, 3).map((a, i) => (
                  <li key={i} className="flex items-start gap-2 text-xs">
                    <span className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${a.severity === 'critical' || a.severity === 'high' ? 'bg-danger' : a.severity === 'medium' ? 'bg-warning' : sevDot}`} />
                    <span className={SEV[a.severity]}>{a.text}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
          {vm.topCreatives && vm.topCreatives.length > 0 && (
            <div className={`rounded-xl border p-3 ${c.card}`}>
              <div className={`mb-1.5 ${lg ? 'text-base' : 'text-sm'} font-bold ${c.title}`}>{w.topCreatives}</div>
              <ul className="space-y-1.5">
                {vm.topCreatives.slice(0, 3).map((cr) => (
                  <li key={cr.id} className="flex items-center justify-between gap-2 text-xs">
                    <span className={`flex items-center gap-1.5 ${c.value}`}>
                      <span className="h-2 w-2 rounded-full" style={{ background: providerColor(cr.provider) }} />
                      <span className="font-semibold">{cr.name}</span>
                      <span className={c.muted}>· {cr.kind}</span>
                    </span>
                    <span className={`tnum ${c.sub}`}>{num(cr.results)} {w.resultUnit}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
        )}
      </div>
    </div>
  )
}
