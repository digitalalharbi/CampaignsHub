import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ContentReading } from './ContentReading'
import { renderWithProviders } from '@/test/utils'
import type { ContentIntelligence } from '@/features/content/api'

/**
 * ANALYTICS-DIFFERENTIATION-001 — the content reading, held to the rules that make it a reading.
 *
 * What is under test is not the layout. It is the four claims that separate this block from the
 * ranked table it sits above: it declines rather than inventing a comparison, it names what it held
 * out, it keeps a cost-per's decimals, and its bar never draws the winner as the shortest one.
 */
const reading = (over: Partial<ContentIntelligence> = {}): ContentIntelligence => ({
  metric: 'cpa',
  lower_is_better: true,
  objective: 'Conversions',
  formats: [
    { format: 'video', value: 1.5, spend: 8000, creatives: 12 },
    { format: 'image', value: 9.25, spend: 2000, creatives: 7 },
  ],
  best: 'video',
  worst: 'image',
  share_of_spend_not_on_the_leading_format: 0.2,
  why_no_spend_share: null,
  too_few_to_speak_for_their_format: [],
  refusal: null,
  ...over,
})

describe('the content reading above the ranked table', () => {
  it('names the format that earns its budget and the one that does not', () => {
    renderWithProviders(<ContentReading data={reading()} currency="SAR" creativesRead={19} />, { locale: 'en' })

    expect(screen.getByTestId('content-reading')).toBeInTheDocument()
    expect(screen.getByTestId('content-reading-action')).toHaveTextContent(/Video/)
    expect(screen.getByTestId('content-reading-action')).toHaveTextContent(/Image/)
  })

  /**
   * UX-KPI-PRESENTATION-001 — «CPC 1.50, not 2».
   *
   * This card's whole subject is the distance between two costs, and compacting either end removes
   * the digits the comparison is made of.
   */
  it('keeps a cost-per’s decimals rather than compacting them', () => {
    renderWithProviders(<ContentReading data={reading()} currency="SAR" creativesRead={19} />, { locale: 'en' })

    expect(screen.getByTestId('content-format-video')).toHaveTextContent('1.50')
    expect(screen.getByTestId('content-format-video')).not.toHaveTextContent(/\b2 SAR/)
  })

  /** A refusal is a sentence about the account, never an empty frame. */
  it('declines with the reason when only one format ran', () => {
    renderWithProviders(
      <ContentReading
        data={reading({ refusal: 'only_one_format_ran_enough_to_compare', metric: null, formats: [], best: null, worst: null })}
        currency="SAR"
        creativesRead={12}
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('content-reading')).not.toBeInTheDocument()
    expect(screen.getByTestId('content-reading-declined')).toHaveTextContent(/Only one kind of content ran/)
  })

  /**
   * The held-out formats are named.
   *
   * «One video ran, which is not enough to speak for video» is a true statement about the media
   * plan; dropping it silently lets the reader believe the format was never tried.
   */
  it('names the formats it held out rather than dropping them silently', () => {
    renderWithProviders(
      <ContentReading
        data={reading({
          refusal: 'only_one_format_ran_enough_to_compare',
          metric: null,
          formats: [],
          too_few_to_speak_for_their_format: [{ format: 'carousel', creatives: 1 }],
        })}
        currency="SAR"
        creativesRead={13}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('content-reading-too-few')).toHaveTextContent(/Carousel/)
  })

  /** A share over an incomplete total overstates itself — «—», never a percentage. */
  it('withholds the spend share when a format withheld its spend', () => {
    renderWithProviders(
      <ContentReading
        data={reading({ share_of_spend_not_on_the_leading_format: null, why_no_spend_share: 'a_format_withheld_its_spend' })}
        currency="SAR"
        creativesRead={19}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('content-reading-spend-share')).toHaveTextContent('—')
    expect(screen.getByTestId('content-reading-spend-share')).toHaveTextContent(/withheld its spend/)
  })

  /**
   * «Nobody reports spend at this grain» must not be printed as «a format withheld it» — the second
   * sends the reader looking for a fault that is not there.
   */
  it('separates a provider that reports no ad-level spend from a format that withheld it', () => {
    renderWithProviders(
      <ContentReading
        data={reading({ share_of_spend_not_on_the_leading_format: null, why_no_spend_share: 'no_spend_was_reported_at_this_grain' })}
        currency="SAR"
        creativesRead={60}
      />,
      { locale: 'en' },
    )

    const card = screen.getByTestId('content-reading-spend-share')
    expect(card).toHaveTextContent(/did not report spend at the ad level/)
    expect(card).not.toHaveTextContent(/withheld/)
  })

  /**
   * On a cost metric the leader is the SMALLEST number, so a bar scaled to the maximum would draw
   * the winner as the shortest one — a chart that reads as the opposite of its own finding.
   */
  it('does not draw the leading format as the shortest bar', () => {
    const { container } = renderWithProviders(
      <ContentReading data={reading()} currency="SAR" creativesRead={19} />, { locale: 'en' },
    )

    const width = (testid: string) => {
      const bar = container.querySelector(`[data-testid="${testid}"] span span`) as HTMLElement | null
      return parseFloat(bar?.style.width ?? '0')
    }

    // The winner's bar is highlighted rather than longest — what must never happen is the geometry
    // silently contradicting the caption, so the leader is identified by its own class.
    const leader = container.querySelector('[data-testid="content-format-video"] span span') as HTMLElement
    expect(leader.className).toMatch(/bg-brand-500/)
    expect(width('content-format-image')).toBeGreaterThan(0)
  })

  it('renders nothing at all when the server said nothing', () => {
    const { container } = renderWithProviders(
      <ContentReading data={undefined} currency={null} creativesRead={0} />, { locale: 'en' },
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('reads in Arabic without leaking the English wording', () => {
    renderWithProviders(<ContentReading data={reading()} currency="SAR" creativesRead={19} />, { locale: 'ar' })

    expect(screen.getByTestId('content-reading-action')).toHaveTextContent(/فيديو/)
    expect(screen.getByTestId('content-reading')).not.toHaveTextContent(/earns its budget/)
  })
})
