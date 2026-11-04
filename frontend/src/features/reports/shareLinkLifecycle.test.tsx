import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ShareManager } from './ReportsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listShares: vi.fn(), getCurrentShareLink: vi.fn(), convertShare: vi.fn() }
})

import * as api from './api'

const share = (over: Record<string, unknown> = {}) => ({
  id: 'sh1', active: true, mode: 'live', form: null, password_protected: false, hide_spend: false,
  view_count: 3, expires_at: null, revoked_at: null, paused_at: null, created_at: '2026-10-01T00:00:00Z', ...over,
})

/** REPORT-LIFECYCLE-MENU-001 / REPORT-LIVE-SNAPSHOT-CONVERT-001 — copy the current link; convert only after being told. */
describe('the lifecycle actions on an existing link', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['reports.view', 'reports.manage', 'reports.share'])
    Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } })
  })
  afterEach(() => signOut())

  it('copies the existing link and says no new link was made', async () => {
    vi.mocked(api.listShares).mockResolvedValue([share()] as never)
    vi.mocked(api.getCurrentShareLink).mockResolvedValue({ available: true, reason: null, url: 'https://x.test/r/abc', path: '/r/abc', mode: 'live', active: true } as never)
    renderWithProviders(<ShareManager projectId="p1" reportId="r1" onClose={() => {}} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('share-copy-sh1'))
    await waitFor(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://x.test/r/abc'))
    expect(await screen.findByTestId('share-copy-note')).toHaveTextContent('no new link was made')
  })

  it('says so plainly when an older link cannot be recovered, and copies nothing', async () => {
    vi.mocked(api.listShares).mockResolvedValue([share()] as never)
    vi.mocked(api.getCurrentShareLink).mockResolvedValue({ available: false, reason: 'created_before_copy_support', url: null, path: null, mode: 'live', active: true } as never)
    renderWithProviders(<ShareManager projectId="p1" reportId="r1" onClose={() => {}} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('share-copy-sh1'))
    expect(await screen.findByTestId('share-copy-note')).toHaveTextContent('cannot be recovered')
    expect(navigator.clipboard.writeText).not.toHaveBeenCalled()
  })

  it('asks first, shows what will happen, and converts only on the second click', async () => {
    vi.mocked(api.listShares).mockResolvedValue([share()] as never)
    vi.mocked(api.convertShare)
      .mockResolvedValueOnce({ changed: false, requires_confirmation: true, from: 'live', to: 'snapshot', notice: { ar: 'ثابت', en: 'This link becomes a fixed Snapshot.' }, link: share() } as never)
      .mockResolvedValueOnce({ changed: true, requires_confirmation: false, from: 'live', to: 'snapshot', notice: { ar: '', en: '' }, link: share({ mode: 'snapshot' }) } as never)
    renderWithProviders(<ShareManager projectId="p1" reportId="r1" onClose={() => {}} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('share-convert-sh1'))
    expect(await screen.findByTestId('share-convert-confirm')).toHaveTextContent('becomes a fixed Snapshot')
    expect(api.convertShare).toHaveBeenCalledWith('p1', 'r1', 'sh1', 'snapshot', false)
    expect(api.convertShare).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getByTestId('share-convert-go'))
    await waitFor(() => expect(api.convertShare).toHaveBeenLastCalledWith('p1', 'r1', 'sh1', 'snapshot', true))
  })

  it('offers neither conversion nor copy-as-active on a revoked link', async () => {
    vi.mocked(api.listShares).mockResolvedValue([share({ active: false, revoked_at: '2026-10-20T10:00:00Z' })] as never)
    renderWithProviders(<ShareManager projectId="p1" reportId="r1" onClose={() => {}} />, { locale: 'en' })
    await screen.findByTestId('share-row')
    expect(screen.queryByTestId('share-convert-sh1')).toBeNull()
  })
})
