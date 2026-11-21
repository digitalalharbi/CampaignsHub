import { useMemo, useState } from 'react'
import { useUrlState } from '@/features/analytics/filterUrlState'
import { LevelTabs } from './LevelTabs'
import { providerLabel } from './labels'
import { useAccountPulse, useAccounts, useSummary, type AccountPulse, type AccountRow, type AccountState } from '@/features/analytics/api'
import { compact, moneyFromTotals } from '@/features/analytics/format'
import { usePeriod } from '@/lib/period/usePeriod'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { FilterBar, FilterSearch } from '@/components/ui/FilterBar'
import { FilterPlatforms } from '@/components/ui/FilterPlatforms'
import { MetricTable } from '@/components/ui/MetricTable'
import { Modal } from '@/components/ui/Modal'
import { CopyId } from '@/components/ui/CopyId'
import { Delta } from '@/components/ui/Delta'
import { Num } from '@/components/ui/Num'
import { PageIntro } from '@/components/ui/PageIntro'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ProviderMark } from '@/components/ProviderMark'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { Donut, Ring, ShareBar, Sparkline, StateChip } from './accountsVisuals'

/**
 * CAMPAIGN-LEVELS-002 — «الحسابات»: the ad accounts of every platform, the fourth level of the strip.
 *
 * Reads the accounts breakdown the analysis already reads (`useAccounts`) — no new formula. Spend is shown through
 * `moneyFromTotals`, so a withheld or absent amount stays «—» and never becomes zero.
 *
 * ACCOUNTS-PULSE-001 (Owner, 2026-11-21) — the page answers «شغّال والا واقف» at a glance: each account carries its
 * platform's logo and the state the server decided (`useAccountPulse`), a share bar and the shape of its days; a strip
 * of cards above says how much went out, how many accounts are running and where the money went; a click opens the
 * account's details in a popup, read-only — nothing here controls a platform.
 */
const STATE_ORDER: Record<AccountState, number> = { error: 0, disabled: 1, running: 2, stopped: 3 }

export function AccountsWorkspacePage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const locale = ar ? 'ar' : 'en'
  const { currentProjectId } = useProject()
  const { range } = usePeriod()
  // CAMPAIGN-LEVELS-003 — the platform travels in the address, to the levels below.
  const [platform, setPlatform] = useUrlState('platform', 'all')
  const [search, setSearch] = useState('')
  const [openKey, setOpenKey] = useState<string | null>(null)

  const accounts = useAccounts(currentProjectId, range)
  const pulse = useAccountPulse(currentProjectId, range)
  const summary = useSummary(currentProjectId, range, platform === 'all' ? {} : { provider: [platform] })

  const keyOf = (r: { provider: string; account_id: string | null }) => `${r.provider}:${r.account_id ?? ''}`
  const pulseBy = useMemo(() => new Map((pulse.data ?? []).map((p) => [keyOf(p), p])), [pulse.data])

  const platforms = useMemo(() => [...new Set((accounts.data ?? []).map((r) => r.provider))].sort(), [accounts.data])
  const inPlatform = useMemo(
    () => (accounts.data ?? []).filter((r) => platform === 'all' || r.provider === platform),
    [accounts.data, platform],
  )
  const rows = useMemo(() => {
    const q = search.trim().toLowerCase()
    const state = (r: AccountRow) => pulseBy.get(keyOf(r))?.state ?? 'stopped'
    return inPlatform
      .filter((r) => q === '' || `${r.account_name ?? ''} ${r.account_id ?? ''}`.toLowerCase().includes(q))
      // Problems first, then running, then stopped — and by spend inside each.
      .sort((a, b) => STATE_ORDER[state(a)] - STATE_ORDER[state(b)] || (b.spend ?? 0) - (a.spend ?? 0))
  }, [inPlatform, search, pulseBy])

  const title = ar ? 'الحسابات' : 'Accounts'
  const purpose = ar
    ? 'كل حساب إعلاني في المشروع على كل المنصات: حالته وإنفاقه ونتائجه في الفترة المحددة.'
    : 'Every ad account in the project across all platforms: its state, spend and results in the selected period.'

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
  const spendTotal = inPlatform.reduce((a, r) => a + (r.spend ?? 0), 0)
  const ctr = (clicks: number | null | undefined, impressions: number | null | undefined) =>
    impressions && impressions > 0 && clicks !== null && clicks !== undefined ? `${((clicks / impressions) * 100).toFixed(2)}%` : '—'

  const stateOf = (r: AccountRow): AccountState | null => pulseBy.get(keyOf(r))?.state ?? null
  const running = inPlatform.filter((r) => stateOf(r) === 'running').length
  const problems = inPlatform.filter((r) => stateOf(r) === 'error' || stateOf(r) === 'disabled').length
  const byPlatform = platforms
    .map((p) => ({ provider: p, value: (accounts.data ?? []).filter((r) => r.provider === p).reduce((a, r) => a + (r.spend ?? 0), 0) }))
    .filter((s) => s.value > 0)

  const nameOf = (r: AccountRow) => r.account_name ?? L('حساب محذوف', 'Removed account')
  const tooltipOf = (p: AccountPulse | undefined) =>
    p
      ? [
          `${L('حملات نشطة', 'Active campaigns')}: ${p.active_campaigns}`,
          `${L('آخر إنفاق', 'Last spend')}: ${p.last_spend_date ?? '—'}`,
          `${L('آخر تحديث للبيانات', 'Last data refresh')}: ${p.last_synced_at ? new Date(p.last_synced_at).toLocaleString('en-GB') : '—'}`,
        ].join(' · ')
      : undefined

  const AccountCell = ({ r }: { r: AccountRow }) => {
    const p = pulseBy.get(keyOf(r))
    return (
      <button
        type="button"
        onClick={() => setOpenKey(keyOf(r))}
        className="group flex min-w-0 items-center gap-2.5 text-start"
        data-testid="account-open"
      >
        <ProviderMark provider={r.provider} size={30} className="rounded-lg" />
        <span className="min-w-0">
          <span className="block truncate font-bold text-text-primary group-hover:text-brand-600 group-hover:underline">{nameOf(r)}</span>
          <span className="mt-0.5 flex items-center gap-1.5">
            {p && <StateChip state={p.state} ar={ar} title={tooltipOf(p)} />}
            <span className="text-xs text-text-muted">{providerLabel(r.provider, locale)}</span>
          </span>
        </span>
      </button>
    )
  }

  const opened = openKey ? (accounts.data ?? []).find((r) => keyOf(r) === openKey) ?? null : null
  const openedPulse = openKey ? pulseBy.get(openKey) : undefined

  return (
    <div className="space-y-4" data-testid="accounts-workspace">
      <LevelTabs />
      <PageIntro title={title} purpose={purpose} />
      <FilterBar
        id="entity-accounts"
        ar={ar}
        applied={platform === 'all' ? [] : [{ key: `platform:${platform}`, axis: L('المنصة', 'Platform'), label: providerLabel(platform, locale), onRemove: () => setPlatform('all') }]}
        onReset={() => { setPlatform('all'); setSearch('') }}
      >
        <DateRangePicker projectId={currentProjectId} testId="entity-period-accounts" />
        <FilterPlatforms
          label={L('المنصة', 'Platform')}
          allLabel={L('الكل', 'All')}
          values={platform === 'all' ? [] : [platform]}
          options={platforms.map((p) => ({ value: p, label: providerLabel(p, locale) }))}
          // One platform at a time: the address carries one, and the levels below read one.
          onChange={(next) => setPlatform(next.length === 0 ? 'all' : next[next.length - 1])}
          marks
          testid="entity-platform-accounts"
        />
        <FilterSearch value={search} onChange={setSearch} placeholder={L('ابحث باسم الحساب أو معرّفه…', 'Search by account name or id…')} testid="entity-search-accounts" />
      </FilterBar>

      {accounts.isLoading && (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-24" />)}</div>
          <Skeleton className="h-40" />
        </div>
      )}
      {accounts.isError && <p role="alert" className="text-sm text-danger">{L('تعذّرت قراءة الحسابات.', 'Accounts could not be read.')}</p>}
      {accounts.data && rows.length === 0 && (
        <EmptyState title={L('لا توجد حسابات مطابقة', 'No matching accounts')} description={L('لا حساب إعلاني بهذه المعطيات في الفترة المحددة.', 'No ad account matches in the selected period.')} />
      )}

      {inPlatform.length > 0 && (
        <section className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="accounts-pulse-cards">
          <div className="rounded-2xl border border-border bg-surface p-4">
            <div className="text-xs font-bold text-text-muted">{L('الإنفاق', 'Spend')}</div>
            <div className="mt-1.5 text-2xl font-extrabold tabular-nums text-text-primary" title={total?.exact ?? total?.note ?? undefined}>{total?.text ?? '—'}</div>
            {summary.data?.previous_rows_in_scope && (
              <div className="mt-1">
                <Delta metric="spend" current={summary.data.current.spend ?? null} previous={summary.data.previous.spend ?? null} change={summary.data.delta.spend ?? null} ar={ar} testid="accounts-spend-delta" />
              </div>
            )}
          </div>

          <div className="flex items-center gap-3 rounded-2xl border border-border bg-surface p-4" data-testid="accounts-running">
            <div className="relative">
              <Ring value={running} total={inPlatform.length} />
              <span className="absolute inset-0 flex items-center justify-center text-xs font-extrabold tabular-nums text-text-primary">{inPlatform.length > 0 ? Math.round((running / inPlatform.length) * 100) : 0}%</span>
            </div>
            <div>
              <div className="text-xs font-bold text-text-muted">{L('حسابات شغّالة', 'Running accounts')}</div>
              <div className="mt-0.5 text-2xl font-extrabold tabular-nums text-text-primary">{running}<span className="text-base text-text-muted"> / {inPlatform.length}</span></div>
              {problems > 0 && <div className="text-xs font-bold text-rose-600 dark:text-rose-400">{problems} {L('تحتاج مراجعة', 'need attention')}</div>}
            </div>
          </div>

          <div className="rounded-2xl border border-border bg-surface p-4">
            <div className="grid grid-cols-2 gap-2">
              <div>
                <div className="text-xs font-bold text-text-muted">{L('مرات الظهور', 'Impressions')}</div>
                <div className="mt-1.5 text-xl font-extrabold tabular-nums text-text-primary">{compact(inPlatform.reduce((a, r) => a + (r.impressions ?? 0), 0))}</div>
              </div>
              <div>
                <div className="text-xs font-bold text-text-muted">{L('النقرات', 'Clicks')}</div>
                <div className="mt-1.5 text-xl font-extrabold tabular-nums text-text-primary">{compact(inPlatform.reduce((a, r) => a + (r.clicks ?? 0), 0))}</div>
              </div>
            </div>
            <div className="mt-2 text-xs text-text-muted">
              {L('معدل النقر', 'CTR')}{' '}
              <span className="font-bold tabular-nums text-text-primary">{ctr(inPlatform.reduce((a, r) => a + (r.clicks ?? 0), 0), inPlatform.reduce((a, r) => a + (r.impressions ?? 0), 0))}</span>
            </div>
          </div>

          {byPlatform.length > 0 && (
            <div className="flex items-center gap-3 rounded-2xl border border-border bg-surface p-4" data-testid="accounts-platform-split">
              <Donut slices={byPlatform} size={76} />
              <ul className="min-w-0 space-y-1 text-xs">
                {byPlatform.sort((a, b) => b.value - a.value).slice(0, 4).map((s) => {
                  const all = byPlatform.reduce((a, x) => a + x.value, 0)
                  return (
                    <li key={s.provider} className="flex items-center gap-1.5">
                      <ProviderMark provider={s.provider} size={16} />
                      <span className="font-bold tabular-nums text-text-primary">{Math.round((s.value / all) * 100)}%</span>
                    </li>
                  )
                })}
              </ul>
            </div>
          )}
        </section>
      )}

      {rows.length > 0 && (
        <>
          {/* Phone: one card per account — nothing scrolls sideways. */}
          <ul className="space-y-2 md:hidden" data-testid="accounts-cards">
            {rows.map((r) => {
              const p = pulseBy.get(keyOf(r))
              return (
                <li key={keyOf(r)} className={`rounded-xl border border-border bg-surface p-3 ${p?.state === 'stopped' ? 'opacity-70' : ''}`}>
                  <div className="flex items-start justify-between gap-2">
                    <AccountCell r={r} />
                    <div className="shrink-0 text-end">
                      <div className="text-sm font-bold tabular-nums" title={spendOf(r).exact ?? spendOf(r).note ?? undefined}>{spendOf(r).text}</div>
                      <Sparkline points={(p?.daily ?? []).map((d) => d.spend)} provider={r.provider} width={72} height={22} />
                    </div>
                  </div>
                  <ShareBar share={spendTotal > 0 ? (r.spend ?? 0) / spendTotal : 0} provider={r.provider} />
                  <dl className="mt-2 grid grid-cols-3 gap-2 text-xs text-text-secondary">
                    <div><dt>{L('مرات الظهور', 'Impressions')}</dt><dd className="font-bold tabular-nums text-text-primary">{compact(r.impressions)}</dd></div>
                    <div><dt>{L('النقرات', 'Clicks')}</dt><dd className="font-bold tabular-nums text-text-primary">{compact(r.clicks)}</dd></div>
                    <div><dt>{L('معدل النقر', 'CTR')}</dt><dd className="font-bold tabular-nums text-text-primary">{ctr(r.clicks, r.impressions)}</dd></div>
                  </dl>
                </li>
              )
            })}
          </ul>

          <div className="hidden space-y-2 md:block" data-testid="accounts-table">
            <MetricTable
              head={[L('الحساب', 'Account'), L('الإنفاق', 'Spend'), L('الاتجاه', 'Trend'), L('مرات الظهور', 'Impressions'), L('النقرات', 'Clicks'), L('معدل النقر', 'CTR')]}
              rows={rows.map((r) => {
                const p = pulseBy.get(keyOf(r))
                return [
                  <span key="n" className={`block ${p?.state === 'stopped' ? 'opacity-70' : ''}`}><AccountCell r={r} /></span>,
                  <span key="s" className="block min-w-24" title={spendOf(r).note ?? undefined}>
                    <span className="font-bold tabular-nums">{spendOf(r).text}</span>
                    <ShareBar share={spendTotal > 0 ? (r.spend ?? 0) / spendTotal : 0} provider={r.provider} />
                  </span>,
                  <Sparkline key="t" points={(p?.daily ?? []).map((d) => d.spend)} provider={r.provider} testId="account-sparkline" />,
                  compact(r.impressions),
                  compact(r.clicks),
                  ctr(r.clicks, r.impressions),
                ]
              })}
              values={rows.map((r) => [
                STATE_ORDER[stateOf(r) ?? 'stopped'],
                r.spend ?? null,
                r.spend ?? null,
                r.impressions ?? null,
                r.clicks ?? null,
                r.impressions ? (r.clicks ?? 0) / r.impressions : null,
              ])}
              exact={rows.map((r) => [null, spendOf(r).exact, null, null, null, null])}
              testId="accounts-metric-table"
              footer={[
                L('الإجمالي', 'Total'),
                /* The platform's own total; with a search narrowing the rows it is not the rows' total, so it is not shown. */
                <span key="s" title={total?.note ?? undefined}>{search.trim() === '' ? (total?.text ?? '—') : '—'}</span>,
                '',
                compact(sum('impressions')),
                compact(sum('clicks')),
                ctr(sum('clicks'), sum('impressions')),
              ]}
            />
          </div>
        </>
      )}

      <Modal open={opened !== null} onClose={() => setOpenKey(null)} title={opened ? nameOf(opened) : undefined} size="lg">
        {opened && <AccountDetail row={opened} pulse={openedPulse} ar={ar} spend={spendOf(opened)} ctr={ctr(opened.clicks, opened.impressions)} />}
      </Modal>
    </div>
  )
}

function AccountDetail({ row, pulse, ar, spend, ctr }: {
  row: AccountRow
  pulse: AccountPulse | undefined
  ar: boolean
  spend: ReturnType<typeof moneyFromTotals>
  ctr: string
}) {
  const L = (a: string, e: string) => (ar ? a : e)
  const locale = ar ? 'ar' : 'en'
  const daily = pulse?.daily ?? []
  const peak = daily.reduce<{ date: string; spend: number } | null>((m, d) => (m === null || d.spend > m.spend ? d : m), null)
  const errorText = pulse?.sync_error === 'access_lost'
    ? L('انقطع وصول الربط لهذا الحساب، ويحتاج إعادة ربط.', 'Access to this account was lost; it needs reconnecting.')
    : pulse?.sync_error
      ? L('فشل آخر تحديث للبيانات.', 'The last data refresh failed.')
      : null

  return (
    <div className="space-y-4" data-testid="account-detail">
      <div className="flex flex-wrap items-center gap-3">
        <ProviderMark provider={row.provider} size={44} className="rounded-xl" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-text-secondary">{providerLabel(row.provider, locale)}</span>
            {pulse && <StateChip state={pulse.state} ar={ar} />}
          </div>
          {pulse?.external_id && (
            <div className="mt-1 flex items-center gap-1.5 text-xs text-text-muted">
              <Num className="tabular-nums">{pulse.external_id}</Num>
              <CopyId value={pulse.external_id} testId="account-detail-copy" />
            </div>
          )}
        </div>
        <div className="flex gap-2 text-xs text-text-muted">
          {pulse?.currency && <span className="rounded-md bg-surface-secondary px-2 py-0.5 font-bold">{pulse.currency}</span>}
          {pulse?.timezone && <span className="rounded-md bg-surface-secondary px-2 py-0.5" dir="ltr">{pulse.timezone}</span>}
        </div>
      </div>

      {errorText && <p role="alert" className="rounded-lg bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{errorText}</p>}

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {[
          [L('الإنفاق', 'Spend'), spend.text],
          [L('مرات الظهور', 'Impressions'), compact(row.impressions)],
          [L('النقرات', 'Clicks'), compact(row.clicks)],
          [L('معدل النقر', 'CTR'), ctr],
        ].map(([k, v]) => (
          <div key={k} className="rounded-xl border border-border bg-surface p-3">
            <div className="text-xs font-bold text-text-muted">{k}</div>
            <div className="mt-1 text-lg font-extrabold tabular-nums text-text-primary">{v}</div>
          </div>
        ))}
      </div>

      {daily.length > 1 && (
        <div className="rounded-xl border border-border bg-surface p-3">
          <div className="mb-2 flex items-center justify-between text-xs font-bold text-text-muted">
            <span>{L('الإنفاق اليومي', 'Daily spend')}</span>
            {peak && <span className="tabular-nums">{L('أعلى يوم', 'Peak')}: {peak.date}</span>}
          </div>
          <Sparkline points={daily.map((d) => d.spend)} provider={row.provider} width={560} height={90} testId="account-detail-chart" />
          <div className="mt-1 flex justify-between text-xs tabular-nums text-text-muted">
            <Num>{daily[0].date}</Num>
            <Num>{daily[daily.length - 1].date}</Num>
          </div>
        </div>
      )}

      {pulse && (
        <div className="grid grid-cols-3 gap-2 text-center" data-testid="account-detail-campaigns">
          {[
            [L('حملات نشطة', 'Active campaigns'), pulse.active_campaigns, 'text-emerald-600 dark:text-emerald-400'],
            [L('متوقفة', 'Paused'), pulse.paused_campaigns, 'text-text-secondary'],
            [L('منتهية', 'Ended'), pulse.ended_campaigns, 'text-text-muted'],
          ].map(([k, v, tone]) => (
            <div key={String(k)} className="rounded-xl bg-surface-secondary p-3">
              <div className={`text-2xl font-extrabold tabular-nums ${tone}`}>{v}</div>
              <div className="text-xs font-bold text-text-muted">{k}</div>
            </div>
          ))}
        </div>
      )}

      {pulse && (
        <dl className="grid grid-cols-2 gap-2 text-xs text-text-secondary">
          <div><dt className="text-text-muted">{L('آخر إنفاق', 'Last spend')}</dt><dd className="font-bold tabular-nums text-text-primary">{pulse.last_spend_date ?? '—'}</dd></div>
          <div><dt className="text-text-muted">{L('آخر تحديث للبيانات', 'Last data refresh')}</dt><dd className="font-bold tabular-nums text-text-primary">{pulse.last_synced_at ? new Date(pulse.last_synced_at).toLocaleString('en-GB') : '—'}</dd></div>
        </dl>
      )}
    </div>
  )
}
