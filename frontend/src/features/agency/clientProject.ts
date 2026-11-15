import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { listClientWorkspaces, listProjects, type Project } from '@/features/projects/api'
import { useAgencyClient } from '@/stores/agencyClient'
import { useProject } from '@/stores/project'

/**
 * CONS-CLIENT-PROJECT — once a client is chosen, its project follows (Owner, consistency pass).
 *
 * /agency/reports and /agency/analytics said «لم يُحدَّد مشروع» / «لا مشروع» with a client already selected in the
 * sidebar. The project is picked INSIDE the chosen client only — never across clients, never by guessing the client:
 *
 * - one project → that project (CLIENT-UNIT-001 already did this; kept);
 * - several → the most recently active one (newest sync among its accounts, else the newest project, which is the order
 *   the server lists them in), and the project picker stays so the person can change it.
 *
 * Every id here comes from lists the server has already narrowed to what this member may reach, so nothing can be
 * widened by it; each request underneath still applies the client and project ceilings server-side.
 */
export function pickClientProject(projects: Project[], clientId: string | null): string | null {
  if (clientId === null) return null
  const owned = projects.filter((p) => p.client_workspace_id === clientId)
  if (owned.length <= 1) return owned[0]?.id ?? null

  const synced = (p: Project) => (p.summary?.data_last_synced_at ? Date.parse(p.summary.data_last_synced_at) : Number.NEGATIVE_INFINITY)
  // Stable: a tie (or no sync anywhere) keeps the server's order, newest project first.
  return owned.reduce((best, p) => (synced(p) > synced(best) ? p : best), owned[0]).id
}

/**
 * The client a project was auto-picked for. Shared by every caller (the sidebar switcher and the pages), so a person who
 * then clears the project in a multi-project client is not overruled on the next render.
 */
let autoPickedFor: string | null = null

/** Tests only. */
export function resetClientProjectAutoPick(): void {
  autoPickedFor = null
}

/**
 * Select the chosen client's project when none of its projects is selected. Agency routes only: the advertiser portal
 * has no client step, and asking it for the agency's client list would only be refused.
 */
export function useClientProjectAutoSelect(): void {
  const agency = useLocation().pathname.startsWith('/agency')
  const { currentProjectId, setCurrentProjectId } = useProject()
  const currentClientId = useAgencyClient((s) => s.currentClientId)

  const clients = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces, enabled: agency })
  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false), enabled: agency })

  useEffect(() => {
    if (!agency || currentClientId === null || !clients.data || !projects.data) return
    if (!clients.data.some((c) => c.id === currentClientId)) return

    const owned = projects.data.filter((p) => p.client_workspace_id === currentClientId)
    if (owned.some((p) => p.id === currentProjectId)) return

    // One project: it is the client's working scope, always. Several: picked once per client choice.
    if (owned.length > 1 && autoPickedFor === currentClientId) return

    const pick = pickClientProject(projects.data, currentClientId)
    autoPickedFor = currentClientId
    if (pick !== null && pick !== currentProjectId) setCurrentProjectId(pick)
  }, [agency, currentClientId, currentProjectId, clients.data, projects.data, setCurrentProjectId])
}
