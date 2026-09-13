import { describe, expect, it } from 'vitest'
import appShell from '@/layouts/AppShell.tsx?raw'
import agencyShell from '@/layouts/AgencyShell.tsx?raw'
import adminShell from '@/layouts/AdminShell.tsx?raw'
import influencerShell from '@/layouts/InfluencerShell.tsx?raw'
import creatorShell from '@/layouts/CreatorShell.tsx?raw'
import authPanel from '@/features/auth/AuthPanel.tsx?raw'

/**
 * BRAND-MARK-001 — nothing stands in for the mark any more.
 *
 * Before this unit, five surfaces each had their own idea of what CampaignsHub looks like: a
 * megaphone, a people glyph, a shield, a gradient tile with a letter in it, and in one case nothing
 * at all. None of them was wrong on purpose — there was simply no mark to use.
 *
 * The browser suite proves what the rail and the phone PAINT, but it only visits the shells an
 * owner account can reach. These cases cover the other three, and they read the source because the
 * question is structural: does this file still draw its own idea of the logo?
 */
const SHELLS: Array<[string, string]> = [
  ['AppShell', appShell],
  ['AgencyShell', agencyShell],
  ['AdminShell', adminShell],
  ['InfluencerShell', influencerShell],
  ['CreatorShell', creatorShell],
  ['AuthPanel', authPanel],
]

describe('no placeholder stands in for the CampaignsHub mark', () => {
  it.each(SHELLS)('%s draws the canonical mark', (_name, source) => {
    expect(source).toMatch(/CampaignsHub(Mark|Logo)/)
  })

  /**
   * The glyphs that WERE the logo, in the place the logo goes.
   *
   * Deliberately narrow: `Megaphone` is still the Campaigns nav icon and `Users` still labels a
   * people screen — those are a section's icons and are meant to stay. What must not come back is
   * one of them inside the brand tile, which is what this matches.
   */
  it.each(SHELLS)('%s puts no glyph back in the brand tile', (_name, source) => {
    const brandTile = /items-center justify-center rounded-xl[^>]*>\s*<(Megaphone|Users|ShieldCheck|Sparkles|Rocket)\s/g
    expect(source.match(brandTile) ?? []).toEqual([])
  })

  it.each(SHELLS)('%s writes no brand colour of its own', (_name, source) => {
    // The mark's colour arrives through --brand-mark. A hex here is a second source of truth.
    expect(source).not.toMatch(/#0[dD]8[aA]6[fF]|#009[bB]83|#[eE]8[aA]33[dD]/)
  })
})
