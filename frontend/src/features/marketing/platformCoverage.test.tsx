import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { PublicHomePage } from './PublicHomePage'
import { PLATFORM_LABELS, PLATFORM_ORDER } from '@/lib/platforms'
import { renderWithProviders } from '@/test/utils'

/**
 * PLATFORM-COVERAGE-001 — «which platforms?» is a first-screen question, answered from one list.
 *
 * The public page kept its own platform list, so a platform the product had learned to read was
 * absent from the one page that tells anybody it exists. Driving it from `PLATFORM_ORDER` makes the
 * omission impossible; this holds that it stays driven, and that the page says what it supports
 * without saying who may buy it.
 */
describe('the public platform coverage', () => {
  it('names every canonical platform, in the canonical order', () => {
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })

    const strip = screen.getByTestId('hero-platform-coverage')

    // Order matters: a visitor comparing this page with the product's own filters should meet the
    // platforms in the same sequence, which is the whole reason the order is written down once.
    expect([...strip.children].map((c) => c.textContent?.trim())).toEqual(
      PLATFORM_ORDER.map((key) => PLATFORM_LABELS[key].ar),
    )
  })

  /** Including the newest one, by name, not as «other» or «coming soon». */
  it('shows ChatGPT Ads as a platform like any other', () => {
    renderWithProviders(<PublicHomePage />, { locale: 'ar' })

    const chip = screen.getByTestId('hero-platform-openai_ads')

    expect(chip).toHaveTextContent('إعلانات ChatGPT')
    expect(chip.textContent).not.toMatch(/قريبًا|أخرى|تجريبي|beta/i)
  })

  /**
   * **And it does not answer a question OpenAI has not answered.**
   *
   * Coverage is ours to state: the product reads this provider. Eligibility is not — whether a given
   * advertiser in a given market can buy ChatGPT Ads at all is OpenAI's answer, and a homepage that
   * implies otherwise makes a promise it cannot keep.
   */
  it('claims coverage and never universal availability', () => {
    const { container } = renderWithProviders(<PublicHomePage />, { locale: 'ar' })

    const text = container.textContent ?? ''

    for (const claim of ['متاحة في السعودية', 'متاح للجميع', 'متاحة لجميع المعلنين', 'حول العالم']) {
      expect(text, `the page claims «${claim}»`).not.toContain(claim)
    }

    // And the old count claim, which became wrong the moment a seventh platform existed.
    expect(text).not.toContain('المنصات الست')
  })
})
