import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  getData: vi.fn(),
  postData: vi.fn(),
}))

import { getData, postData } from '@/lib/api/client'
import {
  approvePortalQuote, formatDate, formatMoney, getPortalQuote, isOfframpStage,
  journeySteps, JOURNEY_MAIN_LINE, listPortalInvoices, listPortalQuotes, listPortalThreads,
  openPortalThread, payPortalInvoice, postPortalThreadMessage, rejectPortalQuote,
} from './portalAccountApi'

const mockGet = vi.mocked(getData)
const mockPost = vi.mocked(postData)

describe('portalAccountApi — response mapping', () => {
  beforeEach(() => vi.clearAllMocks())

  it('unwraps the quotes list from its envelope key', async () => {
    mockGet.mockResolvedValue({ quotes: [{ id: 'q1' }] })
    const out = await listPortalQuotes()
    expect(getData).toHaveBeenCalledWith('/client/quotes')
    expect(out).toEqual([{ id: 'q1' }])
  })

  it('unwraps a single quote and encodes the id', async () => {
    mockGet.mockResolvedValue({ quote: { id: 'q 1' } })
    const out = await getPortalQuote('q 1')
    expect(getData).toHaveBeenCalledWith('/client/quotes/q%201')
    expect(out).toEqual({ id: 'q 1' })
  })

  it('returns BOTH quote and invoice from approve (no unwrap — caller needs both)', async () => {
    mockPost.mockResolvedValue({ quote: { id: 'q1' }, invoice: { id: 'i1' } })
    const out = await approvePortalQuote('q1')
    expect(postData).toHaveBeenCalledWith('/client/quotes/q1/approve')
    expect(out).toEqual({ quote: { id: 'q1' }, invoice: { id: 'i1' } })
  })

  it('unwraps the quote from reject', async () => {
    mockPost.mockResolvedValue({ quote: { id: 'q1', status: 'rejected' } })
    const out = await rejectPortalQuote('q1')
    expect(postData).toHaveBeenCalledWith('/client/quotes/q1/reject')
    expect(out).toEqual({ id: 'q1', status: 'rejected' })
  })

  it('unwraps invoices list', async () => {
    mockGet.mockResolvedValue({ invoices: [{ id: 'i1' }] })
    expect(await listPortalInvoices()).toEqual([{ id: 'i1' }])
  })

  it('unwraps the payment and preserves the HONEST awaiting-provider state', async () => {
    mockPost.mockResolvedValue({ payment: { id: 'p1', payment_state: 'awaiting_provider_credentials', message: 'not configured' } })
    const out = await payPortalInvoice('i1')
    expect(postData).toHaveBeenCalledWith('/client/invoices/i1/pay')
    expect(out.payment_state).toBe('awaiting_provider_credentials')
  })

  it('unwraps threads list and posts new thread / reply with the right bodies', async () => {
    mockGet.mockResolvedValue({ threads: [{ id: 't1' }] })
    expect(await listPortalThreads()).toEqual([{ id: 't1' }])

    mockPost.mockResolvedValue({ thread: { id: 't2' } })
    expect(await openPortalThread('Hi', 'Body')).toEqual({ id: 't2' })
    expect(postData).toHaveBeenCalledWith('/client/messages', { subject: 'Hi', body: 'Body' })

    mockPost.mockResolvedValue({ message: { id: 'm1' } })
    expect(await postPortalThreadMessage('t2', 'Reply')).toEqual({ id: 'm1' })
    expect(postData).toHaveBeenCalledWith('/client/messages/t2', { body: 'Reply' })
  })
})

describe('portalAccountApi — journey helpers', () => {
  it('marks stages before the current one done and the current one current', () => {
    const steps = journeySteps('proposal_sent')
    const proposal = steps.find((s) => s.key === 'proposal_sent')!
    expect(proposal.current).toBe(true)
    expect(proposal.done).toBe(false)
    expect(steps.find((s) => s.key === 'submitted')!.done).toBe(true)
    expect(steps.find((s) => s.key === 'paid')!.done).toBe(false)
  })

  it('treats off-ramp/pre-submission stages as off the main line', () => {
    expect(isOfframpStage('cancelled')).toBe(true)
    expect(isOfframpStage('draft')).toBe(true)
    expect(isOfframpStage('in_progress')).toBe(false)
    // No main-line step is marked current for an off-ramp stage.
    expect(journeySteps('cancelled').some((s) => s.current)).toBe(false)
  })

  it('keeps the canonical main-line order', () => {
    expect(JOURNEY_MAIN_LINE[0]).toBe('submitted')
    expect(JOURNEY_MAIN_LINE[JOURNEY_MAIN_LINE.length - 1]).toBe('completed')
  })
})

describe('portalAccountApi — formatting (Latin digits)', () => {
  it('formats money with a currency code and grouping', () => {
    expect(formatMoney('1500', 'SAR')).toBe('1,500.00 SAR')
    expect(formatMoney('0', 'USD')).toBe('0.00 USD')
  })

  it('falls back gracefully for non-numeric input', () => {
    expect(formatMoney('n/a', 'SAR')).toBe('n/a SAR')
  })

  it('formats dates and null safely', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate('2026-03-15T00:00:00Z')).toBe('2026-03-15')
  })
})
