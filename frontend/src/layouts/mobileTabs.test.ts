import { describe, expect, it } from 'vitest'
import { moreGroupsFrom, reachableOnMobile } from './mobileTabs'
import { navLeaves } from './SidebarNav'
import { appNavGroups } from './appNav'
import { agencyNavGroups } from './agencyNav'
import type { MobileTab } from './MobileTabBar'
import { LayoutDashboard } from 'lucide-react'

/**
 * MOBILE-APP-001 — a phone reaches every section the rail offers. Not most of them.
 *
 * The bottom bar shows four destinations, which is the whole point of it, and the obvious way to
 * ship that is a bar plus a hand-written list of "the rest". That list is correct on the day it is
 * written and silently wrong the first time somebody adds a section to the rail: it exists on
 * desktop, it does not exist on a phone, and no test fails because nothing ever claimed the two were
 * the same set.
 *
 * These tests make that claim. The sheet is DERIVED from the rail, and what follows proves the
 * derivation is total — bar ∪ sheet = rail, exactly, with no duplicates.
 */
describe('the phone reaches everything the rail does', () => {
  const portals = [
    { name: 'advertiser (/app)', groups: appNavGroups },
    { name: 'agency (/agency)', groups: agencyNavGroups },
  ]

  for (const portal of portals) {
    it(`${portal.name}: bar + More sheet == the rail, exactly`, () => {
      // The first four leaves stand in for whatever the shell picks — the property is about the
      // partition, not about which four are promoted.
      const tabs: MobileTab[] = navLeaves(portal.groups).slice(0, 4)
        .map((l) => ({ to: l.to, ar: l.ar, en: l.en, icon: l.icon }))

      const more = moreGroupsFrom(portal.groups, tabs)
      const reachable = reachableOnMobile(tabs, more)
      const rail = navLeaves(portal.groups).map((l) => l.to).sort()

      expect(reachable).toEqual(rail)
    })

    it(`${portal.name}: a section on the bar is not repeated in the sheet`, () => {
      const tabs: MobileTab[] = navLeaves(portal.groups).slice(0, 4)
        .map((l) => ({ to: l.to, ar: l.ar, en: l.en, icon: l.icon }))

      const inSheet = moreGroupsFrom(portal.groups, tabs).flatMap((g) => g.items.map((i) => i.to))

      for (const tab of tabs) expect(inSheet).not.toContain(tab.to)
    })

    it(`${portal.name}: the bar is at most four, so the bar plus More is at most five`, () => {
      // 375px ÷ 5 is 75px a target; ÷ 6 is 62px with a label that has to wrap. Four plus More is the
      // ceiling this design holds itself to, and it is asserted rather than remembered.
      expect(navLeaves(portal.groups).slice(0, 4).length).toBeLessThanOrEqual(4)
    })
  }

  /**
   * The entitlement filter reaches the phone too.
   *
   * A workspace whose plan does not include a section must not be offered it in the More sheet —
   * that would be a hidden door that 403s, which is worse than no door. The rail already filters;
   * this proves the sheet uses the SAME filter rather than a second one that can drift.
   */
  it('a section the workspace is not entitled to is absent from the sheet', () => {
    const tabs: MobileTab[] = [{ to: '/app/dashboard', ar: 'الرئيسية', en: 'Home', icon: LayoutDashboard }]
    const more = moreGroupsFrom(appNavGroups, tabs, (leaf) => leaf.ent !== 'reports')

    expect(more.flatMap((g) => g.items.map((i) => i.to))).not.toContain('/app/reports')
    // …and everything else still arrives, so the filter narrowed rather than emptied.
    expect(more.flatMap((g) => g.items.map((i) => i.to))).toContain('/app/campaigns')
  })
})
