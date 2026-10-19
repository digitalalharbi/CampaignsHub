import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { CampaignQuickPreview } from './CampaignQuickPreview'
import type { CampaignRow } from './api'
import { renderWithProviders } from '@/test/utils'

const trend = vi.hoisted(() => ({ rows: [] as Array<Record<string, unknown>> }))

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  useTimeseries: () => ({ data: trend.rows, isLoading: false, isError: false }),
}))

const row = (over: Partial<CampaignRow> = {}): CampaignRow => ({
  campaign_id: 'c1',
  campaign_name: 'Spring sale',
  provider: 'meta',
  objective: 'sales',
  objective_family: 'sales',
  objective_source: 'platform',
  status: 'active',
  last_active_on: '2026-09-14',
  spend: 1000,
  revenue: 4000,
  conversions: 50,
  ...over,
} as CampaignRow)

/**
 * VISUAL-DECISION-001 — shallow on purpose, and a door to the deep answer.
 *
 * Analytics stays the cross-platform workspace: the preview says only enough to decide whether to
 * open the campaign, and everything else lives one click away in the canonical Campaign Detail.
 */
describe('the campaign quick preview', () => {
  it('leads to the canonical campaign detail', () => {
    renderWithProviders(
      <CampaignQuickPreview row={row()} projectId="p1" range={{ from: '2026-09-01', to: '2026-09-30' }} onClose={() => {}} />,
      { route: '/agency/analytics', locale: 'en' },
    )

    expect(screen.getByTestId('campaign-quick-preview-cta')).toHaveAttribute(
      'href',
      expect.stringContaining('/agency/campaigns/p1/c1'),
    )
  })

  /**
   * An unreported figure is «—», never a zero.
   *
   * The whole money contract rests on this distinction, and a popup that quietly rendered a missing
   * revenue as 0.00× would be a second opinion about money on the surface where a reader is deciding
   * what to do next.
   */
  it('shows a dash where the platform reported nothing, not a zero', () => {
    renderWithProviders(
      <CampaignQuickPreview
        row={row({ revenue: null, conversions: null } as unknown as Partial<CampaignRow>)}
        projectId="p1"
        range={{ from: '2026-09-01', to: '2026-09-30' }}
        onClose={() => {}}
      />,
      { route: '/agency/analytics', locale: 'en' },
    )

    const results = screen.getByText('Results').parentElement?.textContent ?? ''

    expect(results).toContain('—')
    expect(results).not.toMatch(/\b0\b/)
  })

  /** A reported zero is a figure the platform sent, and it is shown as one. */
  it('shows a reported zero as zero', () => {
    renderWithProviders(
      <CampaignQuickPreview row={row({ conversions: 0 })} projectId="p1" range={{ from: '2026-09-01', to: '2026-09-30' }} onClose={() => {}} />,
      { route: '/agency/analytics', locale: 'en' },
    )

    expect(screen.getByText('Results').parentElement?.textContent ?? '').toContain('0')
  })

  /** The objective is a label, not the enum member the backend computes it as. */
  it('names the objective family rather than printing its key', () => {
    renderWithProviders(
      <CampaignQuickPreview row={row()} projectId="p1" range={{ from: '2026-09-01', to: '2026-09-30' }} onClose={() => {}} />,
      { route: '/agency/analytics', locale: 'ar' },
    )

    expect(screen.getByTestId('campaign-quick-preview').textContent ?? '').not.toContain('sales')
  })

  /* REPORT-CHARTS-COMPLETE-001 — the sparkline is drawn only from honest days, and says what it is. */
  describe('the spend sparkline', () => {
    const open = () => renderWithProviders(
      <CampaignQuickPreview row={row()} projectId="p1" range={{ from: '2026-09-01', to: '2026-09-30' }} onClose={() => {}} />,
      { route: '/agency/analytics', locale: 'en' },
    )

    it('is drawn from real days and labelled', () => {
      trend.rows = [{ date: '2026-09-01', spend: 100 }, { date: '2026-09-02', spend: 140 }, { date: '2026-09-03', spend: 120 }]
      open()

      expect(screen.getByTestId('campaign-quick-preview-trend')).toHaveTextContent('Daily spend')
      expect(screen.getByRole('img', { name: 'Daily spend trend for the campaign' })).toBeInTheDocument()
    })

    it('is not drawn when the days carry no spend, instead of a flat line at zero', () => {
      trend.rows = [{ date: '2026-09-01', spend: null }, { date: '2026-09-02', spend: null }]
      open()

      expect(screen.queryByTestId('campaign-quick-preview-trend')).not.toBeInTheDocument()
    })

    it('is not drawn when the money is withheld awaiting a rate', () => {
      trend.rows = [
        { date: '2026-09-01', spend: null, spend_withheld_rows: 1, spend_original: 50, money_original_currency: 'EUR', money_original_currencies: 1 },
        { date: '2026-09-02', spend: null, spend_withheld_rows: 1, spend_original: 60, money_original_currency: 'EUR', money_original_currencies: 1 },
      ]
      open()

      expect(screen.queryByTestId('campaign-quick-preview-trend')).not.toBeInTheDocument()
    })
  })
})
