import { describe, expect, it } from 'vitest'
import { appNavGroups } from './appNav'
import { agencyNavGroups } from './agencyNav'
import type { NavGroup } from './SidebarNav'

/**
 * NAV-IA-001 — one Projects category, and no label that reads as a version of another.
 *
 * ## The defect
 *
 * «المشاريع» and «جميع المشاريع» sat beside each other as siblings. Two entries whose labels differ
 * by one word read as two versions of the same place, and the longer one reads as «the same thing,
 * but more» — so a reader chooses by guessing, and half of them guess wrong. They are not two
 * versions of anything: one is the operational directory of projects, the other is the agency's own
 * portfolio scope.
 *
 * A parent that is a GROUP and not a destination says exactly that, and its children are named for
 * the two ways of LOOKING rather than repeating the subject.
 *
 * ## NAV-FIVE-AREAS-001 (2026-10-19)
 *
 * The Owner's five areas put the projects directory and the portfolio overview inside «إدارة الحملات».
 * The rule survives the move: the two still sit together, side by side, and are named so that neither
 * reads as «the same thing, but more» — «المشاريع» is the directory, «الملخص الشامل» the overview.
 *
 * ## What is NOT being changed
 *
 * PORTFOLIO-SCOPE-001 §36 — the portfolio scope is still entered by its own address, and the page
 * still says «جميع المشاريع» where the figures are. A rail label is not a scope declaration, and
 * this test pins the label while `PortfolioPage.test.tsx` pins the scope.
 */
const rails: Array<[string, readonly NavGroup[], string]> = [
  ['advertiser', appNavGroups, '/app'],
  ['agency', agencyNavGroups, '/agency'],
]

describe('the approved structure (NAV-HOME-PLANS-001)', () => {
  it('the agency rail is Home, Clients, Campaigns, Results, Finance, Operations', () => {
    expect(agencyNavGroups.map((g) => g.key)).toEqual(['home', 'clients', 'campaigns', 'results', 'finance', 'operations'])
    expect(agencyNavGroups.map((g) => g.ar)).toEqual(['الرئيسية', 'العملاء', 'الحملات', 'النتائج', 'المالية', 'العمليات'])
  })

  it('the advertiser rail has no Clients group', () => {
    expect(appNavGroups.map((g) => g.key)).toEqual(['home', 'campaigns', 'results', 'finance', 'operations'])
  })

  it.each(rails)('%s opens the campaign area with the campaigns, ad groups and ads in order', (_name, groups, prefix) => {
    const campaigns = groups.find((g) => g.key === 'campaigns')!
    const names = campaigns.leaves.map((l) => l.ar)
    const at = (to: string) => names.indexOf(campaigns.leaves.find((l) => l.to === `${prefix}/${to}`)!.ar)
    expect(at('campaigns')).toBeLessThan(at('ad-groups'))
    expect(at('ad-groups')).toBeLessThan(at('ads'))
  })

  it.each(rails)('%s uses the Owner’s names: «المجموعات الإعلانية», «روابط التتبع», and no «إطلاق الحملات» or «الملخص»', (_name, groups) => {
    const labels = groups.flatMap((g) => g.leaves.map((l) => l.ar))
    expect(labels).toContain('المجموعات الإعلانية')
    expect(labels).toContain('روابط التتبع')
    expect(labels).not.toContain('إطلاق الحملات')
    expect(labels).not.toContain('الملخص')
    expect(labels).not.toContain('جميع المشاريع')
  })

  it.each(rails)('%s labels use at most three words', (_name, groups) => {
    for (const leaf of groups.flatMap((g) => g.leaves)) {
      expect(leaf.ar.trim().split(/\s+/).length, `«${leaf.ar}» is longer than three words`).toBeLessThanOrEqual(3)
    }
  })

  /**
   * No child repeats its parent — except where the Owner's approved outline itself names the first child after its
   * group: «العملاء › العملاء» and «الحملات › الحملات» (the list, then the things around it), and the one-link home.
   */
  it.each(rails)('%s never repeats a group name in its children beyond the approved outline', (_name, groups) => {
    const approved = new Set(['clients', 'campaigns'])
    for (const group of groups) {
      if (group.leaves.length < 2 || approved.has(group.key)) continue

      for (const leaf of group.leaves) {
        expect(leaf.ar, `«${group.ar}» contains a child with its own name`).not.toBe(group.ar)
      }
    }
  })

  it.each(rails)('%s has no two destinations with the same label', (_name, groups) => {
    const labels = groups.flatMap((g) => g.leaves.map((l) => l.ar))

    expect(new Set(labels).size, `duplicate labels: ${labels.join(', ')}`).toBe(labels.length)
  })
})
