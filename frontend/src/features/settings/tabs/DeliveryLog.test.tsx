import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'

import { DeliveryLog } from './DeliveryLog'
import type { DeliveryRow } from '../deliveryLog'
import { renderWithProviders } from '@/test/utils'

vi.mock('../api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api')>()
  return { ...actual, useDeliveryLog: vi.fn() }
})

import { useDeliveryLog } from '../api'

/**
 * EMAIL-SETTINGS-DEPTH-001 — the delivery log reaches a screen.
 *
 * The ledgers have recorded every attempt since they shipped, `useDeliveryLog` and
 * `summariseDeliveries` were written and tested, and nothing rendered them. A log nobody can open
 * answers no question — and the question it exists for is the one somebody asks with a client on the
 * phone saying they never see the report.
 *
 * The two ways of getting this wrong are both easy to write by accident, and both are asserted:
 * counting only successes, so «12 sent» cannot say the last four failed; and treating an empty log
 * as «no failures», which hides the strongest signal on the page behind a reassuring zero.
 */
const row = (over: Partial<DeliveryRow> = {}): DeliveryRow => ({
  source: 'digest',
  kind: 'daily_digest',
  recipient: 'lead@agency.test',
  status: 'sent',
  reason: null,
  attempts: 1,
  at: '2026-08-29T08:04:00Z',
  ...over,
})

const log = (rows: DeliveryRow[]) =>
  vi.mocked(useDeliveryLog).mockReturnValue({ data: rows, isLoading: false, isError: false, refetch: vi.fn() } as never)

describe('the delivery log', () => {
  beforeEach(() => vi.clearAllMocks())

  it('counts what was sent, what failed and what is waiting on the provider', async () => {
    log([
      row(),
      row({ status: 'failed', reason: 'provider_refused' }),
      row({ status: 'awaiting_provider_credentials' }),
    ])
    renderWithProviders(<DeliveryLog />, { locale: 'en' })

    expect(await screen.findByTestId('delivery-count-sent')).toHaveTextContent('1')
    expect(screen.getByTestId('delivery-count-failed')).toHaveTextContent('1')
    expect(screen.getByTestId('delivery-count-blocked')).toHaveTextContent('1')
  })

  /**
   * Nothing sent is not «no failures».
   *
   * Every count on an empty log is zero, and zero failures reads as health — on a workspace that
   * expects a daily digest, an empty log is the strongest signal there is.
   */
  it('says nothing has been sent rather than reporting zero failures', async () => {
    log([])
    renderWithProviders(<DeliveryLog />, { locale: 'en' })

    expect(await screen.findByText(/Nothing has been sent yet/)).toBeInTheDocument()
    expect(screen.queryByTestId('delivery-count-failed')).not.toBeInTheDocument()
  })

  it('says why a send failed, in words as well as in the ledger code', async () => {
    log([row({ status: 'failed', reason: 'no_recipients' })])
    renderWithProviders(<DeliveryLog />, { locale: 'en' })

    const reason = await screen.findByTestId('delivery-reason-0')
    expect(reason).toHaveTextContent('Nobody is subscribed to this type.')
    /* The machine code stays, for a support conversation. */
    expect(reason).toHaveTextContent('no_recipients')
  })

  /**
   * A reason this build has never heard of still prints.
   *
   * Rendering only known reasons would silently drop a new failure mode — the reader would see a
   * failed row with no explanation and no way to ask about it.
   */
  it('prints an unrecognised reason rather than swallowing it', async () => {
    log([row({ status: 'failed', reason: 'quota_exhausted' })])
    renderWithProviders(<DeliveryLog />, { locale: 'en' })

    expect(await screen.findByTestId('delivery-reason-0')).toHaveTextContent('quota_exhausted')
  })

  it('shows the attempt count only when there was more than one', async () => {
    log([row({ status: 'failed', reason: 'provider_refused', attempts: 3 }), row()])
    renderWithProviders(<DeliveryLog />, { locale: 'en' })

    expect(await screen.findByTestId('delivery-attempts-0')).toHaveTextContent('3 attempts')
    expect(screen.queryByTestId('delivery-attempts-1')).not.toBeInTheDocument()
  })

  /**
   * A digest is one send per person, summarised — it has no single recipient, and inventing one lies.
   *
   * The `kind` is deliberately NOT `daily_digest` here: asserting the row contains «digest» passed
   * even with a hardcoded address injected, because the kind contains the word too. What the test
   * has to say is that no address appears.
   */
  it('does not invent a recipient for a digest row', async () => {
    log([row({ recipient: null, kind: 'weekly_summary' })])
    renderWithProviders(<DeliveryLog />, { locale: 'en' })

    const item = await screen.findByTestId('delivery-row-0')
    expect(item).toHaveTextContent('digest')
    expect(item.textContent ?? '').not.toMatch(/@/)
  })

  it('speaks Arabic', async () => {
    log([row({ status: 'failed', reason: 'no_recipients' })])
    renderWithProviders(<DeliveryLog />, { locale: 'ar' })

    expect(await screen.findByTestId('delivery-reason-0')).toHaveTextContent('لا يوجد مستلمون مشتركون في هذا النوع.')
  })
})
