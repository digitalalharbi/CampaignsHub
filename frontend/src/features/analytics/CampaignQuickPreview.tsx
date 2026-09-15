import { Link, useLocation } from 'react-router-dom'
import { Area, AreaChart, ResponsiveContainer } from 'recharts'
import { X } from 'lucide-react'
import type { CampaignRow, MetricFilters, Range } from './api'
import { useTimeseries } from './api'
import { canonicalObjectiveLabel } from '@/features/campaigns/canonicalObjectives'
import { canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'
import { formatMoneyReading, readCostPer, readMoney, readRoas } from '@/lib/money/contract'
import { money, moneyExact, num } from './format'
import { useUi } from '@/stores/ui'

/**
 * VISUAL-DECISION-001 — a shallow answer, and a door to the deep one.
 *
 * Analytics is the cross-platform workspace and must not become a second Campaigns page, so this
 * says only enough to decide whether to open the campaign: what it is, what it cost, what it
 * returned, and which way it is moving. Everything else is one click away in the canonical Campaign
 * Detail, which is the surface that answers «why is THIS campaign performing this way».
 *
 * ## Nothing here is computed
 *
 * Every figure comes off the row the table already has, through the same money contract the table
 * reads it with — reported value renders as the value, a reported zero renders as `0`, and an
 * unreported or unjoinable figure renders as «—» rather than a number nobody sent. The trend is the
 * canonical timeseries endpoint narrowed to this campaign by the filter the API already takes, so
 * the line in this popup and the line on the page behind it come from one aggregator. A popup that
 * did its own arithmetic would be a second opinion about money, which is the thing this codebase
 * spends most of its guards preventing.
 *
 * ## Objective-aware, because a column every campaign shares is not a figure every campaign has
 *
 * ROAS is shown where revenue is genuinely reported and withheld where it is not. An awareness
 * campaign is not given a blank where its verdict should be.
 */
export function CampaignQuickPreview({
  row,
  projectId,
  range,
  filters,
  onClose,
}: {
  row: CampaignRow
  projectId: string | null
  range: Range
  filters?: MetricFilters
  onClose: () => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const location = useLocation()

  /*
   * The same endpoint, narrowed to this campaign — never a second series.
   *
   * `MetricFilters.campaign` is the axis the API already accepts, so this line is the page's own
   * line with one campaign selected. It is deliberately tiny: the decision it serves is «is this
   * rising or falling», and a reader who needs the shape opens the campaign.
   */
  const trend = useTimeseries(projectId, range, { ...filters, campaign: [row.campaign_id] })
  const points = (trend.data ?? []).map((p) => ({ v: Number(p.spend ?? 0) }))

  const spend = readMoney(row, 'spend', null, ar)
  const revenue = readMoney(row, 'revenue', null, ar)
  const costPer = readCostPer(row, 'cpa', 'conversions', null, ar)
  const roas = readRoas(row, ar)

  const status = row.status ?? null
  const tone =
    status === 'active' ? 'text-success' : status === 'paused' ? 'text-warning' : 'text-text-muted'

  return (
    <div
      data-testid="campaign-quick-preview"
      className="w-[320px] max-w-[92vw] rounded-xl border border-border bg-surface p-3 shadow-lg"
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="truncate text-sm font-bold text-text-primary" title={row.campaign_name ?? undefined}>
            {row.campaign_name ?? (ar ? 'حملة لم يعد اسمها محفوظًا' : 'A campaign whose name is no longer held')}
          </div>
          {/* Identity, as chips rather than a sentence — VISUAL-DECISION-001. */}
          <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-text-secondary">
            <span>{providerLabel(canonicalPlatform(row.provider), ar ? 'ar' : 'en')}</span>
            {/*
              * The family through the shared label, never the raw key.
              *
              * `objective_family` arrives as «sales», computed by the backend so nothing here has to
              * map an objective — but it is an identifier, and printing it put an English enum
              * member in an Arabic chip row. `canonicalObjectiveLabel` is the same translation the
              * filters above this table already use.
              */}
            {row.objective_family !== null && <span aria-hidden>·</span>}
            {row.objective_family !== null && <span>{canonicalObjectiveLabel(row.objective_family, ar ? 'ar' : 'en')}</span>}
            <span aria-hidden>·</span>
            <span className={tone}>{statusLabel(status, ar)}</span>
          </div>
        </div>
        <button
          onClick={onClose}
          data-testid="campaign-quick-preview-close"
          className="rounded p-1 text-text-muted hover:bg-surface-hover hover:text-text-primary"
          aria-label={ar ? 'إغلاق' : 'Close'}
        >
          <X size={14} />
        </button>
      </div>

      <div className="mt-3 grid grid-cols-2 gap-2">
        <Figure label={ar ? 'الإنفاق' : 'Spend'} value={formatMoneyReading(spend, money)} />
        <Figure label={ar ? 'النتائج' : 'Results'} value={row.conversions === null ? '—' : num(row.conversions)} />
        <Figure label={ar ? 'تكلفة النتيجة' : 'Cost / result'} value={formatMoneyReading(costPer, moneyExact)} />
        {/* Revenue and ROAS only where the platform reported revenue — never a blank verdict. */}
        {revenue.kind === 'unavailable' && roas.value === null
          ? <Figure label={ar ? 'الإيرادات' : 'Revenue'} value="—" title={revenue.note ?? undefined} />
          : <Figure label="ROAS" value={roas.value === null ? '—' : `${roas.value.toFixed(2)}×`} title={roas.note ?? undefined} />}
      </div>

      {points.length > 1 && (
        <div className="mt-3 h-10" data-testid="campaign-quick-preview-trend">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={points} margin={{ top: 2, right: 0, bottom: 0, left: 0 }}>
              <Area type="monotone" dataKey="v" stroke="var(--color-accent)" fill="var(--color-accent)" fillOpacity={0.15} strokeWidth={1.5} dot={false} isAnimationActive={false} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      )}

      {projectId !== null && (
        <Link
          to={{ pathname: `${portalBaseOf(location.pathname) ?? '/app'}/campaigns/${projectId}/${row.campaign_id}`, search: location.search }}
          state={{ from: `${location.pathname}${location.search}` }}
          data-testid="campaign-quick-preview-cta"
          className="mt-3 block rounded-lg bg-accent px-3 py-2 text-center text-xs font-semibold text-on-accent hover:opacity-90"
        >
          {ar ? 'عرض تحليل الحملة' : 'View campaign analysis'}
        </Link>
      )}
    </div>
  )
}

function Figure({ label, value, title }: { label: string; value: string; title?: string }) {
  return (
    <div title={title}>
      <div className="text-[11px] text-text-muted">{label}</div>
      <div className="tnum text-sm font-semibold text-text-primary">{value}</div>
    </div>
  )
}

function statusLabel(status: string | null, ar: boolean): string {
  if (status === 'active') return ar ? 'نشطة' : 'Active'
  if (status === 'paused') return ar ? 'موقوفة' : 'Paused'
  if (status === 'draft') return ar ? 'مسودة' : 'Draft'

  return ar ? 'غير معروفة' : 'Unknown'
}

/** The operator portals that mount a campaign route; see `CampaignLink` for the whole reasoning. */
function portalBaseOf(pathname: string): string | null {
  if (pathname.startsWith('/agency')) return '/agency'
  if (pathname.startsWith('/app')) return '/app'

  return null
}
