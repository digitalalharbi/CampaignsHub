import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { useUi } from '@/stores/ui'
import type { NavGroup } from './SidebarNav'
import { navLeaves } from './SidebarNav'

/**
 * REPORT-TITLE-METADATA-001 — the authenticated app never changed its tab title.
 *
 * `index.html` sets «كل حملاتك الإعلانية المدفوعة في مكان واحد — CampaignsHub», the marketing line,
 * and nothing inside `/app` or `/agency` ever touched it again. Every screen in the product — a
 * campaign, analytics, a client, reports — carried that one sentence in its tab, its bookmark and
 * its browser-history entry. An operator with six tabs open cannot tell any of them apart, and the
 * history is six identical rows.
 *
 * The section names come from the RAIL rather than a second table: those labels are already
 * localised, already maintained, and already the words the operator just clicked. A title invented
 * here would be a fourth name for a section that already has one.
 *
 * The match is by longest path prefix, so `/app/campaigns/123/ads` still titles «الحملات» — a
 * detail page belongs to its section, and a tab reading «CampaignsHub» tells the reader nothing
 * that the window's existence did not already tell them.
 */
export const BASE_TITLE = 'CampaignsHub'

export function sectionTitle(
  pathname: string,
  groups: readonly NavGroup[],
  ar: boolean,
): string | null {
  const leaves = navLeaves(groups)

  let best: { to: string; label: string } | null = null

  for (const leaf of leaves) {
    if (pathname !== leaf.to && !pathname.startsWith(`${leaf.to}/`)) {
      continue
    }

    if (best === null || leaf.to.length > best.to.length) {
      best = { to: leaf.to, label: ar ? leaf.ar : leaf.en }
    }
  }

  return best === null ? null : `${best.label} — ${BASE_TITLE}`
}

/**
 * Applies the section title for the current route, and restores the document's own title on the way
 * out — a portal that renamed the tab and left it renamed would follow the reader back to the
 * marketing site.
 */
export function useSectionTitle(groups: readonly NavGroup[]): void {
  const { pathname } = useLocation()
  const ar = useUi((s) => s.locale) === 'ar'

  useEffect(() => {
    const previous = document.title
    const next = sectionTitle(pathname, groups, ar)

    if (next !== null) {
      document.title = next
    }

    return () => {
      document.title = previous
    }
  }, [pathname, groups, ar])
}
