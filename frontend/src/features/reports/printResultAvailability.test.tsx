import { beforeAll, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { renderWithProviders } from '@/test/utils'

/**
 * CONTENT-RESULT-AVAILABILITY-001 §14 — owner regression 7, on the copy that gets forwarded.
 *
 * «One creative + same account + same period must tell the same factual story everywhere», and the
 * printed PDF is the surface where a wrong number does the most damage: it is quoted, filed and
 * read months later by somebody who cannot check it.
 *
 * It read the raw `conversions` column, so a zero the Content card shows as «—» printed here as a
 * measured `0` — the same number on two surfaces meaning opposite things.
 */
beforeAll(() => {
  if (!(document as Document & { fonts?: unknown }).fonts) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const ad = (availability: string | null) => ({
  name: 'Story ad',
  provider: 'snapchat',
  spend: 38.36,
  conversions: 0,
  metrics: availability === null
    ? null
    : { conversions: 0, reported: { conversions: true }, availability: { conversions: availability } },
  preview: { state: 'available', kind: 'image', image_url: 'https://cdn.example/a.jpg' },
})

function adTable(availability: string | null): HTMLTableElement {
  renderWithProviders(
    <PrintDocument
      data={{
        period: { from: '2026-08-01', to: '2026-08-31' },
        platforms: [],
        objective: 'Sales',
        ads: [ad(availability)],
      } as never}
      reportName="August"
      currency="SAR"
    />,
    { locale: 'en' },
  )

  return screen.getByText('Story ad').closest('table') as HTMLTableElement
}

describe('a result in the forwarded document', () => {
  /**
   * RESULT-EVENTS-001 (Owner 2026-10-29) — an ad's generic conversion is not its Result. Whatever the measurement says about it (a
   * dash, a confirmed zero, no figures at all), the PDF's ad table prints no «Results» column built from it.
   */
  it.each(['measurement_unverified', 'real_zero_confirmed', null])('prints no Results column from an ad\'s generic conversions (%s)', (availability) => {
    const table = adTable(availability)

    expect(Array.from(table.querySelectorAll('th')).map((th) => th.textContent)).not.toContain('Results')
    expect(screen.getByText('Story ad').closest('tr')?.querySelectorAll('td').length).toBe(4)
  })
})
