import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  BarChart3,
  Bell,
  LayoutDashboard,
  LogOut,
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
import { logout } from '@/features/auth/api'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { TranslationKey } from '@/lib/i18n'

type NavItem = { to: string; key: TranslationKey; icon: typeof LayoutDashboard }

const operationalNav: NavItem[] = [
  { to: '/', key: 'dashboard', icon: LayoutDashboard },
  { to: '/campaigns', key: 'campaigns', icon: Megaphone },
  { to: '/analytics', key: 'analytics', icon: TrendingUp },
  { to: '/reports', key: 'reports', icon: BarChart3 },
  { to: '/integrations', key: 'integrations', icon: Plug },
]
const utilityNav: NavItem[] = [{ to: '/settings', key: 'settings', icon: Settings }]

function initials(name?: string | null): string {
  if (!name) return '؟'
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')
}

function NavItems({ collapsed, onNavigate }: { collapsed?: boolean; onNavigate?: () => void }) {
  const t = useT()
  const render = (list: NavItem[]) =>
    list.map(({ to, key, icon: Icon }) => (
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

function UserCard({ collapsed }: { collapsed?: boolean }) {
  const { user, setUser } = useAuth()
  const navigate = useNavigate()
  const t = useT()
  const handleLogout = async () => {
    await logout().catch(() => undefined)
    setUser(null)
    navigate('/login', { replace: true })
  }
  return (
    <div className={`mt-3 flex items-center gap-2.5 rounded-xl border border-border bg-surface-secondary p-2 ${collapsed ? 'justify-center' : ''}`}>
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-sm font-bold text-brand-700">
        {initials(user?.name)}
      </div>
      {!collapsed && (
        <>
          <div className="min-w-0 flex-1 leading-tight">
            <div className="truncate text-sm font-semibold text-text-primary">{user?.name}</div>
            <div className="truncate text-xs text-text-muted">{user?.email}</div>
          </div>
          <button
            onClick={handleLogout}
            aria-label={t('sign_out')}
            className="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted transition-colors hover:bg-surface-hover hover:text-danger"
          >
            <LogOut size={16} />
          </button>
        </>
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
        <UserCard collapsed={sidebarCollapsed} />
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
            <UserCard />
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
            <button
              aria-label="Notifications"
              className="relative flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover"
            >
              <Bell size={18} />
              <span className="absolute end-2 top-2 h-2 w-2 rounded-full bg-brand-500 ring-2 ring-surface" />
            </button>
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
          </div>
        </header>

        <main className="mx-auto w-full max-w-[1320px] flex-1 px-4 pb-24 pt-6 sm:px-6 lg:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
