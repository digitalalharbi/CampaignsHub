import { afterEach, describe, expect, it, vi } from 'vitest'

import { downloadSharedFile, filenameFromDisposition } from './api'

/** SHARED-DOWNLOAD-PASSWORD-001 — the password travels as a header, and the file keeps the server's name. */
describe('downloadSharedFile', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('sends the password as a header and not in the address', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('no', { status: 401 }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await downloadSharedFile('tok123', 'csv', 'open-sesame')

    expect(result).toEqual({ ok: false, status: 401 })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/reports/shared/tok123/download/csv')
    expect(url).not.toContain('open-sesame')
    expect((init.headers as Record<string, string>)['X-Report-Password']).toBe('open-sesame')
  })

  it('reports a network failure rather than throwing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))

    expect(await downloadSharedFile('tok123', 'pdf', 'x')).toEqual({ ok: false, status: 0 })
  })

  it('hands the browser the file under the name the server gave it', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('a,b', {
      status: 200,
      headers: { 'content-disposition': "attachment; filename=report.csv; filename*=utf-8''%D8%AA%D9%82%D8%B1%D9%8A%D8%B1.csv" },
    })))
    const createObjectURL = vi.fn(() => 'blob:x')
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() }))
    const names: string[] = []
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      names.push(this.download)
    })

    expect(await downloadSharedFile('tok123', 'csv', 'open-sesame')).toEqual({ ok: true })
    expect(names).toEqual(['تقرير.csv'])
    click.mockRestore()
  })
})

describe('filenameFromDisposition', () => {
  it('prefers the UTF-8 form, falls back to the plain one, and to nothing', () => {
    expect(filenameFromDisposition("attachment; filename=a.csv; filename*=utf-8''%D8%AA.csv")).toBe('ت.csv')
    expect(filenameFromDisposition('attachment; filename="my report.pdf"')).toBe('my report.pdf')
    expect(filenameFromDisposition('attachment; filename=r.xlsx')).toBe('r.xlsx')
    expect(filenameFromDisposition('attachment')).toBeNull()
    expect(filenameFromDisposition(null)).toBeNull()
  })
})
