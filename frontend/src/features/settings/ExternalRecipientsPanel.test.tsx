import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useExternalRecipients: vi.fn(), useExternalRecipientActions: vi.fn(), useExternalBindableProjects: vi.fn() }
})

import { useExternalBindableProjects, useExternalRecipientActions, useExternalRecipients } from './api'
import { ExternalRecipientsPanel } from './ExternalRecipientsPanel'

const add = vi.fn().mockResolvedValue({})
const test = vi.fn().mockResolvedValue({ state: 'sent', projects: 1 })

function setup() {
  vi.mocked(useExternalRecipients).mockReturnValue({
    data: [{ id: 'r1', email: 'pm@client.test', name: 'Sara', cadence: 'weekly', active: true, project_ids: ['p1'] }],
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useExternalRecipients>)
  vi.mocked(useExternalRecipientActions).mockReturnValue({
    add: { mutateAsync: add, isPending: false },
    update: { mutateAsync: vi.fn(), isPending: false },
    remove: { mutate: vi.fn(), isPending: false },
    test: { mutateAsync: test, isPending: false },
  } as unknown as ReturnType<typeof useExternalRecipientActions>)
  vi.mocked(useExternalBindableProjects).mockReturnValue({
    data: [{ id: 'p1', name: 'Alpha' }, { id: 'p2', name: 'Beta' }],
  } as unknown as ReturnType<typeof useExternalBindableProjects>)
  renderWithProviders(<ExternalRecipientsPanel ar />)
}

describe('ExternalRecipientsPanel — SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001', () => {
  it('lists the recipients with their cadence and projects, in plain words', () => {
    setup()
    expect(screen.getByTestId('external-recipient-list').textContent).toContain('pm@client.test')
    expect(screen.getByTestId('external-recipient-list').textContent).toContain('أسبوعي')
    expect(screen.getByTestId('external-recipient-list').textContent).toContain('Alpha')
  })

  it('adds a recipient bound to the chosen projects only, and the add waits for an address and a project', () => {
    setup()
    const button = screen.getByTestId('external-recipient-add') as HTMLButtonElement
    expect(button.disabled).toBe(true)
    fireEvent.change(screen.getByLabelText('البريد'), { target: { value: 'new@client.test' } })
    fireEvent.click(screen.getByLabelText('Beta'))
    expect(button.disabled).toBe(false)
    fireEvent.click(button)
    expect(add).toHaveBeenCalledWith({ email: 'new@client.test', name: null, cadence: 'weekly', project_ids: ['p2'] })
  })

  it('sends the test to the member, never naming a recipient', () => {
    setup()
    fireEvent.click(screen.getByText('أرسل تجربة لي'))
    expect(test).toHaveBeenCalledWith({ cadence: 'weekly', project_ids: undefined })
  })
})
