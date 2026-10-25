import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LogoUploader, checkLogoFile } from './LogoUploader'
import { renderWithProviders } from '@/test/utils'
import { api } from '@/lib/api/client'

vi.mock('@/lib/api/client', async (orig) => {
  const actual = await orig<typeof import('@/lib/api/client')>()
  return { ...actual, api: { post: vi.fn(), delete: vi.fn(), get: vi.fn() }, getData: vi.fn() }
})
import { getData } from '@/lib/api/client'

/**
 * LOGO-UPLOAD-001 — a logo is a file: choose → preview → upload, replace, remove; a wrong file never travels; no address field exists.
 */
const state = (o = {}) => ({ own: false, effective_source: null, url: null, mime: null, bytes: null, ...o })
const mount = (canEdit = true) => renderWithProviders(<LogoUploader endpoint="/app/clients/c1/logo" ar={false} canEdit={canEdit} testId="lg" />, { locale: 'en' })
const png = (name = 'logo.png', size = 100) => new File([new Uint8Array(size)], name, { type: 'image/png' })

beforeEach(() => {
  vi.clearAllMocks()
  URL.createObjectURL = vi.fn(() => 'blob:preview')
  URL.revokeObjectURL = vi.fn()
  vi.mocked(getData).mockResolvedValue(state())
})

describe('LogoUploader', () => {
  it('knows the four accepted formats and the 2 MB ceiling', () => {
    expect(checkLogoFile(png())).toBeNull()
    for (const type of ['image/jpeg', 'image/svg+xml', 'image/webp']) expect(checkLogoFile(new File(['x'], 'a', { type }))).toBeNull()
    expect(checkLogoFile(new File(['x'], 'a.gif', { type: 'image/gif' }))).toBe('type')
    expect(checkLogoFile(new File(['x'], 'a.pdf', { type: 'application/pdf' }))).toBe('type')
    expect(checkLogoFile(png('big.png', 2 * 1024 * 1024 + 1))).toBe('size')
  })

  it('offers a file chooser and no address field', async () => {
    mount()
    await screen.findByTestId('lg-choose')
    expect(document.querySelector('input[type="url"]')).toBeNull()
    expect(screen.getByText(/No logo yet/)).toBeInTheDocument()
  })

  it('shows the file at once, uploads it, then draws the saved logo and offers replace and remove', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: state({ own: true, effective_source: 'own', url: '/api/v1/app/clients/c1/logo/file?v=abc' }) } } as never)
    mount()
    fireEvent.change(await screen.findByTestId('lg-input'), { target: { files: [png()] } })

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/app/clients/c1/logo', expect.any(FormData)))
    await waitFor(() => expect(screen.getByTestId('lg-choose').textContent).toMatch(/Replace/))
    expect(screen.getByTestId('lg-remove')).toBeInTheDocument()
    expect(screen.getByAltText('Logo preview').getAttribute('src')).toContain('/logo/file?v=abc')
  })

  it('refuses a wrong type or an oversized file before it is sent, in words', async () => {
    mount()
    const input = await screen.findByTestId('lg-input')
    fireEvent.change(input, { target: { files: [new File(['x'], 'a.gif', { type: 'image/gif' })] } })
    expect((await screen.findByTestId('lg-problem')).textContent).toMatch(/PNG, JPG, SVG or WEBP/)
    fireEvent.change(input, { target: { files: [png('big.png', 2 * 1024 * 1024 + 1)] } })
    await waitFor(() => expect(screen.getByTestId('lg-problem').textContent).toMatch(/2 MB/))
    expect(api.post).not.toHaveBeenCalled()
  })

  it('keeps a failed upload on screen with a retry that sends the same file again', async () => {
    vi.mocked(api.post).mockRejectedValueOnce({ response: { status: 413, data: '<html>' }, isAxiosError: true }).mockResolvedValue({ data: { data: state({ own: true, effective_source: 'own', url: '/x?v=1' }) } } as never)
    mount()
    fireEvent.change(await screen.findByTestId('lg-input'), { target: { files: [png()] } })
    fireEvent.click(await screen.findByTestId('lg-retry'))
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(screen.queryByTestId('lg-retry')).toBeNull())
  })

  it('removes the logo and says whose logo is drawn now', async () => {
    vi.mocked(getData).mockResolvedValue(state({ own: true, effective_source: 'own', url: '/x?v=1' }))
    vi.mocked(api.delete).mockResolvedValue({ data: { data: state({ effective_source: 'agency', url: '/api/v1/branding/logo/file?v=9' }) } } as never)
    mount()
    fireEvent.click(await screen.findByTestId('lg-remove'))
    expect((await screen.findByTestId('lg-inherited')).textContent).toMatch(/agency/i)
  })

  it('a reader without the right sees the logo and no controls', async () => {
    vi.mocked(getData).mockResolvedValue(state({ own: true, effective_source: 'own', url: '/x?v=1' }))
    mount(false)
    await screen.findByAltText('Logo preview')
    expect(screen.queryByTestId('lg-choose')).toBeNull()
    expect(screen.queryByTestId('lg-remove')).toBeNull()
  })
})
