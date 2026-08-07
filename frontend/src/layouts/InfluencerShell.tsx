import { NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Handshake,
  ListChecks,
  ClipboardCheck,
  Menu,
  Moon,
  PanelLeft,
  Sun,
  Users,
  X,
} from 'lucide-react'
import { AccountMenu } from '@/features/account/UserMenu'
import { NotificationCenter } from '@/features/notifications/NotificationCenter'
import { fetchMemberships } from '@/features/auth/memberships'
import { useUi } from '@/stores/ui'
import { PortalFooter } from '@/features/legal/PolicyFooter'

/**
 * The influencers & UGC portal's shell (ADR 0002, INFL-001).
 *
 * A portal, not a skin. Its two halves have different boundaries and the rail keeps them apart:
 * the ROSTER is tenant-wide, because a creator is not owned by a client and hiding them would only
 * make an account manager re-add people the agency already works with; COLLABORATIONS carry the
 * client, so they narrow with the same client-scope ceiling every other client-bound surface uses.
 *
 * The header says so when the operator's membership names specific clients — a partial view that
 * looks like the whole business is the failure mode this avoids.
 */

const influencerNav = [
  { to: '/influencers', ar: 'التعاونات', en: 'Collaborations', icon: Handshake, end: true },
  { to: '/influencers/roster', ar: 'قائمة المؤثرين', en: 'Creator roster', icon: Users },
  // Sits between the roster and the work, which is where it sits in the actual process: you pick a
  // creator from the roster, somebody answers, and only then is there anything to deliver.
  { to: '/influencers/nominations', ar: 'الترشيحات', en: 'Nominations', icon: ClipboardCheck },
  { to: '/influencers/deliverables', ar: 'المخرجات', en: 'Deliverables', icon: ListChecks },
] as const

type NavEntry = (typeof influencerNav)[number]

function NavItems({ ar, collapsed, onNavigate }: { ar: boolean; collapsed?: boolean; onNavigate?: () => void }) {
  const render = (list: readonly NavEntry[]) =>
    list.map(({ to, icon: Icon, ...label }) => (
      <NavLink
        key={to}
        to={to}
        end={'end' in label ? label.end : undefined}
        onClick={onNavigate}
        title={collapsed ? (ar ? label.ar : label.en) : undefined}
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
            {isActive && <span className="absolute inset-y-2 start-0 w-[3px] rounded-full bg-brand-600" aria-hidden />}
            <Icon size={19} strokeWidth={isActive ? 2.4 : 2} aria-hidden />
            {!collapsed && <span>{ar ? label.ar : label.en}</span>}
          </>
        )}
      </NavLink>
    ))

  return (
    <>
      <nav aria-label={ar ? 'أقسام المؤثرين' : 'Influencer sections'} className="flex flex-col gap-1">
        {render(influencerNav)}
      </nav>
      {/* Nothing is listed here before it works — a nav entry that leads nowhere is a broken
          promise, not a roadmap. */}
      <div className="mt-auto" />
    </>
  )
}

/** Names the agency the operator is inside, and admits when the view is a subset of it. */
function InfluencerIdentity({ collapsed }: { collapsed?: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const state = useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })
  const current = state.data?.current ?? state.data?.memberships.find((m) => m.portal === 'influencers') ?? null
  const scoped = (current?.client_scope_ids?.length ?? 0) > 0

  return (
    <div className={`flex items-center gap-2.5 ${collapsed ? 'justify-center' : 'px-1'}`}>
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-[var(--shadow-small)]">
        <Users size={18} />
      </div>
      {!collapsed && (
        <div className="min-w-0">
          <span className="block truncate font-heading text-[15px] font-extrabold tracking-tight text-text-primary">
            {current?.tenant.name ?? 'CampaignsHub'}
          </span>
          <span data-testid="influencer-scope-note" className="block truncate text-[11.5px] text-text-muted">
            {ar ? 'بوابة المؤثرين وUGC' : 'Influencers & UGC'}
            {scoped && ` · ${ar ? 'عملاء محدّدون' : 'Selected clients'}`}
          </span>
        </div>
      )}
    </div>
  )
}

export function InfluencerShell() {
  const { theme, locale, toggleTheme, toggleLocale, sidebarOpen, setSidebarOpen, sidebarCollapsed, toggleSidebarCollapsed } =
    useUi()
  const ar = locale === 'ar'
  const railWidth = sidebarCollapsed ? 'w-[76px]' : 'w-[264px]'

  return (
    <div data-testid="influencer-shell" className="flex min-h-screen bg-background text-text-primary">
      <aside
        className={`sticky top-0 hidden h-screen shrink-0 flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 transition-[width] duration-200 md:flex ${railWidth}`}
      >
        <div className="flex items-center justify-between gap-2">
          <InfluencerIdentity collapsed={sidebarCollapsed} />
          {!sidebarCollapsed && (
            <button
              onClick={toggleSidebarCollapsed}
              aria-label={ar ? 'طي القائمة' : 'Collapse sidebar'}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-muted transition-colors hover:bg-surface-hover hover:text-text-primary"
            >
              <PanelLeft size={17} />
            </button>
          )}
        </div>
        {sidebarCollapsed && (
          <button
            onClick={toggleSidebarCollapsed}
            aria-label={ar ? 'توسيع القائمة' : 'Expand sidebar'}
            className="mx-auto flex h-8 w-8 items-center justify-center rounded-lg text-text-muted transition-colors hover:bg-surface-hover hover:text-text-primary"
          >
            <PanelLeft size={17} className="rotate-180" />
          </button>
        )}
        <NavItems ar={ar} collapsed={sidebarCollapsed} />
        <AccountMenu variant="sidebar" collapsed={sidebarCollapsed} />
      </aside>

      {sidebarOpen && (
        <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onClick={() => setSidebarOpen(false)} />
          <aside className="absolute inset-y-0 start-0 flex h-full w-[280px] max-w-[82vw] flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 shadow-[var(--shadow-large)]">
            <div className="flex items-center justify-between gap-2">
              <InfluencerIdentity />
              <button
                onClick={() => setSidebarOpen(false)}
                aria-label={ar ? 'إغلاق' : 'Close'}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover"
              >
                <X size={18} />
              </button>
            </div>
            <NavItems ar={ar} onNavigate={() => setSidebarOpen(false)} />
            <AccountMenu variant="sidebar" />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 flex items-center gap-3 border-b border-border bg-surface/85 px-4 py-2.5 backdrop-blur-md sm:px-6">
          <button
            onClick={() => setSidebarOpen(true)}
            aria-label={ar ? 'فتح القائمة' : 'Open menu'}
            className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover md:hidden"
          >
            <Menu size={19} />
          </button>

          <div className="ms-auto flex items-center gap-1.5">
            <NotificationCenter />
            <button
              onClick={toggleLocale}
              aria-label="Toggle language"
              className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover"
            >
              {ar ? 'EN' : 'ع'}
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

        <main className="mx-auto w-full max-w-[1440px] flex-1 px-4 pb-12 pt-4 sm:px-5 lg:px-6">
          <Outlet />
          <PortalFooter />
        </main>
      </div>
    </div>
  )
}
