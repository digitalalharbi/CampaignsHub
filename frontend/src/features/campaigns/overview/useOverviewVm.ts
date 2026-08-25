/**
 * ANALYTICS-AS-DASHBOARD-001 — the command-centre view model, built in ONE place.
 *
 * `DashboardPage` assembled this inline. When the analytics board became «لوحة التحكم» the overview
 * had to move with it, and the choice was to copy ~40 lines of assembly or to extract them. This
 * codebase has already paid for the first option twice — `readMetric` against `moneyFromTotals`, and
 * the dashboard's platform rows against its own KPI card — so it is extracted.
 *
 * Every money figure goes through {@link displaySpend} / {@link withheldCurrencyOf}, which are the
 * same readers the KPI strip uses. No money rule lives here.
 */
import { useMemo } from 'react'

import { displaySpend, withheldCurrencyOf } from '@/features/dashboard/platformMoney'
import { dash } from '@/features/analytics/metricLabels'
import { ratio } from '@/features/analytics/format'
import type { OverviewVM } from './UnifiedCampaignOverview'
import { providerName } from './UnifiedCampaignOverview'

type CampaignRow = {
  campaign_id: string | number
  campaign_name?: string | null
  provider: string
  spend: number
  conversions: number
  cpa?: number | null
  roas?: number | null
}

type PlatformRow = Parameters<typeof displaySpend>[0] & { provider: string; roas?: number | null }

type FreshnessRow = { provider: string; name?: string | null; last_sync_at?: string | null; last_sync_status?: string | null }

type BudgetRow = { campaign_name: string; pace?: number | null }

export function useOverviewVm(input: {
  campaigns: CampaignRow[] | undefined
  platforms: PlatformRow[] | undefined
  freshness: FreshnessRow[] | undefined
  budget: BudgetRow[] | undefined
  currency: string | null | undefined
  source: 'live' | 'demo' | 'mixed' | 'none' | undefined
  ar: boolean
}): OverviewVM {
  const { campaigns, platforms, freshness, budget, currency, source, ar } = input

  const rows = useMemo(() => platforms ?? [], [platforms])
  const withheldCurrency = useMemo(() => withheldCurrencyOf(rows), [rows])

  /**
   * A campaign that spent real money and produced almost nothing.
   *
   * The threshold is deliberately the same one the alert list uses: two lists derived from one
   * condition, written twice, is how a campaign appears in «تحتاج تدخلًا» and not in the alerts.
   */
  const struggling = useMemo(
    () => (campaigns ?? []).filter((c) => c.spend > 3000 && c.conversions < 2),
    [campaigns],
  )

  const alerts = useMemo(() => {
    const out: { severity: 'high' | 'medium'; text: string }[] = []

    freshness?.forEach((f) => {
      if (f.last_sync_status !== 'failed') return
      const who = f.name ?? providerName(f.provider)
      out.push({ severity: 'high', text: ar ? `فشل مزامنة ${who} — يتطلب إعادة ربط` : `${who} sync failed — needs reconnecting` })
    })
    budget?.forEach((b) => {
      if ((b.pace ?? 0) > 1.4) out.push({ severity: 'medium', text: `${b.campaign_name}: ${dash('pacingAhead', ar)} (${ratio(b.pace ?? 0, '×')})` })
    })
    struggling.forEach((c) => {
      out.push({ severity: 'high', text: `${c.campaign_name}: ${dash('spendNoConversions', ar)}` })
    })

    return out.slice(0, 4)
  }, [freshness, budget, struggling, ar])

  const lastSync = useMemo(
    () => freshness?.map((f) => f.last_sync_at).filter(Boolean).sort().at(-1) ?? null,
    [freshness],
  )

  return useMemo(
    () => ({
      currency: withheldCurrency ?? currency ?? 'SAR',
      /*
       * `DataStatus` has three cases — demo, live, stale — and «mixed» is not one of them. A project
       * holding demo rows BESIDE real ones still carries the warning, because the totals add them
       * together, so mixed maps to `demo` rather than being widened with a cast.
       */
      dataStatus: source === 'live' ? 'live' : 'demo',
      lastSyncAt: lastSync,
      /* Empty on purpose: the objective-aware `MetricStrip` owns the KPI row, and two rows of
       * headline figures on one page is two answers to the same question. */
      kpis: [],
      platforms: rows.map((p) => ({
        key: p.provider,
        name: p.provider,
        spend: displaySpend(p),
        results: 0,
        roas: p.roas ?? null,
      })),
      spend: rows.map((p) => ({ name: p.provider, value: displaySpend(p) })),
      topCampaigns: (campaigns ?? []).slice(0, 6).map((c) => ({
        id: String(c.campaign_id),
        name: c.campaign_name ?? '—',
        provider: c.provider,
        spend: c.spend,
        results: c.conversions,
        cpa: c.cpa ?? null,
        roas: c.roas ?? null,
      })),
      needsAttention: struggling.slice(0, 4).map((c) => ({
        id: String(c.campaign_id),
        name: c.campaign_name ?? '—',
        reason: dash('spendNoConversions', ar),
      })),
      alerts,
    }),
    [rows, campaigns, struggling, alerts, lastSync, withheldCurrency, currency, source, ar],
  )
}
