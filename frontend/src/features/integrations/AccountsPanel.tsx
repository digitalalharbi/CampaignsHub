import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CalendarClock, EyeOff, ScrollText, Search, X } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { syncStatusMeaning } from '@/lib/syncStatus'
import {
  backfillAccount, getAccountLogs, listAccounts,
  type AccountRow, type AccountsQuery, type LinkFilter,
} from './api'

/**
 * INTEG-RUNTIME §3 §5 — every account this tenant reaches, and where each one goes.
 *
 * ## The screen this replaces, twice over
 *
 * One real Snapchat authorisation returned **309 ad accounts** and the product first rendered them as
 * 309 identical rows saying «متصل» — three unrelated facts wearing one word, so the page could not
 * answer either question anybody has: «which of these am I paying for?» and «where do this account's
 * numbers go?»
 *
 * The first answer to that was a four-state curation workflow: discovered → enabled → excluded, with
 * chips, a bulk bar and a column of its own. It was internal bookkeeping promoted to customer-facing
 * vocabulary. **Enabling an account did nothing** — it did not sync, did not attach, did not spend a
 * quota slot; only linking it to a project ever did any of those — so the customer was asked to learn
 * a state machine in order to press a button with no effect.
 *
 * There is one distinction left, and it is the only one that was ever real: an account is **linked to
 * a project**, or it is not. That answer is read from the binding, which is the single record of
 * ownership in this system.
 *
 * ## The two numbers on every chip
 *
 * The counts describe the WHOLE inventory and the list describes the filtered part. «مرتبطة ٤» leaves
 * the customer with no way to know whether four is most of what they have or a rounding error on
 * three hundred; «٤ من ٣٠٩» answers it.
 */

const COPY = {
  ar: {
    title: 'الحسابات المكتشَفة',
    subtitle: 'كل حساب تصل إليه المنصة عبر ارتباطاتك، وإلى أي مشروع تذهب بياناته.',
    search_ph: 'ابحث بالاسم أو المعرّف…',
    all: 'الكل',
    linked: 'مرتبطة بمشروع',
    unlinked: 'غير مرتبطة',
    of: 'من',
    loading: 'جارٍ التحميل…',
    empty: 'لا توجد حسابات مطابقة.',
    empty_all: 'لم يُكتشف أي حساب بعد. اربط منصة من الأعلى ليظهر ما تصل إليه هنا.',
    logs: 'سجل المزامنة',
    backfill: 'سحب بيانات سابقة',
    project: 'المشروع',
    no_project: 'غير مرتبط بمشروع — لا تُجلب له بيانات',
    reference: 'المعرّف',
    unnamed_note: 'لم تُرسل المنصة اسمًا لهذا الحساب. سمِّه في لوحة المزوّد ليظهر هنا.',
    quota_free: 'لا يستهلك حصة الحسابات الإعلانية',
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
    run_duration: 'المدة',
    run_detail: 'تفاصيل تقنية',
    trigger_automatic: 'تلقائية',
    trigger_manual: 'يدوية',
    trigger_backfill: 'سحب تاريخي',
  },
  en: {
    title: 'Discovered accounts',
    subtitle: 'Every account this platform can reach through your connections, and which project its data feeds.',
    search_ph: 'Search by name or reference…',
    all: 'All',
    linked: 'Linked to a project',
    unlinked: 'Not linked',
    of: 'of',
    loading: 'Loading…',
    empty: 'No matching accounts.',
    empty_all: 'Nothing discovered yet. Connect a platform above and what it can reach appears here.',
    logs: 'Sync log',
    backfill: 'Pull history',
    project: 'Project',
    no_project: 'Not linked to a project — nothing is fetched for it',
    reference: 'Reference',
    unnamed_note: 'The provider sent no name for this account. Name it in the provider’s console and it appears here.',
    quota_free: 'Does not use an ad-account slot',
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
    run_duration: 'Duration',
    run_detail: 'Technical detail',
    trigger_automatic: 'Automatic',
    trigger_manual: 'Manual',
    trigger_backfill: 'Backfill',
  },
} as const

/** Widened so either language satisfies it — a `typeof COPY.ar` would only accept the Arabic one. */
type Copy = typeof COPY.ar | typeof COPY.en

export function AccountsPanel() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[ar ? 'ar' : 'en']

  const [link, setLink] = useState<LinkFilter | 'all'>('all')
  const [search, setSearch] = useState('')
  const [logsFor, setLogsFor] = useState<AccountRow | null>(null)
  const [backfillFor, setBackfillFor] = useState<AccountRow | null>(null)

  const query: AccountsQuery = useMemo(
    () => ({
      ...(link === 'all' ? {} : { link }),
      ...(search.trim() === '' ? {} : { q: search.trim() }),
      per_page: 50,
    }),
    [link, search],
  )

  const accountsQuery = useQuery({
    queryKey: ['integration-accounts', query],
    queryFn: () => listAccounts(query),
  })

  const accounts = accountsQuery.data?.accounts ?? []
  const summary = accountsQuery.data?.summary

  return (
    <section className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
      <header className="flex flex-col gap-0.5">
        <h2 className="text-base font-bold text-text-primary">{c.title}</h2>
        <p className="text-xs text-text-secondary">{c.subtitle}</p>
      </header>

      {/* The chips are the filter AND the census — «٤ من ٣٠٩» in one control. */}
      <div className="flex flex-wrap items-center gap-2">
        <Chip label={c.all} count={summary?.total} active={link === 'all'} onClick={() => setLink('all')} />
        <Chip
          label={c.linked}
          count={summary?.linked}
          total={summary?.total}
          totalWord={c.of}
          active={link === 'linked'}
          onClick={() => setLink((current) => (current === 'linked' ? 'all' : 'linked'))}
        />
        <Chip
          label={c.unlinked}
          count={summary?.unlinked}
          total={summary?.total}
          totalWord={c.of}
          active={link === 'unlinked'}
          onClick={() => setLink((current) => (current === 'unlinked' ? 'all' : 'unlinked'))}
        />

        <div className="relative min-w-0 flex-1 sm:flex-none">
          <Search size={15} className="pointer-events-none absolute inset-y-0 my-auto ms-2.5 text-text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={c.search_ph}
            aria-label={c.search_ph}
            data-testid="inventory-search"
            className="w-full rounded-lg border border-border bg-background py-1.5 ps-8 pe-2.5 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none sm:w-64"
          />
        </div>
      </div>

      {accountsQuery.isLoading ? (
        <p className="p-6 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : accounts.length === 0 ? (
        <p
          data-testid="inventory-empty"
          className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-text-secondary"
        >
          {summary?.total === 0 ? c.empty_all : c.empty}
        </p>
      ) : (
        <ul className="flex flex-col gap-2" data-testid="inventory-list">
          {accounts.map((account) => (
            <li
              key={account.id}
              data-testid="inventory-row"
              data-linked={account.is_linked}
              className="flex flex-wrap items-start gap-3 rounded-lg border border-border bg-background p-3"
            >
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  {/* The NAME leads. Always words — the server never returns an id here. */}
                  <span className="truncate text-sm font-semibold text-text-primary">{account.name}</span>
                  <span className="text-[11px] text-text-muted">
                    {account.provider_label} · {account.account_type_label}
                  </span>
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-text-secondary">
                  {/* The identifier, as a reference — labelled, secondary, never the name. */}
                  <span className="font-mono">{c.reference}: {account.reference}</span>
                  {account.parent_name !== null && <span>{account.parent_name}</span>}
                  {account.is_linked ? (
                    <span className="text-success">{c.project}: {account.assigned_project_name}</span>
                  ) : (
                    /* Said in words, because «no project» is the reason nothing is happening to it. */
                    <span className="text-text-muted">{c.no_project}</span>
                  )}
                  <span>
                    {c.last_sync}: {account.last_synced_at === null ? c.never : fmtDateTime(account.last_synced_at)}
                  </span>
                  {!account.counts_toward_ad_account_quota && (
                    <span className="rounded bg-surface-hover px-1.5 py-0.5">{c.quota_free}</span>
                  )}
                </div>

                {!account.named_by_provider && (
                  <p className="mt-1 flex items-center gap-1 text-[11px] text-warning">
                    <EyeOff size={12} /> {c.unnamed_note}
                  </p>
                )}
              </div>

              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={() => setLogsFor(account)}
                  className="flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-primary hover:bg-surface-hover"
                >
                  <ScrollText size={13} /> {c.logs}
                </button>
                {/* Offered only where history has somewhere to land — the server refuses otherwise. */}
                {account.is_linked && (
                  <button
                    type="button"
                    onClick={() => setBackfillFor(account)}
                    className="flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-primary hover:bg-surface-hover"
                  >
                    <CalendarClock size={13} /> {c.backfill}
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {logsFor !== null && <LogsDialog account={logsFor} c={c} ar={ar} onClose={() => setLogsFor(null)} />}
      {backfillFor !== null && (
        <BackfillDialog account={backfillFor} c={c} onClose={() => setBackfillFor(null)} />
      )}
    </section>
  )
}

function Chip({
  label, count, total, totalWord, active, onClick,
}: {
  label: string
  count?: number
  total?: number
  totalWord?: string
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${
        active ? 'border-brand-500 bg-brand-500/10 text-brand-600' : 'border-border text-text-secondary'
      }`}
    >
      {label}
      {count !== undefined && (
        <span className="ms-1.5 font-mono text-[11px]">
          {count}
          {total !== undefined && totalWord !== undefined ? ` ${totalWord} ${total}` : ''}
        </span>
      )}
    </button>
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

  const triggerLabel = (trigger: string): string => {
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
                  <span className="rounded bg-surface-hover px-1.5 py-0.5 text-text-secondary">{triggerLabel(run.trigger)}</span>
                  <span className="text-text-secondary">
                    {c.run_window}: {run.window_start} → {run.window_end}
                  </span>
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-text-muted">
                  {/* «—» for a count nobody took; the number for a count of zero. Not the same claim. */}
                  <span>{c.run_rows}: {run.provider_rows ?? '—'}</span>
                  <span>{c.run_metrics}: {run.metrics_imported}</span>
                  <span>{c.run_duration}: {run.duration_seconds === null ? '—' : `${run.duration_seconds}s`}</span>
                  <span>{c.run_started}: {run.started_at === null ? c.never : fmtDateTime(run.started_at)}</span>
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
