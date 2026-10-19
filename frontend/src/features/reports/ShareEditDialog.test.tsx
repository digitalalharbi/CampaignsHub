import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ShareEditDialog } from './ShareEditDialog'
import { renderWithProviders } from '@/test/utils'
import type { ShareRow } from './api'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, updateShare: vi.fn() }
})

import { updateShare } from './api'

/**
 * REPORT-LINK-EDIT-IN-PLACE-001 — a published link is edited, not recreated: only what the Owner touched is
 * sent, so saving never resets a setting they did not look at.
 */
const share = {
  id: 's1', active: true, allow_download: true, hide_spend: true, hide_revenue: false, hide_campaign_names: false, watermark: false,
  password_protected: true, view_count: 3, last_viewed_at: null, expires_at: '2026-12-01T00:00:00Z', revoked_at: null, logs_count: null, created_at: null,
  mode: 'live', form: null, scope: null,
} as unknown as ShareRow

const mount = (onSaved = vi.fn(), onClose = vi.fn()) => renderWithProviders(<ShareEditDialog projectId="p" reportId="r" share={share} onClose={onClose} onSaved={onSaved} />, { locale: 'en' })

describe('ShareEditDialog', () => {
  beforeEach(() => { vi.clearAllMocks(); vi.mocked(updateShare).mockResolvedValue(share) })

  it('starts from the link’s current settings and says the URL does not change', () => {
    mount()

    expect(screen.getByTestId('edit-hide_spend')).toBeChecked()
    expect(screen.getByTestId('edit-allow_download')).toBeChecked()
    expect(screen.getByTestId('edit-watermark')).not.toBeChecked()
    expect(screen.getByText(/The link itself stays exactly as it is/)).toBeInTheDocument()
  })

  it('sends only what was changed', async () => {
    const onSaved = vi.fn()
    const onClose = vi.fn()
    mount(onSaved, onClose)

    fireEvent.click(screen.getByTestId('edit-hide_spend'))
    fireEvent.click(screen.getByTestId('edit-save'))

    await waitFor(() => expect(updateShare).toHaveBeenCalledWith('p', 'r', 's1', { hide_spend: false }))
    await waitFor(() => expect(onSaved).toHaveBeenCalled())
    expect(onClose).toHaveBeenCalled()
  })

  it('sets a new password and expiry, and can clear the old ones', async () => {
    mount()

    fireEvent.change(screen.getByTestId('edit-password'), { target: { value: 'secret9' } })
    fireEvent.change(screen.getByTestId('edit-expiry'), { target: { value: '2027-01-15' } })
    fireEvent.click(screen.getByTestId('edit-save'))
    await waitFor(() => expect(updateShare).toHaveBeenLastCalledWith('p', 'r', 's1', { password: 'secret9', expires_at: '2027-01-15' }))
  })

  it('offers clearing the password and the expiry only where there is one', async () => {
    mount()

    fireEvent.click(screen.getByTestId('edit-clear-password'))
    fireEvent.click(screen.getByTestId('edit-clear-expiry'))
    fireEvent.click(screen.getByTestId('edit-save'))
    await waitFor(() => expect(updateShare).toHaveBeenCalledWith('p', 'r', 's1', { clear_password: true, clear_expiry: true }))
  })

  /** REPORT-CLIENT-NO-DELTAS-001 — off unless the Owner turns it on, and saving names only that switch. */
  it('shows the previous-period comparison as off and sends only that switch when it is turned on', async () => {
    mount()
    expect(screen.getByTestId('edit-previous-comparison')).not.toBeChecked()

    fireEvent.click(screen.getByTestId('edit-previous-comparison'))
    fireEvent.click(screen.getByTestId('edit-save'))

    await waitFor(() => expect(updateShare).toHaveBeenCalledWith('p', 'r', 's1', { sections: { previous_comparison: true } }))
  })
})
