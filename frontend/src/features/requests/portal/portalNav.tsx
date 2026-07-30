import { NavLink } from 'react-router-dom'
import {
  FileText, FolderOpen, LayoutDashboard, Megaphone, MessagesSquare,
  Receipt, ScrollText, User, BarChart3,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useClientSpacePath } from './clientSpace'

/**
 * Bilingual labels for the portal sections (self-contained to the portal, Arabic-first).
 *
 * `to` is a SUFFIX, resolved at render against the space the visitor is in — `/client/invoices` when
 * they are in the shared view, `/portal/clients/acme/invoices` inside a space. Hard-coding `/client`
 * here would drop a visitor out of their brand's space on the first click.
 */
export const PORTAL_NAV: { to: string; icon: LucideIcon; ar: string; en: string; end?: boolean }[] = [
  { to: '', icon: LayoutDashboard, ar: 'الرئيسية', en: 'Dashboard', end: true },
  { to: '/requests', icon: FileText, ar: 'الطلبات', en: 'Requests' },
  { to: '/quotes', icon: ScrollText, ar: 'عروض الأسعار', en: 'Quotes' },
  { to: '/invoices', icon: Receipt, ar: 'الفواتير', en: 'Invoices' },
  { to: '/messages', icon: MessagesSquare, ar: 'الرسائل', en: 'Messages' },
  { to: '/campaigns', icon: Megaphone, ar: 'الحملات', en: 'Campaigns' },
  { to: '/files', icon: FolderOpen, ar: 'الملفات', en: 'Files' },
  { to: '/reports', icon: BarChart3, ar: 'التقارير', en: 'Reports' },
  { to: '/profile', icon: User, ar: 'الملف الشخصي', en: 'Profile' },
]

/** Horizontal, scrollable section nav shown under the portal header on every authenticated page. */
export function PortalNav({ ar }: { ar: boolean }) {
  const spaceTo = useClientSpacePath()

  return (
    <nav className="border-b border-border bg-surface">
      <div className="mx-auto flex max-w-4xl gap-1 overflow-x-auto px-2 sm:px-4">
        {PORTAL_NAV.map((item) => (
          <NavLink
            key={item.to}
            to={spaceTo(item.to)}
            end={item.end}
            className={({ isActive }) =>
              `flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-3 text-sm font-semibold transition-colors ${
                isActive
                  ? 'border-brand-500 text-brand-600'
                  : 'border-transparent text-text-secondary hover:text-text-primary'
              }`
            }
          >
            <item.icon size={15} />
            <span>{ar ? item.ar : item.en}</span>
          </NavLink>
        ))}
      </div>
    </nav>
  )
}
