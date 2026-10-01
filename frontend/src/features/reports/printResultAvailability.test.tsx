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

function printed(availability: string | null) {
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

  /* The «Results» cell is the last column of the ad table — read IT, not the whole row: the row
     also carries «38.36 SAR», and a loose match on «0» finds the one inside the price. */
  const cells = screen.getByText('Story ad').closest('tr')?.querySelectorAll('td') ?? []

  return (cells[cells.length - 1]?.textContent ?? '').trim()
}

describe('a result in the forwarded document', () => {
  it('prints a dash where the account’s measurement is unverified', () => {
    expect(printed('measurement_unverified'), 'the PDF printed a measured zero the card shows as a dash').toBe('—')
  })

  it('prints the zero where the account’s own history confirms it', () => {
    expect(printed('real_zero_confirmed')).toBe('0')
  })

  /** A row with no figures bag at all keeps the old reading — nothing regresses by being silent. */
  it('falls back to the flat column where the row carries no figures', () => {
    expect(printed(null)).toBe('0')
  })
})
