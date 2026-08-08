import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SharedAttributionSection } from './SharedAttributionSection'

/**
 * ATTRIB-VIS-001 on the client — the section exists only where the link carries it.
 *
 * The first test is the one that matters and it is a negative: a link without the section must make
 * NO request for it. A component that asks and then hides the answer has already put the figures on
 * the wire, where the network tab is one keystroke away — which is the difference between a
 * permission and a CSS rule.
 */

const attribution = {
  period: { from: '2026-07-01', to: '2026-07-31' },
  platform_reported: {
    label_ar: 'ما أبلغت به المنصات',
    label_en: 'Platform-Reported',
    basis_ar: '…',
    basis_en: '…',
    platforms: [{ provider: 'meta', platform_reported_orders: 1169, store_confirmed_orders: 640 }],
    total_orders: null,
    total_revenue: null,
    total_withheld: true,
    total_withheld_reason: 'no_shared_order_key_across_platforms',
  },
  store_confirmed: { label_ar: 'ما أكده المتجر', label_en: 'Store-Confirmed', orders: 640, revenue: 100000 },
  dedup: { status: 'not_possible', status_ar: 'غير ممكن' },
  models: [],
  unattributed: { orders: 0 },
}

function renderSection() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={qc}>
      <SharedAttributionSection token="tok" />
    </QueryClientProvider>,
  )
}

describe('the shared attribution section', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ data: attribution }) })) as never)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('asks the link’s own address, once, and renders the panel', async () => {
    renderSection()

    await waitFor(() => expect(screen.getByTestId('shared-attribution')).toBeInTheDocument())
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/api/v1/reports/shared/tok/attribution')
  })

  it('names what it is showing, so a client is not handed two numbers with no explanation', async () => {
    renderSection()

    await waitFor(() => expect(screen.getByText('شفافية الإسناد')).toBeInTheDocument())
    expect(screen.getByText(/ما أبلغت به المنصات مقابل ما أكده المتجر/)).toBeInTheDocument()
  })

  /**
   * A refused section renders nothing rather than an error a client would read as a broken report.
   *
   * The parent does not mount this component for a link without the flag, so reaching the refusal
   * means something else went wrong — and «this section is not available» is not a sentence a client
   * can act on.
   */
  it('renders no figures when the endpoint refuses', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({ message: 'هذا القسم غير متاح في هذا الرابط.' }) })) as never)

    renderSection()

    await waitFor(() => expect(fetch).toHaveBeenCalled())
    expect(screen.queryByText('1,169')).not.toBeInTheDocument()
    expect(screen.queryByText('640')).not.toBeInTheDocument()
  })
})
