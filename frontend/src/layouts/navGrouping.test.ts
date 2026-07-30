import { describe, expect, it } from 'vitest'
import { appNavGroups, appNavLeafPaths } from './appNav'
import { agencyNavGroups, agencyNavLeafPaths } from './agencyNav'
import { navLeaves, type NavGroup } from './SidebarNav'

/**
 * Grouping a rail is where sections quietly disappear: one gets left out of a group, nobody notices,
 * and a working feature becomes unreachable from the navigation while its route still exists.
 *
 * These pin the flat rails as they were BEFORE the grouping, by path. If a future edit drops one,
 * this fails naming it — which is the only way "we did not lose anything" stays true over time.
 */

/** `/app` as it was: sixteen flat entries. */
const APP_BEFORE = [
  '/app/dashboard', '/app/requests', '/app/clients', '/app/projects', '/app/campaigns',
  '/app/content', '/app/analytics', '/app/reports', '/app/tasks', '/app/integrations',
  '/app/files', '/app/alerts', '/app/messages', '/app/billing', '/app/subscriptions',
  '/app/settings',
]

/** `/agency` as it was: twelve flat entries. */
const AGENCY_BEFORE = [
  '/agency/dashboard', '/agency/clients', '/agency/requests', '/agency/projects',
  '/agency/campaigns', '/agency/content', '/agency/reports', '/agency/tasks',
  '/agency/files', '/agency/messages', '/agency/billing', '/agency/team',
]

describe('grouping the rails loses nothing', () => {
  it('keeps every advertiser section', () => {
    expect([...appNavLeafPaths].sort()).toEqual([...APP_BEFORE].sort())
  })

  it('keeps every agency section', () => {
    expect([...agencyNavLeafPaths].sort()).toEqual([...AGENCY_BEFORE].sort())
  })

  it('lists no section twice', () => {
    for (const paths of [appNavLeafPaths, agencyNavLeafPaths]) {
      expect(new Set(paths).size).toBe(paths.length)
    }
  })
})

describe('the structure rules hold', () => {
  const rails: [string, readonly NavGroup[]][] = [
    ['app', appNavGroups],
    ['agency', agencyNavGroups],
  ]

  /**
   * Two levels: a group, and the sections in it. A third would turn the rail into a filing system,
   * and the type makes it unrepresentable — this asserts the data matches the intent as well.
   */
  it.each(rails)('%s stays two levels deep', (_name, groups) => {
    for (const group of groups) {
      expect(Array.isArray(group.leaves)).toBe(true)
      for (const leaf of group.leaves) {
        expect(leaf).not.toHaveProperty('leaves')
      }
    }
  })

  /** A rail with more top-level entries than the flat one it replaced has simplified nothing. */
  it.each(rails)('%s has fewer top-level entries than sections', (_name, groups) => {
    expect(groups.length).toBeLessThan(navLeaves(groups).length)
    expect(groups.length).toBeLessThanOrEqual(7)
  })

  /** Every leaf points inside its own portal — grouping must not move a section to another one. */
  it('never points a section at another portal', () => {
    for (const leaf of navLeaves(appNavGroups)) expect(leaf.to.startsWith('/app/')).toBe(true)
    for (const leaf of navLeaves(agencyNavGroups)) expect(leaf.to.startsWith('/agency/')).toBe(true)
  })
})

/**
 * The portals must not become the same product with different colours. They share the SHAPE of the
 * rail and little else — if their sections ever converge, the four-portal architecture has collapsed
 * into one menu that varies by account type, which ADR 0002 rules out.
 */
describe('the portals stay distinct', () => {
  it('gives each portal sections the other does not have', () => {
    const app = new Set(navLeaves(appNavGroups).map((l) => l.to.replace('/app', '')))
    const agency = new Set(navLeaves(agencyNavGroups).map((l) => l.to.replace('/agency', '')))

    // The advertiser connects its own ad accounts and pays for its own plan.
    expect(app.has('/integrations')).toBe(true)
    expect(agency.has('/integrations')).toBe(false)
    expect(app.has('/subscriptions')).toBe(true)
    expect(agency.has('/subscriptions')).toBe(false)

    // The agency decides who on its team may reach which client. The advertiser has no such question.
    expect(agency.has('/team')).toBe(true)
    expect(app.has('/team')).toBe(false)
  })

  /** Clients is top-level for an agency and nested for an advertiser — the emphasis is the product. */
  it('promotes clients to the top level only in the agency portal', () => {
    expect(agencyNavGroups.find((g) => g.key === 'clients')?.leaves).toHaveLength(1)
    const appWork = appNavGroups.find((g) => g.key === 'work')
    expect(appWork?.leaves.some((l) => l.to === '/app/clients')).toBe(true)
  })
})
