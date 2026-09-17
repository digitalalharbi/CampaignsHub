import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { AdPreviewDialog } from './AdPreviewDialog'
import { renderWithProviders } from '@/test/utils'

/**
 * CONTENT-SPEND-ALWAYS-001 — the figure says where it came from.
 *
 * On every provider except Snapchat nothing writes the creative grain, so a creative's numbers are
 * summed from the ADS that ran it. That is the same money and a different provenance. The rule this
 * replaced protected the distinction by refusing to project ad figures onto a creative at all —
 * which is exactly how the owner came to see «—» for spend on a creative that was delivering.
 *
 * So the figures are shown AND the provenance is stated. What must not happen is the backend
 * carrying `grain` while no surface reads it, which is the shape of defect this session kept
 * finding: a correct answer computed somewhere nothing reads.
 */
const creative = (metrics: Record<string, unknown> | null) => ({
  id: 'cr-1',
  name: 'A creative',
  provider: 'meta',
  objective: null,
  preview: null,
  metrics,
}) as never

describe('a figure summed from ads says so', () => {
  it('states the provenance when the grain is the ad', () => {
    renderWithProviders(
      <AdPreviewDialog
        locale="en"
        creative={creative({ grain: 'ad', spend: 120 })}
        onClose={() => {}}
        figures={[{ key: 'spend', label: 'Spend', value: '120 SAR' }]}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('ad-preview-dialog-grain')).toHaveTextContent(/Summed from the ads/i)
  })

  /** The platform's own creative figure needs no caveat — saying it every time teaches readers to skip it. */
  it('says nothing when the platform reported the creative itself', () => {
    renderWithProviders(
      <AdPreviewDialog
        locale="en"
        creative={creative({ grain: 'creative', spend: 120 })}
        onClose={() => {}}
        figures={[{ key: 'spend', label: 'Spend', value: '120 SAR' }]}
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('ad-preview-dialog-grain')).toBeNull()
  })

  /** A report generated before `grain` existed carries none, and must not acquire a claim. */
  it('says nothing when the payload carries no grain at all', () => {
    renderWithProviders(
      <AdPreviewDialog
        locale="en"
        creative={creative({ spend: 120 })}
        onClose={() => {}}
        figures={[{ key: 'spend', label: 'Spend', value: '120 SAR' }]}
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('ad-preview-dialog-grain')).toBeNull()
  })
})

describe('a set partly filled from the ads says so', () => {
  it('states it when the creative reported itself and some figures came from its ads', () => {
    renderWithProviders(
      <AdPreviewDialog
        locale="en"
        creative={creative({ grain: 'creative', spend: 100, from_ads: ['spend', 'cpa'] })}
        onClose={() => {}}
        figures={[{ key: 'spend', label: 'Spend', value: '100 SAR' }]}
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('ad-preview-dialog-grain-partial')).toHaveTextContent(/summed from the ads/i)
    expect(screen.queryByTestId('ad-preview-dialog-grain')).toBeNull()
  })

  it('says nothing when nothing was taken from the ads', () => {
    renderWithProviders(
      <AdPreviewDialog
        locale="en"
        creative={creative({ grain: 'creative', spend: 100, from_ads: [] })}
        onClose={() => {}}
        figures={[{ key: 'spend', label: 'Spend', value: '100 SAR' }]}
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('ad-preview-dialog-grain-partial')).toBeNull()
  })
})
