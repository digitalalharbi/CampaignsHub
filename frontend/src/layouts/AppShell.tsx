import { NavLink, Outlet } from 'react-router-dom'
import {
  BarChart3,
  Building2,
  Inbox,
  LayoutDashboard,
  Megaphone,
  Menu,
  Moon,
  PanelLeft,
  Plug,
  Search,
  Settings,
  Sun,
  TrendingUp,
  X,
} from 'lucide-react'
import { ProjectSwitcher } from '@/components/ProjectSwitcher'
import { AccountMenu } from '@/features/account/UserMenu'
import { NotificationCenter } from '@/features/notifications/NotificationCenter'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import type { TranslationKey } from '@/lib/i18n'

// `ent` = the account-entitlement nav key; an item shows only when it's in the workspace's entitled nav.
type NavItem = { to: string; key: TranslationKey; icon: typeof LayoutDashboard; ent: string }

const operationalNav: NavItem[] = [
  { to: '/dashboard', key: 'dashboard', icon: LayoutDashboard, ent: 'dashboard' },
  { to: '/campaigns', key: 'campaigns', icon: Megaphone, ent: 'campaigns' },
  { to: '/app/requests', key: 'requests_inbox', icon: Inbox, ent: 'requests' },
  { to: '/app/clients', key: 'clients_portfolio', icon: Building2, ent: 'clients' },
  { to: '/analytics', key: 'analytics', icon: TrendingUp, ent: 'analytics' },
  { to: '/reports', key: 'reports', icon: BarChart3, ent: 'reports' },
  { to: '/integrations', key: 'integrations', icon: Plug, ent: 'connections' },
]
const utilityNav: NavItem[] = [{ to: '/settings', key: 'settings', icon: Settings, ent: 'settings' }]

function NavItems({ collapsed, onNavigate }: { collapsed?: boolean; onNavigate?: () => void }) {
  const t = useT()
  // Filter navigation by the workspace's entitlements (personal = full menu; company = simplified).
  // No entitlements yet (older payload) → show everything, preserving current behavior.
  const nav = useAuth((s) => s.user?.account?.nav)
  const allowed = (item: NavItem) => !nav || nav.includes(item.ent)
  const render = (list: NavItem[]) =>
    list.filter(allowed).map(({ to, key, icon: Icon }) => (
      <NavLink
        key={to}
        to={to}
        end={to === '/'}
        onClick={onNavigate}
        title={collapsed ? t(key) : undefined}
        className={({ isActive }) =>
          `group relative flex items-center rounded-xl text-sm font-semibold transition-all duration-150 ${
            collapsed ? 'justify-center p-2.5' : 'gap-3 px-3 py-2.5'
          } ${
            isActive
              ? 'bg-[var(--brand-background)] text-brand-600'
              : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
          }`
        }
      >
        {({ isActive }) => (
          <>
            {isActive && (
              <span className="absolute inset-y-2 start-0 w-[3px] rounded-full bg-brand-600" aria-hidden />
            )}
            <Icon size={19} strokeWidth={isActive ? 2.4 : 2} aria-hidden />
            {!collapsed && <span>{t(key)}</span>}
          </>
        )}
      </NavLink>
    ))
  return (
    <>
      <nav className="flex flex-col gap-1">{render(operationalNav)}</nav>
      <div className="mt-auto flex flex-col gap-1 pt-4">
        <div className="mb-3 border-t border-border" />
        {render(utilityNav)}
      </div>
    </>
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
  const t = useT()
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
                aria-label={t('cancel')}
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
            className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover md:hidden"
          >
            <Menu size={19} />
          </button>

          {/* Command / search (visual entry point). */}
          <button className="hidden h-9 items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 text-sm text-text-muted transition-colors hover:border-border-strong sm:flex sm:w-[280px]">
            <Search size={16} />
            <span className="flex-1 text-start">{t('search')}…</span>
            <kbd className="tnum rounded-md border border-border bg-surface px-1.5 py-0.5 text-xs text-text-muted">⌘K</kbd>
          </button>

          <div className="ms-auto flex items-center gap-1.5">
            <NotificationCenter />
            <button
              onClick={toggleLocale}
              aria-label="Toggle language"
              className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover"
            >
              {locale === 'ar' ? 'EN' : 'ع'}
            </button>
            <button
              onClick={toggleTheme}
              aria-label="Toggle theme"
              className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover"
            >
              {theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}
            </button>
            <div className="ms-1"><AccountMenu variant="topbar" /></div>
          </div>
        </header>

        <main className="mx-auto w-full max-w-[1320px] flex-1 px-4 pb-24 pt-6 sm:px-6 lg:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
