import { NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  BarChart3,
  Building2,
  FolderKanban,
  FolderOpen,
  Images,
  Inbox,
  LayoutDashboard,
  ListChecks,
  Megaphone,
  Menu,
  MessageSquare,
  Moon,
  PanelLeft,
  Receipt,
  Sun,
  Users,
  X,
} from 'lucide-react'
import { AccountMenu } from '@/features/account/UserMenu'
import { NotificationCenter } from '@/features/notifications/NotificationCenter'
import { fetchMemberships } from '@/features/auth/memberships'
import { useUi } from '@/stores/ui'

/**
 * The agency portal's shell (ADR 0002).
 *
 * A portal, not a skin: the navigation below is the agency's own, and the sections it links to are
 * mounted under `/agency/*` so an operator never leaves their portal mid-journey. The pages behind
 * several of those links are the SAME engines the advertiser portal uses — clients, projects,
 * campaigns, reports — because the business rules must not exist twice. What differs is the boundary:
 * every row is narrowed on the server by the membership's client scope.
 *
 * The header states the agency and, when the operator's membership names specific clients, says so
 * plainly. A partial view that looks like the whole agency is the failure mode this avoids.
 */

const agencyNav = [
  { to: '/agency/dashboard', ar: 'لوحة الوكالة', en: 'Agency overview', icon: LayoutDashboard },
  { to: '/agency/clients', ar: 'العملاء', en: 'Clients', icon: Building2 },
  { to: '/agency/requests', ar: 'الطلبات', en: 'Requests', icon: Inbox },
  { to: '/agency/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban },
  { to: '/agency/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone },
  { to: '/agency/content', ar: 'المحتوى', en: 'Content', icon: Images },
  { to: '/agency/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3 },
  { to: '/agency/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks },
  { to: '/agency/files', ar: 'الملفات', en: 'Files', icon: FolderOpen },
  { to: '/agency/messages', ar: 'المحادثات', en: 'Conversations', icon: MessageSquare },
  { to: '/agency/billing', ar: 'المالية', en: 'Finance', icon: Receipt },
  { to: '/agency/team', ar: 'الفريق والنطاقات', en: 'Team & scopes', icon: Users },
] as const

type NavEntry = (typeof agencyNav)[number]

function NavItems({ ar, collapsed, onNavigate }: { ar: boolean; collapsed?: boolean; onNavigate?: () => void }) {
  const render = (list: readonly NavEntry[]) =>
    list.map(({ to, icon: Icon, ...label }) => (
      <NavLink
        key={to}
        to={to}
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
      <nav aria-label={ar ? 'أقسام الوكالة' : 'Agency sections'} className="flex flex-col gap-1">
        {render(agencyNav)}
      </nav>
      {/* Nothing is listed here before it works. The client portals join this rail when their
          surface lands — a nav entry that leads nowhere is a broken promise, not a roadmap. */}
      <div className="mt-auto" />
    </>
  )
}

/** Names the agency the operator is inside, and admits when the view is a subset of it. */
function AgencyIdentity({ collapsed }: { collapsed?: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const state = useQuery({ queryKey: ['memberships'], queryFn: () => fetchMemberships(), staleTime: 60_000 })
  const current = state.data?.current ?? state.data?.memberships.find((m) => m.portal === 'agency') ?? null
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
          <span data-testid="agency-scope-note" className="block truncate text-[11.5px] text-text-muted">
            {ar ? 'بوابة الوكالة' : 'Agency portal'}
            {scoped && ` · ${ar ? 'عملاء محدّدون' : 'Selected clients'}`}
          </span>
        </div>
      )}
    </div>
  )
}

export function AgencyShell() {
  const { theme, locale, toggleTheme, toggleLocale, sidebarOpen, setSidebarOpen, sidebarCollapsed, toggleSidebarCollapsed } =
    useUi()
  const ar = locale === 'ar'
  const railWidth = sidebarCollapsed ? 'w-[76px]' : 'w-[264px]'

  return (
    <div data-testid="agency-shell" className="flex min-h-screen bg-background text-text-primary">
      <aside
        className={`sticky top-0 hidden h-screen shrink-0 flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 transition-[width] duration-200 md:flex ${railWidth}`}
      >
        <div className="flex items-center justify-between gap-2">
          <AgencyIdentity collapsed={sidebarCollapsed} />
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
              <AgencyIdentity />
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
        </main>
      </div>
    </div>
  )
}
