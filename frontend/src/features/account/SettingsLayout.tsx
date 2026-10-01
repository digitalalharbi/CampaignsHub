import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { ExternalLink, Palette, Plug } from 'lucide-react'
import { SETTINGS_TABS } from '@/features/settings/SettingsPage'
import { useUi } from '@/stores/ui'

/**
 * SYSTEM settings shell (/settings/*) — the sidebar entry. Workspace-wide configuration ONLY.
 * Personal settings (profile, password, security/sessions, language & appearance, personal
 * notifications) live under /account and are reachable ONLY from the account menu — never duplicated here.
 */
/**
 * SETTINGS-ONE-NAV-001 — the workspace's sections, listed HERE and nowhere else.
 *
 * `/settings/workspace` is one page holding five sections behind a `?tab=`, and it used to draw its
 * own list of them. Beside this one. So a reader opening settings met two navigations in the same
 * place for the same thing: «الإعدادات العامة» in this column and «عام» in the page's, both opening
 * the same screen, with «العملاء», «المشاريع», «الإشعارات» and «الأمان» reachable only from the
 * second — which made this column look like it was hiding most of settings.
 *
 * The sections come from `SETTINGS_TABS`, the page's own list, so the two cannot disagree about what
 * exists or what it is called. SETTINGS-TAB-ADDRESS-001 already made the tab part of the address,
 * which is what lets them be links at all.
 */
const WORKSPACE_SECTIONS = ['general', 'clients', 'projects', 'notifications', 'security'] as const

const GROUPS = [
  {
    ar: 'مساحة العمل', en: 'Workspace',
    items: [
      ...WORKSPACE_SECTIONS.map((id) => {
        const tab = SETTINGS_TABS.find((t) => t.id === id)!

        return { to: `/settings/workspace?tab=${id}`, tab: id, ar: tab.ar, en: tab.en, icon: tab.icon }
      }),
      {
        to: '/settings/permissions',
        ar: SETTINGS_TABS.find((t) => t.id === 'team')!.ar,
        en: SETTINGS_TABS.find((t) => t.id === 'team')!.en,
        icon: SETTINGS_TABS.find((t) => t.id === 'team')!.icon,
      },
    ],
  },
  {
    ar: 'الهوية والمحتوى', en: 'Identity & content',
    items: [
      { to: '/settings/branding', ar: 'الهوية', en: 'Brand identity', icon: Palette },
      /*
       * PAGES-001, applied to the two entries it missed.
       *
       * «التصنيفات والخيارات» and «ملاحظات البوابات» both route to `<Navigate to="/admin/settings">`,
       * and a tenant cannot enter `/admin` — so the portal guard turned them around and dropped the
       * reader on the dashboard. Clicking a settings tab and landing on the dashboard reads as the
       * app losing your place, not as a refusal.
       *
       * The reasoning above already removed «الواجهة الرئيسية والبوابات» for exactly this, and these
       * two share its destination. They belong to the platform console, where its own navigation
       * lists them.
       */
    ],
  },
  {
    ar: 'الاتصال الخارجي', en: 'External surfaces',
    items: [
      /*
       * PAGES-001 — «الواجهة الرئيسية والبوابات» is not listed here any more.
       *
       * There is one marketing homepage and it belongs to the platform operator, so the entry pointed
       * at a path that redirects into `/admin` — a console a tenant administrator cannot enter. A link
       * whose only outcome is a refusal is a dead link with a label on it.
       */
      { to: '/app/integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug, external: true },
    ],
  },
] as const

export function SettingsLayout() {
  const ar = useUi((s) => s.locale) === 'ar'
  const location = useLocation()
  /*
   * Which section is open, read from the address rather than from `NavLink`.
   *
   * `NavLink` decides `isActive` from the PATH alone, and all five workspace sections share one
   * path — so every one of them would light up at once. The page falls back to its first tab when
   * `?tab=` is absent, and this agrees with it.
   */
  const openTab = new URLSearchParams(location.search).get('tab') ?? 'general'

  return (
    <div className="grid w-full grid-cols-1 gap-6 lg:grid-cols-[240px_1fr]">
      {/*
        SETTINGS-MOBILE-OVERFLOW-001 — the settings page scrolled sideways on a phone.

        The row of section tabs already had `overflow-x-auto` to scroll within itself, and it could
        not: a grid item's `min-width` defaults to `auto`, so this nav sized itself to its widest
        content — 411px inside a 375px viewport — and took the whole page with it. `min-w-0` is what
        lets the declared overflow actually clip, on the nav and on the scroller inside it.
      */}
      <nav aria-label={ar ? 'إعدادات النظام' : 'System settings'} className="min-w-0 lg:sticky lg:top-20 lg:h-fit lg:self-start">
        <div className="flex min-w-0 gap-3 overflow-x-auto pb-1 lg:flex-col lg:gap-4 lg:overflow-visible lg:pb-0">
          {GROUPS.map((group) => (
            <div key={group.en} className="flex shrink-0 gap-1 lg:flex-col">
              <span className="hidden px-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-text-muted lg:block">
                {ar ? group.ar : group.en}
              </span>
              {group.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end
                  className={({ isActive }) =>
                    `flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                      (('tab' in item ? isActive && item.tab === openTab : isActive) && !('external' in item))
                        ? 'bg-brand-primary-soft font-semibold text-brand-700'
                        : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
                    }`
                  }
                >
                  <item.icon size={16} className="shrink-0" />
                  <span className="flex-1">{ar ? item.ar : item.en}</span>
                  {'external' in item && <ExternalLink size={12} className="shrink-0 opacity-60" />}
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
