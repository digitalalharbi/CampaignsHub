import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CampaignFormModal } from './CampaignFormModal'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', () => ({ createCampaign: vi.fn(), updateCampaign: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listUsers: vi.fn() }))

import { createCampaign, updateCampaign } from './api'
import { listUsers } from '@/features/projects/api'

const created: UnifiedCampaign = {
  id: 'c9', project_id: 'p1', name: 'X', objective: 'sales', status: 'draft', total_budget: null,
  budget_currency: 'SAR', starts_on: null, ends_on: null, primary_conversion_purpose: null,
  attribution_model: null, attribution_window: null, owner_id: null, target_kpi: null, audience: null,
  regions: null, created_at: null,
}

describe('CampaignFormModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listUsers).mockResolvedValue([])
  })
  afterEach(() => signOut())

  it('blocks submit with an empty name (client-side zod)', async () => {
    signInWith(['campaigns.create'])
    renderWithProviders(<CampaignFormModal open onClose={() => {}} projectId="p1" />)

    fireEvent.click(screen.getByText('Save'))
    await waitFor(() => expect(createCampaign).not.toHaveBeenCalled())
    // A field-level error is shown.
    expect(await screen.findByRole('alert')).toBeInTheDocument()
  })

  it('submits a valid campaign and calls createCampaign', async () => {
    vi.mocked(createCampaign).mockResolvedValue(created)
    const onClose = vi.fn()
    signInWith(['campaigns.create'])
    renderWithProviders(<CampaignFormModal open onClose={onClose} projectId="p1" />)

    fireEvent.change(screen.getByLabelText(/Campaign name/i), { target: { value: 'Ramadan' } })
    fireEvent.click(screen.getByText('Save'))

    await waitFor(() => expect(createCampaign).toHaveBeenCalledWith('p1', expect.objectContaining({ name: 'Ramadan' })))
    await waitFor(() => expect(onClose).toHaveBeenCalled())
  })

  it('surfaces a 422 server validation error on the field', async () => {
    vi.mocked(createCampaign).mockRejectedValue({
      response: { status: 422, data: { message: 'Validation failed', errors: { name: ['The name has already been taken.'] } } },
    })
    signInWith(['campaigns.create'])
    renderWithProviders(<CampaignFormModal open onClose={() => {}} projectId="p1" />)

    fireEvent.change(screen.getByLabelText(/Campaign name/i), { target: { value: 'Dup' } })
    fireEvent.click(screen.getByText('Save'))

    expect(await screen.findByText('The name has already been taken.')).toBeInTheDocument()
  })

  it('edit mode pre-fills and calls updateCampaign', async () => {
    vi.mocked(updateCampaign).mockResolvedValue({ ...created, name: 'Edited' })
    signInWith(['campaigns.update'])
    renderWithProviders(
      <CampaignFormModal open onClose={() => {}} projectId="p1" campaign={{ ...created, name: 'Before' }} />,
    )

    const nameInput = screen.getByLabelText(/Campaign name/i) as HTMLInputElement
    expect(nameInput.value).toBe('Before')
    fireEvent.change(nameInput, { target: { value: 'Edited' } })
    fireEvent.click(screen.getByText('Save'))

    await waitFor(() => expect(updateCampaign).toHaveBeenCalledWith('p1', 'c9', expect.objectContaining({ name: 'Edited' })))
  })
})
