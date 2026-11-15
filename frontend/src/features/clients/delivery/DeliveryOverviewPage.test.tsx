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
    expect(within(rows[0]).getByText('1K ر.س')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Nothing needed')).toBeInTheDocument()
  })

  it('never invents a zero for a client whose billing the reader cannot see', async () => {
    vi.mocked(getDeliveryOverview).mockResolvedValue([row({ invoice: null })])
    renderWithProviders(<DeliveryOverviewPage />, { locale: 'en' })

    expect(await screen.findByText('No invoice')).toBeInTheDocument()
    expect(screen.queryByText(/(?:SAR|ر\.س)/)).not.toBeInTheDocument()
  })

  it('speaks Arabic', async () => {
    vi.mocked(getDeliveryOverview).mockResolvedValue([row({ next_action: 'prepare_pack', visual: 'attention', pack: null })])
    renderWithProviders(<DeliveryOverviewPage />, { locale: 'ar' })

    expect(await screen.findByText('جهّز تسليم الأسبوع الماضي')).toBeInTheDocument()
    expect(screen.getByText('لا توجد حزمة بعد')).toBeInTheDocument()
  })

  it('lists a client whose scheduled reports are going out instead of «لا توجد حزمة بعد»', async () => {
    vi.mocked(getDeliveryOverview).mockResolvedValue([row({
      client_id: 'sch', pack: null, next_action: 'none', visual: 'ok',
      scheduled: {
        active: 1, next_run_at: '2026-11-22T05:00:00Z', last_run_at: '2026-11-15T05:00:00Z',
        last_report: { id: 'r1', project_id: 'p1', status: 'completed', generated_at: '2026-11-15T05:01:00Z', period: { from: '2026-10-17', to: '2026-11-15' } },
        deliveries: { awaiting_provider_credentials: 1 },
      },
    })])
    renderWithProviders(<DeliveryOverviewPage />, { locale: 'ar' })

    const scheduled = await screen.findByTestId('delivery-scheduled-sch')
    expect(scheduled).toHaveTextContent('تقرير مجدول')
    expect(scheduled).toHaveTextContent('آخر إرسال')
    expect(scheduled).toHaveTextContent('بانتظار مزود بريد')
    expect(screen.queryByText('لا توجد حزمة بعد')).not.toBeInTheDocument()
    expect(screen.getByText('لا شيء مطلوب')).toBeInTheDocument()
  })
})
