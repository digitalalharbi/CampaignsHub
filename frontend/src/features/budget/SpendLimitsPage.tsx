import { useProject } from '@/stores/project'
import { useUi, type Locale } from '@/stores/ui'
import { days as countedDays } from '@/lib/counted'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip } from '@/components/fit/KpiMini'
import { TabStrip } from '@/components/fit/TabStrip'
import { Pager } from '@/components/fit/Pager'
import { DetailPanel } from '@/components/fit/DetailPanel'
import { ResponsiveList, type ListColumn } from '@/components/fit/ResponsiveList'
import { useFitRows } from '@/components/fit/useFitRows'
import { useTabParam } from '@/components/fit/useTabParam'
import { CircleDot, Gauge, ShieldAlert, ShieldCheck, ListChecks, BarChart3, Pencil, Trash2, ChevronsRight } from 'lucide-react'
import { money, moneyExact, num, percent } from '@/features/analytics/format'
import { ChartCard, RatioBars, StatusMixBar } from '@/features/analytics/charts'
import { providerLabel } from '@/features/campaigns/labels'
import { useRemoveSpendLimit, useSpendLimits, type LimitAction, type SpendLimitReading } from './spendLimitsApi'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { usePortalPath } from '@/app/portalPath'
import { proposeChange } from '@/features/agents/api'
import { FinancialDisclaimer } from '@/features/funding/FinancialDisclaimer'
import { NewSpendLimitDialog } from './NewSpendLimitDialog'
import { EditSpendLimitDialog } from './EditSpendLimitDialog'
import { FreshnessChip } from '@/features/funding/FreshnessChip'
import { FxRateBar } from '@/features/funding/FxRateBar'
import { ApprovedBudgetBar, NoBudget } from '@/features/funding/ApprovedBudget'
import type { ApprovedBudget } from '@/features/funding/fundingApi'
import { useAuth } from '@/stores/auth'
import { RenewAllButton } from '@/features/funding/RenewAllDialog'
import { useEffect, useState } from 'react'
import { withMark } from '@/lib/money/mark'

/**
 * BUDGET-GOVERNANCE-001 — the workspace's own spend limits, and the sentence that keeps them honest.
 *
 * ## The one thing this page must never let a reader lose
 *
 * Nothing here stops an ad platform from spending. `unified_campaigns.total_budget` is the plan set
 * INSIDE the platform and the platform enforces it; a row on this page is a number an agency set for
 * itself, over a scope no single platform can even see. An operator who believes otherwise will not
 * go and pause the campaigns, and the money keeps going out with a green screen in front of it.
 *
 * So the note sits at the top, in the reader's language, and it comes from the API rather than being
 * written here — a copy in the browser is a copy that can drift from the one every other consumer is
 * told.
 *
 * ## «Unknown» is a state, not a blank
 *
 * Spend withheld for want of an exchange rate, or denominated differently from the limit, produces
 * no comparable figure. This page says so and says why. Showing 0% used would be reporting safety it
 * cannot see, which is the failure the whole feature exists to prevent, arriving through the feature
 * meant to prevent it.
 */
const SCOPE_LABEL: Record<string, { ar: string; en: string }> = {
  project: { ar: 'المشروع كاملًا', en: 'The whole project' },
  platform: { ar: 'منصة', en: 'Platform' },
  account: { ar: 'حساب إعلاني', en: 'Ad account' },
  campaign: { ar: 'حملة', en: 'Campaign' },
}

const STATE_TONE: Record<SpendLimitReading['state'], 'success' | 'warning' | 'danger' | 'neutral'> = {
  ok: 'success',
  approaching: 'warning',
  over: 'danger',
  unknown: 'neutral',
}

const STATE_LABEL: Record<SpendLimitReading['state'], { ar: string; en: string }> = {
  ok: { ar: 'ضمن الحد', en: 'Within the limit' },
  approaching: { ar: 'يقترب من الحد', en: 'Approaching the limit' },
  over: { ar: 'تجاوز الحد', en: 'Over the limit' },
  unknown: { ar: 'لا يمكن القياس', en: 'Cannot be measured' },
}

/** Why a figure is missing — the reason travels with the refusal, never a blank. */
const BASIS: Record<string, { ar: string; en: string }> = {
  partial: {
    ar: 'جزء من الإنفاق لم يُحوَّل لعدم توفر سعر صرف، فلا يوجد رقم واحد يُقارَن بالحد.',
    en: 'Part of the spend could not be converted for want of an exchange rate, so there is no single figure to compare.',
  },
  mixed_currency: {
    ar: 'الإنفاق المحجوب بأكثر من عملة، فلا يمكن جمعه في رقم واحد.',
    en: 'The withheld spend is in more than one currency, so it cannot be added into one figure.',
  },
  currency_mismatch: {
    ar: 'الحد بعملة والإنفاق بأخرى ولا يوجد سعر صرف بينهما — أضف السعر ليُقاس.',
    en: 'The limit and the spend are in different currencies and there is no exchange rate between them — add a rate so it can be measured.',
  },
  absent: { ar: 'لا يوجد إنفاق في هذه الفترة.', en: 'There is no spend in this period.' },
  nothing_measured_in_window: {
    ar: 'لا أرقام مقاسة لهذا المشروع في هذه الفترة — غياب، لا صفر.',
    en: 'Nothing was measured for this project in this period — an absence, not a zero.',
  },
}

const PROJECTION: Record<string, { ar: string; en: string }> = {
  too_early: {
    ar: 'الفترة لم تمضِ بما يكفي لتقدير تاريخ — يوم واحد مضروب في ثلاثين ليس توقعًا.',
    en: 'Too little of the period has passed to state a date — one day multiplied by thirty is not a forecast.',
  },
  no_spend_rate: { ar: 'لا يوجد إنفاق بعد، فلا يوجد معدل يُبنى عليه تاريخ.', en: 'Nothing has been spent yet, so there is no rate to build a date on.' },
  not_within_period: { ar: 'لن يُبلغ الحد قبل نهاية هذه الفترة بالمعدل الحالي.', en: 'The limit will not be reached before this period ends at the current rate.' },
  already_reached: { ar: 'بُلغ الحد بالفعل.', en: 'The limit has already been reached.' },
  no_comparable_spend: { ar: 'لا يوجد رقم إنفاق يمكن قياسه.', en: 'There is no measurable spend figure.' },
}

export function SpendLimitsPage() {
  const projectId = useProject((s) => s.currentProjectId)
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const q = useSpendLimits(projectId)
  const limits = q.data?.limits ?? []
  /*
   * The currency to suggest for a new limit: whatever the existing ones are in, because a project
   * that already watches SAR is not about to set a USD cap, and a limit in a currency the project
   * does not report reads «unknown» by design.
   */
  const suggestedCurrency = q.data?.project_currency ?? limits[0]?.currency ?? 'SAR'

  /*
   * PRODUCT-VISUAL-001 §4 §19 — how many limits, and how many of them are a problem.
   *
   * The page listed every limit as a card and answered «how is this workspace doing» only by making
   * the reader count coloured cards. These four are the same readings the cards carry, counted —
   * and `unknown` is one of them rather than folded into «ok», because a limit whose spend could not
   * be compared is not a limit anybody has been told they are within.
   */
  /*
   * PROJECT-FIRST-VISIT-001 — a limit belongs to a project, and with none chosen the page used to
   * say «no limits yet» over a query it had not run. The choice is on the page now.
   */
  if (!projectId) {
    return (
      <FitPage
        testid="spend-limits-page"
        introTestid="spend-limits-intro"
        title={ar ? 'حدود الإنفاق' : 'Spend limits'}
        info={ar
          ? 'حدود تضعها مساحة العمل لنفسها عبر المنصات — للمراقبة والتنبيه، لا للإيقاف.'
          : 'Limits this workspace sets for itself across platforms — for watching and warning, never for stopping.'}
      >
        <ProjectChooser purpose="spend-limits" fit />
      </FitPage>
    )
  }

  const counts = {
    total: limits.length,
    ok: limits.filter((l) => l.state === 'ok').length,
    approaching: limits.filter((l) => l.state === 'approaching').length,
    over: limits.filter((l) => l.state === 'over').length,
    unknown: limits.filter((l) => l.state === 'unknown').length,
  }

  return (
    <SpendLimitsFit
      projectId={projectId}
      locale={locale}
      ar={ar}
      limits={limits}
      counts={counts}
      suggestedCurrency={suggestedCurrency}
      q={q}
    />
  )
}

/**
 * AGENCY-FIT-001 — spend limits on one screen.
 *
 * The page was a stack: the title, four KPI cards, the FX bar, the budget, a panel holding the enforcement sentence and
 * one 260px card per limit (figures, a paragraph per caveat), then two charts. It is now the title, four compact figures,
 * the enforcement sentence (the one statement that must never be lost, kept on screen), and two tabs:
 *
 *   الحدود     one fixed-height row per limit — name and state, period, limit, consumed, remaining, utilisation, pace —
 *              a page of whole rows that fit, with the rest a page away. The caveats (why a figure is missing, the
 *              projection, the pause requests) are the row's details panel; the figures stay in the row.
 *   القراءات   the budget these limits belong to, the state mix and the pace chart.
 */
const LIMIT_TABS = ['limits', 'readings'] as const
type LimitTab = (typeof LIMIT_TABS)[number]

function SpendLimitsFit({ projectId, locale, ar, limits, counts, suggestedCurrency, q }: {
  projectId: string
  locale: Locale
  ar: boolean
  limits: SpendLimitReading[]
  counts: { total: number; ok: number; approaching: number; over: number; unknown: number }
  suggestedCurrency: string
  q: ReturnType<typeof useSpendLimits>
}) {
  const [tab, setTab] = useTabParam<LimitTab>(LIMIT_TABS, 'limits')
  const [detail, setDetail] = useState<string | null>(null)
  const [editing, setEditing] = useState<SpendLimitReading | null>(null)
  const remove = useRemoveSpendLimit(projectId)
  const fit = useFitRows({ reserve: 44, min: 3, max: 30, fallback: 1000 })
  const perPage = fit.locked ? fit.rows : 1000
  const [page, setPage] = useState(1)
  const pages = Math.max(1, Math.ceil(limits.length / perPage))
  const current = Math.min(page, pages)
  useEffect(() => { setPage(1) }, [tab])
  const shown = limits.slice((current - 1) * perPage, current * perPage)
  const open = limits.find((l) => l.id === detail) ?? null
  const tabs = [
    { key: 'limits', label: ar ? 'الحدود' : 'Limits', icon: ListChecks, count: limits.length },
    ...(limits.length > 0 || q.data?.budget ? [{ key: 'readings', label: ar ? 'القراءات' : 'Readings', icon: BarChart3 }] : []),
  ]
  const active = tabs.some((t) => t.key === tab) ? tab : 'limits'
  const name = (l: SpendLimitReading) => limitName(l, ar, locale)
  const pct = (l: SpendLimitReading) => (l.utilisation === null ? '—' : percent(l.utilisation, 0))

  const columns: ListColumn<SpendLimitReading>[] = [
    { key: 'name', header: ar ? 'الحد' : 'Limit', cell: (l) => (
      <span data-testid={`spend-limit-${l.id}`} className="flex min-w-0 items-center gap-2">
        <span className="truncate font-bold text-text-primary">{name(l)}</span>
        <StateChip limit={l} ar={ar} />
        <FreshnessChip freshness={l.freshness} testid={`spend-limit-${l.id}-freshness`} />
      </span>
    ) },
    { key: 'period', header: ar ? 'الفترة' : 'Period', width: '9.5rem', cell: (l) => <span dir="ltr" title={`${l.period.from} → ${l.period.to}`} className="tnum text-xs text-text-muted">{shortDay(l.period.from)} → {shortDay(l.period.to)}</span> },
    { key: 'amount', header: ar ? 'الحد' : 'Amount', width: '8rem', align: 'end', cell: (l) => (
      <span data-testid={`spend-limit-${l.id}-amount`} title={moneyExact(l.amount, l.currency ?? null)} className="tnum font-bold" dir="ltr">{money(l.amount, l.currency)}</span>
    ) },
    { key: 'consumed', header: ar ? 'الإنفاق' : 'Consumed', width: '8rem', align: 'end', cell: (l) => (
      <span data-testid={`spend-limit-${l.id}-consumed`} className="tnum" dir="ltr" title={l.consumed === null ? undefined : moneyExact(l.consumed, l.consumed_currency ?? l.currency ?? null)}>
        {l.consumed === null ? '—' : money(l.consumed, l.consumed_currency ?? l.currency)}
      </span>
    ) },
    { key: 'remaining', header: ar ? 'المتبقي' : 'Remaining', width: '8rem', align: 'end', cell: (l) => (
      <span className={`tnum ${l.remaining !== null && l.remaining < 0 ? 'font-bold text-danger' : ''}`} dir="ltr">{l.remaining === null ? '—' : money(l.remaining, l.currency)}</span>
    ) },
    { key: 'used', header: ar ? 'نسبة الإنفاق' : 'Used', width: '6rem', align: 'end', cell: (l) => <span data-testid={`spend-limit-${l.id}-utilisation`} className="tnum font-bold" dir="ltr">{pct(l)}</span> },
    { key: 'pace', header: ar ? 'السرعة' : 'Pace', width: '5.5rem', align: 'end', cell: (l) => <span className="tnum" dir="ltr">{l.pace === null ? '—' : `${num(l.pace * 100)}%`}</span> },
    { key: 'act', header: '', width: '8.5rem', align: 'end', cell: (l) => <RowActions limit={l} ar={ar} pending={remove.isPending} onDetails={() => setDetail(l.id)} onEdit={() => setEditing(l)} onRemove={() => remove.mutate(l.id)} /> },
  ]

  const card = (l: SpendLimitReading) => (
    <div className="rounded-xl border border-border bg-surface p-3">
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <span className="text-sm font-bold text-text-primary">{name(l)}</span>
        <StateChip limit={l} ar={ar} />
        <FreshnessChip freshness={l.freshness} testid={`spend-limit-${l.id}-freshness`} />
        <span className="ms-auto"><RowActions limit={l} ar={ar} pending={remove.isPending} onDetails={() => setDetail(l.id)} onEdit={() => setEditing(l)} onRemove={() => remove.mutate(l.id)} /></span>
      </div>
      <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
        <Fig label={ar ? 'الحد' : 'Limit'}><span data-testid={`spend-limit-${l.id}-amount`} title={moneyExact(l.amount, l.currency ?? null)} dir="ltr">{money(l.amount, l.currency)}</span></Fig>
        <Fig label={ar ? 'الإنفاق' : 'Consumed'}><span data-testid={`spend-limit-${l.id}-consumed`} dir="ltr">{l.consumed === null ? '—' : money(l.consumed, l.consumed_currency ?? l.currency)}</span></Fig>
        <Fig label={ar ? 'المتبقي' : 'Remaining'}><span dir="ltr" className={l.remaining !== null && l.remaining < 0 ? 'text-danger' : ''}>{l.remaining === null ? '—' : money(l.remaining, l.currency)}</span></Fig>
        <Fig label={ar ? 'نسبة الإنفاق' : 'Utilisation'}><span data-testid={`spend-limit-${l.id}-utilisation`} dir="ltr">{pct(l)}</span></Fig>
      </div>
      <p className="mt-1 text-[11px] text-text-muted" dir="ltr">{l.period.from} → {l.period.to}</p>
    </div>
  )

  return (
    <FitPage
      testid="spend-limits-page"
      introTestid="spend-limits-intro"
      title={ar ? 'حدود الإنفاق' : 'Spend limits'}
      info={ar
        ? 'حدود تضعها مساحة العمل لنفسها عبر المنصات — للمراقبة والتنبيه، لا للإيقاف.'
        : 'Limits this workspace sets for itself across platforms — for watching and warning, never for stopping.'}
      /* The page's one action, in the head — where every other surface puts its primary action. */
      actions={<><RenewAllButton /><NewSpendLimitDialog projectId={projectId} locale={locale} currency={suggestedCurrency} /></>}
      kpis={limits.length === 0 ? undefined : (
        <KpiStrip>
          <KpiMini testid="spend-limits-kpi-total" icon={CircleDot} tone="brand" label={ar ? 'حدود مفعّلة' : 'Active limits'} value={counts.total.toLocaleString('en-US')} />
          <KpiMini testid="spend-limits-kpi-approaching" icon={Gauge} tone={counts.approaching > 0 ? 'warning' : 'neutral'} label={ar ? 'قرّبت تخلص' : 'Near the limit'} value={counts.approaching.toLocaleString('en-US')} />
          <KpiMini testid="spend-limits-kpi-over" icon={ShieldAlert} tone={counts.over > 0 ? 'danger' : 'success'} label={ar ? 'تجاوزت الميزانية' : 'Over the limit'} value={counts.over.toLocaleString('en-US')} />
          {/* «Unknown» is a state and gets a card, not a silence: a limit whose spend could not be compared has told nobody they are within it. */}
          <KpiMini testid="spend-limits-kpi-unknown" icon={ShieldCheck} tone="neutral" label={ar ? 'بدون بيانات' : 'Not comparable'} value={counts.unknown.toLocaleString('en-US')} />
        </KpiStrip>
      )}
      toolbar={
        <div className="flex min-w-0 flex-col gap-2">
          {/* The sentence, first and unmissable, from the API rather than from here — Nothing on this page stops an ad platform from spending. */}
          {q.data && (
            <p data-testid="spend-limits-enforcement" className="rounded-xl border border-warning/40 bg-warning/5 px-3 py-2 text-xs leading-snug text-text-secondary">
              {ar ? q.data.enforcement_note_ar : q.data.enforcement_note_en}
            </p>
          )}
          <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
            {tabs.length > 1 ? <TabStrip testid="spend-limits-tabs" label={ar ? 'أقسام الحدود' : 'Limit sections'} items={tabs} active={active} onChange={(k) => setTab(k as LimitTab)} /> : <span />}
            <FxRateBar />
          </div>
        </div>
      }
    >
      {q.isLoading && <Skeleton className="h-40" />}
      {q.isError && <p role="alert" className="rounded-xl border border-border bg-surface p-4 text-sm text-danger">{ar ? 'تعذّر التحميل.' : 'Could not load.'}</p>}

      {!q.isLoading && !q.isError && active === 'limits' && (
        limits.length === 0 ? (
          <EmptyState
            title={ar ? 'لا حدود بعد' : 'No limits yet'}
            description={ar
              ? 'ضع حدًا لمشروع أو منصة أو حساب أو حملة، وسنراقب الإنفاق مقابله وننبّهك قبل بلوغه.'
              : 'Set a limit for a project, a platform, an account or a campaign, and we will watch spend against it and warn you before it is reached.'}
          />
        ) : (
          <div className="flex min-h-0 flex-1 flex-col">
            <div ref={fit.ref as never} className="min-h-0 flex-1">
              <ResponsiveList
                testid="spend-limits-list"
                rows={shown}
                rowKey={(l) => l.id}
                columns={columns}
                renderCard={(l) => <li data-testid={`spend-limit-${l.id}`}>{card(l)}</li>}
                onOpen={(l) => setDetail(l.id)}
              />
            </div>
            {fit.locked && <Pager testid="spend-limits-pager" page={current} pages={pages} onPage={setPage} total={limits.length} ar={ar} hideWhenSingle />}
          </div>
        )
      )}

      {!q.isLoading && !q.isError && active === 'readings' && (
        <div className="grid min-h-0 flex-1 grid-cols-1 content-start gap-2.5 lg:grid-cols-3">
          {/* ONE-BUDGET-001 — the limits are part of the project's approved budget: the budget first, with its alert steps. */}
          {q.data?.budget && <BudgetOfLimits budget={q.data.budget} ar={ar} />}
          {limits.length > 0 && (
            <>
              {/*
                VIZ-BUDGET-001 — how much of the workspace is in each state, as one bar.

                The four KPI cards answer «how many» four times. «How much of what I set is in trouble» is the fifth question,
                and the one somebody opens this page to ask. The total is the denominator rather than the sum of the bands —
                they do cover it, because every limit carries exactly one state, and passing the total makes that a checked fact.
              */}
              <ChartCard title={ar ? 'حالة الحدود' : 'How the limits stand'}>
                <StatusMixBar
                  testId="limit-states"
                  ar={ar}
                  label={ar ? 'حدود' : 'Limits'}
                  total={limits.length}
                  bands={[
                    { key: 'ok', label: ar ? 'ضمن الحد' : 'Within the limit', count: counts.ok, tone: 'success' },
                    { key: 'approaching', label: ar ? 'قرّبت تخلص' : 'Near the limit', count: counts.approaching, tone: 'warning' },
                    { key: 'over', label: ar ? 'تجاوزت الميزانية' : 'Over the limit', count: counts.over, tone: 'danger' },
                    /* Neutral, never green: a limit whose spend could not be compared has told nobody they are within it. */
                    { key: 'unknown', label: ar ? 'غير قابلة للمقارنة' : 'Not comparable', count: counts.unknown, tone: 'neutral' },
                  ]}
                />
              </ChartCard>
              <LimitPace limits={limits} ar={ar} locale={locale} />
            </>
          )}
        </div>
      )}

      {/* BUDGET-DISCLAIMER-001 — ad-spend money is shown here, so the one financial disclaimer is too. */}
      <FinancialDisclaimer className="mt-auto shrink-0 pt-1" />

      <DetailPanel open={open !== null} onClose={() => setDetail(null)} title={open ? name(open) : ''} closeLabel={ar ? 'إغلاق' : 'Close'} testid="spend-limit-details">
        {open && <LimitDetails limit={open} ar={ar} locale={locale} projectId={projectId} />}
      </DetailPanel>
      {editing && <EditSpendLimitDialog limit={editing} projectId={projectId} ar={ar} onClose={() => setEditing(null)} />}
    </FitPage>
  )
}

/** «26/11/16» — the short form of a day for a one-line row; the full dates are in the title. */
const shortDay = (d: string) => d.slice(2).replaceAll('-', '/')

function StateChip({ limit, ar }: { limit: SpendLimitReading; ar: boolean }) {
  return (
    <span
      data-testid={`spend-limit-${limit.id}-state`}
      className={`shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${
        STATE_TONE[limit.state] === 'danger' ? 'bg-danger/10 text-danger'
          : STATE_TONE[limit.state] === 'warning' ? 'bg-warning/10 text-warning'
            : STATE_TONE[limit.state] === 'success' ? 'bg-success/10 text-success'
              : 'bg-surface-secondary text-text-secondary'
      }`}
    >
      {ar ? STATE_LABEL[limit.state].ar : STATE_LABEL[limit.state].en}
    </span>
  )
}

function Fig({ label, children }: { label: string; children: React.ReactNode }) {
  return <span className="flex min-w-0 flex-col"><span className="text-text-muted">{label}</span><span className="truncate font-bold text-text-primary">{children}</span></span>
}

/**
 * «Remove» deactivates: the events written against a limit are its audit trail, and the endpoint keeps the row so last
 * quarter's limit still sits beside what was spent against it. Icons, with the word as the accessible name.
 */
function RowActions({ limit, ar, pending, onDetails, onEdit, onRemove }: { limit: SpendLimitReading; ar: boolean; pending: boolean; onDetails: () => void; onEdit: () => void; onRemove: () => void }) {
  const btn = 'flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary transition-colors hover:bg-surface-hover'

  return (
    <span className="inline-flex items-center gap-0.5" onClick={(e) => e.stopPropagation()}>
      <button type="button" data-testid={`spend-limit-${limit.id}-details`} aria-label={ar ? 'التفاصيل' : 'Details'} title={ar ? 'التفاصيل' : 'Details'} onClick={onDetails} className={btn}><ChevronsRight size={16} aria-hidden className="rtl:rotate-180" /></button>
      <button type="button" data-testid={`spend-limit-${limit.id}-edit`} aria-label={ar ? 'تعديل' : 'Edit'} title={ar ? 'تعديل' : 'Edit'} onClick={onEdit} className={btn}><Pencil size={15} aria-hidden /></button>
      <button type="button" data-testid={`spend-limit-${limit.id}-remove`} aria-label={ar ? 'إزالة' : 'Remove'} title={ar ? 'إزالة' : 'Remove'} disabled={pending} onClick={onRemove} className={`${btn} hover:!bg-danger/10 hover:text-danger disabled:opacity-60`}><Trash2 size={15} aria-hidden /></button>
    </span>
  )
}

/**
 * ONE-BUDGET-001 — the approved budget these limits belong to (the same reading as /agency/budgets) and its alert steps.
 * A project with no budget says so and offers to add one; its limits are then measured on their own until it has one.
 */
function BudgetOfLimits({ budget, ar }: { budget: ApprovedBudget; ar: boolean }) {
  const canAdd = useAuth((s) => s.hasPermission('funding.manage'))
  if (!budget.has_budget) {
    if (budget.withheld) return null

    return (
      <section data-testid="limits-budget" className="rounded-xl border border-border bg-surface p-3">
        <h2 className="mb-1 text-sm font-bold text-text-primary">{ar ? 'ميزانية المشروع' : 'Project budget'}</h2>
        <NoBudget ar={ar} canAdd={canAdd} />
      </section>
    )
  }

  return (
    <section data-testid="limits-budget" className="flex flex-col gap-2 rounded-xl border border-border bg-surface p-3">
      <h2 className="text-sm font-bold text-text-primary">{ar ? 'ميزانية المشروع' : 'Project budget'}</h2>
      <ApprovedBudgetBar ar={ar} approved={budget.approved} spent={budget.spent} remaining={budget.remaining} ratio={budget.used_ratio} coverDays={budget.cover_days} testid="limits-budget-bar" />
      <div className="flex flex-wrap items-center gap-1.5 text-xs" data-testid="limits-budget-steps">
        <span className="text-text-muted">{ar ? 'تنبيه عند' : 'Alert at'}</span>
        {budget.thresholds.map((step) => {
          const reached = budget.crossed_threshold !== null && step <= budget.crossed_threshold
          return (
            <span key={step} className={`tnum rounded-full px-2 py-0.5 font-semibold ${reached ? 'bg-[var(--warning-background)] text-warning' : 'bg-surface-secondary text-text-secondary'}`}>
              {step}%
            </span>
          )
        })}
      </div>
    </section>
  )
}

/** What a row cannot say in one line: the caveats, the projection and what may be asked for. The figures themselves are in the row. */
function LimitDetails({
  limit,
  ar,
  locale,
  projectId,
}: {
  limit: SpendLimitReading
  ar: boolean
  locale: 'ar' | 'en'
  projectId: string | null
}) {
  return (
    <div data-testid={`spend-limit-${limit.id}-details-body`} className="flex flex-col gap-2 text-xs">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-[11px] text-text-muted" dir="ltr">
          {limit.period.from} → {limit.period.to}
        </span>
      </div>

      <StatGrid min="8rem">
        <StatCard label={ar ? 'الحد' : 'Limit'} value={money(limit.amount, limit.currency)} exact={moneyExact(limit.amount, limit.currency ?? null)} />
        <StatCard
          label={ar ? 'الإنفاق' : 'Consumed'}
          /* Never «0» for a figure nobody could compute — the em dash is the honest answer. */
          value={limit.consumed === null ? '—' : money(limit.consumed, limit.consumed_currency ?? limit.currency)}
        />
        <StatCard
          label={ar ? 'سرعة الصرف' : 'Pace'}
          /* >1 is ahead of plan, against the elapsed share of the limit — not against all of it. */
          value={limit.pace === null ? '—' : `${num(limit.pace * 100)}%`}
          hint={ar
            ? `مضى ${limit.elapsed_days} من ${countedDays(limit.period.days, 'ar')}`
            : `${limit.elapsed_days} of ${countedDays(limit.period.days, 'en')} elapsed`}
        />
      </StatGrid>

      {limit.basis !== 'comparable' && BASIS[limit.basis] && (
        <p data-testid={`spend-limit-${limit.id}-basis`} className="text-xs text-text-secondary">
          {ar ? BASIS[limit.basis]!.ar : BASIS[limit.basis]!.en}
        </p>
      )}

      {/* BUDGET-PACING-COVERAGE-001 — the pace is measured through the last day the data runs to, when that is earlier than today. */}
      {limit.paced_through && (
        <p data-testid={`spend-limit-${limit.id}-paced-through`} className="text-xs text-text-secondary">
          {ar
            ? `السرعة محسوبة حتى ${limit.paced_through} — آخر يوم وصلت بياناته — لا حتى اليوم.`
            : `The pace is measured through ${limit.paced_through} — the last day with data — not through today.`}
        </p>
      )}
      {(limit.state === 'approaching' || limit.state === 'over') && (limit.actions?.length ?? 0) > 0 && (
        <LimitActions limit={limit} ar={ar} locale={locale} projectId={projectId} />
      )}
      {limit.converted && (
        <p data-testid={`spend-limit-${limit.id}-converted`} className="text-xs text-text-secondary">
          {ar
            ? `الإنفاق محوَّل من ${limit.converted.from} إلى ${limit.converted.to} بسعر ${limit.converted.rate}`
            : `Spend converted from ${limit.converted.from} to ${limit.converted.to} at ${limit.converted.rate}`}
        </p>
      )}
      {limit.run_out?.date && (
        <p data-testid={`spend-limit-${limit.id}-run-out`} className="text-xs font-semibold text-text-secondary" title={ar ? 'من متوسط إنفاق آخر 3 أيام' : 'From the average spend of the last 3 days'}>
          {ar ? 'يخلص الحد تقريبًا في ' : 'The limit runs out about '}
          <bdi dir="ltr" className="tnum">{limit.run_out.date}</bdi>
        </p>
      )}
      <p data-testid={`spend-limit-${limit.id}-projection`} className="text-xs text-text-muted">
        {limit.projected_exhaustion.date !== null
          ? (ar
            ? `بالمعدل الحالي يُبلغ الحد في ${limit.projected_exhaustion.date}.`
            : `At the current rate the limit is reached on ${limit.projected_exhaustion.date}.`)
          : (ar
            ? PROJECTION[limit.projected_exhaustion.reason]?.ar ?? ''
            : PROJECTION[limit.projected_exhaustion.reason]?.en ?? '')}
      </p>

      {limit.thresholds.length > 0 && (
        <p className="text-[11px] text-text-muted" dir="ltr">
          {(ar ? 'تنبيه عند: ' : 'Warn at: ') + limit.thresholds.map((t) => `${t}%`).join(' · ')}
        </p>
      )}
    </div>
  )
}

/**
 * VIZ-BUDGET-001 — «which of these is running hot», ranked against the plan.
 *
 * Pace is `consumed ÷ expected-by-now`: not how much of the limit is gone — that is `utilisation` —
 * but whether the spend is ahead of the clock, against the ELAPSED share of the limit. A workspace
 * with nine limits is nine cards to scan and nine figures to compare in your head against 1.0; the
 * line is drawn once here and every limit ranked against it, worst first.
 *
 * ## Why these may share one chart across currencies
 *
 * The ratio carries no unit. A limit set in riyals pacing at 1.3 and one set in dollars pacing at 1.3
 * are running hot by the same proportion, and comparing them breaks no money rule because there is no
 * money in the comparison — the same reasoning the client budget pace chart records.
 *
 * ## What it withholds
 *
 * A limit whose spend could not be compared carries `pace: null` — withheld, partial, or recorded in
 * another currency. There is no zero to draw: «pacing at 0» means «spending nothing», which is a claim
 * about a limit whose spend we could not read at all, and it would rank the limits we know least about
 * as the best-behaved ones. They are withheld and counted beneath the chart.
 */
const WHY_NOT: Record<string, { ar: string; en: string }> = {
  not_implemented: { ar: 'هذه المنصة لا تتيح الإيقاف من هنا', en: 'This platform cannot be paused from here' },
  awaiting_credentials: { ar: 'بانتظار بيانات اعتماد المنصة', en: 'Waiting for the platform credentials' },
  no_permission: { ar: 'لا تملك صلاحية الإيقاف', en: 'You may not pause campaigns' },
  execution_not_enabled_for_provider: { ar: 'التنفيذ غير مفعّل لهذه المنصة', en: 'Execution is not enabled for this platform' },
  already_paused: { ar: 'متوقفة أصلًا', en: 'Already paused' },
}

/**
 * BUDGET-LIMITS-ACTIONS-001 — what may be ASKED for when a campaign runs past its limit. A pause request
 * waits in Approvals for someone else; this page never changes a platform itself, and each platform that
 * cannot be asked says why.
 */
function LimitActions({ limit, ar, locale, projectId }: { limit: SpendLimitReading; ar: boolean; locale: 'ar' | 'en'; projectId: string | null }) {
  const portalTo = usePortalPath()
  const qc = useQueryClient()
  const ask = useMutation({
    mutationFn: (a: LimitAction) => proposeChange(projectId!, {
      provider: a.provider, external_account_id: a.external_account_id, target_type: 'campaign', target_external_id: a.target_external_id, action: 'pause',
      reason: ar
        ? `تجاوز حد الإنفاق: ${limit.consumed ?? '—'} من ${withMark(String(limit.amount), limit.currency)} للفترة ${limit.period.from} → ${limit.period.to}.`
        : `Spend limit ${limit.state === 'over' ? 'passed' : 'approaching'}: ${limit.consumed ?? '—'} of ${withMark(String(limit.amount), limit.currency)} for ${limit.period.from} → ${limit.period.to}.`,
    }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['agent-proposals'] }),
  })

  return (
    <div data-testid={`spend-limit-${limit.id}-actions`} className="mt-3 rounded-xl border border-border/70 p-2.5">
      <p className="text-xs font-semibold text-text-primary">{ar ? 'طلب إيقاف (يحتاج موافقة شخص آخر)' : 'Ask for a pause (another person approves)'}</p>
      <ul className="mt-1.5 space-y-1">
        {(limit.actions ?? []).map((a) => (
          <li key={`${a.provider}-${a.target_external_id}`} className="flex flex-wrap items-center justify-between gap-2 text-xs" data-testid={`limit-action-${a.provider}`}>
            <span className="min-w-0 truncate text-text-secondary">{providerLabel(a.provider, locale)} · {a.name ?? a.target_external_id}</span>
            {a.can_propose ? (
              <Button size="sm" variant="secondary" disabled={ask.isPending || ask.isSuccess} onClick={() => ask.mutate(a)} data-testid={`limit-action-${a.provider}-ask`}>
                {ar ? 'طلب إيقاف' : 'Ask to pause'}
              </Button>
            ) : (
              <span className="text-text-muted" data-testid={`limit-action-${a.provider}-why`}>{a.why_not && WHY_NOT[a.why_not] ? (ar ? WHY_NOT[a.why_not].ar : WHY_NOT[a.why_not].en) : a.why_not}</span>
            )}
          </li>
        ))}
      </ul>
      {ask.isSuccess && (
        <p className="mt-1.5 text-xs text-success" data-testid={`spend-limit-${limit.id}-asked`}>
          {ar ? 'أُرسل الطلب إلى ' : 'Sent to '}
          <Link to={portalTo('/agents/approvals')} className="font-semibold underline">{ar ? 'طلبات الموافقة' : 'Approvals'}</Link>
        </p>
      )}
      {ask.isError && <p role="alert" className="mt-1.5 text-xs text-danger">{toApiError(ask.error).message}</p>}
    </div>
  )
}

/** How many limits the pace chart draws: the hottest. The list tab holds them all. */
const PACE_ROWS = 5

function LimitPace({ limits, ar, locale }: { limits: SpendLimitReading[]; ar: boolean; locale: Locale }) {
  const comparable = limits
    .filter((l) => l.pace !== null && Number.isFinite(l.pace) && (l.pace as number) >= 0)
    .map((l) => ({ id: l.id, label: limitName(l, ar, locale), value: l.pace as number }))
  /* The hottest few: a ranking that has to fit a screen is the top of the ranking, and the rest are in the list. */
  const drawable = [...comparable].sort((a, b) => b.value - a.value).slice(0, PACE_ROWS)
  const hidden = comparable.length - drawable.length
  const withheld = limits.length - comparable.length

  return (
    <ChartCard
      title={ar ? 'سرعة الإنفاق مقابل الخطة' : 'Spending against the plan'}
      subtitle={ar
        ? 'الإنفاق ÷ المتوقَّع حتى الآن. الخط عند 1.0 هو سرعة صرف الفترة، وما فوقه أسرع منها.'
        : 'Spent ÷ expected by now. The line at 1.0 is the period’s own pace; past it is faster than that.'}
    >
      <RatioBars
        testId="limit-pace"
        ar={ar}
        rows={drawable}
        reference={1}
        referenceLabel={ar ? 'على الخطة' : 'On plan'}
      />
      {hidden > 0 && (
        <p data-testid="limit-pace-more" className="mt-2 text-xs text-text-muted">
          {ar ? `الأعلى ${PACE_ROWS} فقط — و${hidden.toLocaleString('en-US')} أخرى في القائمة.` : `Top ${PACE_ROWS} only — ${hidden.toLocaleString('en-US')} more are in the list.`}
        </p>
      )}
      {withheld > 0 && (
        <p data-testid="limit-pace-withheld" className="mt-2 text-xs text-text-muted">
          {ar
            ? `${withheld.toLocaleString('en-US')} من الحدود بلا سرعة قابلة للمقارنة — إنفاقها جزئي أو محجوب أو بعملة أخرى.`
            : `${withheld.toLocaleString('en-US')} limit(s) have no comparable pace — their spend is partial, withheld, or recorded in another currency.`}
        </p>
      )}
    </ChartCard>
  )
}

/** The same name the limit's own card carries, so the chart and the list name one thing once. */
function limitName(limit: SpendLimitReading, ar: boolean, locale: Locale): string {
  const scope = SCOPE_LABEL[limit.scope] ?? SCOPE_LABEL.project!

  return limit.scope === 'platform' && limit.scope_id
    ? providerLabel(limit.scope_id, locale)
    : (ar ? scope.ar : scope.en)
}
