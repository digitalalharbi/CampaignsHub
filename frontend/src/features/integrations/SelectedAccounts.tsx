import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Link2, RefreshCw, ScrollText, X } from 'lucide-react'
import { UnlinkAccountButton } from './UnlinkAccountButton'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { syncStatusMeaning } from '@/lib/syncStatus'
import {
  backfillAccount, getAccountLogs, listAccounts, syncAccountNow,
  type AccountRow,
} from './api'
import { accountHealthBadgeTone, accountHealthLabel } from './accountHealth'

/**
 * INTEGRATIONS-CENTRE-001 — the accounts that were CHOSEN, and nothing else.
 *
 * ## What this replaced
 *
 * `AccountsPanel` listed every account the workspace could reach (the «all discovered» panel), with
 * «linked / not linked» chips to tell the chosen few from the hundreds that were merely visible. One
 * Snapchat authorisation returned 309 ad accounts, and a list that shows them beside the four that
 * feed a client teaches people that all 309 are being read. The Owner's rule is the opposite: an
 * unselected account is not shown on the page at all.
 *
 * Searching and discovering accounts is part of CONNECTING and of EDITING A SELECTION — that is the
 * wizard, which searches and pages on the server. This list is the result of those choices, so it
 * carries no search box and no way to widen itself.
 *
 * ## How the rule is enforced rather than hoped for
 *
 * The props are a union that requires a project or a connection, and the request ALWAYS carries
 * `link=linked`. There is no combination of arguments that asks the server for the whole inventory.
 * The list is paged, so a connection with a hundred selected accounts is never loaded at once.
 *
 * ## Per account
 *
 * State, last sync and next sync, then the four acts that make sense of a chosen account: sync now,
 * pull history, manage the link, remove it from the project. Every one of them needs a project to
 * land in, which a chosen account by definition has.
 */

const COPY = {
  ar: {
    loading: 'جارٍ التحميل…',
    empty: 'لم يُختر أي حساب بعد.',
    sync_now: 'مزامنة الآن',
    synced_queued: 'أُدرجت المزامنة في الطابور.',
    next_sync: 'المزامنة القادمة',
    paused: 'متوقفة',
    manage_link: 'إدارة الربط',
    prev: 'السابق',
    next: 'التالي',
    reference: 'المعرّف',
    project: 'المشروع',
    logs: 'سجل المزامنة',
    backfill: 'سحب بيانات سابقة',
    last_sync: 'آخر مزامنة',
    never: 'لا يوجد',
    close: 'إغلاق',
    from: 'من تاريخ',
    to: 'إلى تاريخ',
    submit_backfill: 'ابدأ السحب',
    queued: 'أُدرج الطلب في الطابور.',
    no_runs: 'لا توجد عمليات مزامنة لهذا الحساب بعد.',
    run_window: 'الفترة',
    run_rows: 'صفوف من المنصة',
    run_metrics: 'قياسات محفوظة',
    run_started: 'البدء',
    run_since: 'منذ',
    run_duration: 'المدة',
    run_detail: 'تفاصيل تقنية',
    trigger_automatic: 'تلقائية',
    trigger_manual: 'يدوية',
    trigger_backfill: 'سحب تاريخي',
    source_first_sync: 'أول مزامنة بعد الاختيار',
    source_reconnect: 'بعد إعادة المصادقة',
    source_scheduled: 'مجدولة',
    run_waited: 'انتظرت في الطابور',
  },
  en: {
    loading: 'Loading…',
    empty: 'No account has been selected yet.',
    sync_now: 'Sync now',
    synced_queued: 'The sync is queued.',
    next_sync: 'Next sync',
    paused: 'Paused',
    manage_link: 'Manage link',
    prev: 'Previous',
    next: 'Next',
    reference: 'Reference',
    project: 'Project',
    logs: 'Sync log',
    backfill: 'Pull history',
    last_sync: 'Last sync',
    never: 'None',
    close: 'Close',
    from: 'From',
    to: 'To',
    submit_backfill: 'Start',
    queued: 'Queued.',
    no_runs: 'No syncs for this account yet.',
    run_window: 'Window',
    run_rows: 'Rows from the platform',
    run_metrics: 'Metrics imported',
    run_started: 'Started',
    run_since: 'Since',
    run_duration: 'Duration',
    run_detail: 'Technical detail',
    trigger_automatic: 'Automatic',
    trigger_manual: 'Manual',
    trigger_backfill: 'Backfill',
    source_first_sync: 'First sync after selecting',
    source_reconnect: 'After reconnecting',
    source_scheduled: 'Scheduled',
    run_waited: 'Queued for',
  },
} as const


/** Widened so either language satisfies it — a `typeof COPY.ar` would only accept the Arabic one. */
type Copy = typeof COPY.ar | typeof COPY.en

/** A project or a connection — at least one, so the list can never be the whole inventory. */
export type SelectedAccountsScope =
  | { project: string; connection?: string }
  | { connection: string; project?: string }

const PER_PAGE = 10

export function SelectedAccounts({ project, connection, onManage }: SelectedAccountsScope & {
  /** Opens the wizard in manage mode on that account's connection and project. */
  onManage?: (account: AccountRow) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const [page, setPage] = useState(1)
  const [logsFor, setLogsFor] = useState<AccountRow | null>(null)
  const [backfillFor, setBackfillFor] = useState<AccountRow | null>(null)

  const scoped = project !== undefined || connection !== undefined

  const accountsQuery = useQuery({
    queryKey: ['integration-accounts', { project, connection, link: 'linked', page }],
    queryFn: () => listAccounts({
      link: 'linked',
      ...(project === undefined ? {} : { project }),
      ...(connection === undefined ? {} : { connection }),
      page,
      per_page: PER_PAGE,
    }),
    enabled: scoped,
    placeholderData: (previous) => previous,
  })

  const accounts = accountsQuery.data?.accounts ?? []
  const lastPage = accountsQuery.data?.meta.last_page ?? 1

  if (accountsQuery.isLoading) return <Skeleton className="h-20 w-full" />

  return (
    <div className="flex min-w-0 flex-col gap-3" data-testid="selected-accounts">
      {accounts.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="selected-accounts-empty">
          {c.empty}
        </p>
      ) : (
        <ul className="flex flex-col gap-2" data-testid="selected-accounts-list">
          {accounts.map((account) => (
            <SelectedAccountItem
              key={account.id}
              account={account}
              ar={ar}
              onLogs={() => setLogsFor(account)}
              onBackfill={() => setBackfillFor(account)}
              onManage={onManage}
            />
          ))}
        </ul>
      )}

      {lastPage > 1 && (
        <div className="flex items-center justify-between gap-2 text-xs" data-testid="selected-accounts-pager">
          <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} data-testid="selected-accounts-prev">
            {c.prev}
          </Button>
          <span className="tnum text-text-muted">{page} / {lastPage}</span>
          <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)} data-testid="selected-accounts-next">
            {c.next}
          </Button>
        </div>
      )}

      {logsFor !== null && <LogsDialog account={logsFor} c={c} ar={ar} onClose={() => setLogsFor(null)} />}
      {backfillFor !== null && (
        <BackfillDialog account={backfillFor} c={c} onClose={() => setBackfillFor(null)} />
      )}
    </div>
  )
}

function SelectedAccountItem({ account, ar, onLogs, onBackfill, onManage }: {
  account: AccountRow
  ar: boolean
  onLogs: () => void
  onBackfill: () => void
  onManage?: (account: AccountRow) => void
}) {
  const c = COPY[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const projectId = account.assigned_project_id

  const sync = useMutation({
    mutationFn: () => syncAccountNow(projectId!, account.id),
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: ['integration-accounts'] })
      void qc.invalidateQueries({ queryKey: ['connection-hub'] })
    },
  })

  return (
    <li className="rounded-lg border border-border bg-background p-3" data-testid="selected-account-row" data-account={account.id}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="min-w-0 truncate text-sm font-semibold text-text-primary">{account.name}</span>
        <Badge tone={accountHealthBadgeTone(account.health)} data-testid="selected-account-state">
          {accountHealthLabel(account.health, ar)}
        </Badge>
      </div>

      <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-text-secondary">
        <span className="font-mono" dir="ltr">{account.reference}</span>
        {account.assigned_project_name !== null && (
          <span className="text-success">{c.project}: {account.assigned_project_name}</span>
        )}
      </p>

      <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-text-secondary">
        <div className="flex gap-1">
          <dt>{c.last_sync}:</dt>
          <dd className="tnum font-semibold text-text-primary" data-testid="selected-account-last-sync">
            {account.last_synced_at === null ? c.never : fmtDateTime(account.last_synced_at)}
          </dd>
        </div>
        <div className="flex gap-1">
          <dt>{c.next_sync}:</dt>
          <dd className="tnum font-semibold text-text-primary" data-testid="selected-account-next-sync">
            {account.next_sync_at === null ? c.paused : fmtDateTime(account.next_sync_at)}
          </dd>
        </div>
      </dl>

      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        {projectId !== null && (
          <Button variant="secondary" size="sm" loading={sync.isPending} onClick={() => sync.mutate()} data-testid="selected-account-sync">
            <RefreshCw size={13} /> {c.sync_now}
          </Button>
        )}
        <Button variant="ghost" size="sm" onClick={onBackfill} data-testid="selected-account-backfill">
          <CalendarClock size={13} /> {c.backfill}
        </Button>
        {onManage !== undefined && (
          <Button variant="ghost" size="sm" onClick={() => onManage(account)} data-testid="selected-account-manage">
            <Link2 size={13} /> {c.manage_link}
          </Button>
        )}
        <Button variant="ghost" size="sm" onClick={onLogs} data-testid={`account-logs-${account.id}`}>
          <ScrollText size={13} /> {c.logs}
        </Button>
      </div>

      {sync.isSuccess && <p className="mt-1 text-[11px] text-success" role="status">{c.synced_queued}</p>}
      {sync.isError && <p className="mt-1 text-[11px] text-danger" role="alert">{toApiError(sync.error).message}</p>}

      {/* INTEGRATION-UNLINK-001 — the way back for an account linked to the wrong client, on the account's own row. */}
      {projectId !== null && (
        <UnlinkAccountButton projectId={projectId} projectName={account.assigned_project_name} accountId={account.id} accountName={account.name} />
      )}
    </li>
  )
}

/** §9 — a run reads as an event: when, why, over what, what came back, and how long it took. */
function LogsDialog({
  account, c, ar, onClose,
}: {
  account: AccountRow
  c: Copy
  ar: boolean
  onClose: () => void
}) {
  const logs = useQuery({
    queryKey: ['integration-account-logs', account.id],
    queryFn: () => getAccountLogs(account.id),
  })

  /*
   * `source` when the server sends one, `trigger` when it does not.
   *
   * Not a transition to clean up later: a log is history, and a run recorded before `source` existed
   * has only the coarser word. Falling back to it says the less precise true thing instead of
   * inventing the precise one.
   */
  const triggerLabel = (run: { trigger: string; source?: string }): string => {
    if (run.source === 'first_sync') return c.source_first_sync
    if (run.source === 'reconnect') return c.source_reconnect
    if (run.source === 'scheduled') return c.source_scheduled
    const trigger = run.source ?? run.trigger
    if (trigger === 'manual') return c.trigger_manual
    if (trigger === 'backfill') return c.trigger_backfill

    return c.trigger_automatic
  }

  return (
    <Dialog title={`${c.logs} — ${account.name}`} closeLabel={c.close} onClose={onClose}>
      {logs.isLoading ? (
        <p className="p-4 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : (logs.data?.runs.length ?? 0) === 0 ? (
        <p className="p-4 text-center text-sm text-text-secondary">{c.no_runs}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {logs.data?.runs.map((run) => {
            const meaning = syncStatusMeaning(run.status)

            return (
              <li key={run.id} data-testid="account-log-row" data-status={run.status} className="rounded-lg border border-border p-2.5 text-xs">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <span className={`rounded-full px-2 py-0.5 font-semibold ${
                    meaning.tone === 'danger' ? 'text-danger' : meaning.tone === 'warning' ? 'text-warning' : meaning.tone === 'success' ? 'text-success' : 'text-text-secondary'
                  }`}
                  >
                    {ar ? meaning.ar : meaning.en}
                  </span>
                  <span className="rounded bg-surface-hover px-1.5 py-0.5 text-text-secondary">{triggerLabel(run)}</span>
                  {/* §8 — the same answer, said once, with how many times it was the answer. */}
                  {run.repeats > 1 && (
                    <span className="rounded bg-surface-hover px-1.5 py-0.5 text-text-secondary" data-testid="account-log-repeats">
                      {ar ? `${run.repeats} محاولة متطابقة` : `${run.repeats} identical attempts`}
                    </span>
                  )}
                  <span className="text-text-secondary">
                    {c.run_window}: {run.window_start} → {run.window_end}
                  </span>
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-text-muted">
                  {/* «—» for a count nobody took; the number for a count of zero. Not the same claim. */}
                  <span>{c.run_rows}: {run.provider_rows ?? '—'}</span>
                  <span>{c.run_metrics}: {run.metrics_imported}</span>
                  <span>{c.run_duration}: {run.duration_seconds === null ? '—' : `${run.duration_seconds}s`}</span>
                  {/* Only when it was measured: «0s» over a run nobody timed would be a claim. */}
                  {typeof run.waited_seconds === 'number' && (
                    <span data-testid="account-log-waited">{c.run_waited}: {run.waited_seconds}s</span>
                  )}
                  <span>
                    {run.repeats > 1 ? c.run_since : c.run_started}:{' '}
                    {(run.repeats > 1 ? run.repeats_since : run.started_at) === null
                      ? c.never
                      : fmtDateTime((run.repeats > 1 ? run.repeats_since : run.started_at) as string)}
                  </span>
                </div>

                {(ar ? meaning.hint_ar : meaning.hint_en) !== '' && (
                  <p className="mt-1 text-text-secondary">{ar ? meaning.hint_ar : meaning.hint_en}</p>
                )}

                {/*
                  The provider's own words, behind a disclosure. Kept verbatim — an operator needs it
                  exactly as recorded — and off the face of the row, where an English stack trace in
                  red says nothing the badge above has not already said.
                */}
                {run.error !== null && (
                  <details className="mt-1">
                    <summary className="cursor-pointer text-text-secondary">{c.run_detail}</summary>
                    <p className="mt-1 rounded bg-surface-hover px-2 py-1 font-mono text-[11px] text-text-secondary" dir="ltr">{run.error}</p>
                  </details>
                )}
              </li>
            )
          })}
          {/*
            No silent caps. Two hundred runs are read, collapsed, then cut to fifty — and a list that
            simply stops reads as «this is what the account has been doing» rather than «this is the
            most recent fifty».
          */}
          {(logs.data?.runs_withheld ?? 0) > 0 && (
            <li className="px-4 py-2 text-[11px] text-text-muted" data-testid="account-runs-withheld">
              {`${logs.data?.runs.length} / ${logs.data?.runs_total}`}
            </li>
          )}
        </ul>
      )}
    </Dialog>
  )
}

function BackfillDialog({
  account, c, onClose,
}: {
  account: AccountRow
  c: Copy
  onClose: () => void
}) {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const run = useMutation({
    mutationFn: () => backfillAccount(account.id, from, to),
  })

  return (
    <Dialog title={`${c.backfill} — ${account.name}`} closeLabel={c.close} onClose={onClose}>
      <div className="flex flex-wrap items-end gap-2">
        {/*
          Text inputs with an explicit YYYY-MM-DD pattern, not `type="date"`.
          A native date input renders in the BROWSER's locale, so an Arabic interface would show a
          picker in whatever order the operating system prefers, and the value the customer reads
          back would not be the value this product stores.
        */}
        <label className="flex flex-col gap-1 text-xs text-text-secondary">
          {c.from}
          <input
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            placeholder="2026-06-01"
            inputMode="numeric"
            className="rounded-lg border border-border bg-background px-2 py-1.5 font-mono text-sm text-text-primary focus:border-brand-500 focus:outline-none"
          />
        </label>
        <label className="flex flex-col gap-1 text-xs text-text-secondary">
          {c.to}
          <input
            value={to}
            onChange={(e) => setTo(e.target.value)}
            placeholder="2026-06-30"
            inputMode="numeric"
            className="rounded-lg border border-border bg-background px-2 py-1.5 font-mono text-sm text-text-primary focus:border-brand-500 focus:outline-none"
          />
        </label>
        <button
          type="button"
          onClick={() => run.mutate()}
          disabled={run.isPending || from === '' || to === ''}
          className="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50"
        >
          {c.submit_backfill}
        </button>
      </div>

      {run.isSuccess && <p className="mt-2 text-xs text-success">{c.queued}</p>}
      {run.isError && <p className="mt-2 text-xs text-danger">{toApiError(run.error).message}</p>}
    </Dialog>
  )
}

function Dialog({
  title, closeLabel, onClose, children,
}: {
  title: string
  closeLabel: string
  onClose: () => void
  children: React.ReactNode
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
      <div className="flex max-h-[80vh] w-full max-w-2xl flex-col gap-3 overflow-y-auto rounded-xl border border-border bg-surface p-4">
        <div className="flex items-center justify-between gap-2">
          <h3 className="truncate text-sm font-bold text-text-primary">{title}</h3>
          <button type="button" onClick={onClose} aria-label={closeLabel} className="text-text-secondary hover:text-text-primary">
            <X size={16} />
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}
