import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { fireEvent } from '@testing-library/react'
import { AdPreviewDialog } from './AdPreviewDialog'
import { renderWithProviders } from '@/test/utils'
import type { CreativeCard, CreativePreview } from './api'

/**
 * AD-PREVIEW-001 — one ad, opened where the reader is standing.
 *
 * The surfaces that list ads are comparison surfaces. A reader asking «what does this one look
 * like» is in the middle of comparing eight of them, and a navigation costs the comparison, the
 * sort, the scroll and the filters — so they stop asking, and decide from a name and a number what
 * the ad actually shows.
 */
const preview = (over: Partial<CreativePreview> = {}): CreativePreview => ({
  state: 'available',
  kind: 'image',
  image_url: 'https://cdn.example/ad.jpg',
  video_url: null,
  thumbnail_url: null,
  expires_at: null,
  note_ar: null,
  note_en: null,
  cards: null,
  ...over,
} as CreativePreview)

const creative = (over: Partial<CreativeCard> = {}): CreativeCard => ({
  id: 'cr-1',
  name: 'Ramadan — Story 9:16',
  format: 'video',
  provider: 'meta',
  status: 'active',
  campaign_id: 'cam-1',
  campaign_name: 'Ramadan Sales',
  ad_set_id: null,
  ads: [],
  preview: preview(),
  aspect_ratio: '9:16',
  duration_seconds: null,
  width: null,
  height: null,
  file_size: null,
  grouped: false,
  group_id: null,
  is_demo: false,
  freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: null, last_active_at: null },
  objective: 'sales',
  path: 'conversion',
  headline_metrics: [],
  ...over,
} as unknown as CreativeCard)

describe('the in-place ad preview', () => {
  it('shows the ad, its platform and the row’s own figures', () => {
    renderWithProviders(
      <AdPreviewDialog
        creative={creative()}
        locale="en"
        figures={[{ label: 'Spend', value: '4.2K SAR' }, { label: 'Impressions', value: '310K' }]}
        onClose={() => {}}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('ad-preview-dialog')).toHaveTextContent('Ramadan — Story 9:16')
    expect(screen.getByTestId('ad-preview-dialog')).toHaveTextContent('Meta')
    expect(screen.getByTestId('ad-preview-dialog-figures')).toHaveTextContent('4.2K SAR')
  })

  /**
   * The figures are the row's, passed in — never fetched here.
   *
   * A second query would eventually answer for a different window than the row the reader clicked,
   * and two numbers for one ad on one screen is worse than one number with a caveat.
   */
  it('shows no figure block when the surface passed none', () => {
    renderWithProviders(<AdPreviewDialog creative={creative()} locale="en" onClose={() => {}} />, { locale: 'en' })

    expect(screen.queryByTestId('ad-preview-dialog-figures')).not.toBeInTheDocument()
  })

  /** An ad whose media the platform withholds says so — it never gets a placeholder picture. */
  it('states an absence rather than drawing an empty frame', () => {
    renderWithProviders(
      <AdPreviewDialog
        creative={creative({ preview: preview({ state: 'withheld', image_url: null, note_en: 'The link carries a credential.' }) })}
        locale="en"
        onClose={() => {}}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('ad-preview-dialog-poster-absent')).toBeInTheDocument()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })

  it('closes from the button and from the backdrop', () => {
    const onClose = vi.fn()
    renderWithProviders(<AdPreviewDialog creative={creative()} locale="en" onClose={onClose} />, { locale: 'en' })

    fireEvent.click(screen.getByTestId('ad-preview-dialog-close'))
    fireEvent.click(screen.getByTestId('ad-preview-dialog'))

    expect(onClose).toHaveBeenCalledTimes(2)
  })

  /** Leaving is still possible — the dialog withholds the comparison's cost, not the page. */
  it('offers the full page when the surface knows where it is', () => {
    renderWithProviders(
      <AdPreviewDialog creative={creative()} locale="en" detailsTo="/app/content/cr-1" onClose={() => {}} />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('ad-preview-dialog-details')).toHaveAttribute('href', '/app/content/cr-1')
  })

  /**
   * CONTENT-DETAIL-MODAL-001 — a film plays, in the library too.
   *
   * This dialog drew every ad as a still: a video showed its poster frame with no way to play it,
   * while the content library and the shared report both played it. Same ad, three surfaces, two
   * behaviours — and a poster frame of a video is a plausible-looking picture of the wrong thing, so
   * nothing on screen said which one a reader was looking at.
   */
  it('plays a video rather than showing its poster frame', () => {
    const { container } = renderWithProviders(
      <AdPreviewDialog
        creative={creative({ preview: preview({ kind: 'video', video_url: 'https://cdn.example/ad.mp4' }) })}
        locale="en"
        onClose={() => {}}
      />,
    )

    expect(container.querySelector('video')).toBeInTheDocument()
    expect(screen.queryByTestId('ad-preview-dialog-poster')).not.toBeInTheDocument()
  })

  /** A carousel is paged, not reduced to whichever card came first. */
  it('pages a carousel instead of showing one card of it', () => {
    renderWithProviders(
      <AdPreviewDialog
        creative={creative({
          preview: preview({
            kind: 'carousel',
            cards_reported: true,
            cards: [
              { index: 0, kind: 'image', image_url: 'https://cdn.example/1.jpg', video_url: null, thumbnail_url: null, headline: 'One' },
              { index: 1, kind: 'image', image_url: 'https://cdn.example/2.jpg', video_url: null, thumbnail_url: null, headline: 'Two' },
            ],
          } as Partial<CreativePreview>),
        })}
        locale="en"
        onClose={() => {}}
      />,
    )

    expect(screen.getByTestId('creative-carousel')).toBeInTheDocument()
  })

  /** A modal a keyboard cannot leave is a trap, and this one opens from a grid people tab through. */
  it('closes on Escape', () => {
    const onClose = vi.fn()

    renderWithProviders(<AdPreviewDialog creative={creative()} locale="en" onClose={onClose} />)
    fireEvent.keyDown(document, { key: 'Escape' })

    expect(onClose).toHaveBeenCalled()
  })

  /**
   * What the ad was bought FOR, beside the figures chosen by it.
   *
   * `headline_metrics` are picked by the objective, so «CTR 0.4%» read without knowing the ad was
   * bought for reach is a judgement against a target nobody set.
   */
  it('says which objective the ad was bought for, and where it sits', () => {
    renderWithProviders(
      <AdPreviewDialog
        creative={creative({ objective: 'reach', ad_set_id: 'as-9' })}
        locale="en"
        onClose={() => {}}
      />,
    )

    expect(screen.getByText('Objective')).toBeInTheDocument()
    expect(screen.getByText('Ad set')).toBeInTheDocument()
    expect(screen.getByText('as-9')).toBeInTheDocument()
  })

  /** An objective the campaign never recorded is absent, not «unknown». */
  it('says nothing about an objective nobody recorded', () => {
    renderWithProviders(
      <AdPreviewDialog creative={creative({ objective: null })} locale="en" onClose={() => {}} />,
    )

    expect(screen.queryByText('Objective')).not.toBeInTheDocument()
  })
})
