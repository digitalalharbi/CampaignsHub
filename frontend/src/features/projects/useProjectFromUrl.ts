import { useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { listClientWorkspaces, listProjects } from './api'
import { useProject } from '@/stores/project'
import { useAgencyClient } from '@/stores/agencyClient'
import { usePortalBase } from '@/app/portalPath'

/**
 * DASHBOARD-DRILLDOWN-001 — a project named in the address is chosen on arrival.
 *
 * ## Why a project-scoped surface needs this
 *
 * The campaigns board shows ONE project, chosen in the store; the server refuses an «every project»
 * list. A link from somewhere that counted across projects — the agency dashboard's paused row, a
 * client's project budget rung — therefore had nowhere to land that meant what it said: it opened
 * the chooser, or whichever project the reader last looked at. `?project=<id>` lets the link carry
 * the choice, so «Q3 Launch · 2 paused» opens Q3 Launch's paused campaigns and not a question.
 *
 * ## What it refuses
 *
 * A project the reader cannot reach is not chosen — the reachable list is the same one the chooser
 * offers, so the address cannot widen anyone's scope. The client is set only where the reader holds
 * that client, for the reason the chooser gives: a viewer scoped to projects alone has no client to
 * set, and handing the switcher an id it cannot find clears both selections.
 *
 * ## Why the key is removed afterwards
 *
 * Left in the address, a reload would re-choose the project for ever and Back from a later choice
 * would undo it. The choice is made once and the address is cleaned, in place, so the remaining
 * keys (`view`, `band`, `lifecycle`) stay exactly as the link wrote them.
 */
export function useProjectFromUrl(): void {
  const [params, setParams] = useSearchParams()
  const wanted = params.get('project')
  const { setCurrentProjectId } = useProject()
  const { setCurrentClientId } = useAgencyClient()
  const agency = usePortalBase() === '/agency'
  const clients = useQuery({ queryKey: ['agency-scope', 'clients'], queryFn: listClientWorkspaces, enabled: agency && wanted !== null })
  const projects = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false), enabled: wanted !== null })

  useEffect(() => {
    if (wanted === null || projects.data === undefined) return
    if (agency && clients.data === undefined && !clients.isError) return

    const project = projects.data.find((p) => p.id === wanted)
    if (project !== undefined) {
      if ((clients.data ?? []).some((c) => c.id === project.client_workspace_id)) setCurrentClientId(project.client_workspace_id)
      setCurrentProjectId(project.id)
    }

    setParams(
      (current) => {
        const out = new URLSearchParams(current)
        out.delete('project')

        return out
      },
      { replace: true },
    )
  }, [wanted, projects.data, clients.data, clients.isError, agency, setCurrentProjectId, setCurrentClientId, setParams])
}
