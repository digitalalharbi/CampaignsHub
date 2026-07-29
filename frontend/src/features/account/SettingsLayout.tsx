import { NavLink, Outlet } from 'react-router-dom'
import { Bell, Building2, KeyRound, Shield, SlidersHorizontal, Tags, User as UserIcon } from 'lucide-react'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

/**
 * Settings sections, grouped by WHAT they configure. Each entry is a distinct route — the previous list had
 * «التفضيلات» and «اللغة والمظهر» pointing at the SAME route, a duplicate that read as randomness.
 */
const GROUPS = [
  {
    ar: 'حسابي', en: 'My account',
    items: [
      { key: 'menu_profile', to: '/settings/profile', icon: UserIcon },
      { key: 'menu_preferences', to: '/settings/preferences', icon: SlidersHorizontal },
      { key: 'menu_notifications', to: '/settings/notifications', icon: Bell },
    ],
  },
  {
    ar: 'الأمان', en: 'Security',
    items: [
      { key: 'menu_password', to: '/settings/password', icon: KeyRound },
      { key: 'menu_security', to: '/settings/security', icon: Shield },
    ],
  },
  {
    ar: 'مساحة العمل', en: 'Workspace',
    items: [
      { key: 'menu_workspace', to: '/settings/workspace', icon: Building2 },
      { key: 'menu_taxonomies', to: '/settings/taxonomies', icon: Tags },
    ],
  },
] as const

/**
 * Two-column settings shell. The section nav is STICKY on desktop, so a long section (taxonomies, security
 * sessions) never forces the user to scroll back up to switch sections.
 */
export function SettingsLayout() {
  const t = useT()
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <div className="grid w-full gap-6 lg:grid-cols-[232px_1fr]">
      <nav aria-label="Settings" className="lg:sticky lg:top-20 lg:h-fit lg:self-start">
        <div className="flex gap-3 overflow-x-auto pb-1 lg:flex-col lg:gap-4 lg:overflow-visible lg:pb-0">
          {GROUPS.map((group) => (
            <div key={group.en} className="flex shrink-0 gap-1 lg:flex-col">
              <span className="hidden px-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-text-muted lg:block">
                {ar ? group.ar : group.en}
              </span>
              {group.items.map(({ key, to, icon: Icon }) => (
                <NavLink
                  key={to}
                  to={to}
                  end
                  className={({ isActive }) =>
                    `flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                      isActive
                        ? 'bg-brand-primary-soft font-semibold text-brand-700'
                        : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
                    }`
                  }
                >
                  <Icon size={16} className="shrink-0" />
                  {t(key)}
                </NavLink>
              ))}
            </div>
          ))}
        </div>
      </nav>
      <div className="min-w-0">
        <Outlet />
      </div>
    </div>
  )
}
