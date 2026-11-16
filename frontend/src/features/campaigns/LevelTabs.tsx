import { Link, useLocation } from 'react-router-dom'
import { Image as ImageIcon, Layers, Megaphone } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'

/**
 * CAMPAIGN-LEVELS-001 — the three levels of an account as ONE strip: الحملات · المجموعات · الإعلانات.
 *
 * They were three rail entries, and moving from a campaign to its ad groups meant leaving through the rail and starting
 * again — the period, the platform and the drill-in were behind. The strip sits at the top of each level and carries
 * the address's query along (`from`/`to`, `drill`, the platform), so the reader moves DOWN a level with the same
 * question still asked. Nothing here filters: each page reads the query it always read.
 *
 * Plain links in a `tablist`-free nav (each is a page, not a panel), active by path, reachable by keyboard, and
 * scrollable sideways inside itself on a phone rather than pushing the page sideways.
 */
const LEVELS = [
  { key: 'campaigns', path: '/campaigns', ar: 'الحملات', en: 'Campaigns', Icon: Megaphone },
  { key: 'ad-groups', path: '/ad-groups', ar: 'المجموعات', en: 'Ad groups', Icon: Layers },
  { key: 'ads', path: '/ads', ar: 'الإعلانات', en: 'Ads', Icon: ImageIcon },
] as const

export function LevelTabs() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { pathname, search } = useLocation()
  const to = usePortalPath()

  return (
    <nav aria-label={ar ? 'مستويات الحساب' : 'Account levels'} data-testid="campaign-levels" className="-mx-0.5 flex min-w-0 max-w-full gap-1 overflow-x-auto px-0.5 pb-0.5 [scrollbar-width:none]">
      {LEVELS.map(({ key, path, ar: labelAr, en, Icon }) => {
        const href = to(path)
        const active = pathname === href || (key === 'campaigns' && pathname.startsWith(`${href}/`))

        return (
          <Link
            key={key}
            to={`${href}${search}`}
            aria-current={active ? 'page' : undefined}
            data-testid={`campaign-level-${key}`}
            className={`inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-xl px-3 text-sm font-bold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 ${
              active ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]' : 'border border-border bg-surface text-text-secondary hover:bg-surface-hover hover:text-text-primary'
            }`}
          >
            <Icon size={15} aria-hidden />
            {ar ? labelAr : en}
          </Link>
        )
      })}
    </nav>
  )
}
