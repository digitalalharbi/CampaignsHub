import { afterEach, describe, expect, it, vi } from 'vitest'
import { downloadSharedFile, sharedDownloadUrl } from './api'

/**
 * REPORT-FILTERS-FUNCTIONAL-001 — a protected link's file is asked for with the password, and a refusal is not saved as a file.
 */
describe('downloadSharedFile', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('sends the password in the header, never in the address, and saves under the server\'s name', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('a,b', {
      status: 200,
      headers: { 'Content-Disposition': "attachment; filename=report.csv; filename*=utf-8''%D8%AA%D9%82%D8%B1%D9%8A%D8%B1.csv" },
    }))
    vi.stubGlobal('fetch', fetchMock)
    const created = vi.fn().mockReturnValue('blob:x')
    const revoked = vi.fn()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: created, revokeObjectURL: revoked }))
    const saved: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      saved.push(this.download)
    })

    await expect(downloadSharedFile('tok', 'csv', 'open-sesame')).resolves.toBe(true)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(sharedDownloadUrl('tok', 'csv'))
    expect(url).not.toContain('open-sesame')
    expect((init.headers as Record<string, string>)['X-Report-Password']).toBe('open-sesame')
    expect(saved).toEqual(['تقرير.csv'])
    expect(revoked).toHaveBeenCalledWith('blob:x')
  })

  it('saves nothing when the server refuses', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 401 })))
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    await expect(downloadSharedFile('tok', 'pdf', 'wrong')).resolves.toBe(false)
    expect(click).not.toHaveBeenCalled()
  })
})
