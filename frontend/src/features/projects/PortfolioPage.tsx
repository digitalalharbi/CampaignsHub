import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, ArrowLeft, ArrowRight, Layers } from 'lucide-react'
import { fetchPortfolioOverview, type PortfolioOverview } from './api'
import { projectStatusLabel } from './ProjectsPage'
import { Card } from '@/components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { Num } from '@/components/ui/Num'
import { accounts as countedAccounts, campaigns as countedCampaigns, days as countedDays } from '@/lib/counted'
import { PageIntro, DataFreshness, STALE_AFTER_HOURS } from '@/components/ui/PageIntro'
import { StatCard } from '@/components/ui/StatCard'
import { Badge } from '@/components/ui/Badge'
import { PeriodLabel, SectionHeader } from '@/components/patterns/Status'
import { CoverageMatrix, RankedBars, Sparkline, StateDistribution } from '@/components/patterns/Visuals'
import { platformColor } from '@/features/analytics/components'
import { usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'

/**
 * PORTFOLIO-SCOPE-001 §13 §14 · PORTFOLIO-VISUAL-001 §9 — «جميع المشاريع», and what it is DOING.
 *
 * ## The question this page answers
 *
 * The agency one, asked deliberately: how many clients are running, which need somebody today, and
 * what is being spent. That is a different question from the project one, and the state that must
 * not exist between them is a surface which widens to every project because nobody chose one. The
 * server refuses to produce that; this page is the other half, the deliberate choice.
 *
 * ## Why it was rebuilt
 *
 * It stated four totals and a grid of cards. «How is my portfolio doing and where should I look?»
 * cannot be answered by totals: they say how much, never what happened, where it came from, or who
 * moved it. So the head answers the four context questions and the body answers the rest in shapes
 * — a trend per currency, a ranked contribution, a health distribution, a platform matrix and an
 * attention queue that says WHY, not merely how many.
 *
 * ## Money is segmented, because it has to be
 *
 * 12,500 SAR beside 400 USD is not 12,900 of anything. Every figure, every bar and every line on
 * this page is per currency, and the payload carries no total for anything to print by accident.
 *
 * ## Nothing here is invented to fill space
 *
 * A day nobody reported is absent from the series rather than drawn as zero. A section with no data
 * says which emptiness it is instead of rendering an empty chart: on this page «no client has an
 * account connected» and «the accounts are connected and nothing has synced» send a reader to two
 * different places, and a blank panel sends them to neither.
 */
const COPY = {
  ar: {
    title: 'المشاريع',
    scope: 'جميع المشاريع',
    lead: 'نظرة عامة على الوكالة — كل مشروع تملك صلاحية الوصول إليه.',
    projects: 'المشاريع',
    active: 'نشطة',
    attention: 'تحتاج متابعة',
    campaigns: 'الحملات',
    spend: 'الإنفاق',
    notComparable: 'عملات مختلفة — تُعرض كل عملة على حدة ولا تُجمع في رقم واحد.',
    empty: 'لا مشاريع ضمن صلاحيتك.',
    noAccounts: 'لا حسابات مربوطة',
    neverSynced: 'لم تصل بيانات',
    stale: 'البيانات متأخرة',
    healthy: 'سليمة',
    manage: 'إدارة الربط',
    enter: 'فتح المشروع',
    inCurrency: 'مشروع',
    trendTitle: 'الإنفاق عبر الفترة',
    trendQuestion: 'ما الذي حدث؟ كل عملة على حدة — لا يُجمع خطّان بعملتين.',
    trendEmpty: 'لا يوجد إنفاق مسجَّل في هذه الفترة.',
    contributionTitle: 'مصدر الإنفاق',
    contributionQuestion: 'من أين جاء؟ المشاريع مرتّبة بالأكبر إنفاقًا.',
    healthTitle: 'حالة المشاريع',
    healthQuestion: 'ما الذي يحتاج انتباهًا، وكم عددها؟',
    platformsTitle: 'المنصات المربوطة',
    platformsQuestion: 'أي منصة تغذّي أي مشروع؟',
    platformsEmpty: 'لا منصة مربوطة بأي مشروع بعد.',
    queueTitle: 'قائمة المتابعة',
    queueQuestion: 'ابدأ من هنا — المشاريع التي تحتاج تدخّلًا ولماذا.',
    queueEmpty: 'لا مشروع يحتاج متابعة الآن.',
    projectsTitle: 'المشاريع',
    projectsQuestion: 'كل مشروع: عميله، منصّاته، حساباته، وحداثة بياناته.',
    accounts: 'حسابات',
    noSpend: 'لا إنفاق',
  },
  en: {
    title: 'Projects',
    scope: 'All projects',
    lead: 'The agency at a glance — every project you may reach.',
    projects: 'Projects',
    active: 'Active',
    attention: 'Need attention',
    campaigns: 'Campaigns',
    spend: 'Spend',
    notComparable: 'Different currencies — each is stated on its own and never added into one figure.',
    empty: 'No projects within your access.',
    noAccounts: 'No linked accounts',
    neverSynced: 'No data received',
    stale: 'Data is behind',
    healthy: 'Healthy',
    manage: 'Manage integrations',
    enter: 'Open project',
    inCurrency: 'projects',
    trendTitle: 'Spend over the period',
    trendQuestion: 'What happened? One series per currency — two currencies are never one line.',
    trendEmpty: 'No spend was recorded in this period.',
    contributionTitle: 'Where the spend came from',
    contributionQuestion: 'Which projects moved it, largest first.',
    healthTitle: 'Project health',
    healthQuestion: 'What needs attention, and how much of it.',
    platformsTitle: 'Platform footprint',
    platformsQuestion: 'Which platform feeds which project.',
    platformsEmpty: 'No platform feeds any project yet.',
    queueTitle: 'Attention queue',
    queueQuestion: 'Start here — the projects that need somebody, and why.',
    queueEmpty: 'Nothing needs attention right now.',
    projectsTitle: 'Projects',
    projectsQuestion: 'Each project: its client, its platforms, its accounts and how current it is.',
    accounts: 'accounts',
    noSpend: 'No spend',
  },
} as const

const ATTENTION_KEY = {
  no_accounts: 'noAccounts',
  never_synced: 'neverSynced',
  stale: 'stale',
} as const

/** Worst first — the page opens on what needs somebody. */
const ATTENTION_ORDER: Array<keyof typeof ATTENTION_KEY> = ['no_accounts', 'never_synced', 'stale']

const ATTENTION_TONE = {
  no_accounts: 'danger',
  never_synced: 'warning',
  stale: 'warning',
} as const

export function PortfolioPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const t = COPY[locale]
  const portalPath = usePortalPath()

  const overview = useQuery({
    queryKey: ['portfolio-overview'],
    queryFn: () => fetchPortfolioOverview(),
  })

  if (overview.isLoading) {
    return (
      <section className="space-y-4">
        <Skeleton className="h-10 w-64" />
        <div className="grid gap-3 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-20" />)}
        </div>
        <Skeleton className="h-48" />
      </section>
    )
  }

  if (overview.isError || !overview.data) {
    return (
      <ErrorState
        title={ar ? 'تعذّر قراءة نظرة المشاريع.' : 'Could not read the portfolio.'}
        error={overview.error}
        onRetry={() => void overview.refetch()}
        ar={ar}
      />
    )
  }

  const data: PortfolioOverview = overview.data
  const items = data.projects.items
  const active = data.projects.by_status.active ?? 0

  /*
   * Read defensively, and not out of superstition.
   *
   * These three keys are newer than the page that reads them, and during a deploy a browser holding
   * the new bundle can be answered by a box still running the old server for a few seconds. The
   * cost of guessing wrong there is a white screen on the agency's landing page; the cost of the
   * fallback is three empty sections that say so.
   */
  const trend = data.trend?.by_currency ?? []
  const contribution = data.contribution?.by_currency ?? []
  const campaigns = data.campaigns ?? { total: 0, by_project: {} }

  // The most recent successful arrival anywhere in the estate — the portfolio's own freshness.
  const freshest = items
    .map((p) => p.data_last_synced_at)
    .filter((at): at is string => at !== null)
    .sort()
    .at(-1) ?? null

  const platforms = Array.from(new Set(items.flatMap((p) => p.providers))).sort()

  const queue = items
    .filter((p) => p.attention !== null)
    .sort((a, b) => ATTENTION_ORDER.indexOf(a.attention!) - ATTENTION_ORDER.indexOf(b.attention!))

  return (
    <section className="space-y-4">
      <PageIntro
        testid="portfolio-intro"
        title={t.title}
        badges={
          <>
            <Badge tone="neutral" data-testid="portfolio-scope">
              <Layers size={12} aria-hidden /> {t.scope}
            </Badge>
            <Badge tone={data.attention.total > 0 ? 'warning' : 'success'} data-testid="portfolio-health">
              {data.attention.total > 0 ? `${t.attention}: ${data.attention.total}` : t.healthy}
            </Badge>
          </>
        }
        purpose={t.lead}
        meta={
          <>
            <PeriodLabel from={data.period.from} to={data.period.to} testId="portfolio-period" />
            <DataFreshness lastSyncAt={freshest} ar={ar} staleAfterHours={STALE_AFTER_HOURS} testid="portfolio-freshness" />
          </>
        }
        kpis={
          <>
            <StatCard
              label={t.projects}
              value={data.projects.total.toLocaleString('en-US')}
              hint={`${active.toLocaleString('en-US')} ${t.active}`}
              tone="brand"
              dot
              testid="portfolio-projects-total"
            />
            <StatCard
              label={t.attention}
              value={data.attention.total.toLocaleString('en-US')}
              tone={data.attention.total > 0 ? 'warning' : 'success'}
              dot
              testid="portfolio-attention-total"
            />
            <StatCard
              label={t.campaigns}
              value={campaigns.total.toLocaleString('en-US')}
              tone="neutral"
              dot
              testid="portfolio-campaigns-total"
            />
            {/*
              Spend is the one KPI that cannot be a single number, so it states the leading currency
              and how many others exist rather than a sum that would be a lie.
            */}
            <StatCard
              label={t.spend}
              value={
                data.spend.by_currency.length === 0
                  ? '—'
                  : `${Math.round(data.spend.by_currency[0]!.spend).toLocaleString('en-US')} ${data.spend.by_currency[0]!.currency}`
              }
              hint={data.spend.comparable ? undefined : t.notComparable}
              tone="neutral"
              dot
              testid="portfolio-spend"
            />
          </>
        }
      />

      {data.projects.total === 0 ? (
        /*
         * «You reach no projects» is a real answer and says which one it is.
         *
         * A reader whose ceiling is empty must not meet a blank page that reads as a loading
         * failure — the server distinguishes the two, and so does this.
         */
        <div data-testid="portfolio-empty">
          <EmptyState title={t.empty} />
        </div>
      ) : (
        <>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <SectionHeader title={t.trendTitle} question={t.trendQuestion} testId="portfolio-trend-header" />
              {trend.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-text-secondary" data-testid="portfolio-trend-empty">
                  {t.trendEmpty}
                </p>
              ) : (
                <div className="flex flex-col gap-4" data-testid="portfolio-trend">
                  {trend.map((series) => (
                    <CurrencyTrend key={series.currency} series={series} ar={ar} />
                  ))}
                </div>
              )}

              {/*
                Every currency, each on its own, and the caveat when there is more than one.

                The head leads with one because a KPI is a single figure; the estate is not, and a
                reader who sees «12,500 SAR» up there has to be able to find the 400 USD that is not
                in it. There is no total to draw — the payload has no such key, which is a stronger
                guarantee than a rule about not printing one.
              */}
              {data.spend.by_currency.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-t border-border pt-3" data-testid="portfolio-spend-breakdown">
                  {data.spend.by_currency.map((row) => (
                    <span key={row.currency} data-testid={`portfolio-spend-${row.currency}`} className="tnum text-xs">
                      <span className="font-extrabold text-text-primary">{row.spend.toLocaleString('en-US')}</span>{' '}
                      <span className="text-text-secondary">{row.currency}</span>
                      <span className="ms-1.5 text-text-muted">· {row.projects.toLocaleString('en-US')} {t.inCurrency}</span>
                    </span>
                  ))}
                </div>
              )}

              {!data.spend.comparable && (
                <p data-testid="portfolio-not-comparable" className="mt-2 text-[11px] text-text-muted">
                  {t.notComparable}
                </p>
              )}
            </Card>

            <Card>
              <SectionHeader title={t.contributionTitle} question={t.contributionQuestion} testId="portfolio-contribution-header" />
              {contribution.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-text-secondary" data-testid="portfolio-contribution-empty">
                  {t.trendEmpty}
                </p>
              ) : (
                <div className="flex flex-col gap-4" data-testid="portfolio-contribution">
                  {contribution.map((group) => (
                    <div key={group.currency} className="flex flex-col gap-2">
                      <p className="text-[11px] font-bold uppercase tracking-wide text-text-muted">{group.currency}</p>
                      <RankedBars
                        testId={`portfolio-contribution-${group.currency}`}
                        rows={group.projects.slice(0, 6).map((p) => ({
                          id: p.id,
                          label: p.name,
                          value: p.spend,
                        }))}
                        format={(v) => `${Math.round(v).toLocaleString('en-US')} ${group.currency}`}
                      />
                    </div>
                  ))}
                </div>
              )}
            </Card>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <SectionHeader title={t.healthTitle} question={t.healthQuestion} testId="portfolio-health-header" />
              <StateDistribution
                testId="portfolio-health-distribution"
                segments={[
                  {
                    id: 'healthy',
                    label: t.healthy,
                    count: data.projects.total - data.attention.total,
                    color: 'var(--success)',
                  },
                  ...ATTENTION_ORDER.map((state) => ({
                    id: state,
                    label: t[ATTENTION_KEY[state]],
                    count: data.attention.by_state[state] ?? 0,
                    color: ATTENTION_TONE[state] === 'danger' ? 'var(--danger)' : 'var(--warning)',
                  })),
                ]}
                total={data.projects.total}
              />
            </Card>

            <Card>
              <SectionHeader title={t.platformsTitle} question={t.platformsQuestion} testId="portfolio-platforms-header" />
              <CoverageMatrix
                testId="portfolio-platform-matrix"
                rows={items.map((p) => ({ id: p.id, label: p.name }))}
                columns={platforms.map((provider) => ({
                  id: provider,
                  label: provider,
                  color: platformColor(provider),
                }))}
                has={(projectId, provider) =>
                  items.find((p) => p.id === projectId)?.providers.includes(provider) ?? false}
                empty={
                  <p className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-text-secondary" data-testid="portfolio-platforms-empty">
                    {t.platformsEmpty}
                  </p>
                }
              />
            </Card>
          </div>

          <Card>
            <SectionHeader title={t.queueTitle} question={t.queueQuestion} testId="portfolio-queue-header" />
            {queue.length === 0 ? (
              <p className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-text-secondary" data-testid="portfolio-queue-empty">
                {t.queueEmpty}
              </p>
            ) : (
              <ul className="flex flex-col divide-y divide-border" data-testid="portfolio-queue">
                {queue.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center gap-3 py-2" data-testid={`portfolio-queue-${p.id}`}>
                    <span className="min-w-0 flex-1">
                      {/*
                        Wraps rather than truncates. Measured at 390px: three projects called
                        «Q3 Launch — Demo» all clipped to «Q3 Launch — De…», which made the queue
                        a list of identical rows — the one thing a name must never become.
                      */}
                      <span className="block break-words text-sm font-bold text-text-primary">{p.name}</span>
                      {p.client_name !== null && (
                        <span className="block break-words text-xs text-text-secondary">{p.client_name}</span>
                      )}
                    </span>

                    {/* WHY, not merely that it needs something — the two need different acts. */}
                    <Badge tone={ATTENTION_TONE[p.attention!]}>
                      <AlertTriangle size={11} aria-hidden /> {t[ATTENTION_KEY[p.attention!]]}
                    </Badge>

                    <Link
                      to={portalPath(`/projects/${p.id}/integrations`)}
                      className="shrink-0 text-xs font-bold text-brand-600 hover:underline"
                    >
                      {t.manage} {ar ? <ArrowLeft size={12} className="inline" /> : <ArrowRight size={12} className="inline" />}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <SectionHeader title={t.projectsTitle} question={t.projectsQuestion} testId="portfolio-projects-header" />
            <ul className="flex flex-col divide-y divide-border">
              {items.map((p) => (
                <li
                  key={p.id}
                  data-testid={`portfolio-project-${p.id}`}
                  /*
                    `sm:flex-wrap` — six cells in one row from 640px up, and the sweep measured the
                    projects list pushing the page 95px sideways at 768px in English, where «Needs
                    attention», «synced 2h ago» and «Open →» are longer than their Arabic. The row may
                    wrap; the document may not scroll (Owner directive 2026-10-09 §6).
                  */
                  className="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
                >
                  <span className="min-w-0 flex-1">
                    <span className="block break-words text-sm font-bold text-text-primary">{p.name}</span>
                    {p.client_name !== null && (
                      <span
                        data-testid={`portfolio-project-client-${p.id}`}
                        className="block break-words text-xs text-text-secondary"
                      >
                        {p.client_name}
                      </span>
                    )}
                  </span>

                  {p.providers.length > 0 && (
                    <span data-testid={`portfolio-project-providers-${p.id}`} className="flex flex-wrap items-center gap-1">
                      {p.providers.map((provider) => (
                        <span
                          key={provider}
                          className="inline-flex items-center gap-1 rounded-[var(--radius-pill)] bg-surface-secondary px-1.5 py-0.5 text-[11px] font-semibold text-text-secondary"
                        >
                          <span
                            className="h-1.5 w-1.5 rounded-full"
                            style={{ background: platformColor(provider) }}
                            aria-hidden
                          />
                          {provider}
                        </span>
                      ))}
                    </span>
                  )}

                  {/*
                    Counted through the shared rule, not written beside the number.
                    «1 accounts» and «1 حسابًا» were on the same screen once; the rule is what stops
                    the next one, and a portfolio row is exactly where numbers meet nouns.
                  */}
                  <span className="tnum text-xs text-text-secondary">
                    {countedAccounts(p.accounts, locale)}
                  </span>

                  <span className="tnum text-xs text-text-secondary">
                    {countedCampaigns(campaigns.by_project[p.id] ?? 0, locale)}
                  </span>

                  <span className="text-xs text-text-muted">{projectStatusLabel(p.status, ar)}</span>

                  {/* What it needs, on the row that decides where you go — not only in the queue. */}
                  {p.attention !== null && (
                    <Badge tone={ATTENTION_TONE[p.attention]} data-testid={`portfolio-project-attention-${p.id}`}>
                      {t[ATTENTION_KEY[p.attention]]}
                    </Badge>
                  )}

                  <span className="text-xs text-text-muted">
                    <DataFreshness
                      lastSyncAt={p.data_last_synced_at}
                      ar={ar}
                      staleAfterHours={STALE_AFTER_HOURS}
                      testid={`portfolio-project-freshness-${p.id}`}
                    />
                  </span>

                  <Link
                    to={portalPath(`/projects/${p.id}/integrations`)}
                    className="shrink-0 text-xs font-bold text-brand-600 hover:underline"
                  >
                    {t.enter} {ar ? <ArrowLeft size={12} className="inline" /> : <ArrowRight size={12} className="inline" />}
                  </Link>
                </li>
              ))}
            </ul>
          </Card>
        </>
      )}
    </section>
  )
}

/**
 * One currency's spend over the window, as a shape and as its own scale.
 *
 * Its own scale deliberately: two currencies on shared axes would make the larger number look like
 * the larger business, which is exactly the comparison this product refuses to imply.
 */
function CurrencyTrend({ series, ar }: {
  series: PortfolioOverview['trend']['by_currency'][number]
  ar: boolean
}) {
  const total = series.points.reduce((sum, point) => sum + point.spend, 0)

  return (
    <div className="flex items-center gap-3" data-testid={`portfolio-trend-${series.currency}`}>
      <div className="min-w-0 flex-1">
        <p className="text-[11px] font-bold uppercase tracking-wide text-text-muted">{series.currency}</p>
        <p className="tnum text-lg font-extrabold leading-tight text-text-primary">
          <Num>{Math.round(total).toLocaleString('en-US')}</Num>
        </p>
        <p className="text-[11px] text-text-muted">
          {/*
            Counted through the shared rule: «1 يوم», «2 يومان», «3 أيام», «11 يومًا» are four
            different words for the same noun, and a call site that types one gets the rest wrong.
          */}
          {ar
            ? `${countedDays(series.points.length, 'ar')} فيها إنفاق`
            : `${countedDays(series.points.length, 'en')} with spend`}
        </p>
      </div>

      <Sparkline
        testId={`portfolio-sparkline-${series.currency}`}
        points={series.points.map((point) => ({ date: point.date, value: point.spend }))}
        width={160}
        height={40}
      />
    </div>
  )
}
