import { Outlet } from 'react-router-dom'
import { PortalFooter } from '@/features/legal/PolicyFooter'
import {
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

// `ent` = the account-entitlement nav key; an item shows only when it's in the workspace's entitled nav.

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

  const railWidth = sidebarCollapsed ? 'w-[76px]' : 'w-[264px]'

  return (
    <div className="flex min-h-screen bg-background text-text-primary">
      {/* Desktop rail. */}
      <aside
        className={`sticky top-0 hidden h-screen shrink-0 flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 transition-[width] duration-200 md:flex ${railWidth}`}
      >
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
      </aside>

      {/* Mobile drawer. */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onClick={() => setSidebarOpen(false)} />
          <aside className="absolute inset-y-0 start-0 flex h-full w-[280px] max-w-[82vw] flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 shadow-[var(--shadow-large)]">
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
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 flex items-center gap-3 border-b border-border bg-surface/85 px-4 py-2.5 backdrop-blur-md sm:px-6">
          <button
            onClick={() => setSidebarOpen(true)}
            aria-label="Open menu"
            className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9 md:hidden"
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

        <main className="mx-auto w-full max-w-[1440px] flex-1 px-4 pb-12 pt-4 sm:px-5 lg:px-6">
          <Outlet />
          <PortalFooter />
        </main>
      </div>
    </div>
  )
}
