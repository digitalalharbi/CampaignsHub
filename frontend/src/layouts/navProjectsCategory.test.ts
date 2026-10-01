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
  it.each(rails)('%s has exactly one projects group, holding both destinations', (_name, groups, prefix) => {
    const projects = groups.filter((g) => g.leaves.some((l) => l.to === `${prefix}/projects`))

    expect(projects, 'the two project destinations are in different groups').toHaveLength(1)
    expect(projects[0]!.leaves.map((l) => l.to)).toEqual([`${prefix}/projects`, `${prefix}/portfolio`])
  })

  it.each(rails)('%s names the children for the view, not for the subject', (_name, groups, prefix) => {
    const group = groups.find((g) => g.leaves.some((l) => l.to === `${prefix}/projects`))!

    expect(group.leaves.map((l) => l.ar)).toEqual(['القائمة', 'الملخص'])
    expect(group.leaves.map((l) => l.en)).toEqual(['List', 'Overview'])
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
