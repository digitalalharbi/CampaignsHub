import { useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { usePortalBase } from '@/app/portalPath'
import { FolderKanban } from 'lucide-react'
import { listClientWorkspaces, listProjects, type Project } from './api'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { useUi } from '@/stores/ui'
import { Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'

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
type Row = Project & { client_name: string; synced_at: string | null; campaigns: number | null; platforms: number }

export type ProjectChooserPurpose = 'campaigns' | 'analytics' | 'reports' | 'recommendations' | 'spend-limits'

export function ProjectChooser({ purpose }: { purpose: ProjectChooserPurpose }) {
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

  const rows: Row[] = useMemo(() => {
    const byClient = new Map((clients.data ?? []).map((c) => [c.id, c.name]))
    return (projects.data ?? [])
      .map((p) => ({
        ...p,
        client_name: byClient.get(p.client_workspace_id) ?? '',
        synced_at: p.summary?.data_last_synced_at ?? null,
        campaigns: typeof p.summary?.campaigns === 'number' ? p.summary.campaigns : null,
        platforms: p.summary?.providers?.length ?? 0,
      }))
      // The project that reported most recently first — the one most likely to be «what is happening now».
      .sort((a, b) => (b.synced_at ?? '').localeCompare(a.synced_at ?? '') || a.name.localeCompare(b.name))
  }, [clients.data, projects.data])

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
    reports: { ar: 'لعرض تقاريره وإنشاء رابط لحظي للعميل', en: 'to see its reports and create a live client link' },
    recommendations: { ar: 'لعرض ما يستحق التنفيذ في حملاته', en: 'to see what its campaigns need done' },
    'spend-limits': { ar: 'لعرض حدود إنفاقه وضبطها', en: 'to see and set its spend limits' },
  }[purpose]

  if (clients.isLoading || projects.isLoading) {
    return <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div>
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
    <section data-testid="project-chooser" aria-label={ar ? 'اختر مشروعًا' : 'Choose a project'} className="space-y-3">
      <div>
        <h2 className="text-base font-bold text-text-primary">{ar ? 'اختر مشروعًا' : 'Choose a project'}</h2>
        <p className="mt-0.5 text-sm text-text-secondary">
          {ar ? `كل مشروع مستقل — اختر واحدًا ${purposeWords.ar}.` : `Each project stands alone — pick one ${purposeWords.en}.`}
        </p>
      </div>
      <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((p) => (
          <li key={p.id} className="min-w-0">
            <button
              type="button"
              data-testid={`project-choice-${p.id}`}
              onClick={() => choose(p)}
              className="w-full rounded-2xl border border-border bg-surface p-4 text-start transition hover:border-brand-500 hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
            >
              <div className="truncate text-sm font-bold text-text-primary">{p.name}</div>
              <div className="mt-0.5 truncate text-xs text-text-secondary">{p.client_name || (ar ? 'بلا عميل' : 'No client')}</div>
              <div className="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-text-muted">
                {p.campaigns !== null && (
                  <span><span dir="ltr" className="tabular-nums">{p.campaigns}</span> {ar ? 'حملة' : 'campaigns'}</span>
                )}
                {p.platforms > 0 && <span><span dir="ltr" className="tabular-nums">{p.platforms}</span> {ar ? 'منصات' : 'platforms'}</span>}
                <span>
                  {p.synced_at
                    ? <>{ar ? 'آخر مزامنة' : 'Last sync'} <span dir="ltr" className="tabular-nums">{fmtDateTime(p.synced_at)}</span></>
                    : (ar ? 'لم تصل بيانات بعد' : 'No data has arrived yet')}
                </span>
              </div>
            </button>
          </li>
        ))}
      </ul>
    </section>
  )
}
