import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CreditCard, Info, Layers, Users } from 'lucide-react'
import {
  fetchPlans, fetchRevenue, fetchSubscriptions, updatePlan,
  type PlatformPlan, type PlatformSubscription,
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

type TabKey = 'plans' | 'subscriptions' | 'revenue'

const money = (amount: string, currency: string) => `${Number(amount).toLocaleString('en-US')} ${currency}`

export function BillingPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [tab, setTab] = useState<TabKey>('plans')

  const TABS: { key: TabKey; ar: string; en: string; icon: typeof Layers }[] = [
    { key: 'plans', ar: 'الخطط', en: 'Plans', icon: Layers },
    { key: 'subscriptions', ar: 'الاشتراكات', en: 'Subscriptions', icon: Users },
    { key: 'revenue', ar: 'الإيراد', en: 'Revenue', icon: CreditCard },
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
  const error = toggle.isError ? toApiError(toggle.error) : null

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
          ? 'إيقاف الخطة يمنع الاشتراكات الجديدة فقط ولا يمس المشتركين الحاليين. السعر لا يُعدَّل من هنا — تغيير ما يدفعه مشتركون حاليون قرار تعاقدي.'
          : 'Deactivating a plan stops new sign-ups only and leaves existing subscribers untouched. The price is not editable here — changing what current subscribers pay is a contractual decision.'}
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
              <Switch
                id={`plan-active-${p.code}`}
                checked={p.is_active}
                disabled={toggle.isPending}
                onCheckedChange={(next) => toggle.mutate({ id: p.id, active: next })}
                label={ar ? 'متاحة للاشتراك' : 'Open for sign-up'}
              />
            </li>
          ))}
        </ul>
      )}
    </>
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
