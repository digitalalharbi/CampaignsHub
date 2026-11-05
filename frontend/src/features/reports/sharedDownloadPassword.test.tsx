import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn(),
  sharedDownloadUrl: (token: string, format: string) => `/api/v1/reports/shared/${token}/download/${format}`,
  downloadSharedFile: vi.fn(),
}))

vi.mock('./LiveSharedReport', () => ({ LiveSharedReport: () => <div data-testid="live-body" /> }))
vi.mock('./InteractiveReport', () => ({ InteractiveReport: () => <div data-testid="static-body" /> }))
vi.mock('./SharedAttributionSection', () => ({ SharedAttributionSection: () => null }))
vi.mock('./SharedCreativeSection', () => ({ SharedCreativeSection: () => null }))
vi.mock('@/features/widgets/SharedWidgetsSection', () => ({ SharedWidgetsSection: () => null }))

import { downloadSharedFile, fetchSharedReport, sharedBranding } from './api'
import { PublicReport } from './PublicReport'

/**
 * SHARED-DOWNLOAD-PASSWORD-001 — the file is taken with the password the reader already typed.
 *
 * The download door refuses a password link without the password, and a plain link cannot carry
 * one. So on a password link the click is a fetch with the header; on any other link it stays the
 * navigation it always was. In neither case does the password reach an address.
 */
const report = {
  name: 'Q3', currency: 'SAR', is_demo: false, generated_at: null, mode: 'snapshot',
  period_start: '2026-07-01', period_end: '2026-07-31',
  settings: { allow_download: true, watermark: false }, data: {}, kind: 'static',
}

const ready = { status: 200, envelope: { data: report } } as never
const locked = { status: 401, envelope: { message: 'x', errors: { password_required: [true] } } } as never

function mount() {
  vi.mocked(sharedBranding).mockResolvedValue({ name: 'Nakheel', logo_url: null, logo_source: 'none' } as never)

  return renderWithProviders(<PublicReport />, { route: '/reports/share/tok123', path: '/reports/share/:token' })
}

describe('shared report downloads', () => {
  beforeEach(() => {
    vi.mocked(fetchSharedReport).mockReset()
    vi.mocked(downloadSharedFile).mockReset()
  })

  it('takes a password link\'s file with the accepted password, and never puts it in an address', async () => {
    vi.mocked(fetchSharedReport).mockResolvedValueOnce(locked).mockResolvedValueOnce(ready)
    vi.mocked(downloadSharedFile).mockResolvedValue({ ok: true })
    const { container } = mount()

    const input = await waitFor(() => {
      const el = container.querySelector('input[type="password"]')
      if (!el) throw new Error('no password prompt')
      return el as HTMLInputElement
    })
    fireEvent.change(input, { target: { value: 'open-sesame' } })
    fireEvent.submit(input.closest('form') as HTMLFormElement)

    const csv = await screen.findByTestId('shared-report-download-csv')
    expect(csv.getAttribute('href')).not.toContain('open-sesame')
    expect(csv.getAttribute('href')).not.toContain('password')

    fireEvent.click(csv)

    await waitFor(() => expect(downloadSharedFile).toHaveBeenCalledWith('tok123', 'csv', 'open-sesame'))
    expect(screen.queryByTestId('shared-report-download-error')).toBeNull()
  })

  it('says so when the file is refused', async () => {
    vi.mocked(fetchSharedReport).mockResolvedValueOnce(locked).mockResolvedValueOnce(ready)
    vi.mocked(downloadSharedFile).mockResolvedValue({ ok: false, status: 401 })
    const { container } = mount()

    const input = await waitFor(() => {
      const el = container.querySelector('input[type="password"]')
      if (!el) throw new Error('no password prompt')
      return el as HTMLInputElement
    })
    fireEvent.change(input, { target: { value: 'open-sesame' } })
    fireEvent.submit(input.closest('form') as HTMLFormElement)

    fireEvent.click(await screen.findByTestId('shared-report-download-xlsx'))

    expect(await screen.findByTestId('shared-report-download-error')).toBeTruthy()
  })

  it('leaves a link with no password as the plain navigation it was', async () => {
    vi.mocked(fetchSharedReport).mockResolvedValue(ready)
    mount()

    const pdf = await screen.findByTestId('shared-report-download-pdf')
    expect(pdf.getAttribute('href')).toBe('/api/v1/reports/shared/tok123/download/pdf')

    // jsdom does not navigate; what matters is that the click was not taken over.
    const notPrevented = fireEvent.click(pdf)
    expect(notPrevented).toBe(true)
    expect(downloadSharedFile).not.toHaveBeenCalled()
  })
})
