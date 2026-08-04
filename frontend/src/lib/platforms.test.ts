import { describe, expect, it } from 'vitest'
import { PLATFORM_ORDER, canonicalPlatform, platformRank, sortByPlatform, sortPlatforms } from './platforms'

/**
 * PLATFORM-ORDER-001 — the mirror of the server's rule, tested against the same cases.
 *
 * The two exist because both sides render lists, and the whole point of writing the order down once
 * is that a second copy can no longer disagree with the first. So this asserts the ORDER as data,
 * not just the sorting behaviour: a mistake here would be silent and would show up as two screens
 * putting Snapchat in different places.
 */
describe('the product platform order', () => {
  it('is the order the product presents', () => {
    expect(PLATFORM_ORDER).toEqual(['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin'])
  })

  it.each([
    ['snapchat', ['snapchat_ads', 'snap', 'SNAPCHAT']],
    ['tiktok', ['tiktok_ads']],
    ['meta', ['meta_ads', 'facebook', 'instagram']],
    ['google', ['google_ads', 'googleads']],
    ['x', ['x_ads', 'twitter']],
    ['linkedin', ['linkedin_ads']],
  ])('reads every spelling of %s as the same platform', (canonical, spellings) => {
    for (const spelling of spellings) {
      expect(canonicalPlatform(spelling)).toBe(canonical)
      expect(platformRank(spelling)).toBe(platformRank(canonical))
    }
  })

  /** An unknown platform sorts last rather than breaking the page rendering it. */
  it('puts an unknown platform after every known one', () => {
    expect(platformRank('pinterest')).toBeGreaterThan(platformRank('linkedin'))
    expect(platformRank(null)).toBeGreaterThan(platformRank('linkedin'))
  })

  /** Sorting reorders; it never rewrites — these keys are used as API filters and connector ids. */
  it('reorders without rewriting the keys', () => {
    expect(sortPlatforms(['linkedin', 'google_ads', 'x', 'meta_ads', 'snapchat', 'tiktok']))
      .toEqual(['snapchat', 'tiktok', 'meta_ads', 'google_ads', 'x', 'linkedin'])
  })

  it('sorts rows by the platform they carry', () => {
    const rows = [{ p: 'meta' }, { p: 'snapchat' }, { p: 'ga4' }, { p: 'tiktok' }]

    expect(sortByPlatform(rows, (r) => r.p).map((r) => r.p)).toEqual(['snapchat', 'tiktok', 'meta', 'ga4'])
  })

  /** Stable: two unknowns keep the order they arrived in rather than swapping between renders. */
  it('keeps unknown platforms in the order they arrived', () => {
    expect(sortPlatforms(['ga4', 'crm', 'snapchat'])).toEqual(['snapchat', 'ga4', 'crm'])
  })
})
