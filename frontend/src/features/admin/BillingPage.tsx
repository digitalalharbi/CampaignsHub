import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CreditCard, GitBranch, Info, Layers, Users } from 'lucide-react'
import {
  fetchPlans, fetchRevenue, fetchRevenueStreams, fetchSubscriptions, updatePlan,
  type PlatformPlan, type PlatformSubscription, type RevenueStream,
} from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Switch } from '@/components/ui/Switch'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/admin/billing` — plans, subscriptions and what the platform is owed (ADMIN-002).
 *
 * One page, three tabs, because plans and the subscribers on them are the same question asked twice
 * and splitting them across rail entries would make the owner navigate to compare them.
 *
 * The honesty that matters here is in the revenue tab: the figure is COMMITTED subscription value,
 * not cash. `invoices`/`payments` belong to agencies invoicing THEIR clients, so counting them would
 * report customers' money as the platform's own result. The page says so rather than showing a
 * number that reads like revenue.
 */

type TabKey = 'plans' | 'subscriptions' | 'revenue' | 'streams'

const money = (amount: string, currency: string) => `${Number(amount).toLocaleString('en-US')} ${currency}`

export function BillingPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [tab, setTab] = useState<TabKey>('plans')

  const TABS: { key: TabKey; ar: string; en: string; icon: typeof Layers }[] = [
    { key: 'plans', ar: 'الخطط', en: 'Plans', icon: Layers },
    { key: 'subscriptions', ar: 'الاشتراكات', en: 'Subscriptions', icon: Users },
    { key: 'revenue', ar: 'الإيراد', en: 'Revenue', icon: CreditCard },
    { key: 'streams', ar: 'مسارات المال', en: 'Money streams', icon: GitBranch },
  ]

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'الخطط والاشتراكات' : 'Plans & subscriptions'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'ما تبيعه المنصة، ومن اشترك فيه — عبر جميع المستأجرين.'
            : 'What the platform sells, and who is on it — across every tenant.'}
        </p>
      </header>

      <div className="mb-5 flex flex-wrap gap-1 border-b border-border" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={tab === t.key}
            data-testid={`billing-tab-${t.key}`}
            onClick={() => setTab(t.key)}
            className={`-mb-px flex items-center gap-1.5 border-b-2 px-3.5 py-2.5 text-sm font-semibold transition-colors ${
              tab === t.key ? 'border-brand-500 text-brand-700' : 'border-transparent text-text-secondary hover:text-text-primary'
            }`}
          >
            <t.icon size={15} aria-hidden /> {ar ? t.ar : t.en}
          </button>
        ))}
      </div>

      <div role="tabpanel">
        {tab === 'plans' && <PlansTab ar={ar} />}
        {tab === 'subscriptions' && <SubscriptionsTab ar={ar} />}
        {tab === 'revenue' && <RevenueTab ar={ar} />}
        {tab === 'streams' && <StreamsTab ar={ar} />}
      </div>
    </div>
  )
}

function PlansTab({ ar }: { ar: boolean }) {
  const qc = useQueryClient()
  const plans = useQuery({ queryKey: ['admin', 'plans'], queryFn: fetchPlans })
  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: string; active: boolean }) => updatePlan(id, { is_active: active }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin'] }),
  })
  /*
   * PLAN-PAID-001 — the brief puts the monthly and annual prices under the platform owner's control.
   *
   * Safe to expose because the two figures were separated long before this screen existed: the
   * catalogue is what NEW customers are quoted, and `subscriptions.unit_amount` is what an existing
   * one owes. Editing here cannot re-price anybody.
   */
  const reprice = useMutation({
    mutationFn: ({ id, monthly, annual, features, reason }: {
      id: string
      monthly: string
      annual: string | null
      features: Record<string, unknown>
      reason: string
    }) => updatePlan(id, { price_monthly: monthly, price_annual: annual, features, reason }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin'] }),
  })
  const error = toggle.isError ? toApiError(toggle.error) : reprice.isError ? toApiError(reprice.error) : null

  if (plans.isPending) return <div className="grid gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-20" />)}</div>
  if (plans.isError || !plans.data) {
    return <ErrorState title={ar ? 'تعذّر تحميل الخطط.' : 'Plans could not be loaded.'} onRetry={() => void plans.refetch()} />
  }

  return (
    <>
      {error && <p role="alert" className="mb-4 rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}

      <p className="mb-4 flex items-start gap-2.5 rounded-xl border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
        <Info size={16} className="mt-0.5 shrink-0 text-info" aria-hidden />
        {ar
          ? 'إيقاف الباقة يمنع الاشتراكات الجديدة فقط ولا يمس المشتركين الحاليين. تعديل السعر يسري على المشتركين الجدد فقط؛ فكل اشتراك قائم يحتفظ بالسعر المتفق عليه عند بدايته. اترك السعر السنوي فارغًا لسحب الباقة من الاشتراك السنوي.'
          : 'Deactivating a plan stops new sign-ups only and leaves existing subscribers untouched. A price change applies to NEW subscribers only — every existing subscription keeps the amount it was sold at. Leave the annual price empty to withdraw the plan from the yearly term.'}
      </p>

      {plans.data.plans.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {ar ? 'لا خطط بعد.' : 'No plans yet.'}
        </p>
      ) : (
        <ul data-testid="plan-list" className="grid gap-2">
          {plans.data.plans.map((p: PlatformPlan) => (
            <li key={p.id} data-testid={`plan-${p.code}`} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface px-4 py-3">
              <div className="min-w-0">
                <p className="text-[14.5px] font-bold text-text-primary">
                  {p.name}
                  {!p.is_active && (
                    <span className="ms-2 rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-muted">
                      {ar ? 'موقوفة' : 'Inactive'}
                    </span>
                  )}
                </p>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[12.5px] text-text-muted">
                  <span className="tnum" dir="ltr">{money(p.price_monthly, p.currency)}/{ar ? 'شهر' : 'mo'}</span>
                  {/* Active vs total, because a plan with 40 cancelled subscribers has no customers. */}
                  <span className="tnum" dir="ltr">
                    · {p.subscribers.active} {ar ? 'مشترك نشط' : 'active'} {ar ? 'من' : 'of'} {p.subscribers.total}
                  </span>
                </p>
              </div>
              <div className="flex flex-wrap items-end gap-3">
                <PlanTerms
                  plan={p}
                  ar={ar}
                  saving={reprice.isPending}
                  onSave={(monthly, annual, features, reason) =>
                    reprice.mutate({ id: p.id, monthly, annual, features, reason })}
                />
                <Switch
                  id={`plan-active-${p.code}`}
                  checked={p.is_active}
                  disabled={toggle.isPending}
                  onCheckedChange={(next) => toggle.mutate({ id: p.id, active: next })}
                  label={ar ? 'متاحة للاشتراك' : 'Open for sign-up'}
                />
              </div>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

/**
 * The services a plan includes, as switches.
 *
 * Only the boolean features are offered. `support` is a tier name rather than a yes/no, and a
 * checkbox that silently turned "priority" into `true` would be worse than not offering it — the
 * ones a switch can honestly express are the ones a switch gets.
 */
const FEATURE_LABELS: Record<string, { ar: string; en: string }> = {
  campaign_tracking: { ar: 'متابعة الحملات', en: 'Campaign tracking' },
  reports: { ar: 'التقارير', en: 'Reports' },
  ai_assist: { ar: 'المساعد الذكي', en: 'AI assist' },
  white_label: { ar: 'علامة بيضاء', en: 'White label' },
}

/**
 * A plan's commercial terms, edited in place — prices and what it includes.
 *
 * The annual field is deliberately allowed to be EMPTY, and empty means null rather than zero: a
 * plan withdrawn from the yearly term has no annual price, and a plan sold for nothing a year is a
 * free tier by another name. Save is disabled until something actually changed AND a reason has been
 * given, so an owner opening the screen cannot re-save the same numbers, and no change reaches the
 * catalogue without an explanation on the audit entry beside it.
 */
function PlanTerms({ plan, ar, saving, onSave }: {
  plan: PlatformPlan
  ar: boolean
  saving: boolean
  onSave: (monthly: string, annual: string | null, features: Record<string, unknown>, reason: string) => void
}) {
  const current = (plan.features && !Array.isArray(plan.features) ? plan.features : {}) as Record<string, unknown>

  const [monthly, setMonthly] = useState(plan.price_monthly)
  const [annual, setAnnual] = useState(plan.price_annual ?? '')
  const [features, setFeatures] = useState<Record<string, boolean>>(
    () => Object.fromEntries(Object.keys(FEATURE_LABELS).map((k) => [k, current[k] === true])),
  )
  const [reason, setReason] = useState('')

  const featuresChanged = Object.keys(FEATURE_LABELS).some((k) => features[k] !== (current[k] === true))
  const changed = monthly !== plan.price_monthly || annual !== (plan.price_annual ?? '') || featuresChanged
  const field = 'h-9 w-24 rounded-lg border border-border bg-surface px-2.5 text-sm tabular-nums text-text-primary outline-none focus:border-brand-500'

  return (
    <div className="flex flex-col gap-2" data-testid={`plan-prices-${plan.code}`}>
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-[11.5px] font-semibold text-text-muted">
          <span className="mb-1 block">{ar ? 'شهري' : 'Monthly'}</span>
          <input
            data-testid={`plan-price-monthly-${plan.code}`}
            className={field} dir="ltr" inputMode="decimal"
            value={monthly} onChange={(e) => setMonthly(e.target.value)}
          />
        </label>
        <label className="text-[11.5px] font-semibold text-text-muted">
          <span className="mb-1 block">{ar ? 'سنوي' : 'Annual'}</span>
          <input
            data-testid={`plan-price-annual-${plan.code}`}
            className={field} dir="ltr" inputMode="decimal"
            placeholder={ar ? 'لا يُباع' : 'Not sold'}
            value={annual} onChange={(e) => setAnnual(e.target.value)}
          />
        </label>
        <button
          type="button"
          data-testid={`plan-save-${plan.code}`}
          disabled={!changed || reason.trim().length < 3 || saving}
          onClick={() => onSave(
            monthly.trim(),
            annual.trim() === '' ? null : annual.trim(),
            { ...current, ...features },
            reason.trim(),
          )}
          className="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white disabled:opacity-45"
        >
          {ar ? 'حفظ' : 'Save'}
        </button>
      </div>

      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        {Object.entries(FEATURE_LABELS).map(([key, label]) => (
          <label key={key} className="flex items-center gap-1.5 text-[12px] text-text-secondary">
            <input
              type="checkbox"
              data-testid={`plan-feature-${plan.code}-${key}`}
              checked={features[key] ?? false}
              onChange={(e) => setFeatures((f) => ({ ...f, [key]: e.target.checked }))}
              className="h-3.5 w-3.5 rounded border-border accent-brand-600"
            />
            {label[ar ? 'ar' : 'en']}
          </label>
        ))}
      </div>

      {/* Asked for whenever there is something to explain, and only then. */}
      {changed && (
        <input
          data-testid={`plan-reason-${plan.code}`}
          value={reason} onChange={(e) => setReason(e.target.value)}
          placeholder={ar ? 'سبب التغيير (مطلوب)' : 'Why the terms changed (required)'}
          className="h-9 w-full rounded-lg border border-border bg-surface px-2.5 text-sm text-text-primary outline-none focus:border-brand-500"
        />
      )}
    </div>
  )
}

function SubscriptionsTab({ ar }: { ar: boolean }) {
  const [status, setStatus] = useState('')
  const subs = useQuery({ queryKey: ['admin', 'subscriptions', status], queryFn: () => fetchSubscriptions(status || undefined) })

  return (
    <>
      <div className="mb-4 flex flex-wrap gap-2">
        {['', 'active', 'cancelled'].map((key) => (
          <button key={key || 'all'} type="button" onClick={() => setStatus(key)} aria-pressed={status === key}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${status === key ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:bg-surface-hover'}`}>
            {key === '' ? (ar ? 'الكل' : 'All') : key === 'active' ? (ar ? 'نشط' : 'Active') : (ar ? 'ملغى' : 'Cancelled')}
          </button>
        ))}
      </div>

      {subs.isPending && <div className="grid gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-14" />)}</div>}
      {subs.isError && <ErrorState title={ar ? 'تعذّر تحميل الاشتراكات.' : 'Subscriptions could not be loaded.'} onRetry={() => void subs.refetch()} />}

      {subs.data && subs.data.subscriptions.length === 0 && (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {ar ? 'لا اشتراكات تطابق هذا الفلتر.' : 'No subscriptions match that filter.'}
        </p>
      )}

      {subs.data && subs.data.subscriptions.length > 0 && (
        <ul data-testid="subscription-list" className="grid gap-2">
          {subs.data.subscriptions.map((s: PlatformSubscription) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface px-4 py-3">
              <span className="min-w-0">
                <span className="block text-[14px] font-bold text-text-primary">{s.tenant_name ?? '—'}</span>
                <span className="mt-0.5 block text-[12.5px] text-text-muted">
                  {s.plan ?? (ar ? 'خطة محذوفة' : 'Removed plan')}
                  {s.seats !== null && <span className="tnum" dir="ltr"> · {s.seats} {ar ? 'مقعد' : 'seats'}</span>}
                </span>
              </span>
              <span className="flex items-center gap-2.5">
                {s.current_period_end && (
                  <span className="tnum text-[12.5px] text-text-muted" dir="ltr">{s.current_period_end}</span>
                )}
                <span className={`rounded-full px-2.5 py-1 text-[11.5px] font-semibold ${
                  s.status === 'active' ? 'bg-success/15 text-success' : 'bg-surface-secondary text-text-muted'
                }`}>
                  {s.status}
                </span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

function RevenueTab({ ar }: { ar: boolean }) {
  const revenue = useQuery({ queryKey: ['admin', 'revenue'], queryFn: fetchRevenue })

  if (revenue.isPending) return <Skeleton className="h-40" />
  if (revenue.isError || !revenue.data) {
    return <ErrorState title={ar ? 'تعذّر تحميل الإيراد.' : 'Revenue could not be loaded.'} onRetry={() => void revenue.refetch()} />
  }

  const d = revenue.data

  return (
    <>
      {/* Stated before the figure, not after it — a number this size is read first. */}
      <div data-testid="revenue-honesty" className="mb-4 flex items-start gap-2.5 rounded-xl border border-info/30 bg-info/10 px-4 py-3 text-sm">
        <Info size={17} className="mt-0.5 shrink-0 text-info" aria-hidden />
        <span className="text-text-primary">
          {ar
            ? 'هذه قيمة اشتراكات ملتزَم بها شهريًا — وليست مبالغ محصَّلة. لا توجد بعد آلية تحصيل من المستأجرين، وسجل الفواتير والمدفوعات يخص فوترة الوكالات لعملائها ولا يُحتسب هنا.'
            : 'This is committed monthly subscription value — not money collected. There is no charging path for tenants yet, and the invoices/payments ledger belongs to agencies billing their own clients, so it is not counted here.'}
        </span>
      </div>

      {d.committed_monthly.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {ar ? 'لا اشتراكات نشطة، فلا قيمة ملتزَم بها.' : 'No active subscriptions, so nothing is committed.'}
        </p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {d.committed_monthly.map((row) => (
            <li key={row.currency} data-testid={`committed-${row.currency}`} className="rounded-2xl border border-border bg-surface p-5">
              <span className="tnum block font-heading text-2xl font-extrabold text-text-primary" dir="ltr">
                {money(row.monthly, row.currency)}
              </span>
              <span className="mt-1 block text-sm font-semibold text-text-secondary">
                {ar ? 'شهريًا (ملتزَم به)' : 'per month (committed)'}
              </span>
              <span className="tnum mt-1 block text-xs text-text-muted" dir="ltr">
                {row.subscriptions} {ar ? 'اشتراك نشط' : 'active subscriptions'}
              </span>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

/**
 * PAY-005 — the four streams money moves through, kept apart.
 *
 * Only one of them is the platform's. Tenants owe CampaignsHub for their subscriptions; an agency's
 * clients owe the AGENCY for its invoices; the request payments are those same invoices filtered by
 * where they came from; and creator payouts would be the platform paying out, except no payout ledger
 * exists.
 *
 * Two mistakes this page is built to make impossible. Adding the platform's subscriptions to an
 * agency's client invoices reports customers' money as the platform's business result — the single
 * most expensive lie an owner's console could tell. And adding request payments to agency invoices
 * counts the same invoice twice, because the first is a VIEW of the second, not additional money.
 * Each card therefore says whose money it is, a subset card says what it is a subset OF, and the
 * refusal to produce one total is written on the page rather than left as an omission a reader might
 * fill in with a calculator.
 */
function StreamsTab({ ar }: { ar: boolean }) {
  const q = useQuery({ queryKey: ['admin', 'revenue-streams'], queryFn: fetchRevenueStreams })

  if (q.isPending) return <Skeleton className="h-64" />
  if (q.isError || !q.data) {
    return <ErrorState title={ar ? 'تعذّر تحميل مسارات المال.' : 'The money streams could not be loaded.'} onRetry={() => void q.refetch()} />
  }

  /*
   * Name, direction AND explanation live here, in both languages.
   *
   * The backend returns its own `note`, and rendering it put a full English paragraph on an
   * Arabic-first page under Arabic headings — the exact half-translated state REVIEW-003a exists to
   * catch, and one a source grep would have missed because the English is in PHP. The API keeps its
   * note for anyone reading the endpoint directly; what the reader sees is written for them.
   */
  const COPY: Record<RevenueStream['key'], { name: { ar: string; en: string }; direction: { ar: string; en: string }; note: { ar: string; en: string } }> = {
    platform_subscriptions: {
      name: { ar: 'اشتراكات المنصة', en: 'Platform subscriptions' },
      direction: { ar: 'المستأجرون ← CampaignsHub', en: 'tenants → CampaignsHub' },
      note: {
        ar: 'القيمة الشهرية الملتزَم بها للاشتراكات النشطة والتجريبية، محسوبة من المبلغ المتفق عليه في كل اشتراك لا من سعر الخطة الحالي. لم يُحصَّل أي مبلغ: لا يوجد مسار تحصيل فعّال بعد.',
        en: 'Committed monthly value of active and trialing subscriptions, priced from the amount agreed on each subscription rather than the plan’s current price. Nothing has been collected: there is no live charging path yet.',
      },
    },
    agency_client_invoices: {
      name: { ar: 'فواتير الوكالة لعملائها', en: 'Agency → client invoices' },
      direction: { ar: 'العملاء ← الوكالة', en: 'clients → agency' },
      note: {
        ar: 'وكالة تُصدر فواتير لعملائها. هذا المال مِلك المستأجر ولا يُحتسب إيرادًا للمنصة أبدًا؛ وعمود client_workspace_id في الفواتير غير قابل للفراغ، وهو ما يجعل هذا الفصل قابلًا للتحقق لا مجرد وعد.',
        en: 'An agency invoicing its own clients. This money is the tenant’s and is never counted as platform revenue; `invoices.client_workspace_id` is NOT NULL, which makes that checkable rather than a promise.',
      },
    },
    request_service_payments: {
      name: { ar: 'مدفوعات طلبات الخدمة', en: 'Request service payments' },
      direction: { ar: 'العملاء ← الوكالة، مقابل خدمة مطلوبة', en: 'clients → agency, for a requested service' },
      note: {
        ar: 'فواتير صادرة عن طلب خدمة. هي نفسها فواتير المسار أعلاه، مُرشَّحة حسب مصدرها — عرضٌ لها لا مال إضافي.',
        en: 'Invoices raised from a service request. These are the SAME invoices as the stream above, filtered by where they came from — a view, not additional money.',
      },
    },
    creator_payouts: {
      name: { ar: 'مستحقات صنّاع المحتوى', en: 'Creator payouts' },
      direction: { ar: 'المنصة ← صنّاع المحتوى', en: 'platform → creators' },
      note: {
        ar: 'لا يوجد سجل مستحقات، ونظام المؤثرين وUGC معطَّل خلف influencers_ugc_enabled=false. يُعرض كغير منفَّذ لا كصفر: الصفر يعني أنه لا شيء مستحق، وهذا ما لم يقسه النظام أصلًا.',
        en: 'No payout ledger exists, and the influencer/UGC sub-system is withdrawn behind `influencers_ugc_enabled=false`. Reported as not implemented rather than as zero: a zero would claim nothing is owed, and that is not something this system has measured.',
      },
    },
  }

  /** English needs the singular; Arabic reads correctly with the bare noun after a numeral. */
  const count = (n: number, one: string, many: string) => `${n.toLocaleString('en-US')} ${n === 1 ? one : many}`

  const OWNER: Record<RevenueStream['belongs_to'], { ar: string; en: string; cls: string }> = {
    platform: { ar: 'مال المنصة', en: 'the platform’s money', cls: 'border-brand-500/40 bg-brand-primary-soft text-brand-700' },
    tenant: { ar: 'مال العميل، لا المنصة', en: 'the customer’s money, not the platform’s', cls: 'border-warning/40 bg-warning/10 text-warning' },
  }

  const STATE: Record<RevenueStream['status'], { ar: string; en: string }> = {
    live: { ar: 'يعمل', en: 'Live' },
    awaiting_credentials: { ar: 'بانتظار بيانات اعتماد', en: 'Awaiting credentials' },
    not_implemented: { ar: 'غير منفَّذ', en: 'Not implemented' },
  }

  return (
    <div data-testid="revenue-streams" className="grid gap-3">
      {/* The refusal, stated before the figures rather than under them. */}
      <p data-testid="no-combined-total" className="flex items-start gap-2.5 rounded-xl border border-info/30 bg-info/10 px-4 py-3 text-sm text-text-primary">
        <Info size={17} className="mt-0.5 shrink-0 text-info" aria-hidden />
        {ar
          ? 'لا يوجد مجموع واحد لهذه المسارات، وهذا مقصود: جمع اشتراكات المنصة مع فواتير الوكالة يحسب مال العملاء إيرادًا للمنصة، وجمع مدفوعات الطلبات مع فواتير الوكالة يحسب الفاتورة نفسها مرتين.'
          : 'There is no single total across these, deliberately: adding platform subscriptions to agency invoices counts customers’ money as the platform’s revenue, and adding request payments to agency invoices counts the same invoice twice.'}
      </p>

      {q.data.streams.map((s) => (
        <section key={s.key} data-testid={`stream-${s.key}`} className="rounded-2xl border border-border bg-surface p-5">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h3 className="font-heading text-lg font-bold text-text-primary">{ar ? COPY[s.key].name.ar : COPY[s.key].name.en}</h3>
              <p className="mt-0.5 text-xs text-text-muted">{ar ? COPY[s.key].direction.ar : COPY[s.key].direction.en}</p>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
              <span className={`rounded-full border px-2.5 py-0.5 text-xs font-semibold ${OWNER[s.belongs_to].cls}`}>
                {ar ? OWNER[s.belongs_to].ar : OWNER[s.belongs_to].en}
              </span>
              <span className="rounded-full border border-border bg-surface-secondary px-2.5 py-0.5 text-xs font-semibold text-text-secondary">
                {ar ? STATE[s.status].ar : STATE[s.status].en}
              </span>
            </div>
          </div>

          {/* A subset must announce itself, or a reader adds it to its parent. */}
          {s.subset_of && (
            <p data-testid={`subset-${s.key}`} className="mt-2 text-sm font-semibold text-warning">
              {ar
                ? 'هذه الفواتير جزء من «فواتير الوكالة لعملائها» أعلاه — عرض لها، وليست مالًا إضافيًا. لا تُجمع مع ما فوقها.'
                : 'These invoices are part of «Agency → client invoices» above — a view of them, not extra money. Do not add the two together.'}
            </p>
          )}

          {s.amounts.length > 0 ? (
            <ul className="mt-3 grid gap-2 sm:grid-cols-2">
              {s.amounts.map((a) => (
                <li key={a.currency} className="rounded-xl border border-border bg-surface-secondary px-4 py-3">
                  <span className="tnum block font-heading text-xl font-extrabold text-text-primary" dir="ltr">
                    {a.monthly !== undefined
                      ? `${a.monthly.toLocaleString('en-US')} ${a.currency}`
                      : `${(a.invoiced ?? 0).toLocaleString('en-US')} ${a.currency}`}
                  </span>
                  <span className="mt-0.5 block text-xs font-semibold text-text-secondary">
                    {a.monthly !== undefined
                      ? (ar ? `شهريًا · ${a.subscriptions} اشتراك` : `per month · ${count(a.subscriptions ?? 0, 'subscription', 'subscriptions')}`)
                      : (ar ? `مُفوتر · ${a.invoices} فاتورة` : `invoiced · ${count(a.invoices ?? 0, 'invoice', 'invoices')}`)}
                  </span>
                  {a.collected !== undefined && (
                    <span className="tnum mt-1 block text-xs text-text-muted" dir="ltr">
                      {(a.collected).toLocaleString('en-US')} {a.currency} {ar ? 'محصَّل' : 'collected'}
                    </span>
                  )}
                </li>
              ))}
            </ul>
          ) : (
            /* No figure at all rather than a zero — see the note, which says why. */
            <p className="mt-3 rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-text-muted">
              {s.status === 'not_implemented'
                ? (ar ? 'لا يوجد سجل لهذا المسار بعد — لا رقم يُعرض، ولا صفر يُدّعى.' : 'No ledger exists for this stream yet — no figure is shown, and no zero is claimed.')
                : (ar ? 'لا توجد حركة في هذا المسار.' : 'Nothing has moved through this stream.')}
            </p>
          )}

          <p className="mt-3 text-[12.5px] leading-relaxed text-text-secondary">{ar ? COPY[s.key].note.ar : COPY[s.key].note.en}</p>
        </section>
      ))}
    </div>
  )
}
