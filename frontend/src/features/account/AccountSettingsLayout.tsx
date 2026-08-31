import { NavLink, Outlet } from 'react-router-dom'
import { Bell, KeyRound, Palette, ShieldCheck, User as UserIcon } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { PolicyNote } from '@/features/legal/PolicyFooter'

/**
 * USER settings shell (/account/*) — reachable ONLY from the account menu in the top bar.
 * System/workspace settings live under /settings and are never duplicated here.
 */
const ITEMS = [
  { to: '/account/profile', ar: 'الملف التعريفي', en: 'Profile', icon: UserIcon },
  { to: '/account/password', ar: 'كلمة المرور', en: 'Password', icon: KeyRound },
  { to: '/account/security', ar: 'الأمان والجلسات', en: 'Security & sessions', icon: ShieldCheck },
  { to: '/account/preferences', ar: 'اللغة والمظهر', en: 'Language & appearance', icon: Palette },
  { to: '/account/notifications', ar: 'الإشعارات الشخصية', en: 'Personal notifications', icon: Bell },
] as const

export function AccountSettingsLayout() {
  const ar = useUi((s) => s.locale) === 'ar'

  /*
   * SETTINGS-MOBILE-OVERFLOW-001 — the same two-part fix as the workspace settings layout.
   *
   * With no explicit column below `lg:`, the implicit grid track is content-sized, so a child wider
   * than the viewport — here a `min-w-[560px]` table on the notifications page — widened the track
   * and carried the whole page sideways. `grid-cols-1` is `minmax(0, 1fr)` in Tailwind, which bounds
   * the track; `min-w-0` then lets the nav's own `overflow-x-auto` clip instead of being sized by
   * its content. Neither alone is enough, which is why the first attempt at this only half-worked.
   */
  return (
    <div className="grid w-full grid-cols-1 gap-6 lg:grid-cols-[240px_1fr]">
      <nav aria-label={ar ? 'إعدادات المستخدم' : 'User settings'} className="min-w-0 lg:sticky lg:top-20 lg:h-fit lg:self-start">
        <span className="mb-2 hidden px-3 text-[11px] font-bold uppercase tracking-wide text-text-muted lg:block">
          {ar ? 'إعدادات حسابي' : 'My account'}
        </span>
        <div className="flex min-w-0 gap-1 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
          {ITEMS.map(({ to, ar: labelAr, en, icon: Icon }) => (
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
              {ar ? labelAr : en}
            </NavLink>
          ))}
        </div>
      </nav>
      <div className="min-w-0 space-y-4">
        <Outlet />
        {/*
          POLICY-PLACEMENT-001 — «what happens to my data, and how do I get it out», beside the
          account it is about. These four used to be on the marketing footer, in front of a visitor
          who has no account at all.
        */}
        <PolicyNote context="account" />
      </div>
    </div>
  )
}
