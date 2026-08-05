import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { PlatformLegalPage } from './PlatformLegalPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { PlatformSettingsPayload } from './legalApi'

vi.mock('./legalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./legalApi')>()),
  getPlatformSettings: vi.fn(),
  savePlatformSettings: vi.fn(),
}))

import { getPlatformSettings, savePlatformSettings } from './legalApi'

/**
 * LEGAL-001 — the operator's legal identity, and the rule that an unknown fact stays unknown.
 *
 * A registration number or a jurisdiction is a business fact somebody has to supply. A plausible
 * default for either would end up printed on a published privacy policy and relied upon by a reader,
 * which is far worse than a blank. These tests are about the screen admitting what it does not know.
 */

function payload(over: Partial<PlatformSettingsPayload> = {}): PlatformSettingsPayload {
  return {
    published: false,
    legal_name_ar: null, legal_name_en: null, trading_name: null,
    registration_number: null, tax_number: null, jurisdiction: null,
    address_ar: null, address_en: null,
    contact_email: 'info@CampaignsHub.io',
    support_email: 'info@CampaignsHub.io',
    security_email: 'info@CampaignsHub.io',
    privacy_email: 'info@CampaignsHub.io',
    phone: null, dpo_name: null, dpo_email: null,
    updated_at: null, missing: ['legal_name'],
    ...over,
  }
}

describe('PlatformLegalPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith([])
  })
  afterEach(() => signOut())

  /** A fresh install shows empty fields, not invented ones. */
  it('leaves unknown legal facts blank rather than filling them in', async () => {
    vi.mocked(getPlatformSettings).mockResolvedValue(payload())

    renderWithProviders(<PlatformLegalPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('platform-legal_name_ar')).toBeInTheDocument())
    for (const key of ['legal_name_ar', 'legal_name_en', 'registration_number', 'tax_number', 'jurisdiction']) {
      expect((screen.getByTestId(`platform-${key}`) as HTMLInputElement).value).toBe('')
    }
    // …and the one address the product genuinely owns is present.
    expect((screen.getByTestId('platform-contact_email') as HTMLInputElement).value).toBe('info@CampaignsHub.io')
  })

  /**
   * The unpublished state is stated, not left for the operator to infer.
   *
   * «Can my privacy policy name a controller yet» is the real question on this screen, and answering
   * it by making somebody compare this form against a policy page is how a field stays empty for a
   * year.
   */
  it('says plainly that no legal identity is published yet', async () => {
    vi.mocked(getPlatformSettings).mockResolvedValue(payload())

    renderWithProviders(<PlatformLegalPage />, { locale: 'ar' })

    const notice = await screen.findByTestId('platform-unpublished')
    expect(notice.textContent).toMatch(/لم يُدخل اسم قانوني/)
    expect(screen.queryByTestId('platform-published')).toBeNull()
  })

  it('confirms when the identity is published', async () => {
    vi.mocked(getPlatformSettings).mockResolvedValue(payload({
      published: true, legal_name_en: 'CampaignsHub Co.', missing: [],
    }))

    renderWithProviders(<PlatformLegalPage />, { locale: 'ar' })

    expect(await screen.findByTestId('platform-published')).toBeInTheDocument()
    expect(screen.queryByTestId('platform-unpublished')).toBeNull()
  })

  it('saves what the operator entered', async () => {
    vi.mocked(getPlatformSettings).mockResolvedValue(payload())
    vi.mocked(savePlatformSettings).mockResolvedValue(payload({ published: true, legal_name_en: 'CampaignsHub Co.', missing: [] }))

    renderWithProviders(<PlatformLegalPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('platform-legal_name_en')).toBeInTheDocument())
    fireEvent.change(screen.getByTestId('platform-legal_name_en'), { target: { value: 'CampaignsHub Co.' } })
    fireEvent.click(screen.getByTestId('platform-save'))

    await waitFor(() => expect(savePlatformSettings).toHaveBeenCalled())
    expect(vi.mocked(savePlatformSettings).mock.calls[0]?.[0]).toMatchObject({ legal_name_en: 'CampaignsHub Co.' })
    expect(await screen.findByTestId('platform-saved')).toBeInTheDocument()
  })

  /** A refused save says why instead of failing silently. */
  it('surfaces the reason a save was refused', async () => {
    vi.mocked(getPlatformSettings).mockResolvedValue(payload())
    vi.mocked(savePlatformSettings).mockRejectedValue({ response: { data: { message: 'البريد العام مطلوب.' } } })

    renderWithProviders(<PlatformLegalPage />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('platform-save')).toBeInTheDocument())
    fireEvent.click(screen.getByTestId('platform-save'))

    expect(await screen.findByTestId('platform-error')).toBeInTheDocument()
  })
})
