import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { DeliveryOverviewPage } from './DeliveryOverviewPage'
import { renderWithProviders } from '@/test/utils'
import type { DeliveryOverviewRow } from './deliveryApi'

vi.mock('./deliveryApi', async (orig) => ({ ...(await orig<typeof import('./deliveryApi')>()), getDeliveryOverview: vi.fn() }))
import { getDeliveryOverview } from './deliveryApi'

const row = (over: Partial<DeliveryOverviewRow>): DeliveryOverviewRow => ({
  client_id: 'c1', client: 'Razzah',
  pack: { id: 'p1', period: { from: '2026-10-12', to: '2026-10-18' }, status: 'sent', sent_at: '2026-10-19T08:00:00Z' },
  report: { status: 'completed', undecided_recommendations: 0 }, evidence: 2, last_viewed_at: null,
  invoice: null, follow_up_required: false, next_action: 'none', visual: 'ok', ...over,
})

describe('DeliveryOverviewPage', () => {
  beforeEach(() => vi.clearAllMocks())

  it('puts the client that needs the Owner first and names the one next step', async () => {
    vi.mocked(getDeliveryOverview).mockResolvedValue([
      row({ client_id: 'calm', client: 'Alpha' }),
      row({
        client_id: 'hot', client: 'Zeta', next_action: 'follow_up_payment', visual: 'urgent', follow_up_required: true, last_viewed_at: '2026-10-18T10:00:00Z',
        invoice: { id: 1, number: 'INV-1', status: 'overdue', currency: 'SAR', total: 1150, amount_due: 1000, due_date: '2026-10-10' },
      }),
    ])
    renderWithProviders(<DeliveryOverviewPage />, { locale: 'en' })

    const list = await screen.findByTestId('delivery-overview')
    const rows = within(list).getAllByRole('listitem')
    expect(rows[0]).toHaveAttribute('data-testid', 'delivery-row-hot')
    expect(within(rows[0]).getByText('Follow up the payment')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Overdue')).toBeInTheDocument()
    expect(within(rows[0]).getByText('1K SAR')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Nothing needed')).toBeInTheDocument()
  })

  it('never invents a zero for a client whose billing the reader cannot see', async () => {
    vi.mocked(getDeliveryOverview).mockResolvedValue([row({ invoice: null })])
    renderWithProviders(<DeliveryOverviewPage />, { locale: 'en' })

    expect(await screen.findByText('No invoice')).toBeInTheDocument()
    expect(screen.queryByText(/SAR/)).not.toBeInTheDocument()
  })

  it('speaks Arabic', async () => {
    vi.mocked(getDeliveryOverview).mockResolvedValue([row({ next_action: 'prepare_pack', visual: 'attention', pack: null })])
    renderWithProviders(<DeliveryOverviewPage />, { locale: 'ar' })

    expect(await screen.findByText('جهّز تسليم الأسبوع الماضي')).toBeInTheDocument()
    expect(screen.getByText('لا توجد حزمة بعد')).toBeInTheDocument()
  })
})
