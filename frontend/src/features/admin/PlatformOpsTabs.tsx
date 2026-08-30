import { useQuery } from '@tanstack/react-query'
import { Info, Lock } from 'lucide-react'
import { fetchIntegrations, fetchPermissions, fetchScheduledWork, fetchStatus, type ScheduledWorkRow } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { days as countedDays } from '@/lib/counted'

/**
 * The three read surfaces of ADMIN-003, mounted as tabs on `/admin/settings` rather than given rail
 * entries of their own — the structure rule is a maximum of two levels, and three read-only lists do
 * not each warrant a place in the navigation.
 */

/** The permission catalogue. Read-only, and it says why rather than leaving a missing button unexplained. */
export function PermissionsTab() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['admin', 'permissions'], queryFn: fetchPermissions })

  if (query.isPending) return <div className="grid gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-24" />)}</div>
  if (query.isError || !query.data) {
    return <ErrorState error={query.error} ar={ar} title={ar ? 'تعذّر تحميل الصلاحيات.' : 'Permissions could not be loaded.'} onRetry={() => void query.refetch()} />
  }

  const d = query.data

  return (
    <>
      <p data-testid="permissions-readonly" className="mb-4 flex items-start gap-2.5 rounded-xl border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
        <Lock size={16} className="mt-0.5 shrink-0 text-text-muted" aria-hidden />
        {ar
          ? 'الكتالوج مُعرَّف في الكود. صلاحية تُنشأ من الواجهة لن تمنح شيئًا لأن لا مكان في النظام يتحقق منها — الأدوار هي ما تجمعه كل مساحة عمل، ولها شاشتها داخل بوابتها.'
          : 'The catalogue is defined in code. A permission created from a screen would grant nothing, because no check in the product looks for it — roles are where each workspace combines these, and roles have their own screen inside their portal.'}
      </p>

      <p className="mb-4 text-sm text-text-secondary">
        <span className="tnum font-bold text-text-primary" dir="ltr">{d.total}</span>{' '}
        {ar ? 'صلاحية عبر' : 'permissions across'}{' '}
        <span className="tnum font-bold text-text-primary" dir="ltr">{d.groups.length}</span>{' '}
        {ar ? 'مجموعة، تستخدمها' : 'groups, used by'}{' '}
        <span className="tnum font-bold text-text-primary" dir="ltr">{d.roles}</span> {ar ? 'دورًا' : 'roles'}.
      </p>

      <ul className="grid gap-3">
        {d.groups.map((g) => (
          <li key={g.group} className="rounded-2xl border border-border bg-surface p-5">
            <h3 className="font-heading text-[15px] font-bold text-text-primary">{g.group}</h3>
            <ul className="mt-3 grid gap-1.5">
              {g.permissions.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
                  <span className="font-mono font-semibold text-text-primary" dir="ltr">{p.key}</span>
                  {/* Zero is meaningful: the key is new, or dead. A list without this cannot say which. */}
                  <span className={`tnum text-[12px] ${p.granted_by_roles === 0 ? 'text-text-muted' : 'text-text-secondary'}`} dir="ltr">
                    {p.granted_by_roles === 0
                      ? (ar ? 'لا يمنحها أي دور' : 'granted by no role')
                      : `${p.granted_by_roles} ${ar ? 'دور' : 'roles'}`}
                  </span>
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ul>
    </>
  )
}

/** Provider connections across tenants — counted, never reinterpreted. */
export function IntegrationsTab() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['admin', 'integrations'], queryFn: fetchIntegrations })

  if (query.isPending) return <div className="grid gap-2">{[0, 1].map((i) => <Skeleton key={i} className="h-24" />)}</div>
  if (query.isError || !query.data) {
    return <ErrorState error={query.error} ar={ar} title={ar ? 'تعذّر تحميل التكاملات.' : 'Integrations could not be loaded.'} onRetry={() => void query.refetch()} />
  }

  return (
    <>
      <p className="mb-4 flex items-start gap-2.5 rounded-xl border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
        <Info size={16} className="mt-0.5 shrink-0 text-info" aria-hidden />
        {ar
          ? 'أعداد فقط. حالات المزوّدين معروضة كما تعرضها شاشات المستأجرين تمامًا — «بانتظار بيانات الاعتماد» تختلف عن «فشل»، ودمجهما يمحو الإجابة.'
          : 'Counts only. Provider states appear exactly as the tenant screens report them — "awaiting credentials" is not "failed", and merging them erases the answer.'}
      </p>

      {query.data.providers.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {ar ? 'لا اتصالات بأي مزوّد بعد.' : 'No provider connections yet.'}
        </p>
      ) : (
        <ul data-testid="provider-list" className="grid gap-2 sm:grid-cols-2">
          {query.data.providers.map((p) => (
            <li key={p.provider} data-testid={`provider-${p.provider}`} className="rounded-2xl border border-border bg-surface p-5">
              <p className="font-heading text-[15px] font-bold text-text-primary" dir="ltr">{p.provider}</p>
              <p className="tnum mt-0.5 text-[12px] text-text-muted" dir="ltr">
                {p.tenants} {ar ? 'مستأجرًا' : 'tenants'}
              </p>
              <ul className="mt-3 flex flex-wrap gap-1.5">
                {Object.entries(p.by_status).map(([status, count]) => (
                  <li key={status} className="rounded-full bg-surface-secondary px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
                    <span dir="ltr">{status}</span>: <span className="tnum" dir="ltr">{count}</span>
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

/** Operational status — the SAME checks /dev/status runs, reached through the owner's gate. */
export function StatusTab() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['admin', 'status'], queryFn: fetchStatus, refetchInterval: 30_000 })

  if (query.isPending) return <Skeleton className="h-40" />
  if (query.isError || !query.data) {
    return <ErrorState error={query.error} title={ar ? 'تعذّر قراءة الحالة التشغيلية.' : 'Operational status could not be read.'} onRetry={() => void query.refetch()} />
  }

  const d = query.data
  const services: [string, string, string][] = [
    ['backend', ar ? 'الخادم' : 'Backend', d.backend?.state ?? 'unknown'],
    ['database', ar ? 'قاعدة البيانات' : 'Database', d.database?.state ?? 'unknown'],
    ['redis', 'Redis', d.redis?.state ?? 'unknown'],
    ['queue_worker', ar ? 'منفّذ الطابور' : 'Queue worker', d.queue_worker?.state ?? 'unknown'],
    ['scheduler', ar ? 'المجدول' : 'Scheduler', d.scheduler?.state ?? 'unknown'],
    ['storage', ar ? 'التخزين' : 'Storage', d.storage?.state ?? 'unknown'],
  ]

  return (
    <>
      <ul data-testid="status-list" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {services.map(([key, label, state]) => (
          <li key={key} data-testid={`status-${key}`} className="flex items-center justify-between gap-3 rounded-xl border border-border bg-surface px-4 py-3">
            <span className="text-[14px] font-semibold text-text-primary">{label}</span>
            <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${
              state === 'running' ? 'bg-success/15 text-success'
                : state === 'stopped' ? 'bg-danger/15 text-danger'
                  : 'bg-surface-secondary text-text-muted'
            }`} dir="ltr">
              {state}
            </span>
          </li>
        ))}
      </ul>

      <dl className="mt-4 grid gap-2 rounded-2xl border border-border bg-surface p-5 text-[13px] sm:grid-cols-3">
        <Fact label={ar ? 'آخر ترحيل' : 'Last migration'} value={d.last_migration ?? '—'} />
        <Fact label={ar ? 'الفرع' : 'Branch'} value={d.branch ?? '—'} />
        <Fact label={ar ? 'الإصدار' : 'Commit'} value={d.commit ?? '—'} />
      </dl>
    </>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-[11px] uppercase tracking-wide text-text-muted">{label}</dt>
      <dd className="truncate font-semibold text-text-primary" dir="ltr">{value}</dd>
    </div>
  )
}

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — the schedulers, and whether anybody can see them.
 *
 * The requirement's second half is observability: «what ran, when, scope, result, rows affected,
 * failure category, retry status, next run». The backend has answered that since the run ledger
 * shipped, and no screen read it — so the automation was observable in the sense that the data
 * existed, and unobservable in the sense that nobody could look.
 *
 * Three states, deliberately not two, because they call for different actions:
 *
 *   - **failing** — it ran and it broke. Read the failure.
 *   - **failing repeatedly** — it has broken every run since the last success. Nobody has looked.
 *   - **never observed** — no run has ever been recorded. That is not «fine» and not «broken»; it is
 *     «we cannot see», and treating it as either is how a scheduler that never fired reads as green.
 *
 * `overdue` is null when there is no history to judge against, and that renders as its own thing
 * rather than as «on time» — the same rule, one level down.
 *
 * No «run now». This requirement is about work that runs on schedulers rather than on buttons, and
 * a button here would answer an operational worry by reintroducing what it exists to remove.
 */
export function ScheduledWorkTab() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({
    queryKey: ['admin', 'scheduled-work'],
    queryFn: fetchScheduledWork,
    refetchInterval: 60_000,
  })

  if (query.isPending) return <Skeleton className="h-40" />
  if (query.isError || !query.data) {
    return (
      <ErrorState
        error={query.error}
        title={ar ? 'تعذّرت قراءة حالة المهام المجدولة.' : 'Scheduled work status could not be read.'}
        onRetry={() => void query.refetch()}
      />
    )
  }

  const { scheduled, summary } = query.data

  /* Attention first: broken, then unseen, then overdue, then the rest by name. */
  const rank = (r: ScheduledWorkRow): number =>
    r.consecutive_failures > 1 ? 0
      : r.last_outcome === 'failed' ? 1
        : r.state === 'never_observed' ? 2
          : r.overdue === true ? 3
            : 4
  const rows = [...scheduled].sort((a, b) => rank(a) - rank(b) || a.command.localeCompare(b.command))

  const ago = (iso: string | null): string => {
    if (iso === null) return '—'
    const ms = Date.now() - Date.parse(iso)
    if (Number.isNaN(ms)) return '—'
    const mins = Math.round(ms / 60_000)
    if (mins < 60) return ar ? `قبل ${mins} دقيقة` : `${mins}m ago`
    const hours = Math.round(mins / 60)
    if (hours < 48) return ar ? `قبل ${hours} ساعة` : `${hours}h ago`
    return ar ? `قبل ${countedDays(Math.round(hours / 24), 'ar')}` : `${Math.round(hours / 24)}d ago`
  }

  return (
    <>
      <ul data-testid="scheduled-summary" className="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        {([
          ['total', ar ? 'مهام مجدولة' : 'Scheduled', summary.total, 'text-text-primary'],
          ['failing', ar ? 'فشلت' : 'Failing', summary.failing, summary.failing > 0 ? 'text-danger' : 'text-text-muted'],
          ['failing_repeatedly', ar ? 'تفشل باستمرار' : 'Failing repeatedly', summary.failing_repeatedly, summary.failing_repeatedly > 0 ? 'text-danger' : 'text-text-muted'],
          ['overdue', ar ? 'متأخرة' : 'Overdue', summary.overdue, summary.overdue > 0 ? 'text-warning' : 'text-text-muted'],
          ['never_observed', ar ? 'لم تُرصد قط' : 'Never observed', summary.never_observed, summary.never_observed > 0 ? 'text-warning' : 'text-text-muted'],
        ] as const).map(([key, label, value, tone]) => (
          <li key={key} data-testid={`scheduled-count-${key}`} className="rounded-xl border border-border bg-surface px-4 py-3">
            <div className="text-[12px] text-text-secondary">{label}</div>
            <div className={`tnum text-xl font-extrabold ${tone}`} dir="ltr">{value}</div>
          </li>
        ))}
      </ul>

      <ul className="grid gap-2">
        {rows.map((r) => (
          <li
            key={r.command}
            data-testid={`scheduled-${r.command}`}
            className="rounded-xl border border-border bg-surface px-4 py-3"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-mono text-[13px] font-semibold text-text-primary" dir="ltr">{r.command}</span>
              <ScheduledState row={r} ar={ar} />
            </div>

            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-text-secondary">
              <span dir="ltr" className="font-mono">{r.expression}</span>
              <span>
                {ar ? 'آخر تشغيل: ' : 'Last run: '}
                <span dir="ltr">{ago(r.last_started_at)}</span>
              </span>
              {r.last_duration_ms !== null && (
                <span dir="ltr">{Math.round(r.last_duration_ms / 1000)}s</span>
              )}
            </div>

            {/*
              The failure text, not a category alone. «sync_failed» tells an operator which bucket it
              is in and nothing about what to do; the message is the part that ends the guessing.
            */}
            {r.last_outcome === 'failed' && r.failure_message !== null && (
              <p data-testid={`scheduled-failure-${r.command}`} className="mt-2 rounded-lg bg-danger/10 px-3 py-2 text-[12px] text-danger">
                {r.failure_class !== null && <span className="font-semibold" dir="ltr">{r.failure_class}: </span>}
                {r.failure_message}
              </p>
            )}
          </li>
        ))}
      </ul>
    </>
  )
}

/** One word for what this command is doing, in the order an operator triages. */
function ScheduledState({ row, ar }: { row: ScheduledWorkRow; ar: boolean }) {
  const [label, tone] =
    row.consecutive_failures > 1
      ? [ar ? `تفشل ${row.consecutive_failures} مرات متتالية` : `failing ${row.consecutive_failures} runs`, 'bg-danger/15 text-danger']
      : row.last_outcome === 'failed'
        ? [ar ? 'فشلت' : 'failed', 'bg-danger/15 text-danger']
        : row.state === 'never_observed'
          ? [ar ? 'لم تُرصد قط' : 'never observed', 'bg-warning/15 text-warning']
          : row.overdue === true
            ? [ar ? 'متأخرة' : 'overdue', 'bg-warning/15 text-warning']
            : [ar ? 'تعمل' : 'ok', 'bg-success/15 text-success']

  return (
    <span data-testid={`scheduled-state-${row.command}`} className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${tone}`}>
      {label}
    </span>
  )
}
