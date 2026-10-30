import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { MetaSaasReadiness } from './MetaSaasReadiness'
import type { MetaSaasReadinessItem } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, confirmMetaSaasReview: vi.fn(), withdrawMetaSaasReview: vi.fn() }
})

import { confirmMetaSaasReview, withdrawMetaSaasReview } from './api'

const REDIRECT = 'https://api.example.test/api/v1/oauth/ads/meta/callback'
const CALLBACK = 'https://api.example.test/api/v1/webhooks/data-deletion/meta-saas'
const items: MetaSaasReadinessItem[] = [
  { key: 'redirect_uri', status: 'ready', detail: { redirect_uri: REDIRECT } },
  { key: 'deletion_callback', status: 'needs_credentials', detail: { callback_url: CALLBACK, received_from_meta: false } },
  { key: 'oauth_round_trip', status: 'not_verified', detail: {} },
  { key: 'app_review', status: 'owner_action', detail: { confirmed_at: null, confirmed_by: null } },
]

/** META-SAAS-APP-001 — the readiness list shows the server's status per item, the URLs to copy, and Meta's own setup links. */
describe('SaaS app readiness', () => {
  it.each([['ar', true], ['en', false]] as const)('labels every item with the server status, never upgrading one (%s)', (_l, ar) => {
    renderWithProviders(<MetaSaasReadiness items={items} ar={ar} />, { locale: ar ? 'ar' : 'en' })

    for (const item of items) {
      const row = screen.getByTestId(`meta-saas-readiness-${item.key}`)
      expect(row).toHaveAttribute('data-status', item.status)
      expect(within(row).getByTestId('meta-saas-readiness-status').textContent).not.toBe('')
    }
    expect(screen.getByTestId('meta-saas-readiness-redirect_uri')).toHaveTextContent(REDIRECT)
    expect(screen.getByTestId('meta-saas-readiness-deletion_callback')).toHaveTextContent(CALLBACK)
    expect(within(screen.getByTestId('meta-saas-readiness-app_review')).getAllByRole('link').length).toBeGreaterThan(0)
    const statusTexts = items.map((i) => within(screen.getByTestId(`meta-saas-readiness-${i.key}`)).getByTestId('meta-saas-readiness-status').textContent)
    expect(new Set(statusTexts).size).toBe(new Set(items.map((i) => i.status)).size)
  })

  it('records Meta approval only after the Owner ticks the statement, and offers to withdraw it once recorded', async () => {
    vi.mocked(confirmMetaSaasReview).mockResolvedValue({} as never)
    const { rerender } = renderWithProviders(<MetaSaasReadiness items={items} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('meta-saas-review-confirm')).toBeDisabled()
    fireEvent.click(screen.getByTestId('meta-saas-review-check'))
    fireEvent.click(screen.getByTestId('meta-saas-review-confirm'))
    await waitFor(() => expect(confirmMetaSaasReview).toHaveBeenCalledTimes(1))

    const confirmed = items.map((i) => i.key === 'app_review' ? { ...i, status: 'ready' as const, detail: { confirmed_at: '2026-10-30T09:00:00+00:00', confirmed_by: 1 } } : i)
    rerender(<MetaSaasReadiness items={confirmed} ar={false} />)
    expect(screen.getByTestId('meta-saas-readiness-app_review')).toHaveTextContent('2026-10-30')
    fireEvent.click(screen.getByTestId('meta-saas-review-withdraw'))
    await waitFor(() => expect(withdrawMetaSaasReview).toHaveBeenCalledTimes(1))
  })
})
