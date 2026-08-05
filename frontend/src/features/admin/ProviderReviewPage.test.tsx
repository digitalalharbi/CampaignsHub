import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProviderReviewPage } from './ProviderReviewPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { ReviewChecklist } from './reviewApi'

vi.mock('./reviewApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./reviewApi')>()),
  getReviewChecklists: vi.fn(),
  setReviewRequirement: vi.fn(),
}))

import { getReviewChecklists, setReviewRequirement } from './reviewApi'

/**
 * REVIEW-001 — eight checklists, and the rows an operator must not be able to tick.
 *
 * The defect this guards against is a checklist that reports itself complete while the redirect URI
 * it will actually send is still HTTP — which is the one value guaranteed to fail every one of these
 * reviews.
 */

function checklist(over: Partial<ReviewChecklist> = {}): ReviewChecklist {
  return {
    provider: 'google',
    label: 'Google Ads API',
    label_ar: 'واجهة جوجل أدز',
    items: [
      {
        key: 'redirect_uri', source: 'derived',
        label_ar: 'رابط العودة', label_en: 'Redirect URI',
        why_ar: 'أي اختلاف يُنهي الرحلة برفض.', why_en: 'Any difference ends the flow with a refusal.',
        status: 'missing', value: 'http://localhost:8000/api/v1/oauth/ads/google/callback',
        detail_ar: 'الرابط ليس HTTPS.', detail_en: 'This is not HTTPS.',
        editable: false,
      },
      {
        key: 'developer_token_basic', source: 'declared',
        label_ar: 'رمز مطوّر — وصول أساسي', label_en: 'Developer token — basic access',
        why_ar: 'بدونه تُرفض كل الاستدعاءات.', why_en: 'Without it every call is refused.',
        status: 'missing', editable: true,
      },
    ],
    summary: { total: 2, missing: 2, ready: 0, submitted: 0, approved: 0, submittable: false },
    ...over,
  }
}

describe('the provider review board', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith([])
  })
  afterEach(() => signOut())

  it('says at a glance how much is still missing', async () => {
    vi.mocked(getReviewChecklists).mockResolvedValue({ providers: [checklist()] })

    renderWithProviders(<ProviderReviewPage />, { locale: 'ar' })

    const summary = await screen.findByTestId('review-google-summary')
    expect(summary.textContent).toMatch(/ناقص 2 من 2/)
  })

  it('says when a provider is ready to submit', async () => {
    vi.mocked(getReviewChecklists).mockResolvedValue({
      providers: [checklist({ summary: { total: 2, missing: 0, ready: 1, submitted: 0, approved: 1, submittable: true } })],
    })

    renderWithProviders(<ProviderReviewPage />, { locale: 'ar' })

    expect((await screen.findByTestId('review-google-summary')).textContent).toMatch(/مكتمل للتقديم/)
  })

  /**
   * A derived row shows its value and offers no control.
   *
   * Being able to mark the redirect URI approved by hand would produce a board that disagrees with
   * itself on reload and, worse, one that claims a submission is ready when the URL is still HTTP.
   */
  it('shows a system-determined row as a fact with no way to tick it', async () => {
    vi.mocked(getReviewChecklists).mockResolvedValue({ providers: [checklist()] })

    renderWithProviders(<ProviderReviewPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByText('واجهة جوجل أدز'))

    const row = await screen.findByTestId('review-google-redirect_uri')
    expect(row.textContent).toContain('http://localhost:8000/api/v1/oauth/ads/google/callback')
    expect(row.textContent).toMatch(/ليس HTTPS/)
    expect(screen.queryByTestId('review-google-redirect_uri-status')).toBeNull()
  })

  /** A declared row is the operator's to set, and the change is sent. */
  it('lets the operator record what happened in the providers console', async () => {
    vi.mocked(getReviewChecklists).mockResolvedValue({ providers: [checklist()] })
    vi.mocked(setReviewRequirement).mockResolvedValue(checklist())

    renderWithProviders(<ProviderReviewPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByText('واجهة جوجل أدز'))
    fireEvent.change(await screen.findByTestId('review-google-developer_token_basic-status'), {
      target: { value: 'submitted' },
    })

    await waitFor(() => expect(setReviewRequirement).toHaveBeenCalled())
    const call = vi.mocked(setReviewRequirement).mock.calls[0]
    expect(call?.[0]).toBe('google')
    expect(call?.[1]).toBe('developer_token_basic')
    expect(call?.[2]).toMatchObject({ status: 'submitted' })
  })

  /** Every row explains why it matters — a bare checkbox teaches nobody anything. */
  it('explains why each requirement matters', async () => {
    vi.mocked(getReviewChecklists).mockResolvedValue({ providers: [checklist()] })

    renderWithProviders(<ProviderReviewPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByText('واجهة جوجل أدز'))

    expect(await screen.findByText('بدونه تُرفض كل الاستدعاءات.')).toBeInTheDocument()
  })
})
