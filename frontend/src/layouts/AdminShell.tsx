import { NavLink, Outlet } from 'react-router-dom'
import {
  ScrollText,
  Settings,
  ShieldCheck,
  Building2,
  LayoutDashboard,
  Menu,
  Moon,
  PanelLeft,
  Sun,
  X,
} from 'lucide-react'
import { AccountMenu } from '@/features/account/UserMenu'
import { NotificationCenter } from '@/features/notifications/NotificationCenter'
import { useUi } from '@/stores/ui'

/**
 * The platform owner's shell (ADR 0002, ADMIN-001).
 *
 * Visually distinct on purpose — a slate mark rather than the brand green — because the one thing
 * that must never happen here is mistaking this for a workspace. Every other portal administers its
 * own data; this one can suspend a customer.
 *
 * It is NOT a copy of a tenant's dashboard with different rows. The owner's job is tenants, access,
 * plans and the trail; campaigns, clients and reports are deliberately absent.
 */

/**
 * Four entries, not fourteen. The owner's console administers the platform; it is not a second copy
 * of a tenant's workspace, and the structure rule is a maximum of two levels — so related things are
 * grouped behind tabs on one page rather than given a rail entry each.
 */
const adminNav = [
  { to: '/admin', ar: 'نظرة عامة', en: 'Overview', icon: LayoutDashboard, end: true },
  { to: '/admin/tenants', ar: 'المستأجرون', en: 'Tenants', icon: Building2 },
  { to: '/admin/settings', ar: 'إعدادات النظام', en: 'System settings', icon: Settings },
  { to: '/admin/audit', ar: 'السجلات والتدقيق', en: 'Logs & audit', icon: ScrollText },
] as const

type NavEntry = (typeof adminNav)[number]

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
      <nav aria-label={ar ? 'أقسام إدارة المنصة' : 'Platform sections'} className="flex flex-col gap-1">
        {render(adminNav)}
      </nav>

      <div className="mt-auto" />
    </>
  )
}

/**
 * Says plainly WHERE the viewer is, because this console can change every customer's access and
 * mistaking it for a workspace is the expensive error. There is no membership to read here — the
 * owner belongs to no tenant, which is the point.
 */
function PlatformIdentity({ collapsed }: { collapsed?: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <div className={`flex items-center gap-2.5 ${collapsed ? 'justify-center' : 'px-1'}`}>
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-slate-700 to-slate-900 text-white shadow-[var(--shadow-small)]">
        <ShieldCheck size={18} />
      </div>
      {!collapsed && (
        <div className="min-w-0">
          <span className="block truncate font-heading text-[15px] font-extrabold tracking-tight text-text-primary">
            CampaignsHub
          </span>
          <span data-testid="admin-scope-note" className="block truncate text-[11.5px] text-text-muted">
            {ar ? 'إدارة المنصة' : 'Platform administration'}
          </span>
        </div>
      )}
    </div>
  )
}

export function AdminShell() {
  const { theme, locale, toggleTheme, toggleLocale, sidebarOpen, setSidebarOpen, sidebarCollapsed, toggleSidebarCollapsed } =
    useUi()
  const ar = locale === 'ar'
  const railWidth = sidebarCollapsed ? 'w-[76px]' : 'w-[264px]'

  return (
    <div data-testid="admin-shell" className="flex min-h-screen bg-background text-text-primary">
      <aside
        className={`sticky top-0 hidden h-screen shrink-0 flex-col gap-6 overflow-y-auto border-e border-border bg-surface p-3.5 transition-[width] duration-200 md:flex ${railWidth}`}
      >
        <div className="flex items-center justify-between gap-2">
          <PlatformIdentity collapsed={sidebarCollapsed} />
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
              <PlatformIdentity />
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
