import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ClassificationEditor } from './ClassificationEditor'
import type { ClientClassification } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [], isPending: false, isError: false, refetch: vi.fn() }),
  createOptionFromDraft: vi.fn(),
}))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getTaxonomy: vi.fn(), updateClassification: vi.fn() }
})

import { getTaxonomy, updateClassification } from './api'

const current: ClientClassification = {
  client_status: 'active', service_level: 'managed', industry: 'retail', owner_id: null, owner_name: null,
  priority: 'normal', default_currency: 'SAR', timezone: 'Asia/Riyadh', language: 'ar', week_start: 'sunday',
  tags: [], enabled_services: [],
}

describe('ClassificationEditor — ErrorSummary', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['clients.manage'])
    vi.mocked(getTaxonomy).mockResolvedValue({ assignable_users: [] } as never)
  })
  afterEach(() => signOut())

  it('surfaces a server validation error and focuses the offending field', async () => {
    vi.mocked(updateClassification).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { default_currency: ['The currency is invalid.'] } } },
    })
    renderWithProviders(<ClassificationEditor clientId="c1" current={current} onClose={() => {}} />, { locale: 'en' })

    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    const summary = await screen.findByTestId('error-summary')
    expect(summary).toHaveTextContent('The currency is invalid.')
    fireEvent.click(screen.getByRole('button', { name: 'The currency is invalid.' }))
    expect(screen.getByLabelText(/Default currency/i)).toHaveFocus()
  })
})
