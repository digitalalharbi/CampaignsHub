import { describe, expect, it } from 'vitest'
import { appNavGroups, appNavLeafPaths } from './appNav'
import { agencyNavGroups, agencyNavLeafPaths } from './agencyNav'
import { navLeaves, type NavGroup } from './SidebarNav'

/**
 * Two things have to stay true at once, and they pull in opposite directions.
 *
 * Nothing may DISAPPEAR: grouping a rail is where a section quietly gets left out, nobody notices,
 * and a working feature becomes unreachable while its route still exists.
 *
 * But sections may MOVE: REG-001 took the multi-client tooling — a client roster, an inbound
 * requests inbox, client invoices, client conversations — out of the advertiser rail, because each
 * of them presumes you run campaigns for other people. While they were listed under `/app` an
 * advertiser signing in met an agency console, which is what made all five portals feel like one
 * product wearing different labels.
 *
 * So the guard below is not "the advertiser rail still has every path it used to". It is: every path
 * it used to have is still SOMEWHERE, in the portal whose purpose it serves. A section that vanishes
 * from both rails fails here, naming itself.
 */

/** `/app` as it was before REG-001: sixteen flat entries. */
const APP_BEFORE = [
  '/app/dashboard', '/app/requests', '/app/clients', '/app/projects', '/app/campaigns',
  '/app/content', '/app/analytics', '/app/reports', '/app/tasks', '/app/integrations',
  '/app/files', '/app/alerts', '/app/messages', '/app/billing', '/app/subscriptions',
  '/app/settings',
]

/**
 * `/agency` as it was, plus its own subscription.
 *
 * An agency is itself a paying tenant of CampaignsHub, so it has both kinds of money: `billing` is
 * what its clients pay IT, `subscriptions` is what it pays US. The advertiser portal has only the
 * second — which is the distinction, rather than "the agency has no plan".
 */
const AGENCY_BEFORE = [
  '/agency/dashboard', '/agency/clients', '/agency/requests', '/agency/projects',
  '/agency/campaigns', '/agency/content', '/agency/reports', '/agency/tasks',
  '/agency/files', '/agency/alerts', '/agency/messages', '/agency/billing',
  '/agency/subscriptions', '/agency/team', '/agency/settings',
]

/** The sections REG-001 moved out of the advertiser rail, and the portal each landed in. */
const MOVED_TO_AGENCY = ['requests', 'clients', 'messages', 'billing']

describe('no section is lost — moved is not the same as deleted', () => {
  it('keeps every advertiser section, in one portal or the other', () => {
    const everywhere = new Set([...appNavLeafPaths, ...agencyNavLeafPaths].map((p) => p.split('/')[2]))

    for (const path of APP_BEFORE) {
      expect(everywhere.has(path.split('/')[2]), `${path} exists in no rail`).toBe(true)
    }
  })

  it('lands each moved section in the agency rail specifically', () => {
    for (const section of MOVED_TO_AGENCY) {
      expect(agencyNavLeafPaths).toContain(`/agency/${section}`)
      expect(appNavLeafPaths).not.toContain(`/app/${section}`)
    }
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
    expect(groups.length).toBeLessThanOrEqual(navLeaves(groups).length)
    expect(groups.length).toBeLessThanOrEqual(7)
  })

  /** Every leaf points inside its own portal — grouping must not move a section to another one. */
  it('never points a section at another portal', () => {
    for (const leaf of navLeaves(appNavGroups)) expect(leaf.to.startsWith('/app/')).toBe(true)
    for (const leaf of navLeaves(agencyNavGroups)) expect(leaf.to.startsWith('/agency/')).toBe(true)
  })
})

/**
 * The portals must not become the same product with different colours. This is the regression guard
 * REG-001 exists for: if the advertiser rail ever grows the agency's sections again, this fails.
 */
describe('the portals stay distinct', () => {
  it('gives each portal sections the other does not have', () => {
    const app = new Set(navLeaves(appNavGroups).map((l) => l.to.replace('/app', '')))
    const agency = new Set(navLeaves(agencyNavGroups).map((l) => l.to.replace('/agency', '')))

    // The advertiser connects its own ad accounts. An agency connects them inside the client or
    // project they belong to, so an agency-wide integrations screen would invite connecting an
    // account to nothing in particular.
    expect(app.has('/integrations')).toBe(true)
    expect(agency.has('/integrations')).toBe(false)

    // Only the agency invoices anyone. Both pay for a plan, so `/subscriptions` is not the
    // distinguishing entry — `/billing` is.
    expect(agency.has('/billing')).toBe(true)
    expect(app.has('/billing')).toBe(false)

    // The agency decides who on its team may reach which client. The advertiser has no such question.
    expect(agency.has('/team')).toBe(true)
    expect(app.has('/team')).toBe(false)
  })

  /**
   * Clients is the agency's axis and is not the advertiser's anything.
   *
   * This assertion used to require the OPPOSITE of its second half — that `/app/clients` appear
   * under the advertiser's Work group, "nested rather than promoted". That framing is what the
   * regression looked like from inside: a multi-client roster in the advertiser portal, demoted a
   * level and therefore assumed to be a difference in emphasis rather than a difference in kind.
   */
  it('gives clients to the agency portal and to no other', () => {
    expect(agencyNavGroups.find((g) => g.key === 'clients')?.leaves).toHaveLength(1)
    expect(navLeaves(appNavGroups).some((l) => l.to.includes('/clients'))).toBe(false)
  })

  /** The two rails must never be substitutable — a portal is not a colour scheme. */
  it('does not let one rail stand in for the other', () => {
    const app = navLeaves(appNavGroups).map((l) => l.to.replace('/app', '')).sort()
    const agency = navLeaves(agencyNavGroups).map((l) => l.to.replace('/agency', '')).sort()

    expect(app).not.toEqual(agency)
  })
})
