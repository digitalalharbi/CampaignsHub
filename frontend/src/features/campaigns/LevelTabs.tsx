import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Building2, Image as ImageIcon, Layers, LayoutDashboard, Megaphone } from 'lucide-react'
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
  { key: 'accounts', path: '/accounts', ar: 'الحسابات', en: 'Accounts', Icon: Building2 },
  { key: 'campaigns', path: '/campaigns', ar: 'الحملات', en: 'Campaigns', Icon: Megaphone },
  { key: 'ad-groups', path: '/ad-groups', ar: 'المجموعات', en: 'Ad groups', Icon: Layers },
  { key: 'ads', path: '/ads', ar: 'الإعلانات', en: 'Ads', Icon: ImageIcon },
] as const

export function LevelTabs() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { pathname, search } = useLocation()
  const to = usePortalPath()
  const navigate = useNavigate()
  const onCampaigns = pathname === to('/campaigns')
  /*
   * Owner 2026-11-24 — from the advanced view, opening Accounts, Ad groups or Ads left the way back to the simple view
   * behind on the campaigns page. Every level of the advanced view carries it, and it lands on the simple Ads Manager.
   */
  const toSimple = () => {
    try { localStorage.setItem('ch-campaigns-layout', 'simple') } catch { /* a private window keeps the choice for this visit */ }
    navigate(`${to('/campaigns')}${search}`)
  }

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
      {!onCampaigns && (
        <button type="button" onClick={toSimple} data-testid="levels-simple-view"
          className="ms-auto inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-xl border border-border bg-surface px-3 text-sm font-bold text-text-secondary hover:bg-surface-hover hover:text-text-primary">
          <LayoutDashboard size={15} aria-hidden />
          {ar ? 'العرض المبسّط' : 'Simple view'}
        </button>
      )}
    </nav>
  )
}
