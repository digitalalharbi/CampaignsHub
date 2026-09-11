import { describe, expect, it } from 'vitest'
import { sectionTitle } from './sectionTitle'
import { appNavGroups } from './appNav'
import { agencyNavGroups } from './agencyNav'

/**
 * REPORT-TITLE-METADATA-001 — a tab tells the operator which screen it holds.
 *
 * Every authenticated screen carried `index.html`'s marketing sentence, so six open tabs were six
 * identical labels and the browser history was a column of the same row. The names come from the
 * rail, because those are already the words the operator clicked.
 */
describe('the section a path belongs to', () => {
  it('names the section, not the product', () => {
    expect(sectionTitle('/app/campaigns', appNavGroups, true)).toBe('الحملات — CampaignsHub')
    expect(sectionTitle('/app/campaigns', appNavGroups, false)).toBe('Campaigns — CampaignsHub')
  })

  /* A detail page belongs to its section: «CampaignsHub» alone says nothing the window did not. */
  it('keeps a detail page under its own section', () => {
    expect(sectionTitle('/app/campaigns/123/ads', appNavGroups, false)).toBe('Campaigns — CampaignsHub')
  })

  /* `/app/short-links` must not be swallowed by a shorter sibling that happens to prefix it. */
  it('prefers the longest matching section', () => {
    expect(sectionTitle('/app/short-links', appNavGroups, false)).toBe('Short Links — CampaignsHub')
  })

  it('reads the agency rail for agency paths', () => {
    expect(sectionTitle('/agency/clients', agencyNavGroups, false)).toContain('CampaignsHub')
    expect(sectionTitle('/agency/clients', agencyNavGroups, false)).not.toBe('CampaignsHub')
  })

  /* A path the rail does not offer says nothing rather than guessing a name for it. */
  it('returns nothing for a path outside the rail', () => {
    expect(sectionTitle('/onboarding', appNavGroups, false)).toBeNull()
  })
})
