import type { ReactNode } from 'react'
import { AlertTriangle } from 'lucide-react'
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import { money, num, ratio } from '@/features/analytics/format'

/**
 * UnifiedCampaignOverview — the ONE campaign command-center view, rendered from a props view-model so the
 * SAME component (design + metrics + classification) is used by BOTH the marketing homepage preview (fed
 * labeled DEMO data) and the authenticated dashboard (fed real API data). This guarantees "what you see
 * before login is an honest preview of what you get after login" — no divergent pretty mock.
 *
 * It is pure/presentational: the caller maps its data source to `OverviewVM`. Platforms are the six paid
 * channels CampaignsHub unifies: Snapchat, TikTok, Meta, Google Ads, X, LinkedIn.
 */

export type DataStatus = 'demo' | 'live' | 'stale'

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
}

/** Canonical display name + a theme-safe accent per provider key. */
const PROVIDERS: Record<string, { name: string; color: string }> = {
  snapchat: { name: 'Snapchat', color: '#F4C000' },
  tiktok: { name: 'TikTok', color: '#14B8A6' },
  meta: { name: 'Meta', color: '#2563EB' },
  google_ads: { name: 'Google Ads', color: '#EA4335' },
  google: { name: 'Google Ads', color: '#EA4335' },
  x: { name: 'X', color: '#64748B' },
  linkedin: { name: 'LinkedIn', color: '#0A66C2' },
}
export function providerName(key: string): string {
  return PROVIDERS[key]?.name ?? key
}
export function providerColor(key: string): string {
  return PROVIDERS[key]?.color ?? 'var(--brand-500)'
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

function StatusBadge({ status }: { status: DataStatus }) {
  const map = {
    demo: { text: 'معاينة توضيحية ببيانات تجريبية', cls: 'bg-[var(--warning-background)] text-warning' },
    live: { text: 'بيانات فعلية', cls: 'bg-[var(--positive-background)] text-success' },
    stale: { text: 'بيانات قديمة', cls: 'bg-[var(--warning-background)] text-warning' },
  }[status]
  return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${map.cls}`}>{map.text}</span>
}

const SEV: Record<OverviewAlert['severity'], string> = {
  critical: 'text-danger',
  high: 'text-danger',
  medium: 'text-warning',
  low: 'text-text-secondary',
  info: 'text-text-secondary',
}

/**
 * @param vm         the view-model (demo or live)
 * @param title      optional heading (dashboard passes its own; marketing omits)
 * @param onOpenAll  optional CTA (e.g. link to /campaigns) rendered in the header
 */
export function UnifiedCampaignOverview({
  vm,
  title,
  headerRight,
}: {
  vm: OverviewVM
  title?: string
  headerRight?: ReactNode
}) {
  const currency = vm.currency ?? 'SAR'
  const maxSpend = Math.max(1, ...vm.platforms.map((p) => p.spend))

  return (
    <div className="space-y-4" data-testid="campaign-overview">
      {/* Header: data status + last sync */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          {title && <h2 className="text-lg font-extrabold tracking-tight text-text-primary">{title}</h2>}
          <StatusBadge status={vm.dataStatus} />
        </div>
        <div className="flex items-center gap-3 text-xs text-text-muted">
          <span>آخر مزامنة: {vm.lastSyncAt ? new Date(vm.lastSyncAt).toLocaleString('en-GB') : '—'}</span>
          {headerRight}
        </div>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {vm.kpis.map((k) => (
          <div key={k.key} className="rounded-2xl border border-border bg-surface p-3">
            <div className="text-xs font-medium text-text-muted" title={k.hint}>{k.label}</div>
            <div
              className={`mt-1 text-xl font-extrabold tracking-tight tnum ${
                k.tone === 'good' ? 'text-success' : k.tone === 'bad' ? 'text-danger' : 'text-text-primary'
              }`}
            >
              {k.value}
            </div>
          </div>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {/* Platform comparison (spend bars + ROAS) */}
        <div className="rounded-2xl border border-border bg-surface p-4 lg:col-span-2">
          <div className="mb-3 text-sm font-bold text-text-primary">مقارنة أداء المنصات</div>
          <div className="space-y-2.5">
            {vm.platforms.map((p) => (
              <div key={p.key} className="flex items-center gap-3">
                <span className="flex w-24 shrink-0 items-center gap-1.5 text-sm text-text-secondary">
                  <span className="h-2.5 w-2.5 rounded-full" style={{ background: providerColor(p.key) }} />
                  {providerName(p.key)}
                </span>
                <div className="h-6 flex-1 overflow-hidden rounded-lg bg-surface-secondary">
                  <div
                    className="h-full rounded-lg"
                    style={{ width: `${Math.max(4, (p.spend / maxSpend) * 100)}%`, background: providerColor(p.key) }}
                  />
                </div>
                <span className="tnum w-24 text-end text-xs text-text-secondary">{money(p.spend, currency)}</span>
                <span className="tnum w-16 text-end text-xs font-semibold text-text-primary">
                  ROAS {p.roas === null ? '—' : ratio(p.roas)}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Spend distribution donut */}
        <div className="rounded-2xl border border-border bg-surface p-4">
          <div className="mb-2 text-sm font-bold text-text-primary">توزيع الإنفاق</div>
          <div className="h-52">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={vm.spend} dataKey="value" nameKey="name" innerRadius={50} outerRadius={80} paddingAngle={2}>
                  {vm.spend.map((d) => (
                    <Cell key={d.name} fill={providerColor(d.name)} stroke="var(--surface)" strokeWidth={2} />
                  ))}
                </Pie>
                <Tooltip {...tooltip} formatter={(v: number) => money(v, currency)} />
              </PieChart>
            </ResponsiveContainer>
          </div>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {/* Top campaigns */}
        <div className="rounded-2xl border border-border bg-surface p-4 lg:col-span-2">
          <div className="mb-2 text-sm font-bold text-text-primary">أفضل الحملات</div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[480px] text-sm">
              <thead>
                <tr className="border-b border-border text-text-muted">
                  <th className="py-2 text-start font-semibold">الحملة</th>
                  <th className="py-2 text-start font-semibold">المنصة</th>
                  <th className="py-2 text-end font-semibold">الإنفاق</th>
                  <th className="py-2 text-end font-semibold">النتائج</th>
                  <th className="py-2 text-end font-semibold">تكلفة النتيجة</th>
                  <th className="py-2 text-end font-semibold">ROAS</th>
                </tr>
              </thead>
              <tbody>
                {vm.topCampaigns.slice(0, 6).map((c) => (
                  <tr key={c.id} className="border-b border-border last:border-0">
                    <td className="py-2.5 pe-2 font-semibold text-text-primary">{c.name}</td>
                    <td className="py-2.5">
                      <span className="inline-flex items-center gap-1.5 text-text-secondary">
                        <span className="h-2 w-2 rounded-full" style={{ background: providerColor(c.provider) }} />
                        {providerName(c.provider)}
                      </span>
                    </td>
                    <td className="tnum py-2.5 text-end">{money(c.spend, currency)}</td>
                    <td className="tnum py-2.5 text-end">{num(c.results)}</td>
                    <td className="tnum py-2.5 text-end">{c.cpa === null ? '—' : money(c.cpa, currency)}</td>
                    <td className="tnum py-2.5 text-end font-semibold">{c.roas === null ? '—' : ratio(c.roas)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Needs attention + key alerts */}
        <div className="space-y-3">
          <div className="rounded-2xl border border-border bg-surface p-4">
            <div className="mb-2 text-sm font-bold text-text-primary">حملات تحتاج تدخلًا</div>
            {vm.needsAttention.length === 0 ? (
              <p className="text-sm text-text-muted">لا شيء يحتاج تدخلًا الآن.</p>
            ) : (
              <ul className="space-y-2">
                {vm.needsAttention.slice(0, 4).map((n) => (
                  <li key={n.id} className="flex items-start gap-2 text-sm">
                    <AlertTriangle size={15} className="mt-0.5 shrink-0 text-warning" />
                    <span><span className="font-semibold text-text-primary">{n.name}</span> — <span className="text-text-secondary">{n.reason}</span></span>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div className="rounded-2xl border border-border bg-surface p-4">
            <div className="mb-2 text-sm font-bold text-text-primary">التنبيهات المهمة</div>
            {vm.alerts.length === 0 ? (
              <p className="text-sm text-text-muted">لا تنبيهات حرجة.</p>
            ) : (
              <ul className="space-y-2">
                {vm.alerts.slice(0, 4).map((a, i) => (
                  <li key={i} className="flex items-start gap-2 text-sm">
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${a.severity === 'critical' || a.severity === 'high' ? 'bg-danger' : a.severity === 'medium' ? 'bg-warning' : 'bg-border-strong'}`} />
                    <span className={SEV[a.severity]}>{a.text}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
