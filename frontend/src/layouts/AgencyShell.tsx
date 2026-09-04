import { Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Building2,
  Inbox,
  LayoutDashboard,
  Megaphone,
  Menu,
  Moon,
  PanelLeft,
  Sun,
  Users,
  X,
} from 'lucide-react'
import { AccountMenu } from '@/features/account/UserMenu'
import { NotificationCenter } from '@/features/notifications/NotificationCenter'
import { fetchMemberships } from '@/features/auth/memberships'
import { useUi } from '@/stores/ui'
import { AgencyScopeSwitcher } from '@/features/agency/AgencyScopeSwitcher'
import { SidebarNav } from './SidebarNav'
import { agencyNavGroups } from './agencyNav'
import { useProjectCapabilities } from '@/features/projects/capabilities'
import { PortalFrame } from './PortalFrame'
import type { MobileTab } from './MobileTabBar'
import { moreGroupsFrom } from './mobileTabs'

/**
 * The four an agency operator opens this portal for (MOBILE-APP-001).
 *
 * Clients is the axis an agency's whole day hangs off — it is second only to knowing where things
 * stand — and Requests is the inbox work arrives through. Projects, content, tasks, conversations,
 * alerts, analytics, reports, files, both kinds of invoicing, connections, team and settings are all
 * in More: eighteen sections is a rail, not a tab bar, and none of them is dropped.
 */
const AGENCY_TABS: MobileTab[] = [
  { to: '/agency/dashboard', ar: 'الرئيسية', en: 'Home', icon: LayoutDashboard },
  { to: '/agency/clients', ar: 'العملاء', en: 'Clients', icon: Building2 },
  { to: '/agency/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone },
  { to: '/agency/requests', ar: 'الطلبات', en: 'Requests', icon: Inbox },
]

/**
 * The agency portal's shell (ADR 0002).
 *
 * A portal, not a skin: the navigation below is the agency's own, and the sections it links to are
 * mounted under `/agency/*` so an operator never leaves their portal mid-journey. The pages behind
 * several of those links are the SAME engines the advertiser portal uses — clients, projects,
 * campaigns, reports — because the business rules must not exist twice. What differs is the boundary:
 * every row is narrowed on the server by the membership's client scope.
 *
 * The header states the agency and, when the operator's membership names specific clients, says so
 * plainly. A partial view that looks like the whole agency is the failure mode this avoids.
 */

/**
 * Navigation lives in `agencyNav.ts` — the same twelve sections, grouped around the question an
 * agency actually asks. Clients is top level rather than one item inside Work, because for an agency
 * it is the axis everything else hangs off.
 */
function NavItems({ ar, collapsed, onNavigate }: { ar: boolean; collapsed?: boolean; onNavigate?: () => void }) {
  /*
   * TEAM-PROJECT-RBAC-001 — the rail stops offering doors that answer 403.
   *
   * A media buyer on a client's project was shown «Team & permissions», clicked it, and was refused.
   * That reads as a broken product rather than as a boundary, and it teaches a reader to distrust the
   * whole rail — the one thing a rail cannot afford.
   *
   * This is NOT the enforcement and must never be mistaken for it: the routes state the same
   * capabilities and the server refuses without them. `can()` fails OPEN — while the answer is
   * loading, with no project chosen, or if the request fails, every link is offered — because the
   * server fails closed, so the worst case here is the 403 that happens today, while failing closed
   * would empty somebody's rail on a slow network and look like an outage.
   */
  const { can } = useProjectCapabilities()

  return (
    <SidebarNav
      groups={agencyNavGroups}
      ar={ar}
      collapsed={collapsed}
      onNavigate={onNavigate}
      allow={(leaf) => can(leaf.cap)}
      label={ar ? 'أقسام الوكالة' : 'Agency sections'}
      storageKey="nav.collapsed.agency"
    />
  )
}

/** Names the agency the operator is inside, and admits when the view is a subset of it. */
function AgencyIdentity({ collapsed }: { collapsed?: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const state = useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })
  const current = state.data?.current ?? state.data?.memberships.find((m) => m.portal === 'agency') ?? null
  const scoped = (current?.client_scope_ids?.length ?? 0) > 0

  return (
    <div className={`flex items-center gap-2.5 ${collapsed ? 'justify-center' : 'px-1'}`}>
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-[var(--shadow-small)]">
        <Users size={18} />
      </div>
      {!collapsed && (
        <div className="min-w-0">
          <span className="block truncate font-heading text-[15px] font-extrabold tracking-tight text-text-primary">
            {current?.tenant.name ?? 'CampaignsHub'}
          </span>
          <span data-testid="agency-scope-note" className="block truncate text-[11px] text-text-muted">
            {ar ? 'بوابة الوكالة' : 'Agency portal'}
            {scoped && ` · ${ar ? 'عملاء محدّدون' : 'Selected clients'}`}
          </span>
        </div>
      )}
    </div>
  )
}

export function AgencyShell() {
  const { theme, locale, toggleTheme, toggleLocale, sidebarOpen, setSidebarOpen, sidebarCollapsed, toggleSidebarCollapsed } =
    useUi()
  const ar = locale === 'ar'

  return (
    <PortalFrame
      testId="agency-shell"
      railWidth={sidebarCollapsed ? 'w-[76px]' : 'w-[264px]'}
      tabs={AGENCY_TABS}
      moreGroups={moreGroupsFrom(agencyNavGroups, AGENCY_TABS)}
      // The agency's client → project scope, for the same reason (MOBILE-APP-001): every section
      // below it is read through that choice, and the drawer is no longer the phone's way in.
      moreHeader={<div className="grid gap-3"><AgencyScopeSwitcher /><AccountMenu variant="sidebar" /></div>}
      drawerOpen={sidebarOpen}
      onDrawerClose={() => setSidebarOpen(false)}
      rail={
        <>
          <div className="flex items-center justify-between gap-2">
            <AgencyIdentity collapsed={sidebarCollapsed} />
            {!sidebarCollapsed && (
              <button
                onClick={toggleSidebarCollapsed}
                aria-label={ar ? 'طي القائمة' : 'Collapse sidebar'}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-muted transition-colors hover:bg-surface-hover hover:text-text-primary"
              >
                <PanelLeft size={17} />
              </button>
            )}
          </div>
          {sidebarCollapsed && (
            <button
              onClick={toggleSidebarCollapsed}
              aria-label={ar ? 'توسيع القائمة' : 'Expand sidebar'}
              className="mx-auto flex h-8 w-8 items-center justify-center rounded-lg text-text-muted transition-colors hover:bg-surface-hover hover:text-text-primary"
            >
              <PanelLeft size={17} className="rotate-180" />
            </button>
          )}
          {/* Client → project, the agency's own scope control (AGENCY-006). Above the rail because
              every section below it is read through that choice. */}
          <AgencyScopeSwitcher collapsed={sidebarCollapsed} />
          <NavItems ar={ar} collapsed={sidebarCollapsed} />
          <AccountMenu variant="sidebar" collapsed={sidebarCollapsed} />
        </>
      }
      drawer={
        <>
          <div className="flex items-center justify-between gap-2">
            <AgencyIdentity />
            <button
              onClick={() => setSidebarOpen(false)}
              aria-label={ar ? 'إغلاق' : 'Close'}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover"
            >
              <X size={18} />
            </button>
          </div>
          <AgencyScopeSwitcher />
          <NavItems ar={ar} onNavigate={() => setSidebarOpen(false)} />
          <AccountMenu variant="sidebar" />
        </>
      }
      header={
        <header className="sticky top-0 z-40 flex items-center gap-3 border-b border-border bg-surface/85 px-4 py-2.5 backdrop-blur-md sm:px-6">
          <button
            onClick={() => setSidebarOpen(true)}
            aria-label={ar ? 'فتح القائمة' : 'Open menu'}
            className="hidden h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:flex sm:h-9 sm:w-9 md:hidden"
          >
            <Menu size={19} />
          </button>

          <div className="ms-auto flex items-center gap-1.5">
            <NotificationCenter />
            <button
              onClick={toggleLocale}
              aria-label="Toggle language"
              className="flex h-11 min-w-11 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover sm:h-9 sm:min-w-9"
            >
              {ar ? 'EN' : 'ع'}
            </button>
            <button
              onClick={toggleTheme}
              aria-label="Toggle theme"
              className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9"
            >
              {theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}
            </button>
            <div className="ms-1"><AccountMenu variant="topbar" /></div>
          </div>
        </header>
      }
    >
      <Outlet />
    </PortalFrame>
  )
}
