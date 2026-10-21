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

describe('the projects category', () => {
  /*
   * NAV-TAXONOMY-001 (Owner, 2026-10-21) supersedes NAV-FIVE-AREAS-001's «side by side» rule: the campaign area
   * follows the approved order exactly — نظرة عامة · المشاريع · إطلاق الحملات · الحملات · المجموعات · الإعلانات — so the
   * portfolio overview moved to «الأداء», where it is read beside Analytics and Reports. What NAV-IA-001 protected
   * still holds: the two are never labelled as versions of one another, and «جميع المشاريع» does not return.
   */
  it.each(rails)('%s opens the campaign area in the approved order', (_name, groups, prefix) => {
    const campaigns = groups.find((g) => g.key === 'campaigns')!
    expect(campaigns.leaves.slice(0, 6).map((l) => l.ar)).toEqual(['نظرة عامة', 'المشاريع', 'إطلاق الحملات', 'الحملات', 'المجموعات', 'الإعلانات'])
    expect(campaigns.leaves[1]!.to).toBe(`${prefix}/projects`)
  })

  it.each(rails)('%s names the two project destinations so neither reads as a version of the other', (_name, groups, prefix) => {
    const leaves = groups.flatMap((g) => g.leaves)
    const directory = leaves.find((l) => l.to === `${prefix}/projects`)!
    const overview = leaves.find((l) => l.to === `${prefix}/portfolio`)!

    expect([directory.ar, overview.ar]).toEqual(['المشاريع', 'الملخص'])
    expect([directory.en, overview.en]).toEqual(['Projects', 'Portfolio'])
    expect(leaves.map((l) => l.ar)).not.toContain('جميع المشاريع')
  })

  it('the advertiser rail is organised in four areas and the agency rail adds its clients', () => {
    expect(appNavGroups.map((g) => g.key)).toEqual(['campaigns', 'performance', 'operations', 'business'])
    expect(appNavGroups.map((g) => g.ar)).toEqual(['إدارة الحملات', 'الأداء', 'العمليات', 'الحساب'])
    expect(agencyNavGroups.map((g) => g.key)).toEqual(['campaigns', 'performance', 'operations', 'clients', 'business'])
    expect(agencyNavGroups.map((g) => g.ar)).toEqual(['إدارة الحملات', 'الأداء', 'العمليات', 'خدمة العملاء', 'الحساب'])
  })

  it.each(rails)('%s labels use one or two words', (_name, groups) => {
    for (const leaf of groups.flatMap((g) => g.leaves)) {
      expect(leaf.ar.trim().split(/\s+/).length, `«${leaf.ar}» is longer than two words`).toBeLessThanOrEqual(2)
    }
  })

  /**
   * No child repeats its parent, in either language.
   *
   * A category whose first item is its own name reads as a stray extra entry — «Campaigns →
   * Campaigns, Content» — and it is the same defect as the sibling pair, one level down.
   */
  it.each(rails)('%s never repeats a group name in its own children', (_name, groups) => {
    for (const group of groups) {
      if (group.leaves.length < 2) continue

      for (const leaf of group.leaves) {
        expect(leaf.ar, `«${group.ar}» contains a child with its own name`).not.toBe(group.ar)
        expect(leaf.en, `«${group.en}» contains a child with its own name`).not.toBe(group.en)
      }
    }
  })

  /** And no two destinations anywhere on a rail carry the same label. */
  it.each(rails)('%s has no two destinations with the same label', (_name, groups) => {
    const labels = groups.flatMap((g) => g.leaves.map((l) => l.ar))

    expect(new Set(labels).size, `duplicate labels: ${labels.join(', ')}`).toBe(labels.length)
  })
})
