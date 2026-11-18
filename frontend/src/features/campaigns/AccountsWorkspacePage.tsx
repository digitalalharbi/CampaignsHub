import { useMemo, useState } from 'react'
import { useUrlState } from '@/features/analytics/filterUrlState'
import { LevelTabs } from './LevelTabs'
import { providerLabel } from './labels'
import { useAccounts, useSummary, type AccountRow } from '@/features/analytics/api'
import { compact, moneyFromTotals } from '@/features/analytics/format'
import { usePeriod } from '@/lib/period/usePeriod'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { FilterBar, FilterSearch, FilterSelect } from '@/components/ui/FilterBar'
import { MetricTable } from '@/components/ui/MetricTable'
import { PageIntro } from '@/components/ui/PageIntro'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * CAMPAIGN-LEVELS-002 — «الحسابات»: the ad accounts of every platform, one table, the fourth level of the strip.
 *
 * Reads the accounts breakdown the analysis already reads (`useAccounts`) — no new endpoint, no new formula. Spend is
 * shown through `moneyFromTotals`, so a withheld or absent amount stays «—» and never becomes zero. The platform filter
 * offers only platforms that have an account in this project; the project's own permissions decide what the list holds.
 */
export function AccountsWorkspacePage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const { range } = usePeriod()
  // CAMPAIGN-LEVELS-003 — the platform travels in the address, to the levels below.
  const [platform, setPlatform] = useUrlState('platform', 'all')
  const [search, setSearch] = useState('')

  const accounts = useAccounts(currentProjectId, range)
  const summary = useSummary(currentProjectId, range, platform === 'all' ? {} : { provider: [platform] })

  const platforms = useMemo(() => [...new Set((accounts.data ?? []).map((r) => r.provider))].sort(), [accounts.data])
  const rows = useMemo(() => {
    const q = search.trim().toLowerCase()
    return (accounts.data ?? [])
      .filter((r) => platform === 'all' || r.provider === platform)
      .filter((r) => q === '' || `${r.account_name ?? ''} ${r.account_id ?? ''}`.toLowerCase().includes(q))
      .sort((a, b) => (b.spend ?? 0) - (a.spend ?? 0))
  }, [accounts.data, platform, search])

  const title = ar ? 'الحسابات' : 'Accounts'
  const purpose = ar
    ? 'كل حساب إعلاني في المشروع على كل المنصات: إنفاقه ونتائجه في الفترة المحددة.'
    : 'Every ad account in the project across all platforms: what it spent and delivered in the selected period.'

  if (!currentProjectId) {
    return (
      <div className="space-y-6">
        <LevelTabs />
        <PageIntro title={title} purpose={purpose} />
        <ProjectChooser purpose="analytics" />
      </div>
    )
  }

  const L = (a: string, e: string) => (ar ? a : e)
  const spendOf = (r: AccountRow) => moneyFromTotals(r, 'spend', ar)
  const total = summary.data?.current ? moneyFromTotals(summary.data.current, 'spend', ar) : null
  const sum = (k: 'impressions' | 'clicks') => rows.reduce((a, r) => a + (r[k] ?? 0), 0)

  return (
    <div className="space-y-4" data-testid="accounts-workspace">
      <LevelTabs />
      <PageIntro title={title} purpose={purpose} />
      <FilterBar
        id="entity-accounts"
        ar={ar}
        applied={platform === 'all' ? [] : [{ key: `platform:${platform}`, axis: L('المنصة', 'Platform'), label: providerLabel(platform, ar ? 'ar' : 'en'), onRemove: () => setPlatform('all') }]}
        onReset={() => { setPlatform('all'); setSearch('') }}
      >
        <DateRangePicker projectId={currentProjectId} testId="entity-period-accounts" />
        <FilterSelect
          label={L('المنصة', 'Platform')}
          value={platform}
          options={[{ value: 'all', label: L('كل المنصات', 'All platforms') }, ...platforms.map((p) => ({ value: p, label: providerLabel(p, ar ? 'ar' : 'en') }))]}
          onChange={setPlatform}
          testid="entity-platform-accounts"
        />
        <FilterSearch value={search} onChange={setSearch} placeholder={L('ابحث باسم الحساب أو معرّفه…', 'Search by account name or id…')} testid="entity-search-accounts" />
      </FilterBar>

      {accounts.isLoading && <Skeleton className="h-40" />}
      {accounts.isError && <p role="alert" className="text-sm text-danger">{L('تعذّرت قراءة الحسابات.', 'Accounts could not be read.')}</p>}
      {accounts.data && rows.length === 0 && (
        <EmptyState title={L('لا توجد حسابات مطابقة', 'No matching accounts')} description={L('لا حساب إعلاني بهذه المعطيات في الفترة المحددة.', 'No ad account matches in the selected period.')} />
      )}

      {rows.length > 0 && (
        <>
          {/* Phone: one card per account — nothing scrolls sideways. */}
          <ul className="space-y-2 md:hidden" data-testid="accounts-cards">
            {rows.map((r) => (
              <li key={`${r.provider}:${r.account_id ?? ''}`} className="rounded-xl border border-border bg-surface p-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="truncate text-sm font-bold text-text-primary">{r.account_name ?? L('حساب محذوف', 'Removed account')}</div>
                    <div className="text-xs text-text-secondary">{providerLabel(r.provider, ar ? 'ar' : 'en')}</div>
                  </div>
                  <div className="shrink-0 text-end text-sm font-bold tabular-nums" title={spendOf(r).note ?? undefined}>{spendOf(r).text}</div>
                </div>
                <dl className="mt-2 grid grid-cols-2 gap-2 text-xs text-text-secondary">
                  <div><dt>{L('مرات الظهور', 'Impressions')}</dt><dd className="font-bold tabular-nums text-text-primary">{compact(r.impressions)}</dd></div>
                  <div><dt>{L('النقرات', 'Clicks')}</dt><dd className="font-bold tabular-nums text-text-primary">{compact(r.clicks)}</dd></div>
                </dl>
              </li>
            ))}
          </ul>

          <div className="hidden space-y-2 md:block" data-testid="accounts-table">
            <MetricTable
              head={[L('الحساب', 'Account'), L('المنصة', 'Platform'), L('الإنفاق', 'Spend'), L('مرات الظهور', 'Impressions'), L('النقرات', 'Clicks')]}
              rows={rows.map((r) => [
                <span key="n" className="font-bold text-text-primary">{r.account_name ?? L('حساب محذوف', 'Removed account')}</span>,
                providerLabel(r.provider, ar ? 'ar' : 'en'),
                <span key="s" title={spendOf(r).note ?? undefined}>{spendOf(r).text}</span>,
                compact(r.impressions),
                compact(r.clicks),
              ])}
              values={rows.map((r) => [r.account_name ?? '', providerLabel(r.provider, ar ? 'ar' : 'en'), r.spend ?? null, r.impressions ?? null, r.clicks ?? null])}
              initialSort={{ column: 2, dir: 'desc' }}
              testId="accounts-metric-table"
            footer={[
              L('الإجمالي', 'Total'),
              '',
              /* The platform's own total; with a search narrowing the rows it is not the rows' total, so it is not shown. */
              <span key="s" title={total?.note ?? undefined}>{search.trim() === '' ? (total?.text ?? '—') : '—'}</span>,
              compact(sum('impressions')),
              compact(sum('clicks')),
            ]}
            />
          </div>
        </>
      )}
    </div>
  )
}
