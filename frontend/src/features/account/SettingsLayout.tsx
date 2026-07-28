import { NavLink, Outlet } from 'react-router-dom'
import { Bell, Building2, Palette, Shield, SlidersHorizontal, Tags, User as UserIcon } from 'lucide-react'
import { useT } from '@/lib/i18n'

const SECTIONS = [
  { key: 'menu_profile', to: '/settings/profile', icon: UserIcon },
  { key: 'menu_password', to: '/settings/password', icon: Shield },
  { key: 'menu_security', to: '/settings/security', icon: Shield },
  { key: 'menu_notifications', to: '/settings/notifications', icon: Bell },
  { key: 'menu_preferences', to: '/settings/preferences', icon: SlidersHorizontal },
  { key: 'menu_appearance', to: '/settings/preferences', icon: Palette },
  { key: 'menu_workspace', to: '/settings/workspace', icon: Building2 },
  { key: 'menu_taxonomies', to: '/settings/taxonomies', icon: Tags },
] as const

/** Two-column settings shell: section nav + the active section (Outlet). */
export function SettingsLayout() {
  const t = useT()
  return (
    <div className="mx-auto grid w-full max-w-5xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[220px_1fr]">
      <nav className="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible" aria-label="Settings">
        {SECTIONS.map(({ key, to, icon: Icon }) => (
          <NavLink
            key={key + to}
            to={to}
            end
            className={({ isActive }) =>
              `flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                isActive ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
              }`
            }
          >
            <Icon size={16} className="shrink-0" />
            {t(key)}
          </NavLink>
        ))}
      </nav>
      <div className="min-w-0">
        <Outlet />
      </div>
    </div>
  )
}
