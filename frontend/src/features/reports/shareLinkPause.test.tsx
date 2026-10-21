import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ShareManager } from './ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listShares: vi.fn(), pauseShare: vi.fn(), resumeShare: vi.fn(), revokeShare: vi.fn() }
})

import * as api from './api'

const share = (over: Record<string, unknown> = {}) => ({
  id: 'sh1', active: true, mode: 'live', form: null, password_protected: false, hide_spend: false,
  view_count: 3, expires_at: null, revoked_at: null, paused_at: null, created_at: '2026-10-01T00:00:00Z', ...over,
})

/** REPORT-LINK-PAUSE-001 — switch a shared link off and back on without deleting its settings. */
describe('pausing a shared report link', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['reports.view', 'reports.manage', 'reports.share'])
  })
  afterEach(() => signOut())

  it('offers «إيقاف الرابط» on an active link and sends the pause', async () => {
    vi.mocked(api.listShares).mockResolvedValue([share()] as never)
    vi.mocked(api.pauseShare).mockResolvedValue(share({ active: false, paused_at: '2026-10-21T10:00:00Z' }) as never)
    renderWithProviders(<ShareManager projectId="p1" reportId="r1" onClose={() => {}} />, { locale: 'ar' })

    const button = await screen.findByTestId('share-pause-sh1')
    expect(button).toHaveTextContent('إيقاف الرابط')
    expect(button).toHaveAttribute('aria-pressed', 'false')
    fireEvent.click(button)
    await waitFor(() => expect(api.pauseShare).toHaveBeenCalledWith('p1', 'r1', 'sh1'))
  })

  it('shows a paused link as «متوقف» with «تفعيل الرابط», and resumes it', async () => {
    vi.mocked(api.listShares).mockResolvedValue([share({ active: false, paused_at: '2026-10-21T10:00:00Z' })] as never)
    vi.mocked(api.resumeShare).mockResolvedValue(share() as never)
    renderWithProviders(<ShareManager projectId="p1" reportId="r1" onClose={() => {}} />, { locale: 'ar' })

    const button = await screen.findByTestId('share-pause-sh1')
    expect(button).toHaveTextContent('تفعيل الرابط')
    expect(button).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('share-row')).toHaveTextContent('متوقف')
    fireEvent.click(button)
    await waitFor(() => expect(api.resumeShare).toHaveBeenCalledWith('p1', 'r1', 'sh1'))
  })

  it('offers no switch on a revoked link — that cut-off is permanent', async () => {
    vi.mocked(api.listShares).mockResolvedValue([share({ active: false, revoked_at: '2026-10-20T10:00:00Z' })] as never)
    renderWithProviders(<ShareManager projectId="p1" reportId="r1" onClose={() => {}} />, { locale: 'en' })

    await screen.findByTestId('share-row')
    expect(screen.queryByTestId('share-pause-sh1')).toBeNull()
  })
})
