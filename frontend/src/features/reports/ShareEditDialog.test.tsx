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

  /** REPORT-TABLES-OWNER-COLUMNS-001 — the published link's columns start from what it publishes and are sent whole per table. */
  it('starts the column picker from the link’s published columns and sends the changed table whole', async () => {
    const withColumns = { ...share, columns: { accounts: ['results', 'impressions'] } } as unknown as ShareRow
    renderWithProviders(<ShareEditDialog projectId="p" reportId="r" share={withColumns} onClose={vi.fn()} onSaved={vi.fn()} />, { locale: 'en' })
    expect(screen.getByTestId('column-accounts-results')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('column-accounts-clicks')).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByTestId('column-ctr')).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByTestId('column-accounts-clicks'))
    fireEvent.click(screen.getByTestId('edit-save'))
    await waitFor(() => expect(updateShare).toHaveBeenCalledWith('p', 'r', 's1', { columns: { accounts: ['results', 'impressions', 'clicks'] } }))
  })

  /** REPORT-CLIENT-ACCOUNT-NAMES-OFF-001 — the switch starts off and is sent as a named section. */
  it('offers ad-account names off by default and sends the section when switched on', async () => {
    mount()
    const box = screen.getByTestId('edit-account-names')
    expect(box).not.toBeChecked()
    fireEvent.click(box)
    fireEvent.click(screen.getByTestId('edit-save'))
    await waitFor(() => expect(updateShare).toHaveBeenCalledWith('p', 'r', 's1', { sections: { account_names: true } }))
  })

  /** REPORT-LINK-EDIT-ADS-001 — a published link with no ad section gains it in place, with the activity listing policy. */
  it('turns the ad section on for a published link and sends the switches with the listing policy', async () => {
    mount()
    expect(screen.getByText('This link shows no ads')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Ad ads/i }))
    expect(screen.queryByTestId('creative-ceiling')).not.toBeInTheDocument()
    fireEvent.click(screen.getByTestId('creatives-master'))
    fireEvent.click(screen.getByTestId('edit-save'))
    await waitFor(() => expect(updateShare).toHaveBeenCalledWith('p', 'r', 's1', {
      creatives: { creatives: true },
      creative_display: { policy: 'activity', include_zero_activity: false, auto_refresh: true },
    }))
  })

  it('starts the ad section from what the link publishes and leaves it unsent when untouched', async () => {
    const withAds = { ...share, creatives: { creatives: true, video: true }, creative_display: { policy: 'all', include_zero_activity: false, auto_refresh: true } } as unknown as ShareRow
    renderWithProviders(<ShareEditDialog projectId="p" reportId="r" share={withAds} onClose={vi.fn()} onSaved={vi.fn()} />, { locale: 'en' })
    expect(screen.getByText(/Shown — 2 options on/)).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('edit-watermark'))
    fireEvent.click(screen.getByTestId('edit-save'))
    await waitFor(() => expect(updateShare).toHaveBeenCalledWith('p', 'r', 's1', { watermark: true }))
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
