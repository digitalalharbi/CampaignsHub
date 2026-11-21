import { execFileSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject } from './helpers'

/**
 * DISCLAIMER-PDF-001 — the exported PDF carries the approved short note on its pages and the FULL note on its last page,
 * in a readable (non-reversed) Arabic text layer, and every printed page holds the readable fit floor.
 *
 * Driven through the repo's own export path (generate → export button's API → Chromium renderer → ToUnicode repair →
 * download) and read with Apple's PDFKit — the reader the repo notes name, not pdfplumber. macOS only: skipped elsewhere.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'
const PHRASES = ['العروض والخصومات', 'مستوى المنافسة', 'جودة المحتوى', 'سلوك العملاء', 'تكاليف المنصات', 'طبيعة المنتج والتسعير']
const SHORT = 'تُدار الحملات وفق منهجية قائمة على الأداء'
const reversed = (s: string) => [...s].reverse().join('')

test.use({ storageState: AUTH.owner })
test.skip(process.platform !== 'darwin', 'PDFKit is the text-layer reader')

function pdfText(bytes: Buffer): string {
  const dir = mkdtempSync(join(tmpdir(), 'disclaimer-pdf-'))
  const pdf = join(dir, 'report.pdf')
  const script = join(dir, 'extract.swift')
  writeFileSync(pdf, bytes)
  writeFileSync(script, 'import PDFKit\nimport Foundation\nlet d = PDFDocument(url: URL(fileURLWithPath: CommandLine.arguments[1]))!\nprint(d.string ?? "")\n')

  return execFileSync('swift', [script, pdf], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
}

test('the PDF carries the approved note and every page holds the fit floor', async ({ page, request }) => {
  test.setTimeout(300_000)
  const projectId = await seededProject(request, STORE_PROJECT)
  const list = ((await (await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status?: string }>
  const reportId = list.find((r) => r.status === 'completed')!.id
  const headers = { ...(await csrfHeaders(request)), 'Content-Type': 'application/json' }

  expect((await request.post(`/api/v1/projects/${projectId}/reports/${reportId}/regenerate`, { headers, data: { confirm: true } })).ok()).toBe(true)
  await expect.poll(async () => (await (await request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data?.status, { timeout: 120_000, intervals: [1500] }).toBe('completed')

  // (1) the printed pages: every one holds the floor, nothing clipped, and the closing notes page is there.
  const tok = await request.post(`/api/v1/projects/${projectId}/reports/${reportId}/print-token`, { headers, data: { type: 'presentation' } })
  expect(tok.status()).toBe(200)
  await page.goto(`/reports/print/${(await tok.json()).data.token}?type=presentation`)
  await page.waitForFunction(() => (window as unknown as { __REPORT_LAYOUT__?: unknown }).__REPORT_LAYOUT__ !== undefined, null, { timeout: 90_000 })
  const layout = (await page.evaluate(() => (window as unknown as { __REPORT_LAYOUT__: Array<{ page: number; slideType: string; fitScale: number; overflow: boolean; clippedElements: number }> }).__REPORT_LAYOUT__))
  // eslint-disable-next-line no-console
  console.log('DISCLAIMER-PRINT-FIT ' + layout.map((p) => `${p.page}:${p.slideType}=${p.fitScale}`).join(' '))
  for (const p of layout) {
    expect(p.fitScale, `page ${p.page} (${p.slideType}) fit scale`).toBeGreaterThanOrEqual(0.85)
    expect(p.overflow, `page ${p.page} overflows`).toBe(false)
    expect(p.clippedElements, `page ${p.page} clips content`).toBe(0)
  }
  expect(layout[layout.length - 1].slideType).toBe('notes')
  await expect(page.getByTestId('print-notes-full')).toContainText('العروض والخصومات')

  // (2) the exported file's text layer, read with PDFKit.
  const queued = await request.post(`/api/v1/projects/${projectId}/reports/${reportId}/export`, { headers, data: { format: 'pdf' } })
  expect(queued.status()).toBe(202)
  const exportId = (await queued.json()).data.id as string
  let token = ''
  await expect.poll(async () => {
    const exports = ((await (await request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data.exports ?? []) as Array<{ id: string; format: string; status: string; token: string | null }>
    token = exports.find((e) => e.id === exportId && e.status === 'completed' && e.token)?.token ?? ''

    return token
  }, { timeout: 150_000, intervals: [2000] }).not.toBe('')
  const res = await request.get(`/api/v1/reports/download/${token}`, { headers: API_HEADERS })
  expect(res.ok()).toBe(true)
  // A printed line may break inside a phrase; the text layer holds a newline there, so compare on collapsed whitespace.
  const text = pdfText(await res.body()).replace(/\s+/g, ' ')
  writeFileSync(join(tmpdir(), 'disclaimer-pdf.txt'), text)
  // eslint-disable-next-line no-console
  console.log(`DISCLAIMER-PDF-TEXT ${text.length} chars; ` + [SHORT, ...PHRASES].map((p) => `${p}=${text.includes(p)}`).join('; '))

  expect(text.length, 'PDFKit read no text').toBeGreaterThan(500)
  expect(text, 'the short note is missing from the PDF').toContain(SHORT)
  for (const phrase of PHRASES) {
    expect(text, `«${phrase}» is missing from the PDF text layer`).toContain(phrase)
    expect(text, `«${phrase}» is reversed in the PDF text layer`).not.toContain(reversed(phrase))
  }
})
