import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { withRankedFigure, ContentTile } from './LiveContent'
import type { ReportAd } from '../ReportAdsSection'
import type { AdsBasis } from '../api'

/** The card shows the figure it is ranked by: ChatGPT ads rank on clicks and used to print only «Spend». */
const basis: AdsBasis = { family: 'unknown', metric: 'clicks', label_ar: 'النقرات', label_en: 'Clicks', family_label_ar: null, family_label_en: null, fallback: true, note_ar: 'رتّبنا حسب النقرات', note_en: 'Ranked by clicks' }
const ad = { name: 'Chat card', provider: 'chatgpt_ads', content_key: 'k1', spend: 120, clicks: 1234, impressions: null } as unknown as ReportAd

describe('ranked-by figure on a content card', () => {
  it('puts clicks first, Latin digits, no currency mark', () => {
    const out = withRankedFigure([{ label: 'Spend', value: '120 SAR' }], ad, basis, false)
    expect(out[0]).toEqual({ label: 'Clicks', value: '1,234' })
    expect(out[0].value).not.toMatch(/SAR|ر\.س/)
    expect(out).toHaveLength(2)
  })

  it('uses the Arabic label and keeps three figures at most', () => {
    const three = [{ label: 'a', value: '1' }, { label: 'b', value: '2' }, { label: 'c', value: '3' }]
    const out = withRankedFigure(three, ad, basis, true)
    expect(out[0].label).toBe('النقرات')
    expect(out).toHaveLength(3)
  })

  it('does not repeat a figure the card already has, nor add money or ratio metrics', () => {
    expect(withRankedFigure([{ label: 'Clicks', value: '9' }], ad, basis, false)).toHaveLength(1)
    expect(withRankedFigure([], ad, { ...basis, metric: 'cpa' }, false)).toEqual([])
    expect(withRankedFigure([], ad, null, false)).toEqual([])
  })

  it('renders clicks on a client tile while spend stays hidden (results-only)', () => {
    render(<ContentTile content={ad} locale="en" currency="SAR" resultsOnly rankedBy={basis} />)
    expect(screen.getByText('Clicks')).toBeTruthy()
    expect(screen.getByText('1,234')).toBeTruthy()
    expect(screen.queryByText('Spend')).toBeNull()
  })

  it('a card in a list with no basis is unchanged', () => {
    render(<ContentTile content={ad} locale="en" currency="SAR" />)
    expect(screen.queryByText('Clicks')).toBeNull()
    expect(screen.getByText('Spend')).toBeTruthy()
  })
})
