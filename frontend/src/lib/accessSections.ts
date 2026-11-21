import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'

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

/**
 * NAV-PAGE-PERMISSION-001 — the permission each page needs to OPEN, as the access center states it (the «page» key of
 * `WorkspaceRolePresets`' areas, and the read each page's first request makes).
 *
 * The seven sections above are the ones an owner can switch off per member; every other page still answers 403 to a
 * member whose role lacks its read permission. The rail offered those pages anyway, so an invited member saw every
 * area and was refused inside it («ليس لديك صلاحية لتنفيذ هذا الإجراء»). A page is now offered only to whoever holds
 * the permission it needs. Presentation only, as everywhere here: the server keeps refusing on every route.
 */
const PAGE_PERMISSION: ReadonlyArray<readonly [RegExp, string]> = [
  [/^\/clients(\/|$)/, 'clients.view'],
  [/^\/requests(\/|$)/, 'requests.view'],
  [/^\/messages(\/|$)/, 'messaging.view'],
  [/^\/(campaigns|ad-groups|ads|short-links|recommendations)(\/|$)/, 'campaigns.view'],
  [/^\/content(\/|$)/, 'content.view'],
  [/^\/files(\/|$)/, 'drive.view'],
  // FINANCE-ONLY-NAV-001 — the analytics board reads the same analytics; a finance member (budgets and invoices only) was offered it.
  // DASHBOARD-PATH-001 — `/dashboard` is the role landing now, open to every member; only «التحليلات» needs analytics.view.
  [/^\/analytics(\/|$)/, 'analytics.view'],
  [/^\/launch(\/|$)/, 'campaigns.launch'],
  [/^\/portfolio(\/|$)/, 'clients.view'],
  [/^\/(reports|delivery)(\/|$)/, 'reports.view'],
  [/^\/forms(\/|$)/, 'leads.view'],
  [/^\/(budgets|spend-limits)(\/|$)/, 'budget.view'],
  [/^\/billing(\/|$)/, 'billing.view'],
  [/^\/tasks(\/|$)/, 'tasks.view'],
  [/^\/alerts(\/|$)/, 'alerts.view'],
  [/^\/agents(\/|$)/, 'ai.view'],
  [/^\/automation(\/|$)/, 'automations.view'],
  [/^\/integrations(\/|$)/, 'integrations.view'],
  [/^\/subscriptions(\/|$)/, 'subscriptions.view'],
  // NAV-PAGE-PERMISSION-002 — the projects list reads `projects.view`; the team page answers `settings.manage` (TeamController::index).
  [/^\/projects\/?$/, 'projects.view'],
  [/^\/team(\/|$)/, 'settings.manage'],
  [/^\/settings(\/|$)/, 'settings.manage'],
]

/** The permission an address needs to open, or null when any member who reaches the portal may open it. */
export function permissionOfPath(to: string): string | null {
  const path = to.replace(/^\/(app|agency)/, '')
  for (const [re, key] of PAGE_PERMISSION) if (re.test(path)) return key

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

/**
 * `(to) => boolean` — false for an address in a section the server says this member lacks, or a page whose permission
 * they do not hold (NAV-PAGE-PERMISSION-001). Fails open while the session or the access answer is still loading.
 */
export function useSectionAllowed(): (to: string) => boolean {
  const q = useMyAccess()
  const sections = q.data?.sections
  const user = useAuth((s) => s.user)
  // NAV-OWNER-001 — the workspace owner sees every section of their own workspace, whatever their role row holds.
  return (to: string) => {
    const perm = permissionOfPath(to)
    if (perm !== null && user && !user.is_platform_admin && !q.data?.is_owner && !user.permissions.includes(perm)) return false
    if (!sections) return true
    const section = sectionOfPath(to)
    return section === null || sections.includes(section)
  }
}
