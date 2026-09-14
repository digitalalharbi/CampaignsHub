import { describe, expect, it } from 'vitest'
import { platformAds } from './InteractiveReport'

/**
 * REPORT-DETAIL-PARITY-001 — the deck's per-platform slide, and the question it was answering.
 *
 * The slide is titled «أفضل الإعلانات — تيك توك» and built its list by filtering the scope-wide
 * ranking to that provider. That answers «which of the report's best ads happen to run here», which
 * is empty for any platform that did not place in the overall cut: a platform running twenty
 * creatives got a slide under its own name saying it had none.
 */
const ad = (name: string, provider: string) => ({ id: name, name, provider })

const group = (family: string, ads: ReturnType<typeof ad>[]) => ({
  family,
  label_ar: family,
  label_en: family,
  metric: 'roas',
  metric_label_ar: null,
  metric_label_en: 'return on ad spend',
  ranked: true,
  ads,
})

describe('the ads a platform slide shows', () => {
  it('shows the platform’s own leaders, not the global list filtered to it', () => {
    const data = {
      // The scope-wide ranking: every entry is Meta's, which is exactly how a platform disappears.
      ads: [ad('Meta A', 'meta'), ad('Meta B', 'meta')],
      ads_platform_groups: [
        { provider: 'meta', groups: [group('sales', [ad('Meta A', 'meta')])] },
        { provider: 'tiktok', groups: [group('sales', [ad('TikTok A', 'tiktok')])] },
      ],
    }

    const tiktok = platformAds(data, 'tiktok')

    expect(tiktok.ads.map((a) => a.name)).toEqual(['TikTok A'])
    expect(tiktok.groups).toHaveLength(1)
  })

  /**
   * A report generated before the section existed keeps rendering exactly as it did.
   *
   * Its slides are the filtered list, which is the weaker answer — and changing what a document
   * already in a client's hands shows is not this change's business.
   */
  it('falls back to the filtered list for a snapshot that carries no platform groups', () => {
    const data = { ads: [ad('Meta A', 'meta'), ad('TikTok A', 'tiktok')] }

    expect(platformAds(data, 'tiktok').ads.map((a) => a.name)).toEqual(['TikTok A'])
    expect(platformAds(data, 'tiktok').groups).toBeUndefined()
  })

  /** A platform in the report with no creatives of its own gets an empty list, not another's. */
  it('never hands one platform another platform’s ads', () => {
    const data = {
      ads: [ad('Meta A', 'meta')],
      ads_platform_groups: [
        { provider: 'meta', groups: [group('sales', [ad('Meta A', 'meta')])] },
        { provider: 'google', groups: [] },
      ],
    }

    expect(platformAds(data, 'google').ads).toEqual([])
  })
})
