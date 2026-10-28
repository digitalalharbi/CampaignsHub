import { campaignStatusLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { Num } from '@/components/ui/Num'
import type { LivePayload } from '../api'

/**
 * NASEL-CAMPAIGN-BREAKDOWN-001 — the client-safe campaign table a link carries only when its settings switch it on.
 *
 * Every campaign with figures in the window, whatever its objective: the objective is a column, never a filter. The four columns are
 * the primary KPIs (under the link's own names where it renamed them); a figure the platform did not report reads «غير متاح», and a
 * spend the link hides is a column that does not exist rather than a column of blanks.
 */
export function LiveCampaignBreakdown({ payload, currency, ar }: { payload: LivePayload; currency: string; ar: boolean }) {
  const rows = payload.campaign_rows ?? []
  if (rows.length === 0) return null

  const locale = ar ? 'ar' : 'en'
  const label = (key: string, fallbackAr: string, fallbackEn: string) => payload.kpi_labels?.[key] || (ar ? fallbackAr : fallbackEn)
  const showSpend = rows.some((r) => r.spend !== null)
  const na = ar ? 'غير متاح' : 'Not available'
  const n = (v: number | null) => (v === null ? na : v.toLocaleString('en-US', { maximumFractionDigits: 2 }))
  const money = (v: number | null) => (v === null ? na : `${v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`)

  return (
    <section data-testid="live-campaign-breakdown" className="rounded-2xl border border-border bg-surface p-4">
      <h3 className="mb-3 text-sm font-bold text-text-primary">{ar ? `الحملات (${rows.length})` : `Campaigns (${rows.length})`}</h3>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-sm">
          <thead>
            <tr className="border-b border-border text-start text-xs text-text-muted">
              <th className="px-2 py-2 text-start font-semibold">{ar ? 'الحملة' : 'Campaign'}</th>
              <th className="px-2 py-2 text-start font-semibold">{ar ? 'المنصة' : 'Platform'}</th>
              <th className="px-2 py-2 text-start font-semibold">{ar ? 'الحالة' : 'Status'}</th>
              <th className="px-2 py-2 text-start font-semibold">{ar ? 'الهدف' : 'Objective'}</th>
              {showSpend && <th className="px-2 py-2 text-end font-semibold">{label('spend', 'الإنفاق', 'Spend')}</th>}
              <th className="px-2 py-2 text-end font-semibold">{label('impressions', 'الظهور', 'Impressions')}</th>
              <th className="px-2 py-2 text-end font-semibold">{label('clicks', 'النقرات', 'Clicks')}</th>
              <th className="px-2 py-2 text-end font-semibold">{label('native_leads', 'عملاء النموذج الأصلي', 'Native-form leads')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={i} data-testid="live-campaign-row" className="border-b border-border/60 last:border-0">
                <td className="max-w-[18rem] truncate px-2 py-2 font-medium text-text-primary" title={r.name}>{r.name}</td>
                <td className="px-2 py-2">{providerLabel(r.platform, locale)}</td>
                <td className="px-2 py-2">{r.status ? campaignStatusLabel(r.status, locale) : '—'}</td>
                <td className="px-2 py-2">{r.objective ? objectiveLabel(r.objective, locale) : '—'}</td>
                {showSpend && <td className="tnum px-2 py-2 text-end"><Num>{money(r.spend)}</Num></td>}
                <td className="tnum px-2 py-2 text-end"><Num>{n(r.impressions)}</Num></td>
                <td className="tnum px-2 py-2 text-end"><Num>{n(r.clicks)}</Num></td>
                <td className="tnum px-2 py-2 text-end"><Num>{n(r.native_leads)}</Num></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
