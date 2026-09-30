import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plug, RefreshCw, X } from 'lucide-react'
import {
  getAccountLogs, listAccounts, refreshDiscoveredAccounts, revokeConnection, startPlatformOAuth,
  syncConnector, type AccountRow, type HubConnection,
} from './api'
import { CONNECTION_COPY, SYNC_COPY, needsReauthorising } from './connectionCopy'
import { syncStatusMeaning } from '@/lib/syncStatus'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/States'
import { Num } from '@/components/ui/Num'
import { platformColor } from '@/features/analytics/components'
import { accounts as countedAccounts } from '@/lib/counted'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

export type DrawerTab = 'overview' | 'accounts' | 'history' | 'settings'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — the connection, in a drawer beside the hub.
 *
 * ## Why a drawer and not a page
 *
 * Everything in here is ABOUT the row that opened it, and a reader comparing two authorisations
 * loses the comparison the moment the list is replaced. A page also has to be navigated back out of,
 * which is how «check whether Meta is still connected» became four screens. The drawer keeps the hub
 * behind it, closes on Escape, and leaves the URL alone.
 *
 * ## The four sections are four questions
 *
 * Overview — «is this working, and who does it feed». Accounts — «which of the seventeen is ours»,
 * asked of the inventory that already paginates and computes per-account health, so there is no
 * second account list to disagree with the first. Sync history — «what has it actually been doing»,
 * from the real run log with the cause of each run named. Settings — the acts that change the
 * authorisation itself, kept away from the ones that change a selection, because «stop syncing this
 * account for this client» and «end this authorisation for every client» are not neighbours.
 */
export function ConnectionDrawer({ connection, tab, onTab, onClose, onManageAccounts }: {
  connection: HubConnection
  tab: DrawerTab
  onTab: (tab: DrawerTab) => void
  onClose: () => void
  onManageAccounts: (connection: HubConnection) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const queryClient = useQueryClient()
  const auth = CONNECTION_COPY[connection.connection_state]
  const data = SYNC_COPY[connection.sync_state]

  // Escape closes it. A panel that can only be dismissed with the mouse is a trap for anybody
  // driving this from the keyboard, and this one opens on top of the list they were reading.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['connection-hub'] })
    void queryClient.invalidateQueries({ queryKey: ['integration-accounts'] })
  }

  const sync = useMutation({ mutationFn: () => syncConnector(connection.provider), onSettled: invalidate })
  const refresh = useMutation({ mutationFn: () => refreshDiscoveredAccounts(connection.id), onSettled: invalidate })
  const revoke = useMutation({ mutationFn: () => revokeConnection(connection.id), onSettled: () => { invalidate(); onClose() } })
  const reauthorise = useMutation({
    mutationFn: () => startPlatformOAuth(connection.provider),
    onSuccess: ({ authorization_url: url }) => { window.location.assign(url) },
  })

  return (
    <div className="fixed inset-0 z-50 flex" role="dialog" aria-modal="true" data-testid="connection-drawer">
      {/* The hub stays visible behind it — this is a detail of the list, not a departure from it. */}
      <button
        type="button"
        aria-label={ar ? 'إغلاق' : 'Close'}
        onClick={onClose}
        className="flex-1 bg-black/30 backdrop-blur-[1px]"
        data-testid="drawer-scrim"
      />

      <aside className="flex h-full w-full max-w-[28rem] flex-col overflow-y-auto border-s border-border bg-surface shadow-2xl">
        <header className="flex flex-col gap-3 border-b border-border p-4">
          <div className="flex items-start gap-3">
            <span
              className="mt-0.5 h-8 w-1 shrink-0 rounded-full"
              style={{ background: platformColor(connection.provider) }}
              aria-hidden
            />
            <div className="min-w-0 flex-1">
              <h2 className="truncate text-base font-bold text-text-primary">
                {ar ? connection.label_ar : connection.label}
              </h2>
              <p className="truncate text-xs text-text-secondary">
                {connection.authorised_by === null
                  ? (ar ? 'لم يُسجَّل من صرّح بالربط' : 'Authoriser not recorded')
                  : connection.authorised_by.email}
              </p>
            </div>
            <button
              type="button"
              onClick={onClose}
              aria-label={ar ? 'إغلاق' : 'Close'}
              className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-secondary hover:text-text-primary"
              data-testid="drawer-close"
            >
              <X size={18} />
            </button>
          </div>

          <div className="flex flex-wrap items-center gap-1.5">
            <Badge tone={auth.tone} data-testid="drawer-auth">{ar ? auth.ar : auth.en}</Badge>
            <Badge tone={data.tone} data-testid="drawer-sync">{ar ? data.ar : data.en}</Badge>
          </div>

          <nav className="flex gap-1 overflow-x-auto" aria-label={ar ? 'أقسام المصدر' : 'Source sections'}>
            {(['overview', 'accounts', 'history', 'settings'] as const).map((key) => (
              <button
                key={key}
                type="button"
                onClick={() => onTab(key)}
                aria-current={tab === key ? 'page' : undefined}
                data-testid={`drawer-tab-${key}`}
                className={`whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors ${
                  tab === key
                    ? 'bg-surface-secondary text-text-primary'
                    : 'text-text-secondary hover:text-text-primary'
                }`}
              >
                {ar ? TABS[key].ar : TABS[key].en}
              </button>
            ))}
          </nav>
        </header>

        <div className="flex-1 p-4">
          {tab === 'overview' && <Overview connection={connection} ar={ar} />}

          {tab === 'accounts' && (
            <Accounts
              connection={connection}
              ar={ar}
              onManageAccounts={() => onManageAccounts(connection)}
            />
          )}

          {tab === 'history' && <History connection={connection} ar={ar} />}

          {tab === 'settings' && (
            <Settings
              ar={ar}
              connection={connection}
              busy={{
                sync: sync.isPending,
                refresh: refresh.isPending,
                revoke: revoke.isPending,
                reauthorise: reauthorise.isPending,
              }}
              onSync={() => sync.mutate()}
              onRefresh={() => refresh.mutate()}
              onRevoke={() => revoke.mutate()}
              onReauthorise={() => reauthorise.mutate()}
            />
          )}
        </div>
      </aside>
    </div>
  )
}

function Overview({ connection, ar }: { connection: HubConnection; ar: boolean }) {
  return (
    <dl className="flex flex-col gap-3 text-sm" data-testid="drawer-overview">
      <Fact label={ar ? 'الحسابات المختارة' : 'Selected accounts'}>
        {ar
          ? <><Num>{connection.selected_accounts}</Num> من <Num>{connection.discovered_accounts}</Num></>
          : <><Num>{connection.selected_accounts}</Num> of <Num>{connection.discovered_accounts}</Num></>}
      </Fact>

      <Fact label={ar ? 'يغذّي العملاء' : 'Feeds'}>
        {/*
          Named, not counted. «Who breaks if this authorisation lapses» is the question somebody asks
          before they reconnect something at four in the afternoon, and a number cannot answer it.
        */}
        {connection.projects.length === 0
          ? (ar ? 'لا عميل بعد' : 'No client yet')
          : connection.projects.map((p) => p.name).join(' · ')}
      </Fact>

      <Fact label={ar ? 'آخر مزامنة ناجحة' : 'Last successful sync'}>
        {connection.last_success_at === null
          ? (ar ? 'لم تتم بعد' : 'Not yet')
          : fmtDateTime(connection.last_success_at)}
      </Fact>

      <Fact label={ar ? 'المزامنة القادمة' : 'Next sync'}>
        {connection.next_sync_at === null
          ? (ar ? 'متوقفة حتى تُعالج الحالة' : 'Paused until this is resolved')
          : fmtDateTime(connection.next_sync_at)}
      </Fact>

      <Fact label={ar ? 'تاريخ الربط' : 'Authorised'}>
        {connection.authorised_at === null ? '—' : fmtDateTime(connection.authorised_at)}
      </Fact>

      {connection.discovery_blocked_reason !== null && (
        <p className="rounded-lg bg-warning-soft p-3 text-xs text-warning" data-testid="drawer-discovery-blocked">
          {ar
            ? 'تعذّر تحديث قائمة الحسابات من المزود في آخر محاولة. الحسابات المعروضة هي آخر قائمة نجحت.'
            : 'The last attempt to re-read this catalogue was refused. What you see is the last list that succeeded.'}
        </p>
      )}
    </dl>
  )
}

function Accounts({ connection, ar, onManageAccounts }: {
  connection: HubConnection
  ar: boolean
  onManageAccounts: () => void
}) {
  /*
   * Selected only, by default, and that default is the point.
   *
   * Seventeen rows under a heading about one client is how the old page taught people that
   * everything discovered was being read. «Show all» is one press away and says how many it will add.
   */
  const [onlySelected, setOnlySelected] = useState(true)
  const [search, setSearch] = useState('')

  const accounts = useQuery({
    queryKey: ['integration-accounts', { connection: connection.id, onlySelected, search }],
    queryFn: () => listAccounts({
      connection: connection.id,
      ...(onlySelected ? { link: 'linked' as const } : {}),
      ...(search.trim() === '' ? {} : { q: search.trim() }),
      per_page: 50,
    }),
  })

  const rows = accounts.data?.accounts ?? []

  return (
    <div className="flex flex-col gap-3" data-testid="drawer-accounts">
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => setOnlySelected((v) => !v)}
          aria-pressed={onlySelected}
          data-testid="drawer-only-selected"
          className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors ${
            onlySelected ? 'bg-surface-secondary text-text-primary' : 'text-text-secondary hover:text-text-primary'
          }`}
        >
          {ar ? 'المختارة فقط' : 'Selected only'}
        </button>

        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={ar ? 'ابحث بالاسم أو المعرّف' : 'Search by name or ID'}
          aria-label={ar ? 'ابحث بالاسم أو المعرّف' : 'Search by name or ID'}
          data-testid="drawer-account-search"
          className="min-w-0 flex-1 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none"
        />
      </div>

      {accounts.isLoading ? (
        <Skeleton className="h-20 w-full" />
      ) : rows.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary">
          {onlySelected
            ? (ar ? 'لم يُختر أي حساب من هذا المصدر بعد.' : 'No account from this source has been chosen yet.')
            : (ar ? 'لا نتائج.' : 'No results.')}
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {rows.map((account) => <AccountLine key={account.id} account={account} ar={ar} />)}
        </ul>
      )}

      <Button variant="secondary" onClick={onManageAccounts} data-testid="drawer-manage-accounts">
        {ar ? 'إدارة الحسابات' : 'Manage accounts'}
      </Button>
    </div>
  )
}

function AccountLine({ account, ar }: { account: AccountRow; ar: boolean }) {
  return (
    <li className="rounded-lg border border-border p-2.5" data-testid="drawer-account-row">
      <p className="truncate text-xs font-semibold text-text-primary">{account.name}</p>
      <p className="mt-0.5 flex flex-wrap gap-x-2 gap-y-0.5 text-[11px] text-text-secondary">
        <span className="font-mono" dir="ltr">{account.reference}</span>
        {account.currency !== null && <span>{account.currency}</span>}
        {account.assigned_project_name !== null && (
          <span className="text-success">{account.assigned_project_name}</span>
        )}
        <span>
          {ar ? 'آخر مزامنة' : 'Last sync'}:{' '}
          {account.last_synced_at === null
            ? (ar ? 'لا يوجد' : 'None')
            : fmtDateTime(account.last_synced_at)}
        </span>
      </p>
    </li>
  )
}

function History({ connection, ar }: { connection: HubConnection; ar: boolean }) {
  /*
   * The runs of the accounts this connection actually feeds — read one account at a time, because
   * the run log is per account and a connection-wide log would be a new endpoint answering a
   * question the inventory's own log already answers correctly.
   */
  const accounts = useQuery({
    queryKey: ['integration-accounts', { connection: connection.id, link: 'linked' }],
    queryFn: () => listAccounts({ connection: connection.id, link: 'linked', per_page: 10 }),
  })

  const first = accounts.data?.accounts[0] ?? null

  const logs = useQuery({
    queryKey: ['account-logs', first?.id],
    queryFn: () => getAccountLogs(first!.id),
    enabled: first !== null,
  })

  if (accounts.isLoading) return <Skeleton className="h-24 w-full" />

  if (first === null) {
    return (
      <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="drawer-history-empty">
        {ar
          ? 'لا يوجد سجل بعد — لم يُختر أي حساب من هذا المصدر.'
          : 'No history yet — no account from this source has been chosen.'}
      </p>
    )
  }

  const runs = logs.data?.runs ?? []

  return (
    <div className="flex flex-col gap-2" data-testid="drawer-history">
      <p className="text-[11px] text-text-secondary">
        {ar ? `سجل ${first.name}` : `Runs for ${first.name}`}
      </p>

      {logs.isLoading ? (
        <Skeleton className="h-20 w-full" />
      ) : runs.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary">
          {ar ? 'لم تُسجَّل أي عملية بعد.' : 'No run has been recorded yet.'}
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {runs.slice(0, 10).map((run) => {
            const meaning = syncStatusMeaning(run.status)
            return (
              <li key={run.id} className="rounded-lg border border-border p-2.5 text-[11px]" data-testid="drawer-run-row">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge tone={meaning.tone}>{ar ? meaning.ar : meaning.en}</Badge>
                  {/* What ASKED for it — the difference between «this never worked» and «this stopped». */}
                  <span className="rounded bg-surface-hover px-1.5 py-0.5 text-text-secondary">
                    {ar ? RUN_SOURCE[run.source ?? 'scheduled'].ar : RUN_SOURCE[run.source ?? 'scheduled'].en}
                  </span>
                  <span className="text-text-secondary">
                    {run.started_at === null ? '—' : fmtDateTime(run.started_at)}
                  </span>
                </div>
                {run.error !== null && (
                  <p className="mt-1 rounded bg-surface-hover px-2 py-1 font-mono text-[10px] text-text-secondary" dir="ltr">
                    {run.error}
                  </p>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

function Settings({ ar, connection, busy, onSync, onRefresh, onRevoke, onReauthorise }: {
  ar: boolean
  connection: HubConnection
  busy: { sync: boolean; refresh: boolean; revoke: boolean; reauthorise: boolean }
  onSync: () => void
  onRefresh: () => void
  onRevoke: () => void
  onReauthorise: () => void
}) {
  const [armed, setArmed] = useState(false)
  const reauth = needsReauthorising(connection.connection_state)

  return (
    <div className="flex flex-col gap-4" data-testid="drawer-settings">
      {reauth && (
        <p className="rounded-lg bg-danger-soft p-3 text-xs text-danger" data-testid="drawer-reauth-note">
          {ar
            ? 'المصادقة لم تعد كافية. لن تنجح أي مزامنة قبل إعادة المصادقة.'
            : 'This authorisation is no longer sufficient. No sync can succeed until it is renewed.'}
        </p>
      )}

      <div className="flex flex-col gap-2">
        {/*
          Re-authorising first when it is needed, because it is the only act that can succeed; a
          «Sync now» beside it would be a button whose whole job is to fail.
        */}
        <Button
          variant={reauth ? 'secondary' : 'ghost'}
          loading={busy.reauthorise}
          onClick={onReauthorise}
          data-testid="drawer-reauthorise"
        >
          <Plug size={14} /> {ar ? 'إعادة المصادقة' : 'Reconnect'}
        </Button>
        <p className="-mt-1 ps-1 text-[11px] text-text-muted">
          {ar
            ? 'تُجدّد المصادقة فقط. لا تتغيّر الحسابات المختارة ولا ارتباطها بالعملاء.'
            : 'Renews the authorisation only. Your selected accounts and their clients are untouched.'}
        </p>

        <Button variant="ghost" loading={busy.refresh} onClick={onRefresh} data-testid="drawer-refresh">
          <RefreshCw size={14} /> {ar ? 'تحديث الحسابات المتاحة' : 'Refresh available accounts'}
        </Button>
        <p className="-mt-1 ps-1 text-[11px] text-text-muted">
          {ar
            ? 'يعيد قراءة قائمة الحسابات من المزود بالمصادقة الحالية — دون المرور بشاشة الموافقة.'
            : 'Re-reads the catalogue with the authorisation you already have — no consent screen.'}
        </p>

        {!reauth && (
          <Button variant="ghost" loading={busy.sync} onClick={onSync} data-testid="drawer-sync-now">
            <RefreshCw size={14} /> {ar ? 'مزامنة الآن' : 'Sync now'}
          </Button>
        )}
      </div>

      <div className="border-t border-border pt-4">
        {/*
          Two presses, and the second is labelled with the consequence. «هل أنت متأكد؟» teaches
          nothing; the count of accounts that stop syncing is the fact somebody needs before they
          answer. It disarms on blur so a stray click cannot leave this panel loaded.
        */}
        <Button
          variant="ghost"
          loading={busy.revoke}
          onBlur={() => setArmed(false)}
          onClick={() => (armed ? onRevoke() : setArmed(true))}
          data-testid="drawer-disconnect"
          className="text-danger"
        >
          {armed
            ? (ar
                ? `تأكيد — سيتوقف ${countedAccounts(connection.selected_accounts, 'ar')} عن المزامنة`
                : `Confirm — ${countedAccounts(connection.selected_accounts, 'en')} stop syncing`)
            : (ar ? 'قطع الاتصال' : 'Disconnect')}
        </Button>
        <p className="mt-1 ps-1 text-[11px] text-text-muted">
          {ar
            ? 'ينهي المصادقة لكل العملاء الذين يعتمدون عليها. لإيقاف حساب واحد عن عميل، استخدم «إدارة الحسابات».'
            : 'Ends the authorisation for every client that depends on it. To stop one account for one client, use Manage accounts.'}
        </p>
      </div>
    </div>
  )
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border pb-2 last:border-0">
      <dt className="text-xs text-text-secondary">{label}</dt>
      <dd className="text-sm font-semibold text-text-primary">{children}</dd>
    </div>
  )
}

const TABS = {
  overview: { ar: 'نظرة عامة', en: 'Overview' },
  accounts: { ar: 'الحسابات', en: 'Accounts' },
  history: { ar: 'سجل المزامنة', en: 'Sync history' },
  settings: { ar: 'الإعدادات', en: 'Settings' },
} as const

const RUN_SOURCE = {
  first_sync: { ar: 'أول مزامنة', en: 'First sync' },
  reconnect: { ar: 'بعد إعادة المصادقة', en: 'After reconnecting' },
  scheduled: { ar: 'مجدولة', en: 'Scheduled' },
  manual: { ar: 'يدوية', en: 'Manual' },
  backfill: { ar: 'سحب تاريخي', en: 'Backfill' },
} as const
