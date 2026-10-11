import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { AttributionDisagreementView } from './AttributionDisagreementView'
import type { AttributionDisagreement } from './api'
import { renderWithProviders } from '@/test/utils'

/** CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — when each platform's claim parted from the store. */
const point = (from: string, to: string, claimed: number, confirmed: number) => ({
  from, to, claimed, confirmed, gap: claimed - confirmed, ratio: confirmed > 0 ? claimed / confirmed : null,
})

const data: AttributionDisagreement = {
  available: true,
  unavailable_reason: null,
  note_ar: 'لا يُجمع بين المنصات.',
  note_en: 'Never summed across platforms.',
  grain: 'week',
  periods: [{ from: '2026-09-12', to: '2026-09-18' }, { from: '2026-09-19', to: '2026-09-25' }],
  platforms: [
    { provider: 'meta', claimed: 9, confirmed: 5, widest_gap: point('2026-09-19', '2026-09-25', 6, 2), points: [point('2026-09-12', '2026-09-18', 3, 3), point('2026-09-19', '2026-09-25', 6, 2)] },
    { provider: 'google', claimed: 2, confirmed: 0, widest_gap: point('2026-09-12', '2026-09-18', 2, 0), points: [point('2026-09-12', '2026-09-18', 2, 0), point('2026-09-19', '2026-09-25', 0, 0)] },
  ],
}

describe('AttributionDisagreementView', () => {
  it('shows one platform at a time, names where its claim ran furthest ahead, and gives no ratio where the store confirmed nothing', () => {
    renderWithProviders(<AttributionDisagreementView data={data} ar={false} />)

    expect(screen.getByTestId('disagreement-widest')).toHaveTextContent('2026-09-19 – 2026-09-25')
    expect(screen.getByTestId('disagreement-table-meta')).toHaveTextContent('+4')
    expect(screen.getByTestId('disagreement-table-meta')).toHaveTextContent('3×')

    fireEvent.click(screen.getByTestId('disagreement-platform-google'))
    const google = screen.getByTestId('disagreement-table-google')
    expect(within(google).getAllByText('—').length).toBeGreaterThan(0)
    expect(screen.queryByTestId('disagreement-table-meta')).toBeNull()
  })

  it('says there is nothing to compare without a store', () => {
    renderWithProviders(<AttributionDisagreementView data={{ ...data, available: false, unavailable_reason: 'no_store_connected', grain: null, periods: [], platforms: [] }} ar={false} />)

    expect(screen.getByTestId('attribution-disagreement-unavailable')).toHaveTextContent('No connected store')
  })
})
