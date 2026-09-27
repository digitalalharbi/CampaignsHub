import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ClientReportsPage } from './ClientReportsPage'
import type { PortalReport } from './portalAccountApi'
import { renderWithProviders } from '@/test/utils'

// Keep the real formatting helpers; mock only the network call.
vi.mock('./portalAccountApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./portalAccountApi')>()
  return { ...actual, listClientReports: vi.fn() }
})

import { listClientReports } from './portalAccountApi'

const report: PortalReport = {
  id: 'r1',
  name: 'تقرير الأداء الشهري — Demo',
  type: 'monthly',
  type_label_ar: 'تقرير شهري',
  type_label_en: 'Monthly',
  audience: 'client',
  status: 'completed',
  period_start: '2026-08-29',
  period_end: '2026-09-27',
  generated_at: '2026-09-27T09:00:00Z',
  share: { shared: true, allow_download: true, watermark: false, expires_at: null },
}

describe('ClientReportsPage', () => {
  beforeEach(() => vi.clearAllMocks())

  /**
   * The client reads their own language, not our storage values.
   *
   * The same defect `ClientCampaignsPage` already fixed for `objective` and `status`, one page over:
   * the type pill printed `report.type` raw, so a client whose portal is Arabic throughout met a
   * bare «monthly» in the middle of it. Seen on the rendered page, not in the code.
   */
  it('names the report type in the reader’s language, not as the stored key', async () => {
    vi.mocked(listClientReports).mockResolvedValue([report])

    renderWithProviders(<ClientReportsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('portal-report-type')).toHaveTextContent('تقرير شهري')
    expect(screen.queryByText('monthly')).not.toBeInTheDocument()
  })

  it('names it in English for an English reader', async () => {
    vi.mocked(listClientReports).mockResolvedValue([report])

    renderWithProviders(<ClientReportsPage />, { locale: 'en' })

    expect(await screen.findByTestId('portal-report-type')).toHaveTextContent('Monthly')
  })

  /**
   * A type the taxonomy has no word for reads as ITSELF.
   *
   * The fallback matters more than it looks: a new report type ships before anyone adds its label,
   * and an empty pill on a client's page is worse than an untranslated one — it reads as a
   * rendering fault rather than as a word we have not translated yet.
   */
  it('falls back to the stored key when the taxonomy has no word for it', async () => {
    vi.mocked(listClientReports).mockResolvedValue([
      { ...report, type: 'quarterly', type_label_ar: null, type_label_en: null },
    ])

    renderWithProviders(<ClientReportsPage />, { locale: 'ar' })

    expect(await screen.findByTestId('portal-report-type')).toHaveTextContent('quarterly')
  })
})
