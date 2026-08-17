import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, CheckSquare, EyeOff, ScrollText, Search, Square, X } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import {
  ACCOUNT_LIFECYCLES, backfillAccount, getAccountLogs, listInventory, setAccountStateBulk,
  type AccountLifecycle, type InventoryAccount, type InventoryQuery, type SettableLifecycle,
} from './api'

/**
 * COMMAND-CENTER §§7–20 — the inventory of every account behind every connection.
 *
 * ## The screen this replaces
 *
 * One real Snapchat authorisation returned **309 ad accounts** and the product rendered them as 309
 * identical rows saying «متصل». Three unrelated facts wore the same word — the provider returned it,
 * the customer claimed it, a project owns it — so the page could not answer either of the questions
 * anybody actually has: «which of these am I paying for?» and «where do this account's numbers go?»
 *
 * ## What is deliberately NOT here
 *
 * No visual borrowing from any other tool. The useful IDEA in that class of product is that sources
 * are managed in one place with an explicit account-by-account decision; the layout, wording and
 * chrome below are this product's own, in Arabic first, and the states are named for what they mean
 * here rather than for what somebody else called them.
 *
 * ## The two numbers on every chip
 *
 * The counts describe the WHOLE inventory and the list describes the filtered part. That is the
 * difference between «مُفعّل ٤» and «٤ من ٣٠٩» — one of them tells the customer whether they have
 * finished choosing.
 */

const COPY = {
  ar: {
    title: 'الحسابات المكتشَفة',
    subtitle: 'كل حساب تصل إليه المنصة، وما يحدث له فعليًا. الاكتشاف ليس تفعيلًا، والتفعيل ليس ارتباطًا بمشروع.',
    search_ph: 'ابحث بالاسم أو المعرّف…',
    all: 'الكل',
    of: 'من',
    loading: 'جارٍ التحميل…',
    empty: 'لا توجد حسابات مطابقة.',
    empty_all: 'لم يُكتشف أي حساب بعد. اربط منصة من الأعلى ليظهر ما تصل إليه هنا.',
    selected: 'محدد',
    enable: 'تفعيل',
    exclude: 'استبعاد',
    reset: 'إعادة إلى «مكتشَف»',
    clear: 'إلغاء التحديد',
    logs: 'سجل المزامنة',
    backfill: 'سحب بيانات سابقة',
    project: 'المشروع',
    no_project: 'لا مشروع',
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
    run_status: 'الحالة',
    run_window: 'النطاق',
    run_metrics: 'المقاييس',
    run_started: 'البدء',
    run_error: 'الخطأ',
  },
  en: {
    title: 'Discovered accounts',
    subtitle: 'Every account this platform can reach, and what is actually happening to it. Discovery is not enabling, and enabling is not assignment to a project.',
    search_ph: 'Search by name or reference…',
    all: 'All',
    of: 'of',
    loading: 'Loading…',
    empty: 'No matching accounts.',
    empty_all: 'Nothing discovered yet. Connect a platform above and what it can reach appears here.',
    selected: 'selected',
    enable: 'Enable',
    exclude: 'Exclude',
    reset: 'Back to “Discovered”',
    clear: 'Clear selection',
    logs: 'Sync log',
    backfill: 'Pull history',
    project: 'Project',
    no_project: 'No project',
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
    run_status: 'Status',
    run_window: 'Window',
    run_metrics: 'Metrics',
    run_started: 'Started',
    run_error: 'Error',
  },
} as const

/** Widened so either language satisfies it — a `typeof COPY.ar` would only accept the Arabic one. */
type Copy = typeof COPY.ar | typeof COPY.en

/**
 * Each state gets its own colour AND its own words.
 *
 * Colour alone would put this back where it started: four shades of one badge that everybody reads
 * as «connected». The label is the signal; the tint is only there to make the list scannable.
 */
const STATE_TONE: Record<AccountLifecycle, string> = {
  discovered: 'bg-surface-hover text-text-secondary border-border',
  enabled: 'bg-brand-500/10 text-brand-600 border-brand-500/30',
  excluded: 'bg-surface-hover text-text-muted border-border line-through',
  assigned: 'bg-success/10 text-success border-success/30',
}

export function AccountInventoryPanel() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale === 'ar' ? 'ar' : 'en']
  const queryClient = useQueryClient()

  const [state, setState] = useState<AccountLifecycle | 'all'>('all')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [logsFor, setLogsFor] = useState<InventoryAccount | null>(null)
  const [backfillFor, setBackfillFor] = useState<InventoryAccount | null>(null)

  const query: InventoryQuery = useMemo(
    () => ({ ...(state === 'all' ? {} : { state }), ...(search.trim() === '' ? {} : { q: search.trim() }), per_page: 50 }),
    [state, search],
  )

  const inventory = useQuery({
    queryKey: ['integration-accounts', query],
    queryFn: () => listInventory(query),
  })

  const bulk = useMutation({
    mutationFn: ({ ids, next }: { ids: string[]; next: SettableLifecycle }) => setAccountStateBulk(ids, next),
    onSuccess: () => {
      setSelected(new Set())
      void queryClient.invalidateQueries({ queryKey: ['integration-accounts'] })
    },
  })

  const accounts = inventory.data?.accounts ?? []
  const summary = inventory.data?.summary

  const toggle = (id: string) => {
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)

      return next
    })
  }

  /*
   * The bulk bar refuses `exclude` while an assigned account is in the selection, in the interface as
   * well as on the server. The server is the rule; this only saves the customer a round trip that
   * ends in a refusal they could have been shown before pressing.
   */
  const selectionHasAssigned = accounts.some((a) => selected.has(a.id) && a.lifecycle === 'assigned')

  return (
    <section className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4">
      <header className="flex flex-col gap-0.5">
        <h2 className="text-base font-bold text-text-primary">{c.title}</h2>
        <p className="text-xs text-text-secondary">{c.subtitle}</p>
      </header>

      {/* The chips are the filter AND the census — «٤ من ٣٠٩» in one control. */}
      <div className="flex flex-wrap items-center gap-2">
        <StateChip
          label={c.all}
          count={summary?.total}
          active={state === 'all'}
          onClick={() => setState('all')}
        />
        {ACCOUNT_LIFECYCLES.map((s) => (
          <StateChip
            key={s}
            label={accounts.find((a) => a.lifecycle === s)?.lifecycle_label ?? s}
            count={summary?.[s]}
            total={summary?.total}
            totalWord={c.of}
            active={state === s}
            tone={STATE_TONE[s]}
            onClick={() => setState((current) => (current === s ? 'all' : s))}
          />
        ))}

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

      {selected.size > 0 && (
        <div
          data-testid="inventory-bulk-bar"
          className="flex flex-wrap items-center gap-2 rounded-lg border border-brand-500/30 bg-brand-500/5 px-3 py-2 text-sm"
        >
          <span className="font-semibold text-text-primary">
            {selected.size} {c.selected}
          </span>
          <button
            type="button"
            onClick={() => bulk.mutate({ ids: [...selected], next: 'enabled' })}
            disabled={bulk.isPending}
            className="rounded-lg border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-text-primary hover:bg-surface-hover disabled:opacity-50"
          >
            {c.enable}
          </button>
          <button
            type="button"
            onClick={() => bulk.mutate({ ids: [...selected], next: 'excluded' })}
            disabled={bulk.isPending || selectionHasAssigned}
            title={selectionHasAssigned ? c.exclude : undefined}
            className="rounded-lg border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-text-primary hover:bg-surface-hover disabled:opacity-50"
          >
            {c.exclude}
          </button>
          <button
            type="button"
            onClick={() => bulk.mutate({ ids: [...selected], next: 'discovered' })}
            disabled={bulk.isPending}
            className="rounded-lg border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-text-primary hover:bg-surface-hover disabled:opacity-50"
          >
            {c.reset}
          </button>
          <button
            type="button"
            onClick={() => setSelected(new Set())}
            className="ms-auto text-xs text-text-secondary underline"
          >
            {c.clear}
          </button>
        </div>
      )}

      {bulk.isError && (
        <p className="rounded-lg border border-danger/30 bg-danger/5 px-3 py-2 text-xs text-danger">
          {toApiError(bulk.error).message}
        </p>
      )}

      {inventory.isLoading ? (
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
              className="flex flex-wrap items-start gap-3 rounded-lg border border-border bg-background p-3"
            >
              <button
                type="button"
                onClick={() => toggle(account.id)}
                aria-pressed={selected.has(account.id)}
                aria-label={account.name}
                className="mt-0.5 text-text-secondary hover:text-text-primary"
              >
                {selected.has(account.id) ? <CheckSquare size={16} /> : <Square size={16} />}
              </button>

              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  {/* The NAME leads. Always words — the server never returns an id here. */}
                  <span className="truncate text-sm font-semibold text-text-primary">{account.name}</span>
                  <span className={`rounded-full border px-2 py-0.5 text-[11px] ${STATE_TONE[account.lifecycle]}`}>
                    {account.lifecycle_label}
                  </span>
                  <span className="text-[11px] text-text-muted">
                    {account.provider_label} · {account.account_type_label}
                  </span>
                </div>

                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-text-secondary">
                  {/* The identifier, as a reference — labelled, secondary, never the name. */}
                  <span className="font-mono">{c.reference}: {account.reference}</span>
                  {account.parent_name !== null && <span>{account.parent_name}</span>}
                  {account.assigned_project_name !== null && (
                    <span className="text-success">{c.project}: {account.assigned_project_name}</span>
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
                {account.lifecycle === 'assigned' && (
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

      {logsFor !== null && <LogsDialog account={logsFor} c={c} onClose={() => setLogsFor(null)} />}
      {backfillFor !== null && (
        <BackfillDialog account={backfillFor} c={c} onClose={() => setBackfillFor(null)} />
      )}
    </section>
  )
}

function StateChip({
  label, count, total, totalWord, active, tone, onClick,
}: {
  label: string
  count?: number
  total?: number
  totalWord?: string
  active: boolean
  tone?: string
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${
        active ? 'border-brand-500 bg-brand-500/10 text-brand-600' : (tone ?? 'border-border text-text-secondary')
      }`}
    >
      {label}
      {count !== undefined && (
        <span className="ms-1.5 font-mono text-[11px]">
          {/*
            Both numbers, always. «مُفعّل ٤» leaves the customer with no way to know whether four is
            most of what they have or a rounding error on three hundred.
          */}
          {count}
          {total !== undefined && totalWord !== undefined ? ` ${totalWord} ${total}` : ''}
        </span>
      )}
    </button>
  )
}

function LogsDialog({
  account, c, onClose,
}: {
  account: InventoryAccount
  c: Copy
  onClose: () => void
}) {
  const logs = useQuery({
    queryKey: ['integration-account-logs', account.id],
    queryFn: () => getAccountLogs(account.id),
  })

  return (
    <Dialog title={`${c.logs} — ${account.name}`} closeLabel={c.close} onClose={onClose}>
      {logs.isLoading ? (
        <p className="p-4 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : (logs.data?.runs.length ?? 0) === 0 ? (
        <p className="p-4 text-center text-sm text-text-secondary">{c.no_runs}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {logs.data?.runs.map((run) => (
            <li key={run.id} className="rounded-lg border border-border p-2.5 text-xs">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="font-semibold text-text-primary">{c.run_status}: {run.status}</span>
                <span className="text-text-secondary">
                  {c.run_window}: {run.window_start} → {run.window_end}
                </span>
                <span className="text-text-secondary">{c.run_metrics}: {run.metrics_upserted ?? 0}</span>
                <span className="text-text-muted">
                  {c.run_started}: {run.started_at === null ? c.never : fmtDateTime(run.started_at)}
                </span>
              </div>
              {/* Shown exactly as recorded. A log that tidies its own errors is a log nobody can debug from. */}
              {run.error !== null && (
                <p className="mt-1 rounded bg-danger/5 px-2 py-1 font-mono text-[11px] text-danger">
                  {c.run_error}: {run.error}
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
    </Dialog>
  )
}

function BackfillDialog({
  account, c, onClose,
}: {
  account: InventoryAccount
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
          Gregorian picker in whatever order the operating system prefers, and the value the customer
          reads back would not be the value this product stores.
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
