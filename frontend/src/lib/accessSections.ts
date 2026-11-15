import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/**
 * ACCESS-SECTIONS-001 — the signed-in member's effective access, from the server's one resolver
 * (`GET /api/v1/me/access`): role, the sections they may open, the clients they reach.
 *
 * The navigation HIDES a section the member lacks — it is not drawn disabled. This is presentation only: the server
 * refuses the same sections on every route (`EnforceSectionAccess` and each route's own check), so failing open
 * while the answer loads (or if it fails) shows at worst what the server will refuse anyway, and never empties the
 * rail on a slow network. Re-fetched on focus and every minute, so a change made in the access center reaches the
 * member's rail without signing in again.
 */
export interface MyAccess {
  role: { slug: string; name_ar: string; name_en: string } | null
  is_owner: boolean
  sections: string[]
  all_sections: { key: string; ar: string; en: string; allowed: boolean }[]
  clients: { all: boolean; ids: string[] }
}

/** Which of the seven sections an address belongs to, or null for everything else (never hidden by this). */
export function sectionOfPath(to: string): string | null {
  const path = to.replace(/^\/(app|agency)/, '')
  if (/^\/campaigns(\/|$)/.test(path)) return 'campaigns'
  if (/^\/budgets(\/|$)/.test(path)) return 'budgets'
  if (/^\/spend-limits(\/|$)/.test(path)) return 'spend_limits'
  if (/^\/billing(\/|$)/.test(path)) return 'invoices'
  if (/^\/(reports|delivery)(\/|$)/.test(path)) return 'reports'
  if (/^\/integrations(\/|$)/.test(path)) return 'integrations'
  if (/^\/team(\/|$)/.test(path)) return 'team'
  return null
}

export function useMyAccess() {
  return useQuery({
    queryKey: ['me', 'access'],
    queryFn: () => getData<MyAccess>('/me/access'),
    staleTime: 30_000,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
    retry: false,
  })
}

/** `(to) => boolean` — false only for an address in a section the server says this member lacks. */
export function useSectionAllowed(): (to: string) => boolean {
  const q = useMyAccess()
  const sections = q.data?.sections
  return (to: string) => {
    if (!sections) return true
    const section = sectionOfPath(to)
    return section === null || sections.includes(section)
  }
}
