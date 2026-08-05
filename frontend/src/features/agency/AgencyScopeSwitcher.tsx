import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Building2, ChevronsUpDown, FolderKanban, Search } from 'lucide-react'
import { listClientWorkspaces, listProjects } from '@/features/projects/api'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { useUi } from '@/stores/ui'

/**
 * Client first, then project — the agency portal's own scope control (AGENCY-006).
 *
 * NOT the advertiser's `ProjectSwitcher` with a second dropdown bolted on. An advertiser has one
 * workspace and a flat list of projects, so picking a project is the whole question. An agency runs
 * campaigns for other people, and a project only means something once you know whose it is: two
 * clients routinely have a project called "Launch", and a flat list of every project across every
 * client is both unreadable and a way to act on the wrong client's campaigns.
 *
 * Both lists come from endpoints the server has already narrowed by the membership's client scope
 * (`portal:agency` + `ClientScopeResolver`), so an operator confined to three clients sees three —
 * the ceiling is applied in the query, not by filtering here.
 *
 * The persisted selection is re-validated against those lists on every mount, which is what makes a
 * stored id harmless: a project that was reachable last week and is not today simply is not in the
 * list, and gets cleared. Nothing here can widen access, because nothing here is the source of what
 * is allowed.
 *
 * It writes the chosen project into the SAME store the advertiser switcher writes to, because the
 * pages underneath — campaigns, analytics, reports, tasks, integrations — are one engine serving
 * both portals and must not learn which portal they are in.
 */
export function AgencyScopeSwitcher({ collapsed }: { collapsed?: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId, setCurrentProjectId } = useProject()
  const { currentClientId, setCurrentClientId } = useAgencyClient()

  const clients = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces })
  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false) })

  const authorisedClients = clients.data ?? []
  const authorisedProjects = projects.data ?? []

  /*
   * Reconcile the stored selection with what this operator may actually reach.
   *
   * Three separate corrections, in order, because they can each be true on their own:
   *   1. a client id that is no longer in the authorised list — dropped;
   *   2. a project that does not belong to the selected client — dropped, so changing client can
   *      never leave the previous client's data on screen;
   *   3. a project id that is not in the authorised list at all — dropped.
   *
   * Nothing is auto-selected. An agency operator who has not chosen sees the choice, not a guess:
   * defaulting to "whichever client came first" is how someone ends up editing the wrong client's
   * campaign believing it is theirs.
   */
  useEffect(() => {
    if (clients.isLoading || projects.isLoading) return

    const clientIsReachable = currentClientId !== null
      && authorisedClients.some((c) => c.id === currentClientId)

    if (currentClientId !== null && !clientIsReachable) {
      setCurrentClientId(null)
      setCurrentProjectId(null)
      return
    }

    if (currentProjectId === null) return

    const project = authorisedProjects.find((p) => p.id === currentProjectId)
    const orphaned = project === undefined
    // Only meaningful when a client IS the axis. Someone scoped to projects alone has no client
    // selection for their project to disagree with.
    const belongsElsewhere = project !== undefined
      && authorisedClients.length > 0
      && clientIsReachable
      && project.client_workspace_id !== currentClientId

    if (orphaned || belongsElsewhere) {
      setCurrentProjectId(null)
    }
  }, [
    clients.isLoading, projects.isLoading, authorisedClients, authorisedProjects,
    currentClientId, currentProjectId, setCurrentClientId, setCurrentProjectId,
  ])

  // Collapsed rail: the control needs its labels to be usable, so it steps aside rather than
  // rendering two unlabelled dropdowns.
  if (collapsed) return null

  if (clients.isLoading) {
    return <div className="h-[76px] w-full animate-pulse rounded-xl bg-surface-secondary" />
  }

  /*
   * Reachable projects but no reachable clients — a real and legitimate shape (AGENCY-006).
   *
   * Client scope and project scope are separate grants. An analyst or a client viewer can be given
   * specific PROJECTS without being given the clients that own them, and for those people a
   * client-first control is a locked door: it would report "no clients" to someone who demonstrably
   * has work to do.
   *
   * So the client step appears only when there are clients to choose between. Otherwise the operator
   * picks straight from the projects they hold — which is what their access actually is, described
   * accurately rather than forced through a hierarchy they were not granted.
   */
  const scopedToProjectsOnly = authorisedClients.length === 0 && authorisedProjects.length > 0

  if (authorisedClients.length === 0 && !scopedToProjectsOnly) {
    return (
      <p data-testid="agency-scope-no-clients" className="rounded-xl border border-dashed border-border p-3 text-xs text-text-muted">
        {ar
          ? 'لا يوجد عملاء أو مشاريع مصرّح لك بها بعد. تواصل مع مدير الوكالة لإسناد عميل إليك.'
          : 'No clients or projects are assigned to you yet. Ask an agency administrator for access.'}
      </p>
    )
  }

  const projectsForClient = scopedToProjectsOnly
    ? authorisedProjects
    : currentClientId === null
      ? []
      : authorisedProjects.filter((p) => p.client_workspace_id === currentClientId)

  return (
    <div data-testid="agency-scope" className="space-y-2">
      {!scopedToProjectsOnly && (
      <Field
        testId="agency-scope-client"
        icon={<Building2 size={14} />}
        label={ar ? 'العميل' : 'Client'}
        value={currentClientId ?? ''}
        placeholder={ar ? 'اختر عميلًا' : 'Select a client'}
        onChange={(id) => {
          setCurrentClientId(id === '' ? null : id)
          // Always clear the project: it belonged to the previous client, and carrying it over is
          // exactly how the wrong client's campaigns end up on screen.
          setCurrentProjectId(null)
        }}
        options={authorisedClients.map((c) => ({ id: c.id, name: c.name }))}
      />
      )}

      <Field
        testId="agency-scope-project"
        icon={<FolderKanban size={14} />}
        label={ar ? 'المشروع' : 'Project'}
        value={currentProjectId ?? ''}
        placeholder={
          !scopedToProjectsOnly && currentClientId === null
            ? (ar ? 'اختر العميل أولًا' : 'Choose a client first')
            : projectsForClient.length === 0
              ? (ar ? 'لا مشاريع لهذا العميل' : 'No projects for this client')
              : (ar ? 'اختر مشروعًا' : 'Select a project')
        }
        disabled={(!scopedToProjectsOnly && currentClientId === null) || projectsForClient.length === 0}
        onChange={(id) => setCurrentProjectId(id === '' ? null : id)}
        options={projectsForClient.map((p) => ({ id: p.id, name: p.name }))}
      />
    </div>
  )
}

/**
 * Above this many options the list stops being scannable and a filter box appears above it.
 *
 * Found by opening the live agency portal: the client step rendered 269 `<option>` elements in one
 * scroll, and it is the entry point to every project-scoped page in the portal. An agency with even
 * fifty clients cannot use it, and this control is not optional — nothing downstream renders until
 * a client is chosen.
 */
const FILTER_ABOVE = 12

/**
 * One labelled select, with a filter box once the list is long.
 *
 * Still a NATIVE select, deliberately: it is keyboard- and screen-reader-correct in both text
 * directions for free, and a listbox reimplemented in React would have to earn that back. The
 * filter is a plain search input beside it, so the accessible control is untouched — it just has
 * fewer options in it.
 *
 * The currently selected option is always kept in the list even when it does not match the filter.
 * Dropping it would make the select fall back to the placeholder and read as "nothing is selected"
 * while the rest of the portal is still showing that client's data.
 */
function Field({ testId, icon, label, value, placeholder, options, onChange, disabled }: {
  testId: string
  icon: React.ReactNode
  label: string
  value: string
  placeholder: string
  options: { id: string; name: string }[]
  onChange: (id: string) => void
  disabled?: boolean
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [term, setTerm] = useState('')
  const filterable = options.length > FILTER_ABOVE && !disabled

  const needle = term.trim().toLowerCase()
  const shown = !filterable || needle === ''
    ? options
    : options.filter((o) => o.name.toLowerCase().includes(needle) || o.id === value)

  return (
    <label className="block">
      <span className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold text-text-muted">
        {icon}
        {label}
      </span>

      {filterable && (
        <div className="relative mb-1.5">
          <Search size={12} className="pointer-events-none absolute start-2.5 top-1/2 -translate-y-1/2 text-text-muted" aria-hidden />
          <input
            type="search"
            data-testid={`${testId}-filter`}
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            aria-label={ar ? `تصفية ${label}` : `Filter ${label}`}
            placeholder={ar ? 'تصفية…' : 'Filter…'}
            className="w-full rounded-xl border border-border bg-surface-secondary py-1.5 pe-2 ps-7 text-[12px] text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none"
          />
        </div>
      )}

      <div className="relative">
        <select
          data-testid={testId}
          aria-label={label}
          disabled={disabled}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="w-full cursor-pointer appearance-none rounded-xl border border-border bg-surface-secondary py-2 pe-8 ps-3 text-[13px] font-semibold text-text-primary transition-colors hover:border-border-strong focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <option value="">{placeholder}</option>
          {shown.map((o) => (
            <option key={o.id} value={o.id}>{o.name}</option>
          ))}
        </select>
        <ChevronsUpDown className="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 text-text-muted" size={14} />
      </div>

      {/* A filter that hides everything must say so, or it reads as "you have no clients". */}
      {filterable && needle !== '' && shown.length === 0 && (
        <span data-testid={`${testId}-filter-empty`} className="mt-1 block text-[11px] text-text-muted">
          {ar ? 'لا نتائج لهذه التصفية.' : 'Nothing matches that filter.'}
        </span>
      )}
    </label>
  )
}
