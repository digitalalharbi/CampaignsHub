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
  it.each(rails)('%s keeps both project destinations in one area, side by side', (_name, groups, prefix) => {
    const holding = groups.filter((g) => g.leaves.some((l) => l.to === `${prefix}/projects` || l.to === `${prefix}/portfolio`))

    expect(holding, 'the two project destinations are in different areas').toHaveLength(1)
    const paths = holding[0]!.leaves.map((l) => l.to)
    expect(paths.indexOf(`${prefix}/portfolio`) - paths.indexOf(`${prefix}/projects`), 'the overview does not follow the directory').toBe(1)
  })

  it.each(rails)('%s names the two so neither reads as a version of the other', (_name, groups, prefix) => {
    const leaves = groups.flatMap((g) => g.leaves)
    const directory = leaves.find((l) => l.to === `${prefix}/projects`)!
    const overview = leaves.find((l) => l.to === `${prefix}/portfolio`)!

    expect([directory.ar, overview.ar]).toEqual(['المشاريع', 'الملخص الشامل'])
    expect([directory.en, overview.en]).toEqual(['Projects', 'Portfolio overview'])
    // The sibling pair NAV-IA-001 removed must not return.
    expect(leaves.map((l) => l.ar)).not.toContain('جميع المشاريع')
  })

  it.each(rails)('%s is organised in the five areas', (_name, groups) => {
    expect(groups.map((g) => g.key)).toEqual(['command', 'campaigns', 'agents', 'analytics', 'business'])
    expect(groups.map((g) => g.ar)).toEqual(['مركز القيادة', 'إدارة الحملات', 'الوكلاء الأذكياء', 'التحليلات والتقارير', 'إدارة الأعمال'])
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
