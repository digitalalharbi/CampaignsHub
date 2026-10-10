import { useState } from 'react'
import { Link } from 'react-router-dom'
import { StatCard, StatGrid, type StatTone } from '@/components/ui/StatCard'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Building2, FolderKanban, Inbox, Megaphone, ShieldCheck } from 'lucide-react'
import { fetchAgencyDashboard, fetchClientBudgets, type AgencyDashboard, type ClientBudgetRow } from './api'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { money, ratio } from '@/features/analytics/format'
import { ChartCard, RankingBarChart, RatioBars, StatusMixBar } from '@/features/analytics/charts'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Badge } from '@/components/ui/Badge'
import { PageIntro } from '@/components/ui/PageIntro'
import { useUi } from '@/stores/ui'
import { clients as countedClients } from '@/lib/counted'
import { CreativePulseSection } from '@/features/content/CreativePulseSection'
import { AgencyAlerts, AgencyHeadline, AgencyTrendAndPlatforms } from './AgencyHeadline'
import type { LibraryQuery } from '@/features/content/api'

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
 * UX-KPI-PRESENTATION-001 — the agency dashboard's figures, on the product's own card.
 *
 * This drew its own: a rounded card, a tinted icon square, a 3xl tabular figure, a label under it.
 * The composition was fine and that was never the problem — the problem is that it was a SECOND
 * opinion about the label size, the value size, the padding and the height, on a page a reader
 * reaches from the same rail as every surface that uses the shared one.
 *
 * What stays local is what is genuinely this page's: which icon names the figure, which tone it
 * carries, and where pressing it goes. The card owns the type.
 */
function Metric({
  to,
  label,
  value,
  hint,
  icon: Icon,
  tone,
}: {
  to: string
  label: string
  value: number
  hint?: string
  icon: typeof Building2
  tone: StatTone
}) {
  const tones: Record<string, string> = {
    brand: 'bg-brand-primary-soft text-brand-700',
    success: 'bg-success/15 text-success',
    warning: 'bg-warning/15 text-warning',
    info: 'bg-info/15 text-info',
  }

  return (
    <Link to={to} className="block transition-colors [&>*]:hover:border-brand-400">
      <StatCard
        tone={tone}
        label={
          <span className="flex items-center gap-2">
            <span className={`flex h-7 w-7 items-center justify-center rounded-lg ${tones[tone] ?? ''}`}>
              <Icon size={15} aria-hidden />
            </span>
            {label}
          </span>
        }
        value={<span dir="ltr">{num(value)}</span>}
        hint={hint}
      />
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
            height={Math.max(180, entries.length * 44)}
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
      title={ar ? 'حالة محفظة العملاء' : 'The shape of the client book'}
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

export function AgencyDashboardPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['agency', 'dashboard'], queryFn: fetchAgencyDashboard })
  /*
    BUDGET-GOVERNANCE-001 — the CLIENT rung, on the page an agency opens first.

    This dashboard carried counts of clients, projects and campaigns and no money at all, so «which
    client is overspending» could not be asked anywhere in the product. Its own query, because a
    budget failing must not take the counts down with it.
  */
  const budgets = useQuery({ queryKey: ['agency', 'client-budgets'], queryFn: fetchClientBudgets, retry: false })

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

  return (
    <div className="w-full">
      {/*
        PRODUCT-VISUAL-001 §4 §34 — the same head as every other surface, and not a hero.

        This opened with a 3xl heading and a sentence, which is a third of the first screen spent
        saying the name of a page the reader navigated to deliberately. The heading is now the
        product's standard size, and the space goes to the one thing the old head could not say:
        the SCOPE these figures cover, beside the title rather than in a banner below it.
      */}
      <div className="mb-4">
        <PageIntro
          testid="agency-intro"
          title={ar ? 'لوحة الوكالة' : 'Agency overview'}
          badges={
            <Badge tone={d.scope.is_restricted ? 'warning' : 'neutral'} data-testid="agency-scope">
              {d.scope.is_restricted
                ? (ar ? `${countedClients(d.scope.client_count, 'ar')} من العملاء` : countedClients(d.scope.client_count, 'en'))
                : (ar ? 'كل العملاء' : 'All clients')}
            </Badge>
          }
          purpose={ar
            ? 'كل رقم هنا محسوب على العملاء الذين تصل إليهم فعليًا — لا أكثر.'
            : 'Every figure here covers the clients you can actually reach — and no others.'}
        />
      </div>

      {/*
        The boundary, stated before the numbers — but only where it IS one.

        An unrestricted reader was shown a banner saying the figures cover the whole agency, which
        is what an unqualified figure already means: a line of reassurance that costs a row on every
        load and tells nobody anything. A RESTRICTED membership is different — a subset read as the
        whole agency is the misreading this banner exists to prevent — so that one stays, and the
        chip in the head carries the fact in both cases.
      */}
      <div
        data-testid="agency-scope-banner"
        hidden={!d.scope.is_restricted}
        className={`mb-4 flex items-start gap-2.5 rounded-xl border px-4 py-3 text-sm ${
          d.scope.is_restricted
            ? 'border-info/30 bg-info/10 text-text-primary'
            : 'hidden'
        }`}
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

      {/* DASHBOARD-FIRST-SCREEN-001 — money, results, cost, movement, platforms, freshness and alerts come first. */}
      <div className="mb-4">
        <AgencyHeadline ar={ar} />
      </div>

      {/*
        Owner directive 2026-10-09 §11, and the standing feature-first rule: the Dashboard's one
        question is «what needs attention now?», so the answer comes before anything that merely
        describes the estate. The attention block and the client pace — the operational signals —
        open the page; the client-mix bar, the objective chart and the creative section follow.
        The page used to draw two charts first and put the attention block at the fold, with the
        money signals three thousand pixels down behind the creative section.
      */}
      <div className="grid gap-4 lg:grid-cols-2">
        <section data-testid="agency-attention" className="rounded-2xl border border-border bg-surface p-5">
          <h2 className="font-heading text-lg font-extrabold text-text-primary">
            {ar ? 'ما يحتاج انتباهك' : 'Needs your attention'}
          </h2>
          <ul className="mt-4 space-y-2.5 text-sm">
            {/*
              DASHBOARD-DRILLDOWN-001 — each row lands on exactly what it counts, not on the surface
              that happens to contain it: the count is a question, and the page it opens is the answer.
            */}
            <AttentionRow
              to="/agency/clients?status=needs_attention"
              label={ar ? 'عملاء يحتاجون متابعة' : 'Clients needing attention'}
              value={d.clients.needs_attention}
              ar={ar}
            />
            <AttentionRow
              to="/agency/clients?status=onboarding"
              label={ar ? 'عملاء قيد التهيئة' : 'Clients onboarding'}
              value={d.clients.onboarding}
              ar={ar}
            />
            <AttentionRow
              to="/agency/requests?status=client_review"
              label={ar ? 'طلبات بانتظار رد العميل' : 'Requests awaiting the client'}
              value={d.requests.awaiting_client}
              ar={ar}
            />
            <PausedCampaignsRow campaigns={d.campaigns} ar={ar} />
          </ul>
        </section>
        <AgencyAlerts ar={ar} />
      </div>
      <div className="mt-4">
        <AgencyTrendAndPlatforms ar={ar} />
      </div>
      <div className="mt-4">
        <ClientPace rows={budgets.data ?? []} ar={ar} />
      </div>
      {/* Owner directive 2026-10-09 §8 — the shared grid, so these spark-less cards reserve no sparkline row. */}
      <div className="mb-4 mt-4">
      <StatGrid columns="grid-cols-2 lg:grid-cols-4">
        <Metric
          to="/agency/clients"
          label={ar ? 'العملاء' : 'Clients'}
          value={d.clients.total}
          hint={ar ? `${num(d.clients.active)} نشط` : `${num(d.clients.active)} active`}
          icon={Building2}
          tone="brand"
        />
        <Metric
          to="/agency/projects"
          label={ar ? 'المشاريع' : 'Projects'}
          value={d.projects.total}
          hint={ar ? `${num(d.projects.active)} نشط` : `${num(d.projects.active)} active`}
          icon={FolderKanban}
          tone="info"
        />
        <Metric
          to="/agency/campaigns"
          label={ar ? 'الحملات' : 'Campaigns'}
          value={d.campaigns.total}
          hint={ar
            ? `${num(d.campaigns.active)} نشطة · ${num(d.campaigns.paused)} موقوفة`
            : `${num(d.campaigns.active)} active · ${num(d.campaigns.paused)} paused`}
          icon={Megaphone}
          tone="success"
        />
        <Metric
          to="/agency/requests"
          label={ar ? 'طلبات مفتوحة' : 'Open requests'}
          value={d.requests.open}
          hint={ar
            ? `${num(d.requests.awaiting_client)} بانتظار العميل`
            : `${num(d.requests.awaiting_client)} awaiting the client`}
          icon={Inbox}
          tone="warning"
        />
      </StatGrid>
      </div>
      <ClientBudgets rows={budgets.data ?? []} loading={budgets.isLoading} failed={budgets.isError} ar={ar} />
      {/*
        The shape of the book: full width, because a composition bar is one row tall and reads worse
        the narrower it gets — a segment holding 4% of 24 clients is a sliver at 340px.
      */}
      <div className="mt-4 mb-4">
        <ClientMix clients={d.clients} ar={ar} />
      </div>
      <ObjectiveBreakdown data={d.campaigns} ar={ar} />
      {/*
        §15.11 — the creative section, over the clients this operator actually reaches.

        This page carries no filters of its own, so the section renders its own: period, client,
        project, platform, objective and creative type. Every one of them narrows inside the
        membership ceiling the banner above describes — a control here cannot widen what the scope
        already decided, and the same options populate it that populate the library's filter bar.
      */}
      <div className="mt-4">
        <CreativePulseSection
          libraryPath="/agency/content"
          axes={['period', 'clients', 'projects', 'providers', 'objectives', 'kinds']}
          filters={AGENCY_WINDOW}
        />
      </div>

    </div>
  )
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
        className="flex items-center justify-between gap-3 rounded-xl border border-border px-3.5 py-3 transition-colors hover:border-brand-400"
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
            {projects.map((p) => (
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
function ClientPace({ rows, ar }: { rows: ClientBudgetRow[]; ar: boolean }) {
  if (rows.length === 0) return null

  const drawable = rows
    .filter((r) => r.pace !== null && Number.isFinite(r.pace) && (r.pace as number) >= 0 && r.currencies <= 1)
    .map((r) => ({ id: r.client_id, label: r.client_name, value: r.pace as number }))
  const withheld = rows.length - drawable.length
  /* Owner directive 2026-10-09 §19 — an unmeasured window is named as such, not folded into «no budget». */
  const unmeasured = rows.filter((r) => (r.unmeasured ?? 0) > 0 && r.pace === null).length
  const otherWithheld = withheld - unmeasured

  return (
    <section className="mt-6">
      <ChartCard
        title={ar ? 'سرعة إنفاق كل عميل' : 'How fast each client is spending'}
        subtitle={ar
          ? 'المتوقع ÷ الميزانية. الخط عند 1.0 هو حدّ الميزانية، وما فوقه تجاوز.'
          : 'Forecast ÷ budget. The line at 1.0 is the budget; anything past it overruns.'}
      >
        <RatioBars
          testId="client-pace"
          ar={ar}
          rows={drawable}
          reference={1}
          referenceLabel={ar ? 'على الميزانية' : 'On budget'}
        />
        {unmeasured > 0 && (
          <p data-testid="client-pace-unmeasured" className="mt-3 text-sm text-text-muted">
            {ar
              ? `${num(unmeasured)} من العملاء بلا أرقام مقاسة في هذه الفترة — لم تُقرأ أي بيانات من مصادرهم، فلا سرعة تُحسب.`
              : `${num(unmeasured)} client(s) have no measured figures in this period — nothing was read from their sources, so no pace is stated.`}
          </p>
        )}
        {otherWithheld > 0 && (
          <p data-testid="client-pace-withheld" className="mt-3 text-sm text-text-muted">
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

  const dash = '—'

  if (loading || failed || rows.length === 0) return null

  /* Resolved from the rows each render, so a refetch that drops a client closes its panel with it. */
  const open = rows.find((r) => r.client_id === openClient) ?? null

  return (
    <section className="mt-6" data-testid="client-budgets">
      <h2 className="mb-2 font-heading text-lg font-bold text-text-primary">
        {ar ? 'ميزانيات العملاء' : 'Client budgets'}
      </h2>
      <MetricTable
        head={ar
          ? ['العميل', 'الميزانية', 'المصروف', 'المتبقي', 'المتوقع', 'السرعة']
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
      {open && (
        <div className="mt-3" data-testid="project-budgets">
          <h3 className="mb-2 text-sm font-semibold text-text-primary">
            {ar ? `مشاريع ${open.client_name}` : `Projects in ${open.client_name}`}
          </h3>
          <MetricTable
            head={ar
              ? ['المشروع', 'الميزانية', 'المصروف', 'المتبقي', 'المتوقع', 'السرعة']
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
    </section>
  )
}
