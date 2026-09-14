import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { ReportPlatformCreatives, type AdPlatformGroup } from './ReportAdsSection'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-DETAIL-PARITY-001 — «best-performing creatives per platform», on the page.
 *
 * The report ended in one gallery grouped by objective across every platform, which answers «what
 * worked» and cannot answer «what works HERE». The second is the question an agency takes into next
 * month's plan: the ad to make more of on TikTok is rarely the ad to make more of on Google.
 *
 * What is pinned here is the part a layout change could quietly undo — that each platform's cards
 * are that platform's, that a group still says what ordered it, that a cut list says what it was cut
 * from, and that the section refuses to exist where it would only repeat the gallery above it.
 */
const group = (family: string, names: string[], over: Partial<AdPlatformGroup['groups'][number]> = {}) => ({
  family,
  label_ar: family,
  label_en: family,
  metric: 'roas',
  metric_label_ar: 'العائد',
  metric_label_en: 'return on ad spend',
  ranked: true,
  ads: names.map((name, i) => ({ id: `${family}-${i}`, name, provider: 'meta' })),
  candidates: names.length,
  shown: names.length,
  ...over,
})

const render = (platforms: AdPlatformGroup[]) =>
  renderWithProviders(
    <ReportPlatformCreatives platforms={platforms} currency="SAR" locale="en" />,
    { locale: 'en' },
  )

describe('the creatives, platform by platform', () => {
  it('gives each platform its own cards and never another platform’s', () => {
    render([
      { provider: 'meta', candidates: 2, groups: [group('sales', ['Meta leader', 'Meta second'])] },
      { provider: 'tiktok', candidates: 1, groups: [group('sales', ['TikTok leader'])] },
    ])

    const meta = within(screen.getByTestId('report-platform-creatives-meta'))
    const tiktok = within(screen.getByTestId('report-platform-creatives-tiktok'))

    expect(meta.getByText('Meta leader')).toBeInTheDocument()
    expect(meta.queryByText('TikTok leader')).toBeNull()
    expect(tiktok.getByText('TikTok leader')).toBeInTheDocument()
    expect(tiktok.queryByText('Meta leader')).toBeNull()
  })

  /** The platform is named as a platform, never as its database key. */
  it('names the platform', () => {
    render([
      { provider: 'meta', groups: [group('sales', ['A'])] },
      { provider: 'tiktok', groups: [group('sales', ['B'])] },
    ])

    expect(screen.getByText('TikTok')).toBeInTheDocument()
    expect(screen.queryByText('tiktok')).toBeNull()
  })

  /**
   * A group still says what ordered it, inside a platform.
   *
   * «Best» without «by what» is the sentence that let a spend ordering pass for a performance one,
   * and the section reuses the objective gallery's own group component precisely so it cannot drift
   * into making that claim differently.
   */
  it('says what ordered each group', () => {
    render([
      { provider: 'meta', groups: [group('sales', ['A'])] },
      { provider: 'tiktok', groups: [group('sales', ['B'])] },
    ])

    expect(screen.getByTestId('report-platform-meta-basis-sales')).toHaveTextContent('ranked by return on ad spend')
  })

  /**
   * REPORT-CREATIVE-TRUTH-001 §B — «the best three» says «of how many».
   *
   * Three of forty and three of three are different reports, and a truncated list makes the second
   * claim by default.
   */
  it('says how many a cut list was cut from, and stays quiet when nothing was cut', () => {
    render([
      { provider: 'meta', groups: [group('sales', ['A', 'B', 'C'], { candidates: 17, shown: 3 })] },
      { provider: 'tiktok', groups: [group('sales', ['D'])] },
    ])

    expect(screen.getByTestId('report-platform-meta-of-sales')).toHaveTextContent('3 of 17')
    // One of one is not a truncation, and saying «1 of 1» teaches a reader to ignore the line.
    expect(screen.queryByTestId('report-platform-tiktok-of-sales')).toBeNull()
  })

  /**
   * A snapshot generated before the counts existed carries neither, and must not start claiming one.
   */
  it('claims nothing about a list nobody counted', () => {
    render([
      { provider: 'meta', groups: [group('sales', ['A', 'B'], { candidates: undefined, shown: undefined })] },
      { provider: 'tiktok', groups: [group('sales', ['C'])] },
    ])

    expect(screen.queryByTestId('report-platform-meta-of-sales')).toBeNull()
  })

  /**
   * One platform is not a comparison.
   *
   * On an account running a single platform this section is the gallery above it with a platform
   * name on top — the same creatives, a second time, under a heading promising a comparison the
   * account cannot make.
   */
  it('does not exist on a single-platform account', () => {
    render([{ provider: 'meta', groups: [group('sales', ['A'])] }])

    expect(screen.queryByTestId('report-platform-creatives')).toBeNull()
  })
})
