import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { useProject } from '@/stores/project'

/**
 * TEAM-PROJECT-RBAC-001 — what this person may do in the project they are looking at.
 *
 * ## What this is for, and what it is not
 *
 * It is not authorisation, and nothing here is allowed to read as if it were. Every route under
 * `projects/{project}` states its own capability and the server refuses without it; that is the
 * enforcement, and it does not consult this. Hiding a menu item is not security.
 *
 * What this is for is the other half of the same requirement: not OFFERING a door that answers 403.
 * The agency rail is a static list, so a media buyer on a client's project is shown «Team &
 * permissions», clicks it, and is refused — which reads as a broken product rather than as a
 * boundary, and teaches them to distrust the rail.
 *
 * ## It fails OPEN, deliberately
 *
 * While the answer is loading, or when no project is chosen, or when the request fails, `can()`
 * returns true and every link is offered. That is the right direction for a MENU: the server fails
 * closed, so the worst case is a link that refuses — the same thing that happens today. Failing
 * closed here would empty somebody's rail on a slow network and look like an outage.
 */
export function useProjectCapabilities() {
  const projectId = useProject((s) => s.currentProjectId)

  const query = useQuery({
    queryKey: ['project', projectId, 'capabilities'],
    queryFn: () => getData<{ capabilities: string[] }>(`/projects/${projectId}/capabilities`),
    enabled: Boolean(projectId),
    // A membership changes rarely, and a rail that refetched on every focus would ask for this on
    // every tab switch for a set that has not moved.
    staleTime: 5 * 60_000,
    retry: false,
  })

  const held = query.data?.capabilities

  return {
    capabilities: held,
    /** True unless we KNOW the capability is absent — see «fails open» above. */
    can: (capability: string | undefined): boolean =>
      capability === undefined || held === undefined || held.includes(capability),
  }
}
