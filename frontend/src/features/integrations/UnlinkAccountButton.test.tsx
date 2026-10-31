import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { UnlinkAccountButton } from './UnlinkAccountButton'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  unlinkAccount: vi.fn(() => Promise.resolve({ account_id: 'a1', project_id: 'p1', history_rows_kept: 12 })),
}))

import { unlinkAccount } from './api'

/**
 * INTEGRATION-UNLINK-001 — an account linked to the wrong client can be taken off it from the account's own row, after asking once.
 */
describe('removing an account from a project', () => {
  beforeEach(() => vi.clearAllMocks())

  it('asks first, says what happens and what does not, and does nothing until confirmed', () => {
    renderWithProviders(<UnlinkAccountButton projectId="p1" projectName="razzahavenu" accountId="a1" accountName="Nasel" />, { locale: 'en' })

    fireEvent.click(screen.getByTestId('unlink-account'))
    const ask = screen.getByTestId('unlink-confirm')
    expect(ask).toHaveTextContent('Remove “Nasel” from “razzahavenu”?')
    expect(ask).toHaveTextContent('Nothing is deleted')
    expect(unlinkAccount).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('unlink-confirm-no'))
    expect(screen.queryByTestId('unlink-confirm')).not.toBeInTheDocument()
    expect(unlinkAccount).not.toHaveBeenCalled()
  })

  it('removes the account from THAT project only, in Arabic too', async () => {
    renderWithProviders(<UnlinkAccountButton projectId="p1" projectName="razzahavenu" accountId="a1" accountName="Nasel" />, { locale: 'ar' })

    fireEvent.click(screen.getByTestId('unlink-account'))
    expect(screen.getByTestId('unlink-confirm')).toHaveTextContent('لا يُحذف شيء')
    fireEvent.click(screen.getByTestId('unlink-confirm-yes'))

    await waitFor(() => expect(unlinkAccount).toHaveBeenCalledWith('p1', 'a1'))
    await waitFor(() => expect(screen.queryByTestId('unlink-confirm')).not.toBeInTheDocument())
  })

  it('shows the server’s reason when it refuses', async () => {
    vi.mocked(unlinkAccount).mockRejectedValueOnce({ response: { status: 403, data: { success: false, message: 'ليست لديك صلاحية.' } }, isAxiosError: true })
    renderWithProviders(<UnlinkAccountButton projectId="p1" projectName={null} accountId="a1" accountName="Nasel" />, { locale: 'ar' })

    fireEvent.click(screen.getByTestId('unlink-account'))
    fireEvent.click(screen.getByTestId('unlink-confirm-yes'))

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.getByTestId('unlink-confirm')).toBeInTheDocument()
  })
})
