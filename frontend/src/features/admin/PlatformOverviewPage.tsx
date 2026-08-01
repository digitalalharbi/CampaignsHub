import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, ArrowRight, Building2, CheckCircle2, Info, Receipt, ShieldCheck, Users, Wallet } from 'lucide-react'
import { fetchOverview } from './api'
import { ATTENTION_LABELS, accountTypeLabel, planLabel, subscriptionStateLabel } from './labels'
import { ChartCard, MetricLineChart, RankingBarChart } from '@/features/analytics/charts'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * `/admin` — the platform at a glance (ADMIN-001, rebuilt for ADMIN-100).
 *
 * Counts and statuses only. No customer's campaigns, clients or figures appear here: owning the
 * platform is not a reason to read a tenant's work, and a console that put it one click away would
 * see it happen without anyone deciding to. Every number on this page is about the PLATFORM —
 * how many tenants, in what state, on what plan, owing what.
 *
 * Two things this page refuses to do:
 *
 * - **Call committed subscription value "revenue".** CampaignsHub does not charge tenants yet; the
 *   invoices ledger in this database is agency-to-client billing and belongs to the agency. The card
 *   says «committed», and the note under it says the collection side is not built. A dashboard that
 *   showed it as money in the bank would be the most expensive lie in the product.
 * - **Print database codes at a reader.** `self_serve_company` and `past_due` are column values, not
 *   words; they were being rendered raw into an Arabic-first interface. See `labels.ts`.
 */

/** Latin digits everywhere, per the product's standing rule. */
const num = (n: number) => n.toLocaleString('en-US')

export function PlatformOverviewPage() {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const Arrow = ar ? ArrowLeft : ArrowRight
  const query = useQuery({ queryKey: ['admin', 'overview'], queryFn: fetchOverview })

  if (query.isPending) {
    return (
      <div className="grid gap-4">
        <Skeleton className="h-10 w-64" />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-32" />)}
        </div>
        <Skeleton className="h-20" />
        <div className="grid gap-4 lg:grid-cols-2">
          <Skeleton className="h-80" />
          <Skeleton className="h-80" />
        </div>
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
  const committed = d.subscriptions.committed_monthly
  const primary = committed[0]

  // The growth series, in the reader's calendar. `month` is `YYYY-MM`; the day is added so the
  // formatter has a real date to work with rather than parsing a partial one.
  const growth = d.growth.map((p) => ({
    label: new Date(`${p.month}-01T00:00:00`).toLocaleDateString(ar ? 'ar' : 'en-GB', { month: 'short', year: '2-digit' }),
    opened: p.opened,
    total: p.total,
  }))

  const byState = Object.entries(d.subscriptions.by_status)
    .map(([code, count]) => ({ label: subscriptionStateLabel(code, ar), count }))
    .sort((a, b) => b.count - a.count)

  const byType = Object.entries(d.tenants.by_account_type)
    .map(([code, count]) => ({ label: accountTypeLabel(code, ar), count }))
    .sort((a, b) => b.count - a.count)

  const byPlan = Object.entries(d.tenants.by_plan)
    .map(([code, count]) => ({ label: planLabel(code, ar), count }))
    .sort((a, b) => b.count - a.count)

  const open = d.attention.filter((a) => a.count > 0)

  return (
    <div data-testid="platform-overview" className="w-full">
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
        {/*
          The money card, said honestly.
          `monthly` is what active and trialing subscriptions are worth per month. It is a COMMITMENT,
          and the sub-label says so; the note below the row says the collection side is not built.
        */}
        <Metric
          testId="committed-monthly"
          to="/admin/billing" icon={Wallet} tone="brand"
          label={ar ? 'قيمة الاشتراكات شهريًا' : 'Committed monthly'}
          display={primary ? `${num(primary.monthly)} ${primary.currency}` : '—'}
          hint={primary
            ? (ar ? `${num(primary.subscriptions)} اشتراك · قيمة ملتزم بها` : `${num(primary.subscriptions)} subscriptions · committed`)
            : (ar ? 'لا اشتراكات نشطة' : 'No active subscriptions')}
        />
        <Metric
          to="/admin/tenants" icon={Building2} tone="info"
          label={ar ? 'المستأجرون' : 'Tenants'} display={num(d.tenants.total)}
          hint={ar ? `${num(d.tenants.active)} نشط · ${num(d.tenants.suspended)} موقوف`
            : `${num(d.tenants.active)} active · ${num(d.tenants.suspended)} suspended`}
        />
        <Metric
          to="/admin/tenants" icon={Users} tone="success"
          label={ar ? 'المستخدمون' : 'People'} display={num(d.people.users)}
          hint={ar ? `${num(d.people.memberships)} عضوية` : `${num(d.people.memberships)} memberships`}
        />
        <Metric
          to="/admin/tenants" icon={ShieldCheck} tone="warning"
          label={ar ? 'مساحات العملاء' : 'Client workspaces'} display={num(d.workload.client_workspaces)}
          hint={ar ? `${num(d.workload.open_requests)} طلب مفتوح` : `${num(d.workload.open_requests)} open requests`}
        />
      </div>

      {/* The one honesty note that has to sit beside the figure it qualifies, not in a tooltip. */}
      {d.subscriptions.collection_status === 'not_implemented' && (
        <p data-testid="collection-note" className="mb-4 flex items-start gap-2 rounded-xl border border-border bg-surface-secondary px-4 py-2.5 text-[12.5px] leading-relaxed text-text-secondary">
          <Info size={15} className="mt-0.5 shrink-0 text-text-muted" aria-hidden />
          {ar
            ? 'القيمة أعلاه هي ما التزم به المشتركون شهريًا، وليست مبالغ محصّلة: تحصيل اشتراكات المنصة غير مفعّل بعد. الفواتير في النظام هي فوترة الوكالة لعملائها وتخص الوكالة وحدها.'
            : 'The figure above is what subscribers have committed per month, not money collected: platform subscription collection is not live yet. The invoices in this system are agency-to-client billing and belong to the agency.'}
        </p>
      )}

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

      {/*
        What to look at, and where it lives.
        Only non-zero rows are listed; "nothing needs attention" is stated in words rather than left
        as an empty strip somebody has to interpret.
      */}
      <section data-testid="attention" className="mb-4 rounded-2xl border border-border bg-surface p-5">
        <h2 className="font-heading text-base font-bold text-text-primary">
          {ar ? 'يحتاج انتباهك' : 'Needs your attention'}
        </h2>
        {open.length === 0 ? (
          <p className="mt-3 flex items-center gap-2 text-sm text-text-secondary">
            <CheckCircle2 size={16} className="text-success" aria-hidden />
            {ar ? 'لا شيء معلّق: لا طلبات بانتظار المراجعة، ولا اشتراكات متأخرة، ولا حسابات موقوفة.'
              : 'Nothing pending: no registrations awaiting review, no subscriptions past due, no suspended accounts.'}
          </p>
        ) : (
          <ul className="mt-3 grid gap-2">
            {open.map((row) => (
              <li key={row.key}>
                <Link
                  to={row.to}
                  data-testid={`attention-${row.key}`}
                  className="flex items-center gap-3 rounded-xl border border-border px-4 py-3 transition-colors hover:border-brand-400 hover:bg-surface-hover"
                >
                  <span className={`tnum inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-extrabold ${
                    row.tone === 'danger' ? 'bg-danger/15 text-danger'
                      : row.tone === 'warning' ? 'bg-warning/15 text-warning' : 'bg-info/15 text-info'
                  }`} dir="ltr">
                    {num(row.count)}
                  </span>
                  <span className="flex-1 text-sm font-semibold text-text-primary">
                    {ar ? ATTENTION_LABELS[row.key]?.ar ?? row.key : ATTENTION_LABELS[row.key]?.en ?? row.key}
                  </span>
                  <Arrow size={16} className="shrink-0 text-text-muted" aria-hidden />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <div className="mb-4 grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        {/* Latin digits in the Arabic copy too — the product's standing rule, and why every figure
            on this page reads the same way whichever language it is in. */}
        <ChartCard
          title={ar ? 'نمو المنصة' : 'Platform growth'}
          subtitle={ar ? 'المستأجرون المفتوحون شهريًا، وإجماليهم التراكمي — آخر 12 شهرًا.'
            : 'Tenants opened each month, and the running total — the last 12 months.'}
        >
          <div data-testid="growth-chart">
            <MetricLineChart
              data={growth}
              series={[
                { key: 'total', name: ar ? 'الإجمالي' : 'Total', kind: 'num' },
                { key: 'opened', name: ar ? 'مفتوح هذا الشهر' : 'Opened', color: 'var(--info)', kind: 'num' },
              ]}
              height={260}
            />
          </div>
        </ChartCard>

        <ChartCard
          title={ar ? 'حالة الاشتراكات' : 'Subscription status'}
          subtitle={ar ? 'كل اشتراك في المنصة، حسب حالته الآن.' : 'Every subscription on the platform, by its current state.'}
        >
          {byState.length === 0 ? (
            <Empty ar={ar} text={ar ? 'لا اشتراكات بعد.' : 'No subscriptions yet.'} />
          ) : (
            <div data-testid="subscription-status">
              <RankingBarChart
                data={byState}
                bars={[{ key: 'count', name: ar ? 'اشتراكات' : 'Subscriptions', kind: 'num' }]}
                horizontal
                height={260}
              />
            </div>
          )}
        </ChartCard>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard
          title={ar ? 'المستأجرون حسب النوع' : 'Tenants by account type'}
          subtitle={ar ? 'من يستخدم المنصة، ولأي غرض.' : 'Who is on the platform, and for what.'}
        >
          {byType.length === 0
            ? <Empty ar={ar} text={ar ? 'لا مستأجرين بعد.' : 'No tenants yet.'} />
            : (
              <div data-testid="tenants-by-type">
                <RankingBarChart
                  data={byType}
                  bars={[{ key: 'count', name: ar ? 'مستأجرون' : 'Tenants', kind: 'num' }]}
                  horizontal height={240}
                />
              </div>
            )}
        </ChartCard>

        <ChartCard
          title={ar ? 'المستأجرون حسب الخطة' : 'Tenants by plan'}
          subtitle={ar ? 'الخطة المسجّلة على كل مساحة عمل.' : 'The plan recorded against each workspace.'}
          action={
            <Link to="/admin/billing" className="text-sm font-semibold text-brand-600 hover:underline">
              {ar ? 'الخطط' : 'Plans'}
            </Link>
          }
        >
          {byPlan.length === 0
            ? <Empty ar={ar} text={ar ? 'لا خطط بعد.' : 'No plans yet.'} />
            : (
              <div data-testid="tenants-by-plan">
                <RankingBarChart
                  data={byPlan}
                  bars={[{ key: 'count', name: ar ? 'مستأجرون' : 'Tenants', kind: 'num' }]}
                  horizontal height={240}
                />
              </div>
            )}
        </ChartCard>
      </div>

      {d.workload.unpaid_invoices > 0 && (
        <p className="mt-4 flex items-center gap-2 text-[12.5px] text-text-muted">
          <Receipt size={14} aria-hidden />
          {ar
            ? `${num(d.workload.unpaid_invoices)} فاتورة غير مسددة عبر المنصة — وهي فوترة الوكالات لعملائها، لا فواتير المنصة.`
            : `${num(d.workload.unpaid_invoices)} unpaid invoices across the platform — agency-to-client billing, not the platform’s own.`}
        </p>
      )}
    </div>
  )
}

function Empty({ text }: { ar: boolean; text: string }) {
  return (
    <p className="rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-text-muted">{text}</p>
  )
}

function Metric({
  to, label, display, hint, icon: Icon, tone, testId,
}: {
  to: string; label: string; display: string; hint?: string
  icon: typeof Building2; tone: 'brand' | 'success' | 'warning' | 'info'; testId?: string
}) {
  const tones = {
    brand: 'bg-brand-primary-soft text-brand-700',
    success: 'bg-success/15 text-success',
    warning: 'bg-warning/15 text-warning',
    info: 'bg-info/15 text-info',
  } as const

  return (
    <Link to={to} data-testid={testId} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 transition-colors hover:border-brand-400">
      <span className={`flex h-9 w-9 items-center justify-center rounded-xl ${tones[tone]}`}>
        <Icon size={18} aria-hidden />
      </span>
      <span>
        <span className="tnum block font-heading text-[26px] font-extrabold leading-tight tracking-tight text-text-primary" dir="ltr">
          {display}
        </span>
        <span className="mt-0.5 block text-sm font-semibold text-text-secondary">{label}</span>
        {hint && <span className="mt-1 block text-xs text-text-muted">{hint}</span>}
      </span>
    </Link>
  )
}
