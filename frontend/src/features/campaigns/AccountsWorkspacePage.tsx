import { useMemo, useState } from 'react'
import { LevelTabs } from './LevelTabs'
import { providerLabel } from './labels'
import { useAccounts, useSummary, type AccountRow } from '@/features/analytics/api'
import { compact, moneyFromTotals } from '@/features/analytics/format'
import { usePeriod } from '@/lib/period/usePeriod'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { FilterBar, FilterSearch, FilterSelect } from '@/components/ui/FilterBar'
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
  const [platform, setPlatform] = useState('all')
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

          <div className="hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
            <table className="w-full text-sm" data-testid="accounts-table">
              <thead className="border-b border-border text-start text-xs text-text-secondary">
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-bold">{L('الحساب', 'Account')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-bold">{L('المنصة', 'Platform')}</th>
                  <th scope="col" className="px-3 py-2 text-end font-bold">{L('الإنفاق', 'Spend')}</th>
                  <th scope="col" className="px-3 py-2 text-end font-bold">{L('مرات الظهور', 'Impressions')}</th>
                  <th scope="col" className="px-3 py-2 text-end font-bold">{L('النقرات', 'Clicks')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={`${r.provider}:${r.account_id ?? ''}`} className="border-b border-border last:border-0">
                    <td className="px-3 py-2.5 font-bold text-text-primary">{r.account_name ?? L('حساب محذوف', 'Removed account')}</td>
                    <td className="px-3 py-2.5 text-text-secondary">{providerLabel(r.provider, ar ? 'ar' : 'en')}</td>
                    <td className="px-3 py-2.5 text-end tabular-nums" title={spendOf(r).note ?? undefined}>{spendOf(r).text}</td>
                    <td className="px-3 py-2.5 text-end tabular-nums">{compact(r.impressions)}</td>
                    <td className="px-3 py-2.5 text-end tabular-nums">{compact(r.clicks)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="border-t-2 border-border bg-surface-hover/40 font-bold">
                <tr data-testid="accounts-total">
                  <td className="px-3 py-2.5" colSpan={2}>{L('الإجمالي', 'Total')}</td>
                  <td className="px-3 py-2.5 text-end tabular-nums">{total?.text ?? '—'}</td>
                  <td className="px-3 py-2.5 text-end tabular-nums">{compact(sum('impressions'))}</td>
                  <td className="px-3 py-2.5 text-end tabular-nums">{compact(sum('clicks'))}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
