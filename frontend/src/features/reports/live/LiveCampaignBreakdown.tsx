import { campaignStatusLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { compact, moneyExact } from '@/features/analytics/format'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import type { LiveCampaignRow, LivePayload } from '../api'
import { landingLabel } from './leadResults'

/**
 * NASEL-CAMPAIGN-BREAKDOWN-001 — the client-safe campaign table a link carries only when its settings switch it on.
 *
 * Every campaign in the link's scope, whatever its objective: the objective is a column, never a filter. The four figures are the
 * primary KPIs (under the link's own names where it renamed them); a figure the platform did not report reads «غير متاح», a campaign
 * still awaiting metrics says so beside its status, and a spend the link hides is a column that does not exist rather than a column
 * of blanks. Drawn through `MetricTable`, so figures are centred and sortable like every other client table.
 */
export function LiveCampaignBreakdown({ payload, currency, ar }: { payload: LivePayload; currency: string; ar: boolean }) {
  const rows = payload.campaign_rows ?? []
  if (rows.length === 0) return null

  const locale = ar ? 'ar' : 'en'
  const label = (key: string, fallbackAr: string, fallbackEn: string) => payload.kpi_labels?.[key] || (ar ? fallbackAr : fallbackEn)
  const showSpend = rows.some((r) => r.spend !== null)
  // NASEL 2026-10-29 — landing-page completions beside native leads, from the same resolver, when the link maps a landing event.
  const showLanding = Object.values(payload.results?.leads?.events ?? {}).some((paths) => typeof paths.landing_page === 'string')
  const na = ar ? 'غير متاح' : 'Not available'
  const STATE: Record<string, [string, string]> = {
    zero: ['لم تسجّل نشاطًا', 'No activity'],
    no_data: ['بانتظار البيانات', 'Awaiting data'],
    disconnected: ['الحساب غير متصل', 'Account disconnected'],
  }
  const stateText = (s?: string) => (s && STATE[s] ? (ar ? STATE[s][0] : STATE[s][1]) : null)
  const count = (v: number | null) => (v === null ? na : compact(v))

  const head = [
    ar ? 'الحملة' : 'Campaign',
    ar ? 'المنصة' : 'Platform',
    ar ? 'الحالة' : 'Status',
    ar ? 'الهدف' : 'Objective',
    ...(showSpend ? [label('spend', 'الإنفاق', 'Spend')] : []),
    label('impressions', 'الظهور', 'Impressions'),
    label('clicks', 'النقرات', 'Clicks'),
    label('native_leads', 'عملاء النموذج الأصلي', 'Native-form leads'),
    ...(showLanding ? [landingLabel(payload, ar)] : []),
  ]

  const cells = (r: LiveCampaignRow) => [
    <span key="name" data-testid="live-campaign-row" data-state={r.data_state ?? 'reported'} className="font-medium text-text-primary" title={r.name}>{r.name}</span>,
    <span key="platform">{providerLabel(r.platform, locale)}</span>,
    <span key="status">
      {r.status ? campaignStatusLabel(r.status, locale) : '—'}
      {stateText(r.data_state) && <span data-testid="live-campaign-state" className="ms-1 rounded-full bg-surface-secondary px-1.5 py-0.5 text-[10px] text-text-muted">{stateText(r.data_state)}</span>}
    </span>,
    <span key="objective">{r.objective ? objectiveLabel(r.objective, locale) : '—'}</span>,
    ...(showSpend ? [<span key="spend" dir="ltr">{r.spend === null ? na : moneyExact(r.spend, currency)}</span>] : []),
    <span key="impressions" dir="ltr">{count(r.impressions)}</span>,
    <span key="clicks" dir="ltr">{count(r.clicks)}</span>,
    <span key="native" dir="ltr">{count(r.native_leads)}</span>,
    ...(showLanding ? [<span key="landing" dir="ltr">{count(r.landing_leads)}</span>] : []),
  ]

  const values = rows.map((r): SortValues => [
    r.name,
    r.platform,
    r.status ?? '',
    r.objective ?? '',
    ...(showSpend ? [r.spend] : []),
    r.impressions,
    r.clicks,
    r.native_leads,
    ...(showLanding ? [r.landing_leads] : []),
  ])

  return (
    <section data-testid="live-campaign-breakdown" className="rounded-2xl border border-border bg-surface p-4">
      <h3 className="mb-3 text-sm font-bold text-text-primary">{ar ? `الحملات (${rows.length})` : `Campaigns (${rows.length})`}</h3>
      <MetricTable head={head} rows={rows.map(cells)} values={values} />
    </section>
  )
}
