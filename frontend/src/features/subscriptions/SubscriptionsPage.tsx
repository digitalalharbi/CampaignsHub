import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, CreditCard, Gauge } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { toApiError } from '@/lib/api/client'
import { changePlan, getCurrent, getPlans, type CurrentSubscription, type SubscriptionPlan, type UsageMetric } from './api'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'الاشتراكات', subtitle: 'اطّلع على خطة المستأجر الحالية والاستهلاك مقابل الحدود، وبدّل الخطة.',
    no_permission: 'لا تملك صلاحية عرض الاشتراكات.',
    loading: 'جارٍ التحميل…', error: 'تعذّر تحميل بيانات الاشتراك.',
    current_plan: 'الخطة الحالية', default_plan: 'الخطة الافتراضية (بدون اشتراك مُسجَّل)',
    per_month: '/ شهرياً', seats: 'المقاعد', period_end: 'نهاية الدورة',
    usage_title: 'الاستهلاك والحدود', metric: 'المقياس', used: 'المُستهلك', remaining: 'المتبقّي', limit: 'الحد',
    unlimited: 'غير محدود', catalogue: 'الخطط المتاحة', change: 'التبديل إلى هذه الخطة',
    current_badge: 'خطتك الحالية', changing: 'جارٍ التبديل…', changed: 'تم تحديث الاشتراك',
    manage_note: 'تبديل الخطة يتطلّب صلاحية subscriptions.manage.',
    st_active: 'نشِط', st_trialing: 'فترة تجريبية', st_past_due: 'متأخّر السداد', st_canceled: 'مُلغى',
    features: 'المزايا',
    m_projects: 'المشاريع', m_team_members: 'أعضاء الفريق', m_connections: 'الربط', m_reports_per_month: 'التقارير / شهر',
    f_support: 'الدعم', f_ai_assist: 'مساعد الذكاء', f_white_label: 'العلامة البيضاء', yes: 'نعم', no: 'لا',
  },
  en: {
    title: 'Subscriptions', subtitle: 'See the tenant’s current plan and usage against limits, and change plan.',
    no_permission: 'You do not have permission to view subscriptions.',
    loading: 'Loading…', error: 'Could not load subscription data.',
    current_plan: 'Current plan', default_plan: 'Default plan (no explicit subscription)',
    per_month: '/ month', seats: 'Seats', period_end: 'Period ends',
    usage_title: 'Usage & limits', metric: 'Metric', used: 'Used', remaining: 'Remaining', limit: 'Limit',
    unlimited: 'Unlimited', catalogue: 'Available plans', change: 'Switch to this plan',
    current_badge: 'Your current plan', changing: 'Switching…', changed: 'Subscription updated',
    manage_note: 'Changing the plan requires the subscriptions.manage permission.',
    st_active: 'Active', st_trialing: 'Trialing', st_past_due: 'Past due', st_canceled: 'Canceled',
    features: 'Features',
    m_projects: 'Projects', m_team_members: 'Team members', m_connections: 'Connections', m_reports_per_month: 'Reports / month',
    f_support: 'Support', f_ai_assist: 'AI assist', f_white_label: 'White-label', yes: 'Yes', no: 'No',
  },
}
type Copy = (typeof COPY)['ar']

const STATUS_TONE: Record<string, string> = {
  active: 'bg-success/15 text-success',
  trialing: 'bg-info/15 text-info',
  past_due: 'bg-warning/15 text-warning',
  canceled: 'bg-surface-secondary text-text-muted',
}

function statusLabel(status: string, c: Copy): string {
  const map: Record<string, string> = { active: c.st_active, trialing: c.st_trialing, past_due: c.st_past_due, canceled: c.st_canceled }
  return map[status] ?? status
}

function metricLabel(metric: string, c: Copy): string {
  const map: Record<string, string> = {
    projects: c.m_projects, team_members: c.m_team_members, connections: c.m_connections, reports_per_month: c.m_reports_per_month,
  }
  return map[metric] ?? metric
}

function featureLabel(key: string, c: Copy): string {
  const map: Record<string, string> = { support: c.f_support, ai_assist: c.f_ai_assist, white_label: c.f_white_label }
  return map[key] ?? key.replace(/_/g, ' ')
}

function num(value: number | null | undefined, unlimited: string): string {
  if (value === null || value === undefined) return unlimited
  return value.toLocaleString('en-US')
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-CA')
}

export function SubscriptionsPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const canView = useAuth((s) => s.hasPermission('subscriptions.view'))
  const canManage = useAuth((s) => s.hasPermission('subscriptions.manage'))
  const qc = useQueryClient()

  const currentQ = useQuery({ queryKey: ['subscriptions', 'current'], queryFn: getCurrent, retry: false, enabled: canView })
  const plansQ = useQuery({ queryKey: ['subscriptions', 'plans'], queryFn: getPlans, retry: false, enabled: canView })

  const changeM = useMutation({
    mutationFn: (planCode: string) => changePlan(planCode),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['subscriptions', 'current'] })
    },
  })

  if (!canView) {
    return (
      <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_permission}</p>
      </div>
    )
  }

  const isLoading = currentQ.isLoading || plansQ.isLoading
  const isError = currentQ.isError || plansQ.isError
  const current = currentQ.data
  const plans = plansQ.data ?? []
  const currentCode = current?.plan?.code ?? null

  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="flex items-center gap-2 text-3xl font-extrabold tracking-tight text-text-primary"><CreditCard size={26} /> {c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      {isLoading ? (
        <div className="flex flex-col gap-3">{[0, 1].map((i) => <div key={i} className="h-40 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : isError || !current ? (
        <div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{c.error}</div>
      ) : (
        <>
          <CurrentPlanCard current={current} c={c} />
          <UsageTable usage={current.usage} c={c} />
          <section className="flex flex-col gap-3">
            <h2 className="text-sm font-bold text-text-primary">{c.catalogue}</h2>
            {changeM.isSuccess && <span className="text-xs font-semibold text-success">{c.changed}</span>}
            {changeM.isError && <span className="text-xs font-semibold text-danger">{toApiError(changeM.error).message}</span>}
            {!canManage && <p className="text-xs text-text-tertiary">{c.manage_note}</p>}
            <div className="grid gap-3 md:grid-cols-3">
              {plans.map((plan) => (
                <PlanCard
                  key={plan.code}
                  plan={plan}
                  isCurrent={plan.code === currentCode}
                  canManage={canManage}
                  pending={changeM.isPending && changeM.variables === plan.code}
                  onChange={() => changeM.mutate(plan.code)}
                  c={c}
                />
              ))}
            </div>
          </section>
        </>
      )}
    </div>
  )
}

function CurrentPlanCard({ current, c }: { current: CurrentSubscription; c: Copy }) {
  const plan = current.plan
  const sub = current.subscription
  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-col gap-0.5">
          <span className="text-xs font-semibold uppercase tracking-wide text-text-muted">{current.is_default_plan ? c.default_plan : c.current_plan}</span>
          <span className="text-2xl font-extrabold text-text-primary">{plan?.name ?? '—'}</span>
        </div>
        {sub && <span className={`rounded-full px-3 py-1 text-xs font-semibold ${STATUS_TONE[sub.status] ?? 'bg-surface-secondary text-text-muted'}`}>{statusLabel(sub.status, c)}</span>}
      </div>
      <div className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-text-secondary">
        {plan && <span className="tnum font-bold text-text-primary" dir="ltr">{plan.price_monthly} {plan.currency} <span className="text-xs font-normal text-text-muted">{c.per_month}</span></span>}
        {sub && <span>{c.seats}: <span className="tnum font-semibold text-text-primary">{num(sub.seats, c.unlimited)}</span></span>}
        {sub?.current_period_end && <span>{c.period_end}: <span className="tnum font-semibold text-text-primary">{fmtDate(sub.current_period_end)}</span></span>}
      </div>
    </div>
  )
}

function UsageTable({ usage, c }: { usage: Record<string, UsageMetric>; c: Copy }) {
  const rows = Object.entries(usage)
  return (
    <section className="flex flex-col gap-3">
      <h2 className="flex items-center gap-2 text-sm font-bold text-text-primary"><Gauge size={16} /> {c.usage_title}</h2>
      <div className="overflow-x-auto rounded-2xl border border-border bg-surface">
        <table className="w-full min-w-[420px] text-sm">
          <thead className="bg-surface-secondary text-xs text-text-secondary">
            <tr>
              <th className="p-3 text-start font-semibold">{c.metric}</th>
              <th className="p-3 text-end font-semibold">{c.used}</th>
              <th className="p-3 text-end font-semibold">{c.remaining}</th>
              <th className="p-3 text-end font-semibold">{c.limit}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(([metric, u]) => (
              <tr key={metric} className="border-t border-border">
                <td className="p-3 font-medium text-text-primary">{metricLabel(metric, c)}</td>
                <td className="p-3 text-end tnum text-text-secondary" dir="ltr">{num(u.used, c.unlimited)}</td>
                <td className="p-3 text-end tnum text-text-secondary" dir="ltr">{u.remaining === null ? c.unlimited : num(u.remaining, c.unlimited)}</td>
                <td className="p-3 text-end tnum text-text-secondary" dir="ltr">{u.limit === null ? c.unlimited : num(u.limit, c.unlimited)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function PlanCard({
  plan, isCurrent, canManage, pending, onChange, c,
}: {
  plan: SubscriptionPlan; isCurrent: boolean; canManage: boolean; pending: boolean; onChange: () => void; c: Copy
}) {
  const features = Object.entries(plan.features)
  return (
    <div className={`flex flex-col gap-3 rounded-2xl border bg-surface p-5 ${isCurrent ? 'border-brand-500 ring-1 ring-brand-500/30' : 'border-border'}`}>
      <div className="flex items-start justify-between gap-2">
        <span className="font-bold text-text-primary">{plan.name}</span>
        {isCurrent && <span className="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-brand-500/15 px-2 py-0.5 text-[11px] font-semibold text-brand-600"><CheckCircle2 size={12} /> {c.current_badge}</span>}
      </div>
      <div className="tnum text-xl font-extrabold text-text-primary" dir="ltr">{plan.price_monthly} {plan.currency}<span className="ms-1 text-xs font-normal text-text-muted">{c.per_month}</span></div>

      {features.length > 0 && (
        <ul className="flex flex-col gap-1 border-t border-border pt-3 text-xs text-text-secondary">
          {features.map(([key, value]) => (
            <li key={key} className="flex items-center justify-between gap-2">
              <span>{featureLabel(key, c)}</span>
              <span className="font-semibold text-text-primary">
                {typeof value === 'boolean' ? (value ? c.yes : c.no) : String(value)}
              </span>
            </li>
          ))}
        </ul>
      )}

      {canManage && (
        <button
          type="button"
          disabled={isCurrent || pending}
          onClick={onChange}
          className="mt-auto rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {isCurrent ? c.current_badge : pending ? c.changing : c.change}
        </button>
      )}
    </div>
  )
}
