import { NavLink, Outlet } from 'react-router-dom'
import {
  BarChart3,
  LayoutDashboard,
  Megaphone,
  Moon,
  Palette,
  Settings,
  Sun,
  Users,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'
import type { TranslationKey } from '@/lib/i18n'

const nav: { to: string; key: TranslationKey; icon: typeof LayoutDashboard }[] = [
  { to: '/', key: 'dashboard', icon: LayoutDashboard },
  { to: '/clients', key: 'clients', icon: Users },
  { to: '/campaigns', key: 'campaigns', icon: Megaphone },
  { to: '/reports', key: 'reports', icon: BarChart3 },
  { to: '/design', key: 'design', icon: Palette },
  { to: '/settings', key: 'settings', icon: Settings },
]

export function AppShell() {
  const t = useT()
  const { theme, locale, toggleTheme, toggleLocale } = useUi()

  return (
    <div className="flex min-h-screen bg-background text-text-primary">
      {/* Sidebar */}
      <aside className="sticky top-0 hidden h-screen w-[250px] shrink-0 overflow-y-auto border-e border-border bg-surface p-2.5 md:block">
        <div className="px-2 py-3">
          <span className="font-[var(--font-heading)] text-sm font-extrabold text-brand-600">
            {t('app_name')}
          </span>
        </div>
        <nav className="mt-2 flex flex-col gap-1">
          {nav.map(({ to, key, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/'}
              className={({ isActive }) =>
                `relative flex items-center gap-3 rounded-[9px] px-3 py-2 text-[13px] font-semibold transition-colors ${
                  isActive
                    ? 'bg-[var(--brand-background)] text-brand-600 before:absolute before:inset-y-1.5 before:start-0 before:w-[3px] before:rounded-full before:bg-brand-600'
                    : 'text-text-secondary hover:bg-surface-secondary'
                }`
              }
            >
              <Icon size={17} aria-hidden />
              {t(key)}
            </NavLink>
          ))}
        </nav>
      </aside>

      {/* Main */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 flex items-center gap-3 border-b border-border bg-surface px-6 py-3 shadow-[var(--shadow-small)]">
          <span className="font-[var(--font-heading)] text-sm font-bold md:hidden">
            {t('app_name')}
          </span>
          <div className="ms-auto flex items-center gap-2">
            <Button variant="secondary" onClick={toggleLocale} aria-label="Toggle language">
              {locale === 'ar' ? 'EN' : 'ع'}
            </Button>
            <Button variant="secondary" onClick={toggleTheme} aria-label="Toggle theme">
              {theme === 'light' ? <Moon size={16} /> : <Sun size={16} />}
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
