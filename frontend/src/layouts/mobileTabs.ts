import type { MobileMoreGroup, MobileTab } from './MobileTabBar'
import type { NavGroup } from './SidebarNav'

/**
 * The More sheet, DERIVED from the rail rather than written out beside it — MOBILE-APP-001.
 *
 * The failure this prevents is the obvious one and the easy one to ship: a bottom bar with four
 * shortcuts and a hand-maintained "everything else" list that was accurate the day it was written.
 * Add a section to the rail six months later and it exists on desktop and not on a phone, silently,
 * with no test failing — because nothing ever claimed the two lists were the same list.
 *
 * So the sheet is computed: take the portal's real navigation, drop what is already a tab, keep the
 * rail's own groups and order. A new rail section appears in the sheet the moment it appears in the
 * rail, and `layouts/mobileTabs.test.ts` asserts the two sets reconcile exactly.
 *
 * `allow` is the entitlement filter the rail already applies. It is passed through rather than
 * re-derived: a phone must not offer a section the workspace's plan does not include, and it must
 * not offer a DIFFERENT set than the rail does, which is what two filters would eventually produce.
 */
export function moreGroupsFrom(
  groups: readonly NavGroup[],
  tabs: readonly MobileTab[],
  allow: (leaf: NavGroup['leaves'][number]) => boolean = () => true,
): MobileMoreGroup[] {
  const onTheBar = new Set(tabs.map((t) => t.to))

  return groups.map((group) => ({
    key: group.key,
    ar: group.ar,
    en: group.en,
    items: group.leaves
      .filter((leaf) => allow(leaf) && !onTheBar.has(leaf.to))
      .map((leaf) => ({ to: leaf.to, ar: leaf.ar, en: leaf.en, icon: leaf.icon, end: leaf.end })),
  }))
}

/**
 * Every destination a phone can reach in this portal: the bar plus the sheet.
 *
 * Exported for the test that proves it equals the rail's own set — the property that makes «no
 * feature removed on mobile» checkable rather than asserted.
 */
export function reachableOnMobile(tabs: readonly MobileTab[], more: readonly MobileMoreGroup[]): string[] {
  return [...tabs.map((t) => t.to), ...more.flatMap((g) => g.items.map((i) => i.to))].sort()
}
