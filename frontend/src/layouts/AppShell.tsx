import { Outlet } from 'react-router-dom'
import {
  BarChart3,
  FolderKanban,
  LayoutDashboard,
  Megaphone,
  Menu,
  Moon,
  PanelLeft,
  Search,
  Sun,
  X,
} from 'lucide-react'
import { ProjectSwitcher } from '@/components/ProjectSwitcher'
import { AccountMenu } from '@/features/account/UserMenu'
import { NotificationCenter } from '@/features/notifications/NotificationCenter'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { SidebarNav } from './SidebarNav'
import { appNavGroups } from './appNav'
import { PortalFrame } from './PortalFrame'
import type { MobileTab } from './MobileTabBar'
import { moreGroupsFrom } from './mobileTabs'

// `ent` = the account-entitlement nav key; an item shows only when it's in the workspace's entitled nav.

/**
 * The four an advertiser opens this portal for (MOBILE-APP-001).
 *
 * «كل حملاتك الإعلانية المدفوعة في مكان واحد» is the promise, so the bar is: where things stand, the
 * campaigns themselves, the numbers behind them, and the projects they belong to. Content, reports,
 * alerts, tasks, files, integrations, subscription and settings are all one tap away in More — none
 * of them is gone, and none of them is something you reach for several times an hour on a phone.
 */
const APP_TABS: MobileTab[] = [
  { to: '/app/dashboard', ar: 'الرئيسية', en: 'Home', icon: LayoutDashboard },
  { to: '/app/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone },
  { to: '/app/analytics', ar: 'التحليلات', en: 'Analytics', icon: BarChart3 },
  { to: '/app/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban },
]

/**
 * Navigation lives in `appNav.ts` — the same sixteen sections the flat rail had, grouped by the
 * question the advertiser is asking. See that file for why each group holds what it does.
 */
function NavItems({ collapsed, onNavigate }: { collapsed?: boolean; onNavigate?: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  // Filter by the workspace's entitlements (personal = full menu; company = simplified). No
  // entitlements yet (older payload) → show everything, preserving the previous behaviour.
  const nav = useAuth((s) => s.user?.account?.nav)

  return (
    <SidebarNav
      groups={appNavGroups}
      ar={ar}
      collapsed={collapsed}
      onNavigate={onNavigate}
      allow={(leaf) => !nav || leaf.ent === undefined || nav.includes(leaf.ent)}
      label={ar ? 'أقسام مساحة العمل' : 'Workspace sections'}
      storageKey="nav.collapsed.app"
    />
  )
}

function Brand({ collapsed }: { collapsed?: boolean }) {
  return (
    <div className={`flex items-center gap-2.5 ${collapsed ? 'justify-center' : 'px-1'}`}>
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-[var(--shadow-small)]">
        <Megaphone size={18} />
      </div>
      {!collapsed && (
        <span className="font-heading text-lg font-extrabold tracking-tight text-text-primary">CampaignsHub</span>
      )}
    </div>
  )
}

export function AppShell() {
  const { theme, locale, toggleTheme, toggleLocale, sidebarOpen, setSidebarOpen, sidebarCollapsed, toggleSidebarCollapsed } =
    useUi()
  const nav = useAuth((s) => s.user?.account?.nav)

  // The same entitlement filter the rail applies, so the phone offers the same set — never more.
  const moreGroups = moreGroupsFrom(appNavGroups, APP_TABS, (leaf) => !nav || leaf.ent === undefined || nav.includes(leaf.ent))

  return (
    <PortalFrame
      railWidth={sidebarCollapsed ? 'w-[76px]' : 'w-[264px]'}
      tabs={APP_TABS}
      moreGroups={moreGroups}
      /*
        * The project switcher lives HERE on a phone, not only in the drawer (MOBILE-APP-001).
        *
        * The bottom bar carries destinations; the switcher is context, and every destination is read
        * through it. Removing the hamburger below `sm` — correct for an app shell — left it with no
        * door at all on a real phone, which is a feature removed to make navigation fit. It is the
        * first thing in the sheet because choosing the project comes before choosing the section.
        */
      moreHeader={<div className="grid gap-3"><ProjectSwitcher /><AccountMenu variant="sidebar" /></div>}
      drawerOpen={sidebarOpen}
      onDrawerClose={() => setSidebarOpen(false)}
      rail={
        <>
          <div className="flex items-center justify-between">
            <Brand collapsed={sidebarCollapsed} />
            {!sidebarCollapsed && (
              <button
                onClick={toggleSidebarCollapsed}
                aria-label="Collapse sidebar"
                className="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted transition-colors hover:bg-surface-hover hover:text-text-primary"
              >
                <PanelLeft size={17} />
              </button>
            )}
          </div>
          {sidebarCollapsed ? (
            <button
              onClick={toggleSidebarCollapsed}
              aria-label="Expand sidebar"
              className="mx-auto flex h-8 w-8 items-center justify-center rounded-lg text-text-muted transition-colors hover:bg-surface-hover hover:text-text-primary"
            >
              <PanelLeft size={17} className="rotate-180" />
            </button>
          ) : (
            <ProjectSwitcher />
          )}
          <NavItems collapsed={sidebarCollapsed} />
          <AccountMenu variant="sidebar" collapsed={sidebarCollapsed} />
        </>
      }
      drawer={
        <>
          <div className="flex items-center justify-between">
            <Brand />
            <button
              onClick={() => setSidebarOpen(false)}
              aria-label="Close"
              className="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover"
            >
              <X size={18} />
            </button>
          </div>
          <ProjectSwitcher />
          <NavItems onNavigate={() => setSidebarOpen(false)} />
          <AccountMenu variant="sidebar" />
        </>
      }
      header={
        <header className="sticky top-0 z-40 flex items-center gap-3 border-b border-border bg-surface/85 px-4 py-2.5 backdrop-blur-md sm:px-6">
          {/*
            * The hamburger is a DESKTOP-tablet control now (`md:hidden` still, but `hidden` below
            * `sm`): on a phone the bottom bar is the navigation, and two ways to open the same rail
            * is the clutter this pass exists to remove. Between `sm` and `md` — a small tablet, no
            * rail and no tab bar — it is still the only way in, so it stays there.
            */}
          <button
            onClick={() => setSidebarOpen(true)}
            aria-label="Open menu"
            className="hidden h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:flex sm:h-9 sm:w-9 md:hidden"
          >
            <Menu size={19} />
          </button>

          {/* Command / search (visual entry point). */}
          <button className="hidden h-9 items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 text-sm text-text-muted transition-colors hover:border-border-strong sm:flex sm:w-[280px]">
            <Search size={16} />
            <span className="flex-1 text-start">Search…</span>
            <kbd className="tnum rounded-md border border-border bg-surface px-1.5 py-0.5 text-xs text-text-muted">⌘K</kbd>
          </button>

          <div className="ms-auto flex items-center gap-1.5">
            <NotificationCenter />
            <button
              onClick={toggleLocale}
              aria-label="Toggle language"
              className="flex h-11 min-w-11 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover sm:h-9 sm:min-w-9"
            >
              {locale === 'ar' ? 'EN' : 'ع'}
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
