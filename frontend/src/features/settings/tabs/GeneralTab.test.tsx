import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { GeneralTab } from './GeneralTab'
import type { OrgGeneral, OrgSettings } from '../api'
import { renderWithProviders } from '@/test/utils'

const general: OrgGeneral = {
  account_type: 'agency', logo_url: null, contact_email: null, contact_phone: null, country: 'SA',
  default_locale: 'ar', default_currency: 'SAR', timezone: 'Asia/Riyadh', date_format: 'YYYY-MM-DD',
  number_format: 'latin', fiscal_year_start_month: 1, demo_mode: false,
}
const settings: OrgSettings = {
  name: 'Acme', slug: 'acme', general,
  options: { account_types: ['agency'], date_formats: ['YYYY-MM-DD'], number_formats: ['latin'] },
}

const mutateAsync = vi.fn()

vi.mock('../api', () => ({
  useOrgSettings: () => ({ data: settings, isLoading: false }),
  useUpdateOrgSettings: () => ({ mutateAsync, isPending: false, isError: true }),
}))

describe('GeneralTab — ErrorSummary', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.clearAllMocks())

  it('renders an ErrorSummary from server field errors and focuses the field', async () => {
    mutateAsync.mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { 'general.contact_email': ['The email is invalid.'] } } },
    })
    renderWithProviders(<GeneralTab />, { locale: 'ar' })

    fireEvent.click(screen.getByRole('button', { name: /حفظ التغييرات/i }))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The email is invalid.')
    fireEvent.click(screen.getByRole('button', { name: 'The email is invalid.' }))
    expect(document.getElementById('cemail')).toHaveFocus()
  })
})
