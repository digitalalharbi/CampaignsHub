import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PlatformMark, hasPlatformMark, platformMarkLabel } from './PlatformMark'
import { FilterPlatforms } from '@/components/ui/FilterPlatforms'
import { PLATFORM_LABELS, PLATFORM_ORDER } from '@/lib/platforms'

/**
 * PLATFORM-MARK-001 — a logo is allowed to replace a name only while the name is still there.
 *
 * The filter spelled seven platforms out and was the widest control on the dashboard's bar. Logos
 * are what a reader recognises fastest and what makes the bar fit, and they are also the easiest way
 * to build a control that is unusable to somebody who cannot see them. These hold both halves.
 */
describe('the platform marks', () => {
  /** Every canonical platform has one — a filter where some chips are logos and others are words. */
  it('covers every canonical platform', () => {
    for (const key of PLATFORM_ORDER) {
      expect(hasPlatformMark(key), `${key} has no mark`).toBe(true)
    }
  })

  /** And resolves the spellings, because a report sends `openai` and a filter sends `openai_ads`. */
  it('reads every spelling of a platform', () => {
    for (const spelling of ['openai', 'chatgpt', 'google_ads', 'snapchat_ads']) {
      expect(hasPlatformMark(spelling), `${spelling} resolves to no mark`).toBe(true)
    }
  })

  it('takes the colour of whatever it sits in', () => {
    const { container } = render(<PlatformMark platform="openai_ads" />)

    expect(container.querySelector('svg')?.getAttribute('fill')).toBe('currentColor')
  })

  /** A platform with no mark draws nothing rather than a placeholder that claims to be its logo. */
  it('draws nothing for a platform it has no mark for', () => {
    const { container } = render(<PlatformMark platform="pinterest" />)

    expect(container.querySelector('svg')).toBeNull()
  })

  it('still knows every platform by name', () => {
    expect(platformMarkLabel('openai_ads', true)).toBe(PLATFORM_LABELS.openai_ads.ar)
    expect(platformMarkLabel('openai_ads', false)).toBe(PLATFORM_LABELS.openai_ads.en)
  })
})

describe('the platform filter drawn as logos', () => {
  const options = PLATFORM_ORDER.map((key) => ({ value: key, label: PLATFORM_LABELS[key].ar }))

  /**
   * **The name is never gone, only moved.**
   *
   * A chip identified by a shape alone is unusable to a screen reader and unreadable to anybody who
   * does not already know the brand. It moves to `aria-label` and `title`, so assistive technology
   * announces it and a pointer reveals it.
   */
  it('keeps every platform findable by its name', () => {
    render(
      <FilterPlatforms
        label="المنصة"
        allLabel="الكل"
        values={[]}
        options={options}
        onChange={vi.fn()}
        testid="t-platform"
        marks
      />,
    )

    for (const key of PLATFORM_ORDER) {
      const chip = screen.getByTestId(`t-platform-${key}`)

      expect(chip).toHaveAttribute('aria-label', PLATFORM_LABELS[key].ar)
      expect(chip).toHaveAttribute('title', PLATFORM_LABELS[key].ar)
      expect(chip.querySelector('svg'), `${key} has no logo`).not.toBeNull()
    }

    // And the control itself is still named, so «what am I filtering by» needs no guessing.
    expect(screen.getByText('المنصة')).toBeInTheDocument()
  })

  /** Selection stays legible without reading anything — `aria-pressed` carries it either way. */
  it('says which platforms are on', () => {
    render(
      <FilterPlatforms
        label="المنصة"
        allLabel="الكل"
        values={['openai_ads']}
        options={options}
        onChange={vi.fn()}
        testid="t-platform"
        marks
      />,
    )

    expect(screen.getByTestId('t-platform-openai_ads')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('t-platform-meta')).toHaveAttribute('aria-pressed', 'false')
    // Empty means ALL, so a selection means «الكل» is off — the contract the API expects.
    expect(screen.getByTestId('t-platform-all')).toHaveAttribute('aria-pressed', 'false')
  })

  /** Without `marks` nothing changes: the analysis surface keeps the names on the chips. */
  it('keeps the names when marks are not asked for', () => {
    render(
      <FilterPlatforms
        label="المنصة"
        allLabel="الكل"
        values={[]}
        options={options}
        onChange={vi.fn()}
        testid="t-platform"
      />,
    )

    expect(screen.getByTestId('t-platform-openai_ads')).toHaveTextContent('إعلانات ChatGPT')
  })
})
