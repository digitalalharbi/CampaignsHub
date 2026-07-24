import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  BarChart3,
  LayoutDashboard,
  LogOut,
  Megaphone,
  Moon,
  Plug,
  Settings,
  Sun,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { logout } from '@/features/auth/api'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { TranslationKey } from '@/lib/i18n'

type NavItem = { to: string; key: TranslationKey; icon: typeof LayoutDashboard }

import { ProjectSwitcher } from '@/components/ProjectSwitcher'

// Primary media-buying operational navigation.
const operationalNav: NavItem[] = [
  { to: '/', key: 'overview', icon: LayoutDashboard },
  { to: '/campaigns', key: 'campaigns', icon: Megaphone },
  { to: '/reports', key: 'reports', icon: BarChart3 },
  { to: '/integrations', key: 'integrations', icon: Plug },
]

const utilityNav: NavItem[] = [
  { to: '/settings', key: 'settings', icon: Settings },
]

const linkClass = ({ isActive }: { isActive: boolean }) =>
  `relative flex items-center gap-3 rounded-[9px] px-3 py-2 text-[13px] font-semibold transition-colors ${
    isActive
      ? 'bg-[var(--brand-background)] text-brand-600 before:absolute before:inset-y-1.5 before:start-0 before:w-[3px] before:rounded-full before:bg-brand-600'
      : 'text-text-secondary hover:bg-surface-secondary'
  }`

export function AppShell() {
  const t = useT()
  const navigate = useNavigate()
  const { theme, locale, toggleTheme, toggleLocale } = useUi()
  const { user, setUser } = useAuth()
  // const { salesCrmEnabled } = useFeatures() // unused

  const handleLogout = async () => {
    await logout().catch(() => undefined)
    setUser(null)
    navigate('/login', { replace: true })
  }

  const renderItems = (items: NavItem[]) =>
    items.map(({ to, key, icon: Icon }) => (
      <NavLink key={to} to={to} end={to === '/'} className={linkClass}>
        <Icon size={17} aria-hidden />
        {t(key)}
      </NavLink>
    ))

  return (
    <div className="flex min-h-screen bg-background text-text-primary">
      <aside className="sticky top-0 hidden h-screen w-[250px] shrink-0 flex-col overflow-y-auto border-e border-border bg-surface p-4 md:flex">
        <div className="mb-6 flex items-center gap-2 px-1">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white shadow-sm">
            <Megaphone size={16} />
          </div>
          <span className="font-[var(--font-heading)] text-lg font-extrabold tracking-tight text-text-primary">CampaignsHub</span>
        </div>

        <div className="mb-6">
          <ProjectSwitcher />
        </div>

        <nav className="flex flex-col gap-1">{renderItems(operationalNav)}</nav>

        <div className="mt-auto pt-4">
          <div className="mb-4 border-t border-border" />
          <nav className="flex flex-col gap-1">{renderItems(utilityNav)}</nav>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 flex items-center gap-3 border-b border-border bg-surface px-6 py-3 shadow-[var(--shadow-small)]">
          <span className="font-[var(--font-heading)] text-sm font-bold md:hidden">{t('app_name')}</span>
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

        <main className="mx-auto w-full max-w-[1240px] px-6 pb-20 pt-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
