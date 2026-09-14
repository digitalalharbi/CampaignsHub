import { describe, expect, it } from 'vitest'

/**
 * BRAND-MARK-001 — the lockup is the way home, on every shell that draws one.
 *
 * ## The defect
 *
 * The phone bar has carried `<Link to="/app">` since the mark was put on it. The desktop rail beside
 * it rendered the same identity as a plain `<div>`: the one place a reader looks for «take me back to
 * the start» did nothing, on the surface operators use most. A dead logo raises no error — it simply
 * fails to respond, and a reader concludes the product works that way, which is why nobody reported
 * it.
 *
 * ## Why this reads the source
 *
 * The shells mount a router, a session, a nav payload and a workspace switcher; rendering one to ask
 * «is the logo a link» tests the harness more than the answer. What is being asserted is structural
 * and one line long: the element carrying the brand is an anchor with a destination inside the app.
 *
 * ## Where it points, per shell
 *
 * `/app` and `/agency` and `/influencers` — each portal's own home, never the marketing site. An
 * authenticated reader sent to the public homepage has been logged out as far as they can tell.
 *
 * And in the agency and influencer rails only the MARK is the link: the words beside it are the
 * tenant's name, and a name that navigates is a claim about what it belongs to. That is the owner's
 * own instruction — preserve tenant branding, do not turn their logo into a CampaignsHub-home link.
 */
const SHELLS = import.meta.glob('/src/layouts/*Shell.tsx', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

const shell = (name: string): string => {
  const source = SHELLS[`/src/layouts/${name}.tsx`]

  if (source === undefined) {
    throw new Error(`${name} is not in the glob — this test has gone stale: ${Object.keys(SHELLS).join(', ')}`)
  }

  return source
}

describe('the brand lockup navigates home', () => {
  it.each([
    ['AppShell', '/app'],
    ['AgencyShell', '/agency'],
    ['InfluencerShell', '/influencers'],
  ])('%s sends the reader to %s', (name, base) => {
    const source = shell(name)

    /*
     * The BRAND element specifically, not any link in the file. Every shell is full of `<Link>`s, so
     * matching one of those would pass on a shell whose logo is still a dead div.
     */
    const brand = /<Link[^>]*?data-testid="shell-brand-(home|mark)"[\s\S]{0,400}?>/m.exec(source)
      ?? /<Link[\s\S]{0,400}?data-testid="shell-brand-(home|mark)"[\s\S]{0,200}?>/m.exec(source)

    expect(brand, `${name} draws its brand as something other than a link`).not.toBeNull()
    expect(brand?.[0], `${name}'s brand must point at its own portal home`).toContain(`to="${base}"`)
  })

  /** And never at the marketing site, which is the one destination that reads as a logout. */
  it.each(['AppShell', 'AgencyShell', 'InfluencerShell'])('%s never sends an authenticated reader to the public site', (name) => {
    const source = shell(name)
    const brandBlock = source.slice(
      Math.max(0, source.indexOf('shell-brand-') - 600),
      source.indexOf('shell-brand-') + 600,
    )

    expect(brandBlock).not.toMatch(/to="\/"[\s>]/)
    expect(brandBlock).not.toContain('href="https://campaignshub.io')
  })

  /**
   * The mobile bar keeps its own link — the rail is hidden on a phone, so a fix to the rail alone
   * would leave the phone exactly where it was before the mark was put on it.
   */
  it.each([
    ['AppShell', '/app'],
    ['AgencyShell', '/agency'],
    ['InfluencerShell', '/influencers'],
  ])('%s keeps the phone bar pointing at %s', (name, base) => {
    const source = shell(name)
    const i = source.indexOf('data-testid="mobile-brand-mark"')

    expect(i, `${name} has no brand on its phone bar`).toBeGreaterThan(-1)
    expect(source.slice(Math.max(0, i - 300), i)).toContain(`to="${base}"`)
  })
})
