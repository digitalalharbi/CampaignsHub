import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, FolderKanban, Layers } from 'lucide-react'
import { fetchPortfolioOverview, type PortfolioOverview } from './api'
import { projectStatusLabel } from './ProjectsPage'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'

/**
 * PORTFOLIO-SCOPE-001 §13 §14 — «جميع المشاريع», and never mistakable for one project.
 *
 * ## The question this page answers
 *
 * The agency one, asked deliberately: how many clients are running, which need somebody today, and
 * what is being spent. That is a different question from the project one, and the state that must
 * not exist between them is a surface which widens to every project because nobody chose one. The
 * server already refuses to produce that — this page is the other half, the deliberate choice.
 *
 * ## The scope says itself
 *
 * A figure on a screen that does not name its scope is a figure the reader attributes to whatever
 * they had in mind, which on a page like this is usually one client. So the scope is drawn, not
 * implied, and the payload carries `scope: portfolio` so the heading cannot drift from the data.
 *
 * ## Money is per currency, because it has to be
 *
 * 12,500 SAR beside 400 USD is not 12,900 of anything. The server states each currency with the
 * number of projects reporting in it and offers no total, so there is nothing here to print by
 * accident — and where the estate is genuinely mixed the page says plainly that the figures are not
 * addable, rather than letting a reader assume the first one is the headline.
 */
const COPY = {
  ar: {
    scope: 'جميع المشاريع',
    lead: 'نظرة عامة على الوكالة — كل مشروع تملك صلاحية الوصول إليه.',
    projects: 'المشاريع',
    attention: 'تحتاج متابعة',
    spend: 'الإنفاق',
    notComparable: 'عملات مختلفة — تُعرض كل عملة على حدة ولا تُجمع في رقم واحد.',
    empty: 'لا مشاريع ضمن صلاحيتك.',
    noAccounts: 'لا حسابات مربوطة',
    neverSynced: 'لم تصل بيانات',
    stale: 'البيانات متأخرة',
    manage: 'إدارة الربط',
    inCurrency: 'مشروع',
  },
  en: {
    scope: 'All projects',
    lead: 'The agency at a glance — every project you may reach.',
    projects: 'Projects',
    attention: 'Need attention',
    spend: 'Spend',
    notComparable: 'Different currencies — each is stated on its own and never added into one figure.',
    empty: 'No projects within your access.',
    noAccounts: 'No linked accounts',
    neverSynced: 'No data received',
    stale: 'Data is behind',
    manage: 'Manage integrations',
    inCurrency: 'projects',
  },
} as const

const ATTENTION_KEY = {
  no_accounts: 'noAccounts',
  never_synced: 'neverSynced',
  stale: 'stale',
} as const

export function PortfolioPage() {
  const locale = useUi((s) => s.locale)
  const t = COPY[locale]
  const portalPath = usePortalPath()

  const overview = useQuery({
    queryKey: ['portfolio-overview'],
    queryFn: () => fetchPortfolioOverview(),
  })

  if (overview.isLoading) {
    return (
      <section className="space-y-5">
        <Skeleton className="h-10 w-64" />
        <div className="grid gap-3 sm:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-24" />)}
        </div>
      </section>
    )
  }

  if (overview.isError || !overview.data) {
    return (
      <ErrorState
        title={locale === 'ar' ? 'تعذّر قراءة نظرة المشاريع.' : 'Could not read the portfolio.'}
        error={overview.error}
        onRetry={() => void overview.refetch()}
        ar={locale === 'ar'}
      />
    )
  }

  const data: PortfolioOverview = overview.data

  return (
    <section className="space-y-5">
      <header>
        <h1 data-testid="portfolio-scope" className="flex items-center gap-2 font-[var(--font-heading)] text-xl font-extrabold">
          <Layers size={20} className="text-brand-600" aria-hidden />
          {t.scope}
        </h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">{t.lead}</p>
      </header>

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
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard
              label={t.projects}
              value={<span data-testid="portfolio-projects-total">{data.projects.total.toLocaleString('en-US')}</span>}
              tone="brand"
              dot
            />
            <StatCard
              label={t.attention}
              value={<span data-testid="portfolio-attention-total">{data.attention.total.toLocaleString('en-US')}</span>}
              tone={data.attention.total > 0 ? 'warning' : 'success'}
              dot
            />
          </div>

          {/*
            Spend, one row per currency. There is no total to draw — the payload has no such key,
            which is a stronger guarantee than a rule saying not to print one.
          */}
          <Card>
            <h2 className="text-sm font-bold text-text-primary">{t.spend}</h2>
            <div data-testid="portfolio-spend" className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
              {data.spend.by_currency.map((row) => (
                <span key={row.currency} data-testid={`portfolio-spend-${row.currency}`} className="tnum">
                  <span className="text-lg font-extrabold text-text-primary">
                    {row.spend.toLocaleString('en-US')}
                  </span>{' '}
                  <span className="text-sm text-text-secondary">{row.currency}</span>
                  <span className="ms-2 text-xs text-text-muted">
                    · {row.projects.toLocaleString('en-US')} {t.inCurrency}
                  </span>
                </span>
              ))}
            </div>
            {!data.spend.comparable && (
              <p data-testid="portfolio-not-comparable" className="mt-2 text-xs text-text-muted">
                {t.notComparable}
              </p>
            )}
          </Card>

          <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
            {data.projects.items.map((p) => (
              <Card key={p.id} data-testid={`portfolio-project-${p.id}`}>
                <div className="flex items-start justify-between gap-2">
                  <span className="flex items-center gap-2 text-sm font-bold">
                    <FolderKanban size={16} className="text-brand-600" aria-hidden />
                    {p.name}
                  </span>
                  <span className="text-xs text-text-muted">{projectStatusLabel(p.status, locale === 'ar')}</span>
                </div>

                {p.attention && (
                  <p className="mt-2 inline-flex items-center gap-1 rounded-lg border border-warning bg-warning-soft px-2 py-1 text-[11px] font-semibold text-warning">
                    <AlertTriangle size={12} aria-hidden />
                    {t[ATTENTION_KEY[p.attention]]}
                  </p>
                )}

                <div className="mt-3 border-t border-border pt-2 text-xs">
                  {/* Into the PROJECT's own scope — the portfolio is where you decide which one. */}
                  <Link to={portalPath(`/projects/${p.id}/integrations`)} className="font-bold text-brand-600 hover:underline">
                    {t.manage} →
                  </Link>
                </div>
              </Card>
            ))}
          </div>
        </>
      )}
    </section>
  )
}
