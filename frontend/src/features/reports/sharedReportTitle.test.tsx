import { describe, expect, it, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn(),
  sharedDownloadUrl: () => '#',
}))

import { fetchSharedReport, sharedBranding } from './api'
import { PublicReport } from './PublicReport'

/**
 * REPORT-TITLE-METADATA-001 on the client's own page.
 *
 * `index.html` ships the marketing line and the authenticated app replaces it per section. This
 * route replaced nothing, so a client opening their report got a tab, a bookmark and a history entry
 * reading «كل حملاتك الإعلانية المدفوعة في مكان واحد» — an advertisement, on a document about their
 * account, and identical across every client's link.
 *
 * The owner's rule for this surface is that a shared report keeps the CLIENT's title rather than
 * taking the product's, which is what the two cases below pin: the report's name under whoever the
 * header resolved this document to, and the product's name nowhere in it.
 */
const report = {
  name: 'Q3 Performance', currency: 'SAR', is_demo: false, generated_at: null,
  period_start: '2026-07-01', period_end: '2026-07-31',
  settings: { allow_download: false }, data: {}, kind: 'static',
}

function open(brandingName: string | null, locale: 'ar' | 'en' = 'en') {
  // `{status: 200, envelope: {data}}` is what `load()` reads — a «ready» envelope leaves the report
  // null and the page on its loading state, which is how the first version of this test failed.
  vi.mocked(fetchSharedReport).mockResolvedValue({ status: 200, envelope: { data: report } } as never)
  vi.mocked(sharedBranding).mockResolvedValue(
    (brandingName === null
      ? null
      : { name: brandingName, logo_url: null, logo_source: 'none', by: null }) as never,
  )

  renderWithProviders(<PublicReport />, { locale, route: '/r/tok', path: '/r/:token' })
}

describe('the tab a client’s report opens in', () => {
  it('carries the report and the client, not the product’s advertising', async () => {
    document.title = 'كل حملاتك الإعلانية المدفوعة في مكان واحد — CampaignsHub'
    open('Nakheel')

    await waitFor(() => expect(document.title).toBe('Q3 Performance · Nakheel'))
  })

  /**
   * With no branding resolved, `headerIdentity` answers with the product's name — and the tab is
   * allowed to say so, because that IS who the document belongs to then. What it must never do is
   * keep the marketing sentence, which says nothing about this report at all.
   */
  it('never leaves the marketing line on an unbranded report', async () => {
    const marketing = 'كل حملاتك الإعلانية المدفوعة في مكان واحد — CampaignsHub'
    document.title = marketing
    open(null)

    await waitFor(() => expect(document.title).not.toBe(marketing))
    expect(document.title).toContain('Q3 Performance')
  })
})
