import { useQuery } from '@tanstack/react-query'
import { Info, Lock } from 'lucide-react'
import { fetchIntegrations, fetchPermissions, fetchStatus } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

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
    return <ErrorState title={ar ? 'تعذّر تحميل الصلاحيات.' : 'Permissions could not be loaded.'} onRetry={() => void query.refetch()} />
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
    return <ErrorState title={ar ? 'تعذّر تحميل التكاملات.' : 'Integrations could not be loaded.'} onRetry={() => void query.refetch()} />
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
              <p className="tnum mt-0.5 text-[12.5px] text-text-muted" dir="ltr">
                {p.tenants} {ar ? 'مستأجرًا' : 'tenants'}
              </p>
              <ul className="mt-3 flex flex-wrap gap-1.5">
                {Object.entries(p.by_status).map(([status, count]) => (
                  <li key={status} className="rounded-full bg-surface-secondary px-2.5 py-1 text-[11.5px] font-semibold text-text-secondary">
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
    return <ErrorState title={ar ? 'تعذّر قراءة الحالة التشغيلية.' : 'Operational status could not be read.'} onRetry={() => void query.refetch()} />
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
            <span className={`rounded-full px-2.5 py-1 text-[11.5px] font-semibold ${
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
      <dt className="text-[11.5px] uppercase tracking-wide text-text-muted">{label}</dt>
      <dd className="truncate font-semibold text-text-primary" dir="ltr">{value}</dd>
    </div>
  )
}
