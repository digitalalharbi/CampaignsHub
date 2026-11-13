import { NavLink, useLocation } from 'react-router-dom'
import {
  BadgeCheck, BarChart3, FileText, FolderOpen, Gauge, LayoutDashboard, Megaphone, MessagesSquare,
  MoreHorizontal, Receipt, ScrollText, User,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { clientSpaceBaseOf, useClientSpacePath } from './clientSpace'
import { usePortalVisibility } from './usePortalVisibility'

/**
 * Bilingual labels for the portal pages (self-contained to the portal, Arabic-first).
 *
 * `to` is a SUFFIX, resolved at render against the space the visitor is in — `/portal/acme/invoices`
 * inside a space, `/portal/invoices` in the merged view. Hard-coding a base here would drop a visitor out
 * of their brand's space on the first click.
 *
 * Every page that existed before CLIENT-PORTAL-THREE-SECTIONS-001 is still here, at the same path.
 */
export const PORTAL_NAV: { to: string; icon: LucideIcon; ar: string; en: string; end?: boolean }[] = [
  { to: '', icon: LayoutDashboard, ar: 'نظرة عامة', en: 'Overview', end: true },
  { to: '/campaigns', icon: Megaphone, ar: 'الحملات', en: 'Campaigns' },
  { to: '/reports', icon: BarChart3, ar: 'التقارير', en: 'Reports' },
  { to: '/approvals', icon: BadgeCheck, ar: 'المحتوى', en: 'Content' },
  { to: '/quotes', icon: ScrollText, ar: 'عروض الأسعار', en: 'Quotes' },
  { to: '/requests', icon: FileText, ar: 'الطلبات', en: 'Requests' },
  { to: '/messages', icon: MessagesSquare, ar: 'الرسائل', en: 'Messages' },
  { to: '/files', icon: FolderOpen, ar: 'الملفات', en: 'Files' },
  { to: '/invoices', icon: Receipt, ar: 'الفواتير', en: 'Invoices' },
  { to: '/profile', icon: User, ar: 'الملف الشخصي', en: 'Profile' },
]

/**
 * CLIENT-PORTAL-THREE-SECTIONS-001 — the portal's main structure (Owner, master task 7, 2026-11-13).
 *
 * Three sections a client opens the portal for — how it is going, what needs their yes, what they asked
 * for — and a compact «المزيد» for the paperwork that is looked up rather than watched. Each section's
 * first page is where its tab lands; the rest sit one tap below it in the section row.
 */
export const PORTAL_SECTIONS: { key: string; icon: LucideIcon; ar: string; en: string; pages: string[] }[] = [
  { key: 'performance', icon: Gauge, ar: 'الأداء', en: 'Performance', pages: ['', '/campaigns', '/reports'] },
  { key: 'approvals', icon: BadgeCheck, ar: 'بانتظار اعتمادك', en: 'Awaiting you', pages: ['/approvals', '/quotes'] },
  { key: 'requests', icon: FileText, ar: 'طلباتي', en: 'My requests', pages: ['/requests', '/messages', '/files'] },
  { key: 'more', icon: MoreHorizontal, ar: 'المزيد', en: 'More', pages: ['/invoices', '/profile'] },
]

/**
 * CP-VISIBILITY-001 — which visibility switch each page belongs to. Home and Profile are always the
 * client's own; quotes ride with invoices because they are the same paperwork. Content approvals follow
 * the `content` switch — the server answers the same way.
 */
const SECTION_OF: Record<string, string> = {
  '/requests': 'requests', '/campaigns': 'campaigns', '/reports': 'reports', '/approvals': 'content',
  '/quotes': 'invoices', '/invoices': 'invoices', '/messages': 'messages', '/files': 'files',
}

/** The pages this client's agency has left switched on. */
export function useVisiblePortalNav() {
  const { allows } = usePortalVisibility()

  return PORTAL_NAV.filter((i) => SECTION_OF[i.to] === undefined || allows(SECTION_OF[i.to]))
}

/** The sections with at least one visible page, each carrying only its visible pages. */
export function useVisiblePortalSections() {
  const visible = new Map(useVisiblePortalNav().map((i) => [i.to, i]))

  return PORTAL_SECTIONS.map((s) => ({ ...s, items: s.pages.map((p) => visible.get(p)).filter((i): i is NonNullable<typeof i> => i !== undefined) }))
    .filter((s) => s.items.length > 0)
}

/** The page suffix of a portal path: `/portal/acme/quotes/12` → `/quotes`, `/portal/acme` → ``. */
export function portalPageOf(pathname: string): string {
  // The legacy `/portal/clients/<slug>` form redirects, but is read correctly while it does.
  const clean = pathname.replace(/^\/portal\/clients\//, '/portal/')
  const base = clientSpaceBaseOf(clean)
  const rest = clean.startsWith(base) ? clean.slice(base.length) : clean
  const first = rest.split('/').filter(Boolean)[0]

  return first === undefined ? '' : `/${first}`
}

/** The section a path belongs to — the one that lists its page. */
export function portalSectionOf(pathname: string): string {
  const page = portalPageOf(pathname)

  return PORTAL_SECTIONS.find((s) => s.pages.includes(page))?.key ?? 'performance'
}

/**
 * The section strip (desktop) and the section's own page row (every width).
 *
 * The page row wraps instead of scrolling: three short labels fit a 375px phone, and a row that scrolls
 * sideways hides the page you came for (Owner, compact one-screen rule).
 */
export function PortalNav({ ar }: { ar: boolean }) {
  const spaceTo = useClientSpacePath()
  const sections = useVisiblePortalSections()
  const active = portalSectionOf(useLocation().pathname)

  return (
    <nav className="hidden border-b border-border bg-surface md:block" aria-label={ar ? 'أقسام البوابة' : 'Portal sections'}>
      <div className="mx-auto flex max-w-4xl gap-1 px-2 sm:px-4">
        {sections.map((s) => (
          <NavLink
            key={s.key}
            to={spaceTo(s.items[0].to)}
            end={s.items[0].end}
            data-testid={`portal-section-${s.key}`}
            aria-current={active === s.key ? 'page' : undefined}
            className={`flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-semibold transition-colors ${
              active === s.key ? 'border-brand-500 text-brand-600' : 'border-transparent text-text-secondary hover:text-text-primary'
            }`}
          >
            <s.icon size={15} />
            <span>{ar ? s.ar : s.en}</span>
          </NavLink>
        ))}
      </div>
    </nav>
  )
}

/** The current section's pages, as a compact wrapping row. Hidden when the section has only one page. */
export function PortalSectionPages({ ar }: { ar: boolean }) {
  const spaceTo = useClientSpacePath()
  const sections = useVisiblePortalSections()
  const active = portalSectionOf(useLocation().pathname)
  const section = sections.find((s) => s.key === active)

  if (section === undefined || section.items.length < 2) return null

  return (
    <div data-testid="portal-section-pages" className="mb-3 flex flex-wrap gap-1.5">
      {section.items.map((item) => (
        <NavLink
          key={item.to}
          to={spaceTo(item.to)}
          end={item.end}
          className={({ isActive }) =>
            `flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold ${
              isActive ? 'border-brand-500 bg-brand-primary-soft text-brand-600' : 'border-border text-text-secondary hover:text-text-primary'
            }`
          }
        >
          <item.icon size={13} aria-hidden />
          <span>{ar ? item.ar : item.en}</span>
        </NavLink>
      ))}
    </div>
  )
}
