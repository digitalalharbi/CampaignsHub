import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ACCOUNT_LABELS } from './GeneralTab'
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

/**
 * SETTINGS-ACCOUNT-TYPE-001 — the label map must cover every value the API can send.
 *
 * The list below is the cases of `backend/app/Domains/Accounts/Enums/AccountType.php`, which the
 * settings endpoint returns verbatim. It is written out rather than derived, because TypeScript
 * cannot read a PHP enum — so a case added there and not here fails HERE, instead of reaching a
 * customer as a raw identifier in a dropdown. That is exactly how `in_house_team` and
 * `self_serve_company` shipped: the map said `in_house`, and the select falls back to the key.
 */
describe('every account type the backend can send has a label', () => {
  const ACCOUNT_TYPES = ['freelancer', 'agency', 'in_house_team', 'brand', 'self_serve_company']

  it.each(ACCOUNT_TYPES)('labels %s in both languages', (key) => {
    const label = ACCOUNT_LABELS[key]

    expect(label, `${key} would render as its own key`).toBeDefined()
    expect(label.ar.length).toBeGreaterThan(0)
    expect(label.en.length).toBeGreaterThan(0)
  })

  it('labels nothing the backend cannot send, so a dead entry cannot masquerade as coverage', () => {
    expect(Object.keys(ACCOUNT_LABELS).sort()).toEqual([...ACCOUNT_TYPES].sort())
  })
})
