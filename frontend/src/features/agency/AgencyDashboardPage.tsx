import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, BarChart3, Bell, Building2, FolderKanban, Inbox, Megaphone, PieChart, ShieldCheck, Sparkles, TrendingUp, Wallet } from 'lucide-react'
import { fetchAgencyDashboard, fetchClientBudgets, type AgencyDashboard, type ClientBudgetRow } from './api'
import { ClientCards } from '@/features/overview/ClientCards'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { money, ratio } from '@/features/analytics/format'
import { ChartCard, RankingBarChart, RatioBars, StatusMixBar } from '@/features/analytics/charts'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Badge } from '@/components/ui/Badge'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip, type KpiTone } from '@/components/fit/KpiMini'
import { TabStrip } from '@/components/fit/TabStrip'
import { DetailPanel } from '@/components/fit/DetailPanel'
import { useFitRows } from '@/components/fit/useFitRows'
import { useTabParam } from '@/components/fit/useTabParam'
import { useUi } from '@/stores/ui'
import { clients as countedClients } from '@/lib/counted'
import { CreativePulseSection } from '@/features/content/CreativePulseSection'
import { AgencyAlerts, AgencyHeadline, AgencyPeriodBar, AgencyTrendAndPlatforms } from './AgencyHeadline'
import type { LibraryQuery } from '@/features/content/api'
import { FinancialDisclaimer } from '@/features/funding/FinancialDisclaimer'

/**
 * `/agency/dashboard` — the agency's own overview (ADR 0002, AGENCY-002).
 *
 * Every figure here is computed on the server over the SAME set of clients this operator's client
 * list returns. When their membership names specific clients, the page says so above the numbers:
 * a partial view presented as the whole agency is worse than no dashboard, because it makes every
 * figure unexplainable to the person reading it.
 *
 * Zero means zero. An agency with no campaigns sees 0, never a sample figure.
 */

const OBJECTIVE_LABELS: Record<string, { ar: string; en: string }> = {
  sales: { ar: 'مبيعات', en: 'Sales' },
  awareness: { ar: 'وعي بالعلامة', en: 'Awareness' },
  traffic: { ar: 'زيارات', en: 'Traffic' },
  leads: { ar: 'عملاء محتملون', en: 'Leads' },
  engagement: { ar: 'تفاعل', en: 'Engagement' },
  app_installs: { ar: 'تثبيت التطبيق', en: 'App installs' },
  video_views: { ar: 'مشاهدات الفيديو', en: 'Video views' },
}

/** Latin digits everywhere, per the product's standing rule — never locale-native numerals. */
const num = (n: number) => n.toLocaleString('en-US')

/**
 * AGENCY-FIT-001 — the agency dashboard's four counts, as the compact card (`KpiMini`) that opens the page it counts.
 *
 * They were the product's `StatCard` (about 110px each) in a row of their own at the BOTTOM of a page that ran to six
 * thousand pixels. They are now one strip under the title, 64px tall, and the sentence under each figure (how many are
 * active, paused, waiting) is its tooltip. What stays local is which icon names the figure, which tone it carries and
 * where pressing it goes.
 */
function Metric({
  to,
  label,
  value,
  hint,
  icon,
  tone,
  testid,
}: {
  to: string
  label: string
  value: number
  hint?: string
  icon: typeof Building2
  tone: KpiTone
  testid: string
}) {
  return (
    <Link to={to} className="block min-w-0 transition-colors [&>*]:hover:border-brand-400">
      <KpiMini testid={testid} tone={tone} icon={icon} label={label} value={num(value)} hint={hint} />
    </Link>
  )
}

/**
 * VIZ-AGENCY-001 — the objective split, drawn by the product's own chart layer.
 *
 * This hand-rolled its bars: a track div, a fill div and a percentage width, computed against the
 * largest count rather than the total. It worked, and that was never the objection — the objection is
 * that it was a SECOND opinion about what a bar chart is, on a page a reader reaches from the same
 * rail as Analytics and the reports, where the ranked bar has a tooltip, an axis, a colour sequence
 * and a shared type scale. Two bar chart implementations in one product is one too many, and the one
 * that loses is the one with no axis.
 *
 * Ranked horizontally, because the categories are NAMES of unequal length — «تثبيت التطبيق» and
 * «app_installs» both need a readable label, and a vertical chart gives a category label the width of
 * one bar and then rotates it.
 */
function ObjectiveBreakdown({ data, ar }: { data: AgencyDashboard['campaigns']; ar: boolean }) {
  const entries = Object.entries(data.by_objective ?? {}).sort((a, b) => b[1] - a[1])

  return (
    <ChartCard
      title={ar ? 'الحملات حسب الهدف' : 'Campaigns by objective'}
      subtitle={ar
        ? 'رقم واحد يخلط الأهداف لا يعني شيئًا — التوزيع هنا حسب هدف كل حملة.'
        : 'One blended number across objectives means nothing — this is the split by each campaign’s objective.'}
    >
      {entries.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-text-muted">
          {ar ? 'لا توجد حملات ضمن نطاقك بعد.' : 'No campaigns within your scope yet.'}
        </p>
      ) : (
        <div data-testid="agency-objective-chart" className="min-w-0">
          <RankingBarChart
            horizontal
            height={Math.max(160, entries.length * 34)}
            data={entries.map(([objective, count]) => ({
              label: objectiveLabel(objective, ar),
              count,
            }))}
            bars={[{ key: 'count', name: ar ? 'حملات' : 'Campaigns', kind: 'num' }]}
          />
          {/*
            The same figures as text, under the chart.
            *
            * A recharts chart is an SVG a screen reader walks as a pile of unlabelled shapes, and the
            * PDF renderer and a printed page are both places this card can end up. The list is the
            * chart's key, not a duplicate of it: it is what the reader falls back to when the drawing
            * is unavailable, which is why the labels here are the same ones the axis carries.
          */}
          <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-text-secondary">
            {entries.map(([objective, count]) => (
              <li key={objective} className="flex items-center gap-1.5">
                <span>{objectiveLabel(objective, ar)}</span>
                <span className="tnum font-bold text-text-primary" dir="ltr">{num(count)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </ChartCard>
  )
}

/**
 * An objective the platform never set is NAMED as unset.
 *
 * `by_objective` is a group-by, so a campaign with a null objective arrives under the empty key — and
 * the old list rendered that key raw, printing a bar with a blank label and leaving the reader to
 * guess whether the name failed to load. «Unspecified» is what the data says.
 */
function objectiveLabel(objective: string, ar: boolean): string {
  if (objective === '' || objective === 'null') return ar ? 'غير محدد' : 'Unspecified'
  const label = OBJECTIVE_LABELS[objective]
  return label ? (ar ? label.ar : label.en) : objective
}

/**
 * VIZ-AGENCY-001 — how much of the book is in each state, as one divided bar.
 *
 * The four client figures were four cards' worth of arithmetic: «24 total · 18 active · 3 onboarding ·
 * 2 needing attention» answers «how many» four times and «how much of my book is in trouble» never.
 *
 * The total is passed as the denominator rather than summed from the states, because the states do
 * not have to cover it — a client that is neither active, onboarding nor flagged is in a state nobody
 * named, and summing would redefine the estate as the part we have labels for. That remainder is
 * drawn and named instead.
 */
function ClientMix({ clients, ar }: { clients: AgencyDashboard['clients']; ar: boolean }) {
  return (
    <ChartCard
      title={ar ? 'حالة العملاء' : 'The shape of the client book'}
      subtitle={ar
        ? 'نسبة كل حالة من إجمالي العملاء داخل نطاقك.'
        : 'What share of the clients in your scope sits in each state.'}
    >
      <StatusMixBar
        testId="client-mix"
        ar={ar}
        label={ar ? 'العملاء' : 'Clients'}
        total={clients.total}
        residualLabel={ar ? 'بلا حالة محدّدة' : 'Other'}
        bands={[
          /* DASHBOARD-DRILLDOWN-001 — each band is the portfolio filtered to exactly its members. */
          { key: 'active', label: ar ? 'نشط' : 'Active', count: clients.active, tone: 'success', to: '/agency/clients?status=active' },
          { key: 'onboarding', label: ar ? 'قيد التهيئة' : 'Onboarding', count: clients.onboarding, tone: 'info', to: '/agency/clients?status=onboarding' },
          { key: 'attention', label: ar ? 'يحتاج متابعة' : 'Needs attention', count: clients.needs_attention, tone: 'warning', to: '/agency/clients?status=needs_attention' },
        ]}
      />
    </ChartCard>
  )
}

/**
 * `onOpenClient` is given by `OverviewDashboard` (the home page's two levels). Without it the page is exactly what it was —
 * the cards are the home's first level, not a property of this page.
 */
export function AgencyDashboardPage({ onOpenClient, extraActions }: { onOpenClient?: (row: ClientBudgetRow) => void; extraActions?: ReactNode } = {}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['agency', 'dashboard'], queryFn: fetchAgencyDashboard })
  /*
    BUDGET-GOVERNANCE-001 — the CLIENT rung, on the page an agency opens first.

    This dashboard carried counts of clients, projects and campaigns and no money at all, so «which
    client is overspending» could not be asked anywhere in the product. Its own query, because a
    budget failing must not take the counts down with it.
  */
  const budgets = useQuery({ queryKey: ['agency', 'client-budgets'], queryFn: fetchClientBudgets, retry: false })
  const [tab, setTab] = useTabParam<TabKey>(TAB_KEYS, 'attention')

  if (query.isLoading) {
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
    // AGENCY-PERMS — signed in without an agency membership (the platform admin holds no tenant),
    // this endpoint answers 403. Offering «أعد المحاولة» there sends somebody to press a button
    // that will refuse them again.
    return (
      <QueryFailure
        error={query.error}
        ar={ar}
        testId="agency-dashboard-failure"
        onRetry={() => void query.refetch()}
        fallbackTitle={ar ? 'تعذّر تحميل لوحة الوكالة.' : 'The agency overview could not be loaded.'}
      />
    )
  }

  const d = query.data
  const t = ar ? TAB_COPY.ar : TAB_COPY.en
  const rows = budgets.data ?? []

  const tabs = [
    { key: 'attention', label: t.attention, icon: Bell },
    { key: 'figures', label: t.figures, icon: BarChart3 },
    { key: 'trend', label: t.trend, icon: TrendingUp },
    ...(onOpenClient !== undefined ? [{ key: 'clients', label: t.clients, icon: Building2 }] : []),
    ...(rows.length > 0 || budgets.isLoading ? [{ key: 'budgets', label: t.budgets, icon: Wallet }] : []),
    { key: 'mix', label: t.mix, icon: PieChart },
    { key: 'creative', label: t.creative, icon: Sparkles },
  ]
  const active = tabs.some((x) => x.key === tab) ? tab : 'attention'
  const periodTab = active === 'figures' || active === 'trend'

  const scopeBanner = (
    <div
      data-testid="agency-scope-banner"
      hidden={!d.scope.is_restricted}
      className={`flex items-start gap-2.5 rounded-xl border px-3 py-2 text-sm ${d.scope.is_restricted ? 'border-info/30 bg-info/10 text-text-primary' : 'hidden'}`}
    >
      <ShieldCheck size={17} className="mt-0.5 shrink-0 text-info" aria-hidden />
      <span>
        {d.scope.is_restricted
          ? ar
            ? `عضويتك محدّدة بعملاء بعينهم. الأرقام أدناه تغطي ${countedClients(d.scope.client_count, 'ar')} فقط.`
            : `Your membership names specific clients. The figures below cover ${countedClients(d.scope.client_count, 'en')} only.`
          : ar
            ? `الأرقام أدناه تغطي كامل عملاء الوكالة (${num(d.scope.client_count)}).`
            : `The figures below cover the whole agency (${countedClients(d.scope.client_count, 'en')}).`}
      </span>
    </div>
  )

  return (
    <FitPage
      testid="agency-dashboard"
      introTestid="agency-intro"
      fit={active !== 'creative'}
      title={ar ? 'لوحة الوكالة' : 'Agency overview'}
      info={ar
        ? 'كل رقم هنا محسوب على العملاء الذين تصل إليهم فعليًا — لا أكثر.'
        : 'Every figure here covers the clients you can actually reach — and no others.'}
      actions={
        <>
        {extraActions}
        <Badge tone={d.scope.is_restricted ? 'warning' : 'neutral'} data-testid="agency-scope">
          {d.scope.is_restricted
            ? (ar ? `${countedClients(d.scope.client_count, 'ar')} من العملاء` : countedClients(d.scope.client_count, 'en'))
            : (ar ? 'كل العملاء' : 'All clients')}
        </Badge>
        </>
      }
      kpis={
        <KpiStrip testid="agency-counts">
          <Metric testid="agency-count-clients" to="/agency/clients" label={ar ? 'العملاء' : 'Clients'} value={d.clients.total}
            hint={ar ? `${num(d.clients.active)} نشط` : `${num(d.clients.active)} active`} icon={Building2} tone="brand" />
          <Metric testid="agency-count-projects" to="/agency/clients" label={ar ? 'المشاريع' : 'Projects'} value={d.projects.total}
            hint={ar ? `${num(d.projects.active)} نشط` : `${num(d.projects.active)} active`} icon={FolderKanban} tone="info" />
          <Metric testid="agency-count-campaigns" to="/agency/campaigns" label={ar ? 'الحملات' : 'Campaigns'} value={d.campaigns.total}
            hint={ar ? `${num(d.campaigns.active)} نشطة · ${num(d.campaigns.paused)} موقوفة` : `${num(d.campaigns.active)} active · ${num(d.campaigns.paused)} paused`}
            icon={Megaphone} tone="success" />
          <Metric testid="agency-count-requests" to="/agency/requests" label={ar ? 'طلبات مفتوحة' : 'Open requests'} value={d.requests.open}
            hint={ar ? `${num(d.requests.awaiting_client)} بانتظار العميل` : `${num(d.requests.awaiting_client)} awaiting the client`}
            icon={Inbox} tone="warning" />
        </KpiStrip>
      }
      toolbar={
        <div className="flex min-w-0 flex-col gap-2">
          {scopeBanner}
          <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <TabStrip testid="agency-tabs" label={ar ? 'أقسام اللوحة' : 'Overview sections'} items={tabs} active={active} onChange={(k) => setTab(k as TabKey)} />
            {periodTab && <AgencyPeriodBar ar={ar} />}
          </div>
        </div>
      }
    >
      {active === 'attention' && (
        // DASHBOARD-FIRST-SCREEN / Owner directive 2026-10-09 §11 — the dashboard's one question is «what needs attention now?»,
        // so the answer is the tab it opens on.
        <div className="grid min-h-0 flex-1 grid-cols-1 content-start gap-2.5 lg:grid-cols-2">
          <section data-testid="agency-attention" className="rounded-xl border border-border bg-surface p-3">
            <h2 className="font-heading text-base font-extrabold text-text-primary">{ar ? 'تنبيهات' : 'Needs your attention'}</h2>
            <ul className="mt-2 space-y-2 text-sm">
              {/*
                DASHBOARD-DRILLDOWN-001 — each row lands on exactly what it counts, not on the surface
                that happens to contain it: the count is a question, and the page it opens is the answer.
              */}
              <AttentionRow to="/agency/clients?status=needs_attention" label={ar ? 'عملاء يحتاجون متابعة' : 'Clients needing attention'} value={d.clients.needs_attention} ar={ar} />
              <AttentionRow to="/agency/clients?status=onboarding" label={ar ? 'عملاء قيد التهيئة' : 'Clients onboarding'} value={d.clients.onboarding} ar={ar} />
              <AttentionRow to="/agency/requests?status=client_review" label={ar ? 'طلبات بانتظار رد العميل' : 'Requests awaiting the client'} value={d.requests.awaiting_client} ar={ar} />
              <PausedCampaignsRow campaigns={d.campaigns} ar={ar} />
            </ul>
          </section>
          <AgencyAlerts ar={ar} />
        </div>
      )}

      {active === 'figures' && <AgencyHeadline ar={ar} bar={false} />}

      {active === 'trend' && <AgencyTrendAndPlatforms ar={ar} />}

      {active === 'clients' && onOpenClient !== undefined && <ClientCards ar={ar} onOpenClient={onOpenClient} fit />}

      {active === 'budgets' && (
        <div className="grid min-h-0 flex-1 grid-cols-1 gap-2.5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
          <ClientPace rows={rows} ar={ar} />
          <ClientBudgets rows={rows} loading={budgets.isLoading} failed={budgets.isError} ar={ar} />
        </div>
      )}

      {active === 'mix' && (
        <div className="grid min-h-0 flex-1 grid-cols-1 content-start gap-2.5 lg:grid-cols-2">
          <ClientMix clients={d.clients} ar={ar} />
          <ObjectiveBreakdown data={d.campaigns} ar={ar} />
        </div>
      )}

      {active === 'creative' && (
        /*
          §15.11 — the creative section, over the clients this operator actually reaches.

          This page carries no filters of its own, so the section renders its own: period, client,
          project, platform, objective and creative type. Every one of them narrows inside the
          membership ceiling the banner above describes — a control here cannot widen what the scope
          already decided, and the same options populate it that populate the library's filter bar.

          It is the one tab that lets the page flow (`fit` above): a filter bar, a poster wall and the findings
          are a document of their own and cannot be budgeted against a screen.
        */
        <CreativePulseSection
          libraryPath="/agency/content"
          axes={['period', 'clients', 'projects', 'providers', 'objectives', 'kinds']}
          filters={AGENCY_WINDOW}
        />
      )}

      <FinancialDisclaimer className="mt-auto shrink-0 pt-1" />
    </FitPage>
  )
}

const TAB_KEYS = ['attention', 'figures', 'trend', 'clients', 'budgets', 'mix', 'creative'] as const
type TabKey = (typeof TAB_KEYS)[number]

const TAB_COPY = {
  ar: { attention: 'تحتاج متابعة', figures: 'الأرقام', trend: 'الاتجاه والمنصات', clients: 'العملاء', budgets: 'الميزانيات', mix: 'التوزيع', creative: 'المحتوى' },
  en: { attention: 'Attention', figures: 'Figures', trend: 'Trend & platforms', clients: 'Clients', budgets: 'Budgets', mix: 'Mix', creative: 'Creatives' },
}

/**
 * The section's starting window, defined once outside the component.
 *
 * A fresh object literal on every render is a new query key on every render, which turns a cached
 * section into one that refetches whenever anything else on the page changes.
 */
const AGENCY_WINDOW: LibraryQuery = {}

function AttentionRow({ to, label, value, ar }: { to: string; label: string; value: number; ar: boolean }) {
  return (
    <li>
      <Link
        to={to}
        className="flex items-center justify-between gap-3 rounded-xl border border-border px-3 py-2.5 transition-colors hover:border-brand-400"
      >
        <span className="flex items-center gap-2 font-semibold text-text-primary">
          {value > 0 && <AlertTriangle size={15} className="shrink-0 text-warning" aria-hidden />}
          {label}
        </span>
        <span className="tnum shrink-0 font-bold text-text-secondary" dir="ltr">
          {value === 0 ? (ar ? 'لا شيء' : 'None') : num(value)}
        </span>
      </Link>
    </li>
  )
}


/** The Paused view of one project's campaigns table — CAMPAIGN-VIEWS-001's band, chosen on arrival. */
const pausedIn = (projectId: string) => `/agency/campaigns?project=${projectId}&view=table&lifecycle=all&band=paused`

/**
 * DASHBOARD-DRILLDOWN-001 — «paused campaigns», with the project rung the campaigns surface needs.
 *
 * The count spans every client the operator reaches; the campaigns board shows one project. So the
 * row cannot be one link that means the same thing on arrival unless the paused campaigns all sit in
 * one project — then it is exactly that project's Paused view. Across several, the row itself opens
 * the board with the Paused band already chosen (the chooser is the honest landing for «which
 * project?»), and each project is listed beneath with its own count and its own link, so the number
 * the reader clicks is the number they find.
 */
function PausedCampaignsRow({ campaigns, ar }: { campaigns: AgencyDashboard['campaigns']; ar: boolean }) {
  const label = ar ? 'حملات موقوفة' : 'Paused campaigns'
  const projects = campaigns.paused > 0 ? (campaigns.paused_by_project ?? []) : []

  if (projects.length === 1) {
    return <AttentionRow to={pausedIn(projects[0].project_id)} label={label} value={campaigns.paused} ar={ar} />
  }

  return (
    <>
      <AttentionRow to="/agency/campaigns?view=table&lifecycle=all&band=paused" label={label} value={campaigns.paused} ar={ar} />
      {projects.length > 1 && (
        <li>
          <ul data-testid="attention-paused-projects" className="ms-4 space-y-1.5 border-s border-border ps-3">
            {projects.slice(0, 3).map((p) => (
              <li key={p.project_id}>
                <Link
                  to={pausedIn(p.project_id)}
                  className="flex items-center justify-between gap-3 rounded-lg px-2 py-1 text-text-secondary transition-colors hover:bg-surface-secondary hover:text-text-primary"
                >
                  <span className="truncate">{p.project_name}</span>
                  <span className="tnum shrink-0 font-semibold" dir="ltr">{num(p.paused)}</span>
                </Link>
              </li>
            ))}
            {projects.length > 3 && (
              <li className="px-2 text-xs text-text-muted">
                <Link to="/agency/campaigns?view=table&lifecycle=all&band=paused" className="hover:text-text-primary">{ar ? `و${num(projects.length - 3)} مشاريع أخرى` : `+${num(projects.length - 3)} more projects`}</Link>
              </li>
            )}
          </ul>
        </li>
      )}
    </>
  )
}

/**
 * VIZ-AGENCY-001 — «which client is going to overrun», as a shape rather than a column.
 *
 * The budget table beneath this already carries every pace. Reading it means scanning a column of
 * «1.24 / 0.88 / 1.02 / —» and holding 1.0 in your head on every row; the chart draws that line once
 * and orders the rows against it, so the client to open first is the top bar.
 *
 * ## Why these rows may share one chart across currencies
 *
 * Pace is `projected / budget` — a dimensionless number. A client budgeted in riyals pacing at 1.2 and
 * one budgeted in dollars pacing at 1.2 are overrunning by the same proportion, and comparing them
 * breaks no money rule, because there is no money in the comparison. This is the one chart on the page
 * that is allowed to span currencies, and it is allowed because of what it plots.
 *
 * ## What it withholds
 *
 * - A client the aggregator could not give a pace (`pace: null`) — usually no committed budget at all.
 *   There is no «0» to draw: zero pace means «forecast to spend nothing», which is a claim about a
 *   client we have no budget for.
 * - A client whose OWN roll-up mixes currencies (`currencies > 1`). Its pace is a ratio whose
 *   denominator added riyals to dollars, so the ratio itself is unsound — being unitless does not
 *   rescue a figure built on a sum that was never valid.
 *
 * Both are counted in a note under the chart, never dropped silently, because a pace ranking missing
 * the client with no budget reads as «every client is accounted for».
 */
/** How many clients the pace chart draws; the budgets table beside it carries the rest, a page at a time. */
const PACE_MAX = 6

function ClientPace({ rows, ar }: { rows: ClientBudgetRow[]; ar: boolean }) {
  if (rows.length === 0) return null

  const drawable = rows
    .filter((r) => r.pace !== null && Number.isFinite(r.pace) && (r.pace as number) >= 0 && r.currencies <= 1)
    .map((r) => ({ id: r.client_id, label: r.client_name, value: r.pace as number }))
  const withheld = rows.length - drawable.length
  /* Owner directive 2026-10-09 §19 — an unmeasured window is named as such, not folded into «no budget». */
  const unmeasured = rows.filter((r) => (r.unmeasured ?? 0) > 0 && r.pace === null).length
  const otherWithheld = withheld - unmeasured

  const shown = [...drawable].sort((x, y) => y.value - x.value).slice(0, PACE_MAX)
  const hiddenByFit = drawable.length - shown.length

  return (
    <section className="min-h-0 min-w-0">
      <ChartCard
        title={ar ? 'سرعة إنفاق كل عميل' : 'How fast each client is spending'}
        subtitle={ar
          ? 'المتوقع ÷ الميزانية. الخط عند 1.0 هو حدّ الميزانية، وما فوقه تجاوز.'
          : 'Forecast ÷ budget. The line at 1.0 is the budget; anything past it overruns.'}
      >
        <RatioBars
          testId="client-pace"
          ar={ar}
          rows={shown}
          reference={1}
          referenceLabel={ar ? 'على الميزانية' : 'On budget'}
        />
        {hiddenByFit > 0 && (
          <p data-testid="client-pace-more" className="mt-2 text-xs text-text-muted">
            {ar ? `الأعلى ${num(PACE_MAX)} فقط — و${num(hiddenByFit)} آخرون في الجدول.` : `Top ${num(PACE_MAX)} only — ${num(hiddenByFit)} more are in the table.`}
          </p>
        )}
        {unmeasured > 0 && (
          <p data-testid="client-pace-unmeasured" className="mt-2 text-xs text-text-muted">
            {ar
              ? `${num(unmeasured)} من العملاء بلا أرقام مقاسة في هذه الفترة — لم تُقرأ أي بيانات من مصادرهم، فلا سرعة تُحسب.`
              : `${num(unmeasured)} client(s) have no measured figures in this period — nothing was read from their sources, so no pace is stated.`}
          </p>
        )}
        {otherWithheld > 0 && (
          <p data-testid="client-pace-withheld" className="mt-2 text-xs text-text-muted">
            {ar
              ? `${num(otherWithheld)} من العملاء بلا سرعة قابلة للمقارنة — إمّا بلا ميزانية معتمدة أو بميزانية بعملات مختلفة.`
              : `${num(otherWithheld)} client(s) have no comparable pace — either no committed budget, or a budget held in more than one currency.`}
          </p>
        )}
      </ChartCard>
    </section>
  )
}

/**
 * One client's whole budget, and whether it is going to hold.
 *
 * The refusals travel with the figures: a client whose campaigns span two currencies shows «—» and
 * says so rather than adding riyals to dollars, and `excluded` counts what was left out — a total
 * that quietly drops a campaign is worse than one that admits it.
 */
function ClientBudgets({ rows, loading, failed, ar }: { rows: ClientBudgetRow[]; loading: boolean; failed: boolean; ar: boolean }) {
  /* Declared before the early return: a hook after a conditional return is a hook that sometimes runs. */
  const [openClient, setOpenClient] = useState<string | null>(null)
  /* Whole rows that fit the card, so the table pages instead of growing the page (it is 41px a row, plus its head and pager). */
  const fit = useFitRows({ rowHeight: 58, reserve: 37 + 28 + 64, min: 3, max: 30, fallback: 10 })

  const dash = '—'

  if (loading || failed || rows.length === 0) return null

  /* Resolved from the rows each render, so a refetch that drops a client closes its panel with it. */
  const open = rows.find((r) => r.client_id === openClient) ?? null

  return (
    <section className="flex min-h-0 min-w-0 flex-col rounded-xl border border-border bg-surface p-3" data-testid="client-budgets">
      {/*
        ONE-BUDGET-001 — this table sums the campaign budgets set INSIDE the platforms over the dashboard window. It is
        informational and named for what it is; «the budget» of a client is its approved project budget (the cards above,
        /agency/budgets), which this window never changes.
      */}
      <h2 className="font-heading text-base font-bold text-text-primary">
        {ar ? 'ميزانيات الحملات في المنصات' : 'Campaign budgets in the platforms'}
      </h2>
      <p className="mb-1 text-xs text-text-muted" data-testid="client-budgets-note">
        {ar ? 'للاطلاع فقط — ميزانية المشروع المعتمدة في صفحة الميزانيات.' : 'For reference only — the approved project budget is on the budgets page.'}
      </p>
      <div ref={fit.ref as never} className="min-h-0 flex-1">
      <MetricTable
        pageSize={fit.rows}
        testId="client-budgets-table"
        head={ar
          ? ['العميل', 'الميزانية', 'الإنفاق', 'المتبقي', 'المتوقع', 'السرعة']
          : ['Client', 'Budget', 'Spent', 'Remaining', 'Forecast', 'Pace']}
        rows={rows.map((r) => [
          <div key="c" className="flex flex-col">
            {/*
              * The row that raises «which client is overspending» is where «which project» is asked.
              *
              * A client with no project holding money is plain text: a control that opens an empty
              * panel teaches the reader that the control means nothing.
              */}
            {(r.projects_breakdown ?? []).length > 0 ? (
              <button
                type="button"
                onClick={() => setOpenClient((c) => (c === r.client_id ? null : r.client_id))}
                aria-expanded={openClient === r.client_id}
                className="text-start font-semibold text-text-primary underline decoration-dotted underline-offset-4 hover:text-brand-600"
              >
                {r.client_name}
              </button>
            ) : (
              <span className="font-semibold text-text-primary">{r.client_name}</span>
            )}
            {(r.unmeasured ?? 0) > 0 && (
              <span className="text-[11px] text-text-muted" data-testid={`client-budget-unmeasured-${r.client_id}`}>
                {ar ? 'لا أرقام مقاسة في هذه الفترة' : 'No measured figures in this period'}
              </span>
            )}
            {/* No silent caps — what the total left out is said where the total is read. */}
            {r.excluded > 0 && (
              <span className="text-[11px] text-text-muted">
                {ar ? `${r.excluded} خارج الحساب` : `${r.excluded} excluded`}
              </span>
            )}
          </div>,
          <span key="b" dir="ltr">{r.budget === null ? dash : money(r.budget, r.currency ?? undefined)}</span>,
          <span key="s" dir="ltr">{r.spent === null ? dash : money(r.spent, r.currency ?? undefined)}</span>,
          <span key="r" dir="ltr">{r.remaining === null ? dash : money(r.remaining, r.currency ?? undefined)}</span>,
          <span key="f" dir="ltr">{r.projected === null ? dash : money(r.projected, r.currency ?? undefined)}</span>,
          <span key="p" dir="ltr" className={r.pace !== null && r.pace > 1 ? 'font-semibold text-danger' : undefined}>
            {r.pace === null ? dash : ratio(r.pace)}
          </span>,
        ])}
        values={rows.map((r): SortValues => [
          r.client_name,
          r.budget,
          r.spent,
          r.remaining,
          r.projected,
          r.pace,
        ])}
        initialSort={{ column: 1, dir: 'desc' }}
      />
      </div>
      {/* The project rung is a layer, not a second table under the first: the page does not grow when a client is opened. */}
      <DetailPanel open={open !== null} onClose={() => setOpenClient(null)} title={open ? (ar ? `مشاريع ${open.client_name}` : `Projects in ${open.client_name}`) : ''} closeLabel={ar ? 'إغلاق' : 'Close'} testid="project-budgets-panel">
      {open && (
        <div data-testid="project-budgets">
          <MetricTable
            head={ar
              ? ['المشروع', 'الميزانية', 'الإنفاق', 'المتبقي', 'المتوقع', 'السرعة']
              : ['Project', 'Budget', 'Spent', 'Remaining', 'Forecast', 'Pace']}
            rows={(open.projects_breakdown ?? []).map((p) => [
              <div key="n" className="flex flex-col">
                {/* DASHBOARD-DRILLDOWN-001 — the project rung opens that project's campaigns, chosen on arrival. */}
                <Link
                  to={`/agency/campaigns?project=${p.project_id}&view=table&lifecycle=all`}
                  className="text-text-primary underline decoration-dotted underline-offset-4 hover:text-brand-600"
                >
                  {p.project_name}
                </Link>
                {p.excluded > 0 && (
                  <span className="text-[11px] text-text-muted">
                    {ar ? `${p.excluded} خارج الحساب` : `${p.excluded} excluded`}
                  </span>
                )}
              </div>,
              <span key="b" dir="ltr">{p.budget === null ? dash : money(p.budget, p.currency ?? undefined)}</span>,
              <span key="s" dir="ltr">{p.spent === null ? dash : money(p.spent, p.currency ?? undefined)}</span>,
              <span key="r" dir="ltr">{p.remaining === null ? dash : money(p.remaining, p.currency ?? undefined)}</span>,
              <span key="f" dir="ltr">{p.projected === null ? dash : money(p.projected, p.currency ?? undefined)}</span>,
              <span key="p" dir="ltr" className={p.pace !== null && p.pace > 1 ? 'font-semibold text-danger' : undefined}>
                {p.pace === null ? dash : ratio(p.pace)}
              </span>,
            ])}
            values={(open.projects_breakdown ?? []).map((p): SortValues => [
              p.project_name, p.budget, p.spent, p.remaining, p.projected, p.pace,
            ])}
            initialSort={{ column: 1, dir: 'desc' }}
          />
        </div>
      )}
      </DetailPanel>
    </section>
  )
}
