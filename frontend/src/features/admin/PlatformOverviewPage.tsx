import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Building2, Receipt, ShieldCheck, Users } from 'lucide-react'
import { fetchOverview } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * `/admin` — the platform at a glance (ADMIN-001).
 *
 * Counts and statuses only. No customer's campaigns, clients or figures appear here: owning the
 * platform is not a reason to read a tenant's work, and a console that put it one click away would
 * see it happen without anyone deciding to.
 *
 * The one figure that is a WARNING rather than information is "people with no workspace" — a growing
 * number there means a grant path is dropping users, which is how invitees ended up signing in to
 * nothing (BUG-INVITE-001). It reads as a problem when it is above zero, and as nothing when it is not.
 */

/** Latin digits everywhere, per the product's standing rule. */
const num = (n: number) => n.toLocaleString('en-US')

export function PlatformOverviewPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['admin', 'overview'], queryFn: fetchOverview })

  if (query.isPending) {
    return (
      <div className="grid gap-4">
        <Skeleton className="h-10 w-64" />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-32" />)}
        </div>
        <Skeleton className="h-56" />
      </div>
    )
  }

  if (query.isError || !query.data) {
    return (
      <ErrorState
        title={ar ? 'تعذّر تحميل نظرة عامة على المنصة.' : 'The platform overview could not be loaded.'}
        onRetry={() => void query.refetch()}
      />
    )
  }

  const d = query.data
  const stranded = d.people.without_membership

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'نظرة عامة على المنصة' : 'Platform overview'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'حالة المنصة: المستأجرون والوصول والاشتراكات — لا بيانات عمل أي عميل.'
            : 'The state of the platform: tenants, access and subscriptions — never any customer’s work.'}
        </p>
      </header>

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Metric to="/admin/tenants" icon={Building2} tone="brand"
          label={ar ? 'المستأجرون' : 'Tenants'} value={d.tenants.total}
          hint={ar ? `${num(d.tenants.active)} نشط · ${num(d.tenants.suspended)} موقوف`
                   : `${num(d.tenants.active)} active · ${num(d.tenants.suspended)} suspended`} />
        <Metric to="/admin/tenants" icon={Users} tone="info"
          label={ar ? 'المستخدمون' : 'People'} value={d.people.users}
          hint={ar ? `${num(d.people.memberships)} عضوية` : `${num(d.people.memberships)} memberships`} />
        <Metric to="/admin/tenants" icon={ShieldCheck} tone="success"
          label={ar ? 'مساحات العملاء' : 'Client workspaces'} value={d.workload.client_workspaces}
          hint={ar ? `${num(d.workload.open_requests)} طلب مفتوح` : `${num(d.workload.open_requests)} open requests`} />
        <Metric to="/admin/tenants" icon={Receipt} tone="warning"
          label={ar ? 'فواتير غير مسددة' : 'Unpaid invoices'} value={d.workload.unpaid_invoices} />
      </div>

      {/* Above zero this is a defect, not a statistic — say which. */}
      {stranded > 0 && (
        <div data-testid="stranded-users" role="alert"
          className="mb-4 flex items-start gap-2.5 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
          <AlertTriangle size={17} className="mt-0.5 shrink-0 text-warning" aria-hidden />
          <span className="text-text-primary">
            {ar
              ? `${num(stranded)} مستخدمًا بلا أي مساحة عمل. هذا ليس وضعًا طبيعيًا — يعني أن مسار منح صلاحية يُسقط المستخدمين، وهؤلاء يسجّلون الدخول ولا يصلون إلى شيء.`
              : `${num(stranded)} people belong to no workspace at all. That is not a normal state — it means a grant path is dropping users, and they sign in to nothing.`}
          </span>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <Breakdown
          title={ar ? 'المستأجرون حسب النوع' : 'Tenants by account type'}
          empty={ar ? 'لا مستأجرين بعد.' : 'No tenants yet.'}
          data={d.tenants.by_account_type}
          ar={ar}
        />
        <Breakdown
          title={ar ? 'المستأجرون حسب الخطة' : 'Tenants by plan'}
          empty={ar ? 'لا اشتراكات بعد.' : 'No subscriptions yet.'}
          data={d.tenants.by_plan}
          ar={ar}
        />
      </div>
    </div>
  )
}

function Metric({
  to, label, value, hint, icon: Icon, tone,
}: {
  to: string; label: string; value: number; hint?: string
  icon: typeof Building2; tone: 'brand' | 'success' | 'warning' | 'info'
}) {
  const tones = {
    brand: 'bg-brand-primary-soft text-brand-700',
    success: 'bg-success/15 text-success',
    warning: 'bg-warning/15 text-warning',
    info: 'bg-info/15 text-info',
  } as const

  return (
    <Link to={to} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 transition-colors hover:border-brand-400">
      <span className={`flex h-9 w-9 items-center justify-center rounded-xl ${tones[tone]}`}>
        <Icon size={18} aria-hidden />
      </span>
      <span>
        <span className="tnum block font-heading text-3xl font-extrabold tracking-tight text-text-primary" dir="ltr">
          {num(value)}
        </span>
        <span className="mt-0.5 block text-sm font-semibold text-text-secondary">{label}</span>
        {hint && <span className="mt-1 block text-xs text-text-muted">{hint}</span>}
      </span>
    </Link>
  )
}

function Breakdown({ title, data, empty, ar }: { title: string; data: Record<string, number>; empty: string; ar: boolean }) {
  const entries = Object.entries(data).sort((a, b) => b[1] - a[1])
  const max = entries.length > 0 ? Math.max(...entries.map(([, c]) => c)) : 0

  return (
    <section className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="font-heading text-lg font-extrabold text-text-primary">{title}</h2>
      {entries.length === 0 ? (
        <p className="mt-4 rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-text-muted">{empty}</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {entries.map(([key, count]) => (
            <li key={key}>
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className="font-semibold text-text-primary">
                  {key === 'none' ? (ar ? 'بلا خطة' : 'No plan') : key === 'unset' ? (ar ? 'غير محدّد' : 'Unset') : key}
                </span>
                <span className="tnum font-bold text-text-secondary" dir="ltr">{num(count)}</span>
              </div>
              <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-secondary">
                <div className="h-full rounded-full bg-brand-500" style={{ width: `${max === 0 ? 0 : Math.round((count / max) * 100)}%` }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
