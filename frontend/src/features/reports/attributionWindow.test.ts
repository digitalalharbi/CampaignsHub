import { describe, expect, it } from 'vitest'

import { attributionWindow } from './attributionWindow'

/** ATTRIBUTION-WINDOW-001 — «أساس الإسناد: 7d_click_1d_view» on the footer of a client's report. */
describe('the attribution window, as a reader sees it', () => {
  it('reads the common Meta window as prose in both languages', () => {
    expect(attributionWindow('7d_click_1d_view', true)).toEqual({ text: 'نقرة خلال 7 أيام، ومشاهدة خلال يوم واحد', known: true })
    expect(attributionWindow('7d_click_1d_view', false)).toEqual({ text: '7-day click · 1-day view', known: true })
  })

  it('handles a click-only window', () => {
    expect(attributionWindow('28d_click', false).text).toBe('28-day click')
  })

  it('gets Arabic plurals right, which is where a naive template reads as broken', () => {
    expect(attributionWindow('1d_click', true).text).toBe('نقرة خلال يوم واحد')
    expect(attributionWindow('2d_click', true).text).toBe('نقرة خلال يومين')
    expect(attributionWindow('7d_click', true).text).toBe('نقرة خلال 7 أيام')
    expect(attributionWindow('28d_click', true).text).toBe('نقرة خلال 28 يومًا')
  })

  /**
   * `default` means the platform did not say. Stated plainly rather than shown as the word, which
   * reads as a setting somebody chose — and marked unknown, because the figures are fine and only
   * the basis is unstated.
   */
  it('says plainly when the platform did not state a window', () => {
    const r = attributionWindow('default', true)

    expect(r.known).toBe(false)
    expect(r.text).not.toContain('default')
  })

  /** An unrecognised window is a fact about the data; inventing «7 days» for it would be a claim. */
  it('returns an unparseable window unchanged rather than guessing', () => {
    expect(attributionWindow('lifetime_v2', false)).toEqual({ text: 'lifetime_v2', known: false })
  })

  it('treats an absent window as not stated', () => {
    expect(attributionWindow(null, true).known).toBe(false)
    expect(attributionWindow('', true).known).toBe(false)
  })
})
