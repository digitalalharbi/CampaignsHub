import { useQuery } from '@tanstack/react-query'
import { fetchPortalVisibility } from './portalOverviewApi'
import { useClientSpaceSlug } from './clientSpace'

/**
 * What the agency lets this client see, for navigation only (CP-VISIBILITY-001).
 *
 * The server has already enforced every one of these on the data itself, so this hook decides nothing a
 * client could exploit by ignoring it: it only stops the portal offering a section that would answer
 * «not available». While the answer is on its way, nothing is hidden — a flash of missing navigation on
 * every page load is worse than a link that, rarely, leads to a refusal.
 */
export function usePortalVisibility(): { allows: (section: string) => boolean; loaded: boolean } {
  const slug = useClientSpaceSlug()
  const spaces = useQuery({ queryKey: ['portal', 'visibility'], queryFn: fetchPortalVisibility, retry: false, staleTime: 60_000 })

  const space = (spaces.data ?? []).find((s) => s.slug === slug) ?? ((spaces.data ?? []).length === 1 ? spaces.data?.[0] : undefined)

  return {
    loaded: space !== undefined,
    allows: (section) => (space === undefined ? true : space.visibility[section] !== false),
  }
}
