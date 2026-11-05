import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'

vi.mock('./api', () => ({
  fetchSharedReport: vi.fn(),
  sharedBranding: vi.fn(),
  sharedDownloadUrl: (token: string, format: string) => `/api/v1/reports/shared/${token}/download/${format}`,
  downloadSharedFile: vi.fn(),
}))

vi.mock('./LiveSharedReport', () => ({
  LiveSharedReport: () => <div data-testid="live-body" />,
}))

import { downloadSharedFile, fetchSharedReport, sharedBranding } from './api'
import { PublicReport } from './PublicReport'

/**
 * SHARED-DOWNLOAD-PASSWORD-001 — the file of a protected link is asked for WITH the password.
 *
 * The server now refuses the file without it. A plain address cannot carry the header, so a reader
 * who unlocked the page and pressed «CSV» would have been handed a refusal: the button takes the
 * file with the password that opened the page, and an open link keeps its plain address.
 */
const report = {
  name: 'Q3', currency: 'SAR', is_demo: false, generated_at: null,
  period_start: '2026-07-01', period_end: '2026-07-31',
  settings: { allow_download: true }, data: {}, kind: 'static', mode: 'snapshot',
}

beforeEach(() => {
  vi.mocked(downloadSharedFile).mockReset().mockResolvedValue(undefined)
  vi.mocked(sharedBranding).mockResolvedValue({ name: 'Nakheel', logo_url: null, logo_source: 'none', by: null } as never)
})

const mount = () => renderWithProviders(<PublicReport />, { locale: 'en', route: '/r/tok', path: '/r/:token' })

describe('the download buttons of a shared report', () => {
  it('take the file with the password that unlocked a protected link', async () => {
    vi.mocked(fetchSharedReport).mockImplementation(async (_token: string, pw?: string) =>
      (pw === 'open-sesame-1' ? { status: 200, envelope: { data: report } } : { status: 401, envelope: {} }) as never)
    mount()

    const field = await waitFor(() => {
      const input = document.querySelector('input[type="password"]')
      expect(input).not.toBeNull()
      return input as HTMLInputElement
    })
    fireEvent.change(field, { target: { value: 'open-sesame-1' } })
    fireEvent.submit(field.closest('form') as HTMLFormElement)

    fireEvent.click(await screen.findByTestId('shared-report-download-csv'))

    await waitFor(() => expect(downloadSharedFile).toHaveBeenCalledWith('tok', 'csv', 'open-sesame-1'))
    // The password never rides the address.
    expect(screen.getByTestId('shared-report-download-csv').getAttribute('href')).not.toContain('open-sesame-1')
  })

  it('says so when the protected file does not arrive', async () => {
    vi.mocked(fetchSharedReport).mockImplementation(async (_token: string, pw?: string) =>
      (pw === 'open-sesame-1' ? { status: 200, envelope: { data: report } } : { status: 401, envelope: {} }) as never)
    vi.mocked(downloadSharedFile).mockRejectedValue(new Error('download_failed_401'))
    mount()

    const field = await waitFor(() => {
      const input = document.querySelector('input[type="password"]')
      expect(input).not.toBeNull()
      return input as HTMLInputElement
    })
    fireEvent.change(field, { target: { value: 'open-sesame-1' } })
    fireEvent.submit(field.closest('form') as HTMLFormElement)

    fireEvent.click(await screen.findByTestId('shared-report-download-pdf'))

    expect(await screen.findByTestId('shared-report-download-failed')).toBeInTheDocument()
  })

  it('leave an open link on its plain address', async () => {
    vi.mocked(fetchSharedReport).mockResolvedValue({ status: 200, envelope: { data: report } } as never)
    mount()

    const link = await screen.findByTestId('shared-report-download-xlsx')
    expect(link.getAttribute('href')).toBe('/api/v1/reports/shared/tok/download/xlsx')
    fireEvent.click(link)

    expect(downloadSharedFile).not.toHaveBeenCalled()
  })
})
