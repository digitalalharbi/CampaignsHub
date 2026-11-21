import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { usePortalBase } from '@/app/portalPath'
import { FolderKanban, Megaphone, RefreshCw } from 'lucide-react'
import { listClientWorkspaces, listProjects, type Project } from './api'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { useUi } from '@/stores/ui'
import { Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'
import { Pager } from '@/components/fit/Pager'
import { useFitRows } from '@/components/fit/useFitRows'
import { useColumns } from '@/components/fit/useColumns'
import { useAuth } from '@/stores/auth'
import { useBudgets, type BudgetRow } from '@/features/funding/fundingApi'
import { ClientAvatar } from '@/features/agency/ClientScopeChip'
import { ProviderMark } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { money } from '@/features/analytics/format'

/**
 * PROJECT-FIRST-VISIT-001 — a project-scoped page with no project chosen shows the CHOICE, on the page.
 *
 * Campaigns, Analytics and Reports used to open on «اختر مشروعًا من المبدّل» and nothing else: a
 * sentence pointing at a switcher in the sidebar, which on a first visit is two selects the reader
 * has not touched. The Owner's first screen of the product was a dead end. The switcher's rule
 * stands — nothing is guessed for an operator who can reach several clients, because defaulting to
 * «whichever came first» is how someone edits the wrong client's campaign believing it is theirs —
 * but a choice is not a dead end when the choices are on the page: every reachable project, under
 * its client, with what it is (campaigns, platforms) and whether it has reported, one click away.
 *
 * One exception, and it is safe: a reader who can reach exactly ONE project has no choice to make,
 * and the page selects it for them — there is no wrong client to land on.
 */
type Row = Project & { client_name: string; client_logo: string | null; synced_at: string | null; campaigns: number | null; platforms: number; providers: string[]; budget: BudgetRow | null }

export type ProjectChooserPurpose = 'campaigns' | 'analytics' | 'reports' | 'recommendations' | 'spend-limits'

/**
 * PROJECT-CHOOSER-VISUAL-001 — Owner 2026-11-21: with «كل العملاء» chosen, every project-scoped page (campaigns, analytics,
 * reports, spend limits…) opens on this choice, and it was a list of names. Each card now carries the client's own logo,
 * the platforms' logos, the campaigns, how fresh its data is and — for a reader who holds the money key — a gauge of its
 * approved budget: spent of approved, what is left and its state. The figures are the budgets' own (`/funding`).
 *
 * A card is this tall and the grid's gap is 8px.
 */
const CARD_HEIGHT = 92
const CARD_GAP = 8

/**
 * `fit` (AGENCY-FIT-001): inside a fitted page the choices are a page of whole rows that fit the screen, with a pager for
 * the rest. Without it the list is as it always was.
 */
export function ProjectChooser({ purpose, fit: fitted = false }: { purpose: ProjectChooserPurpose; fit?: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const { setCurrentProjectId } = useProject()
  const { setCurrentClientId } = useAgencyClient()
  /*
   * `/client-workspaces` is agency-scoped and answers 403 to an advertiser. The advertiser portal
   * mounts this for one render before its switcher picks a project, and that one render fired the
   * request — a 403 in the console on every first visit (the chromium and webkit gates caught it in
   * campaigns-responsive.spec.ts). Client names are asked for only where there are clients to name.
   */
  const agency = usePortalBase() === '/agency'
  const clients = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces, enabled: agency })
  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false) })
  const canMoney = useAuth((st) => st.hasPermission('funding.view'))
  const budgets = useBudgets(false, null, agency && canMoney)
  const cols = useColumns([[1280, 4], [1024, 3], [640, 2]])
  const fit = useFitRows({ rowHeight: CARD_HEIGHT + CARD_GAP, reserve: 0, min: 1, max: 12, fallback: 1000 })
  const [page, setPage] = useState(1)

  const rows: Row[] = useMemo(() => {
    const byClient = new Map((clients.data ?? []).map((c) => [c.id, c]))
    const byProject = new Map((budgets.data?.rows ?? []).map((b) => [b.project_id, b]))
    return (projects.data ?? [])
      .map((p) => {
        const b = byProject.get(p.id) ?? null
        return {
          ...p,
          client_name: byClient.get(p.client_workspace_id)?.name ?? p.client_name ?? '',
          client_logo: byClient.get(p.client_workspace_id)?.logo_url ?? null,
          synced_at: p.summary?.data_last_synced_at ?? null,
          campaigns: typeof p.summary?.campaigns === 'number' ? p.summary.campaigns : null,
          platforms: p.summary?.providers?.length ?? 0,
          providers: p.summary?.providers ?? [],
          budget: b !== null && !['void', 'no_budget', 'unset'].includes(b.status) ? b : null,
        }
      })
      // The project that reported most recently first — the one most likely to be «what is happening now».
      .sort((a, b) => (b.synced_at ?? '').localeCompare(a.synced_at ?? '') || a.name.localeCompare(b.name))
  }, [clients.data, projects.data, budgets.data])

  const choose = (p: Row) => {
    /*
     * The client is set only where the reader holds that client. A viewer scoped to projects alone
     * reaches no client workspace; handing the switcher a client id it cannot find made its effect
     * clear BOTH selections, which put the chooser back, which chose again — a loop the chromium
     * gate caught as a project select with no options (campaigns-roles, client viewer).
     */
    if ((clients.data ?? []).some((c) => c.id === p.client_workspace_id)) setCurrentClientId(p.client_workspace_id)
    setCurrentProjectId(p.id)
  }

  // Exactly one reachable project: no choice exists, so none is asked for.
  const only = !clients.isLoading && !projects.isLoading && rows.length === 1 ? rows[0] : null
  useEffect(() => {
    if (only) choose(only)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [only?.id])

  const purposeWords = {
    campaigns: { ar: 'لعرض حملاته', en: 'to see its campaigns' },
    analytics: { ar: 'لعرض تحليلاته', en: 'to see its analytics' },
    reports: { ar: 'لعرض تقاريره وإنشاء تقرير رابط', en: 'to see its reports and create a link report' },
    recommendations: { ar: 'لعرض ما يستحق التنفيذ في حملاته', en: 'to see what its campaigns need done' },
    'spend-limits': { ar: 'لعرض حدود إنفاقه وضبطها', en: 'to see and set its spend limits' },
  }[purpose]

  const perPage = fitted && fit.locked ? Math.max(1, fit.rows * cols) : Number.MAX_SAFE_INTEGER
  const pages = Math.max(1, Math.ceil(rows.length / perPage))
  const current = Math.min(page, pages)
  const pageRows = fitted && fit.locked ? rows.slice((current - 1) * perPage, current * perPage) : rows

  if (clients.isLoading || projects.isLoading) {
    return <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div>
  }

  if (rows.length === 0) {
    return (
      <div data-testid="project-chooser-empty" className="rounded-2xl border border-border bg-surface p-6 text-center">
        <FolderKanban size={28} className="mx-auto text-text-muted" aria-hidden />
        <h2 className="mt-2 text-base font-bold text-text-primary">{ar ? 'لا مشاريع يمكنك الوصول إليها بعد' : 'No project you can reach yet'}</h2>
        <p className="mt-1 text-sm text-text-secondary">{ar ? 'أنشئ مشروعًا من قائمة المشاريع، أو اطلب من مدير الوكالة إضافتك إلى مشروع.' : 'Create a project from the projects list, or ask the agency manager to add you to one.'}</p>
      </div>
    )
  }

  return (
    <section data-testid="project-chooser" aria-label={ar ? 'اختر مشروعًا' : 'Choose a project'} className={fitted ? 'flex min-h-0 flex-1 flex-col gap-2' : 'space-y-3'}>
      <div>
        <h2 className="text-base font-bold text-text-primary">{ar ? 'اختر مشروعًا' : 'Choose a project'}</h2>
        <p className="mt-0.5 text-sm text-text-secondary">
          {ar ? `كل مشروع مستقل — اختر واحدًا ${purposeWords.ar}.` : `Each project stands alone — pick one ${purposeWords.en}.`}
        </p>
      </div>
      <div ref={fitted ? (fit.ref as never) : undefined} className={fitted ? 'min-h-0 flex-1' : undefined}>
      <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        {pageRows.map((p) => (
          <li key={p.id} className="min-w-0">
            <button
              type="button"
              data-testid={`project-choice-${p.id}`}
              onClick={() => choose(p)}
              style={fitted ? { height: CARD_HEIGHT } : undefined}
              className="group flex w-full flex-col overflow-hidden rounded-xl border border-border bg-surface px-3 py-2.5 text-start transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 motion-reduce:transform-none"
            >
              <ChoiceCard p={p} ar={ar} />
            </button>
          </li>
        ))}
      </ul>
      </div>
      {fitted && fit.locked && <Pager testid="project-chooser-pager" page={current} pages={pages} onPage={setPage} total={rows.length} ar={ar} hideWhenSingle />}
    </section>
  )
}

const TONE = {
  danger: { text: 'text-danger', chip: 'bg-danger/10 text-danger', bar: 'bg-danger' },
  warning: { text: 'text-warning', chip: 'bg-warning/10 text-warning', bar: 'bg-warning' },
  success: { text: 'text-success', chip: 'bg-success/10 text-success', bar: 'bg-success' },
  neutral: { text: 'text-text-secondary', chip: 'bg-surface-secondary text-text-secondary', bar: 'bg-border-strong' },
} as const

function budgetTone(b: BudgetRow): keyof typeof TONE {
  if (b.status === 'over' || b.status === 'credit_over') return 'danger'
  if (b.status === 'low' || b.status === 'credit_near' || b.status === 'incomplete') return 'warning'
  if (b.status === 'ok' || b.status === 'credit') return 'success'
  return 'neutral'
}

function budgetLabel(b: BudgetRow, ar: boolean): string {
  const t = budgetTone(b)
  if (t === 'danger') return ar ? 'تجاوزت الميزانية' : 'Over budget'
  if (t === 'warning') return b.status === 'incomplete' ? (ar ? 'ناقصة بيانات' : 'Incomplete') : (ar ? 'قرّبت تخلص' : 'Running low')
  if (b.payment_mode === 'credit') return ar ? 'بالأجل' : 'On credit'
  return ar ? 'ضمن الميزانية' : 'Within budget'
}

/** How long ago, in a few words — «قبل 12 د» reads at a glance where a full timestamp does not. */
function ago(iso: string | null, ar: boolean): string | null {
  if (!iso) return null
  const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  if (mins < 60) return ar ? `قبل ${mins} د` : `${mins}m ago`
  const h = Math.round(mins / 60)
  if (h < 48) return ar ? `قبل ${h} س` : `${h}h ago`
  return fmtDateTime(iso)
}

/** PROJECT-CHOOSER-VISUAL-001 — one project, as a card worth choosing from. */
function ChoiceCard({ p, ar }: { p: Row; ar: boolean }) {
  const b = p.budget
  const t = b ? budgetTone(b) : 'neutral'
  const credit = b?.payment_mode === 'credit'
  const synced = ago(p.synced_at, ar)
  const attention = p.summary?.attention ?? null
  const pct = b && !credit && b.used_ratio !== null ? Math.round(b.used_ratio * 100) : null

  return (
    <>
      <div className="flex items-center gap-2">
        <ClientAvatar name={p.client_name || p.name} logo={p.client_logo} size={28} />
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-extrabold leading-tight text-text-primary group-hover:text-brand-600">{p.name}</div>
          <div className="truncate text-xs leading-tight text-text-secondary">{p.client_name && p.client_name !== p.name ? p.client_name : (p.client_name ? (ar ? 'عميل' : 'Client') : (ar ? 'بلا عميل' : 'No client'))}</div>
        </div>
        {b && <span data-testid={`chooser-budget-${p.id}`} className={`shrink-0 rounded-full px-2 py-px text-xs font-bold ${TONE[t].chip}`}>{budgetLabel(b, ar)}</span>}
        {!b && attention && (
          <span className="shrink-0 rounded-full bg-warning/10 px-2 py-px text-xs font-bold text-warning">
            {attention === 'no_accounts' ? (ar ? 'بلا حسابات' : 'No accounts') : attention === 'never_synced' ? (ar ? 'لا بيانات' : 'No data') : (ar ? 'بيانات قديمة' : 'Stale')}
          </span>
        )}
      </div>

      {b && (
        <div className="mt-1.5 flex items-center gap-2 text-xs">
          <span className="min-w-0 flex-1">
            <span className="block h-1 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
              <span className={`block h-full rounded-full ${TONE[t].bar}`} style={{ width: `${Math.min(100, pct ?? (credit ? 0 : 0))}%` }} />
            </span>
          </span>
          <span className="shrink-0 font-bold text-text-primary">
            <Num>{money(credit ? (b.to_date ?? b.spent) : b.spent, b.currency)}</Num>
            {!credit && <span className="font-normal text-text-muted"> / <Num>{money(b.approved, b.currency)}</Num></span>}
          </span>
          {pct !== null && <span data-testid="chooser-gauge" className={`shrink-0 font-bold ${TONE[t].text}`}><Num>{`${pct}%`}</Num></span>}
        </div>
      )}

      <div className="mt-auto flex items-center justify-between gap-2 pt-1">
        <span className="flex min-w-0 items-center gap-1" data-testid={`chooser-platforms-${p.id}`}>
          {p.providers.slice(0, 5).map((pr) => <ProviderMark key={pr} provider={pr} size={14} />)}
          {p.providers.length === 0 && <span className="text-xs text-text-muted">—</span>}
        </span>
        <span className="flex shrink-0 items-center gap-2 text-xs text-text-muted">
          {p.campaigns !== null && <span className="inline-flex items-center gap-1"><Megaphone size={10} aria-hidden /><Num>{String(p.campaigns)}</Num> {ar ? 'حملة' : 'campaigns'}</span>}
          <span className="inline-flex items-center gap-1" title={p.synced_at ? fmtDateTime(p.synced_at) : undefined}>
            <RefreshCw size={10} aria-hidden />{synced ?? (ar ? 'لم تصل بيانات بعد' : 'No data has arrived yet')}
          </span>
        </span>
      </div>
    </>
  )
}
