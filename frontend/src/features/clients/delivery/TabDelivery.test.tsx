import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { TabDelivery } from './TabDelivery'
import { renderWithProviders } from '@/test/utils'
import type { DeliveryPack, DeliverySettingsData, PackDetail } from './deliveryApi'

vi.mock('./deliveryApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./deliveryApi')>()
  return {
    ...actual,
    listPacks: vi.fn(), getPack: vi.fn(), preparePack: vi.fn(), setComponents: vi.fn(), attachInvoice: vi.fn(), approvePack: vi.fn(),
    markPackSent: vi.fn(), whatsappText: vi.fn(), editRecommendation: vi.fn(), setRecommendationStatus: vi.fn(), uploadEvidence: vi.fn(), deleteEvidence: vi.fn(), getDeliverySettings: vi.fn(), saveDeliverySettings: vi.fn(),
  }
})
vi.mock('@/features/billing/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/billing/api')>()
  return { ...actual, listInvoices: vi.fn() }
})

import * as api from './deliveryApi'
import { listInvoices } from '@/features/billing/api'

/**
 * WD-PACK-001, WD-DRAFTS-001, WD-WHATSAPP-001, WD-EVIDENCE-001 — the Owner's weekly review: what blocks
 * approval is visible, every recommendation needs a decision, evidence drops in, and the WhatsApp text
 * appears only once the pack is approved.
 */
const readiness = { report: 'completed', undecided_recommendations: 2, approved_recommendations: 0, evidence: { included: true, count: 0 }, invoice: { included: true, status: 'none' }, approved: false, ready_to_approve: false }

const pack = (over: Partial<DeliveryPack> = {}): DeliveryPack => ({
  id: 'pk1', client_workspace_id: 'c1', project_id: 'p1', report_id: 'r1', invoice_id: null, period: { from: '2026-10-05', to: '2026-10-11' },
  status: 'draft', components: { whatsapp: true, link: true, report_pdf: true, evidence: true, invoice_pdf: true, amount_due: true, spend: false },
  approved_at: null, sent_at: null, readiness, ...over,
})

const detail = (over: Partial<PackDetail> = {}): PackDetail => ({
  ...pack(), evidence: [],
  recommendations: [
    { id: 'a1', platform: 'snapchat', text_ar: 'انقل الميزانية إلى سناب', text_en: 'Move budget to Snapchat', proposed_action: null, priority: 'high', status: 'draft', source: 'auto' },
    { id: 'a2', platform: null, text_ar: 'أوقف المجموعة الضعيفة', text_en: 'Pause the weak set', proposed_action: null, priority: 'normal', status: 'reviewed', source: 'edited' },
  ],
  ...over,
})

const mount = () => renderWithProviders(<TabDelivery clientId="c1" projects={[{ id: 'p1', name: 'Store' }]} />, { locale: 'en' })

const settings = (o: Partial<DeliverySettingsData> = {}): DeliverySettingsData => ({ enabled: false, cadence: 'weekly', week_start: 'monday', mode: 'snapshot', components: {}, next_period: { from: '2026-10-12', to: '2026-10-18' }, ...o })

describe('TabDelivery', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api.listPacks).mockResolvedValue([pack()])
    vi.mocked(api.getPack).mockResolvedValue(detail())
    vi.mocked(api.getDeliverySettings).mockResolvedValue(settings())
    vi.mocked(listInvoices).mockResolvedValue({ items: [], total: 0, withheld: 0 } as never)
  })

  it('says there is nothing yet and prepares last week on request', async () => {
    vi.mocked(api.listPacks).mockResolvedValue([])
    vi.mocked(api.preparePack).mockResolvedValue(pack())
    mount()

    expect(await screen.findByText(/No delivery packs yet/)).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('delivery-prepare'))
    await waitFor(() => expect(api.preparePack).toHaveBeenCalledWith('p1'))
  })

  it('shows what blocks approval and keeps the approve button disabled until every recommendation has a decision', async () => {
    mount()
    fireEvent.click(await screen.findByTestId('pack-pk1'))

    const panel = await screen.findByTestId('pack-panel')
    expect(within(panel).getByTestId('check-undecided')).toHaveTextContent('2')
    expect(within(panel).getByTestId('pack-approve')).toBeDisabled()
    expect(within(panel).queryByTestId('pack-whatsapp')).toBeNull()
  })

  it('records a decision on a recommendation and marks an edited one as edited', async () => {
    vi.mocked(api.setRecommendationStatus).mockResolvedValue({} as never)
    mount()
    fireEvent.click(await screen.findByTestId('pack-pk1'))
    const rec = await screen.findByTestId('rec-a1')

    fireEvent.click(within(rec).getByRole('button', { name: /Approve/ }))
    await waitFor(() => expect(api.setRecommendationStatus).toHaveBeenCalledWith('p1', 'r1', 'a1', 'approved'))
    expect(within(screen.getByTestId('rec-a2')).getByText('Edited')).toBeInTheDocument()
  })

  it('lets the Owner rewrite a draft in their own words', async () => {
    vi.mocked(api.editRecommendation).mockResolvedValue({} as never)
    mount()
    fireEvent.click(await screen.findByTestId('pack-pk1'))
    const rec = await screen.findByTestId('rec-a1')

    fireEvent.click(within(rec).getByRole('button', { name: /Edit/ }))
    fireEvent.change(within(rec).getByRole('textbox'), { target: { value: 'My own words' } })
    fireEvent.click(within(rec).getByRole('button', { name: /Save/ }))

    await waitFor(() => expect(api.editRecommendation).toHaveBeenCalledWith('pk1', 'a1', { text_en: 'My own words' }))
  })

  it('attaches a screenshot dropped on the evidence area for the pack’s own period', async () => {
    vi.mocked(api.uploadEvidence).mockResolvedValue({} as never)
    mount()
    fireEvent.click(await screen.findByTestId('pack-pk1'))
    await screen.findByTestId('pack-panel')

    const file = new File(['x'], 'snap.png', { type: 'image/png' })
    fireEvent.drop(screen.getByTestId('evidence-drop'), { dataTransfer: { files: [file] } })

    await waitFor(() => expect(api.uploadEvidence).toHaveBeenCalledWith(expect.objectContaining({ clientId: 'c1', projectId: 'p1', from: '2026-10-05', to: '2026-10-11', file })))
  })

  it('saves the delivery settings one choice at a time and shows the period they produce', async () => {
    vi.mocked(api.saveDeliverySettings).mockImplementation(async (_id, body) => settings({ ...body, next_period: { from: '2026-10-01', to: '2026-10-31' } }))
    mount()
    const card = await screen.findByTestId('delivery-settings')
    expect(card.textContent).toContain('2026-10-12')

    fireEvent.click(within(card).getByTestId('delivery-auto'))
    await waitFor(() => expect(api.saveDeliverySettings).toHaveBeenCalledWith('c1', { enabled: true }))

    fireEvent.click(within(card).getByTestId('delivery-cadence-monthly'))
    await waitFor(() => expect(api.saveDeliverySettings).toHaveBeenCalledWith('c1', { cadence: 'monthly' }))
    await waitFor(() => expect(screen.getByTestId('delivery-settings').textContent).toContain('2026-10-31'))
    fireEvent.click(within(screen.getByTestId('delivery-settings')).getByTestId('delivery-mode-live'))
    await waitFor(() => expect(api.saveDeliverySettings).toHaveBeenCalledWith('c1', { mode: 'live' }))
  })

  it('only a weekly cadence asks which day the week begins', async () => {
    vi.mocked(api.getDeliverySettings).mockResolvedValue(settings({ cadence: 'monthly' }))
    mount()
    const card = await screen.findByTestId('delivery-settings')
    expect(within(card).queryByTestId('delivery-weekstart-sunday')).toBeNull()
  })

  it('lets the Owner choose which pieces this client receives', async () => {
    vi.mocked(api.setComponents).mockResolvedValue(pack())
    mount()
    fireEvent.click(await screen.findByTestId('pack-pk1'))
    await screen.findByTestId('pack-panel')

    fireEvent.click(screen.getByTestId('piece-spend'))

    await waitFor(() => expect(api.setComponents).toHaveBeenCalledWith('pk1', { spend: true }))
  })

  it('approves a ready pack, and once approved shows the WhatsApp text and the sent action', async () => {
    vi.mocked(api.getPack).mockResolvedValueOnce(detail({ readiness: { ...readiness, undecided_recommendations: 0, ready_to_approve: true } }))
    vi.mocked(api.approvePack).mockResolvedValue(pack({ status: 'approved' }))
    // What the server answers once the approval has landed: the next read of the pack is the approved one.
    vi.mocked(api.getPack).mockResolvedValue(detail({ status: 'approved', readiness: { ...readiness, undecided_recommendations: 0, approved: true } }))
    vi.mocked(api.whatsappText).mockResolvedValue('Hello — Razzah campaign performance')
    mount()
    fireEvent.click(await screen.findByTestId('pack-pk1'))
    const approve = await screen.findByTestId('pack-approve')
    expect(approve).toBeEnabled()
    fireEvent.click(approve)
    await waitFor(() => expect(api.approvePack).toHaveBeenCalledWith('pk1'))

    const wa = await screen.findByTestId('pack-whatsapp')
    await waitFor(() => expect(within(wa).getByRole('textbox')).toHaveValue('Hello — Razzah campaign performance'))
    expect(screen.getByTestId('pack-sent')).toBeInTheDocument()
  })
})
