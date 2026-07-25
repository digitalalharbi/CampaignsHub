import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  BarChart3,
  LayoutDashboard,
  LogOut,
  Megaphone,
  Menu,
  Moon,
  Plug,
  Settings,
  Sun,
  X,
} from 'lucide-react'
import { ProjectSwitcher } from '@/components/ProjectSwitcher'
import { Button } from '@/components/ui/Button'
import { logout } from '@/features/auth/api'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { TranslationKey } from '@/lib/i18n'

type NavItem = { to: string; key: TranslationKey; icon: typeof LayoutDashboard }

// Primary media-buying operational navigation.
const operationalNav: NavItem[] = [
  { to: '/', key: 'overview', icon: LayoutDashboard },
  { to: '/campaigns', key: 'campaigns', icon: Megaphone },
  { to: '/reports', key: 'reports', icon: BarChart3 },
  { to: '/integrations', key: 'integrations', icon: Plug },
]

const utilityNav: NavItem[] = [{ to: '/settings', key: 'settings', icon: Settings }]

const linkClass = ({ isActive }: { isActive: boolean }) =>
  `relative flex items-center gap-3 rounded-[9px] px-3 py-2 text-[13px] font-semibold transition-colors ${
    isActive
      ? 'bg-[var(--brand-background)] text-brand-600 before:absolute before:inset-y-1.5 before:start-0 before:w-[3px] before:rounded-full before:bg-brand-600'
      : 'text-text-secondary hover:bg-surface-secondary'
  }`

/** Sidebar contents (brand + project switcher + nav). Shared between the desktop rail and the mobile drawer. */
function SidebarInner({ onNavigate }: { onNavigate?: () => void }) {
  const t = useT()
  const items = (list: NavItem[]) =>
    list.map(({ to, key, icon: Icon }) => (
      <NavLink key={to} to={to} end={to === '/'} className={linkClass} onClick={onNavigate}>
        <Icon size={17} aria-hidden />
        {t(key)}
      </NavLink>
    ))

  return (
    <>
      <div className="mb-6 flex items-center gap-2 px-1">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white shadow-sm">
          <Megaphone size={16} />
        </div>
        <span className="font-[var(--font-heading)] text-lg font-extrabold tracking-tight text-text-primary">
          CampaignsHub
        </span>
      </div>

      <div className="mb-6">
        <ProjectSwitcher />
      </div>

      <nav className="flex flex-col gap-1">{items(operationalNav)}</nav>

      <div className="mt-auto pt-4">
        <div className="mb-4 border-t border-border" />
        <nav className="flex flex-col gap-1">{items(utilityNav)}</nav>
      </div>
    </>
  )
}

export function AppShell() {
  const t = useT()
  const navigate = useNavigate()
  const { theme, locale, toggleTheme, toggleLocale, sidebarOpen, setSidebarOpen } = useUi()
  const { user, setUser } = useAuth()

  const handleLogout = async () => {
    await logout().catch(() => undefined)
    setUser(null)
    navigate('/login', { replace: true })
  }

  return (
    <div className="flex min-h-screen bg-background text-text-primary">
      {/* Desktop rail. */}
      <aside className="sticky top-0 hidden h-screen w-[250px] shrink-0 flex-col overflow-y-auto border-e border-border bg-surface p-4 md:flex">
        <SidebarInner />
      </aside>

      {/* Mobile drawer — opened by the header menu button; keeps the nav + switcher usable on small screens. */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true">
          <div className="absolute inset-0 bg-black/40" onClick={() => setSidebarOpen(false)} />
          <aside className="absolute inset-y-0 start-0 flex h-full w-[260px] max-w-[80vw] flex-col overflow-y-auto border-e border-border bg-surface p-4 shadow-lg">
            <div className="mb-2 flex justify-end">
              <Button variant="ghost" onClick={() => setSidebarOpen(false)} aria-label={t('cancel')}>
                <X size={18} />
              </Button>
            </div>
            <SidebarInner onNavigate={() => setSidebarOpen(false)} />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 flex items-center gap-3 border-b border-border bg-surface px-4 py-3 shadow-[var(--shadow-small)] sm:px-6">
          <Button
            variant="ghost"
            className="md:hidden"
            onClick={() => setSidebarOpen(true)}
            aria-label="Open menu"
          >
            <Menu size={18} />
          </Button>
          {/* Brand also lives in the drawer/sidebar; hide it on the narrowest screens so the header fits. */}
          <span className="hidden font-[var(--font-heading)] text-sm font-bold sm:inline md:hidden">{t('app_name')}</span>
          <div className="ms-auto flex items-center gap-2">
            {user && (
              <span className="hidden text-[12px] text-text-secondary sm:inline" title={user.email}>
                {user.name}
              </span>
            )}
            <Button variant="secondary" onClick={toggleLocale} aria-label="Toggle language">
              {locale === 'ar' ? 'EN' : 'ع'}
            </Button>
            <Button variant="secondary" onClick={toggleTheme} aria-label="Toggle theme">
              {theme === 'light' ? <Moon size={16} /> : <Sun size={16} />}
            </Button>
            <Button variant="ghost" onClick={handleLogout} aria-label={t('sign_out')}>
              <LogOut size={16} />
            </Button>
          </div>
        </header>

        <main className="mx-auto w-full max-w-[1240px] px-4 pb-20 pt-6 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
