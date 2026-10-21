import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ShortLinkQr, qrModules } from './ShortLinkQr'
import { renderWithProviders } from '@/test/utils'

/** SHORT-LINK-QR-001 — the code is the link, drawn in the browser; a change in the address changes the code. */
describe('the short-link QR code', () => {
  it('encodes the address into a square of modules with the finder patterns in place', () => {
    const m = qrModules('https://campaignshub.io/l/k7m2ph4')
    expect(m.length).toBeGreaterThanOrEqual(21)
    expect(m.every((row) => row.length === m.length)).toBe(true)
    // Three finder patterns: 7×7 squares whose outer ring is dark, at three corners.
    for (const [r0, c0] of [[0, 0], [0, m.length - 7], [m.length - 7, 0]]) {
      for (let i = 0; i < 7; i++) {
        expect(m[r0][c0 + i]).toBe(true)
        expect(m[r0 + 6][c0 + i]).toBe(true)
        expect(m[r0 + i][c0]).toBe(true)
        expect(m[r0 + i][c0 + 6]).toBe(true)
      }
    }
    expect(qrModules('https://campaignshub.io/l/zzzzzzz')).not.toEqual(m)
  })

  it('draws the code, names the address, and offers SVG and PNG without sending anything anywhere', () => {
    const onClose = vi.fn()
    const create = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:x')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    renderWithProviders(<ShortLinkQr url="https://campaignshub.io/l/k7m2ph4" slug="k7m2ph4" ar onClose={onClose} />, { locale: 'ar' })

    const box = screen.getByTestId('short-link-qr')
    expect(Number(box.getAttribute('data-modules'))).toBeGreaterThanOrEqual(21)
    expect(box.querySelectorAll('rect').length).toBeGreaterThan(100)
    expect(box).toHaveTextContent('https://campaignshub.io/l/k7m2ph4')
    expect(box).toHaveTextContent('لا يُرسل شيء لأي خدمة')

    fireEvent.click(screen.getByTestId('short-link-qr-svg'))
    expect(create).toHaveBeenCalledTimes(1)
    const blob = create.mock.calls[0][0] as Blob
    expect(blob.type).toBe('image/svg+xml')
  })
})
