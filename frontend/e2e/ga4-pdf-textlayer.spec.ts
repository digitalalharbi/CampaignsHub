import { execFileSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject } from './helpers'

/**
 * GA4-PDF-TEXTLAYER-001 — a hidden element's figures are absent from the exported PDF's TEXT LAYER, and the Arabic text
 * layer is not reversed.
 *
 * Driven through the repo's own export path (generate → export button's API → Chromium renderer → ToUnicode repair →
 * download) and read with Apple's PDFKit — the reader the repo notes name, not pdfplumber. macOS only: skipped elsewhere.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'
const SALES = ['tile.transactions', 'tile.revenue', 'tile.aov', 'tile.conversion_rate', 'chart.series.sales', 'stage.purchase', 'table.products', 'col.purchases', 'col.revenue', 'col.aov', 'col.conversion_rate', 'attr.sales', 'attr.gap', 'gap.headline', 'gap.reason.no_trace', 'gap.reason.attribution_rule', 'gap.reason.untagged_channel', 'gap.reason.no_platform_trace']
const PRODUCT = 'عباية سوداء كلاسيكية'
const PHRASE = 'مطابقة الإسناد'
const reversed = (s: string) => [...s].reverse().join('')

test.use({ storageState: AUTH.owner })
test.skip(process.platform !== 'darwin', 'PDFKit is the text-layer reader')

function pdfText(bytes: Buffer): string {
  const dir = mkdtempSync(join(tmpdir(), 'ga4-pdf-'))
  const pdf = join(dir, 'report.pdf')
  const script = join(dir, 'extract.swift')
  writeFileSync(pdf, bytes)
  writeFileSync(script, 'import PDFKit\nimport Foundation\nlet d = PDFDocument(url: URL(fileURLWithPath: CommandLine.arguments[1]))!\nprint(d.string ?? "")\n')

  return execFileSync('swift', [script, pdf], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
}

for (const [mode, hidden] of [['full data', [] as string[]], ['sales hidden', SALES]] as const) {
  test(`the PDF text layer, ${mode}`, async ({ page, request }) => {
    test.setTimeout(300_000)
    const projectId = await seededProject(request, STORE_PROJECT)
    const list = ((await (await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status?: string }>
    const reportId = list.find((r) => r.status === 'completed')!.id
    const headers = { ...(await csrfHeaders(request)), 'Content-Type': 'application/json' }

    expect((await request.put(`/api/v1/projects/${projectId}/reports/${reportId}/sections`, { headers, data: { ga4_hidden: hidden } })).ok()).toBe(true)
    expect((await request.post(`/api/v1/projects/${projectId}/reports/${reportId}/regenerate`, { headers, data: { confirm: true } })).ok()).toBe(true)
    await expect.poll(async () => (await (await request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data?.status, { timeout: 120_000, intervals: [1500] }).toBe('completed')
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
    const text = pdfText(await res.body())
    writeFileSync(join(tmpdir(), `ga4-pdf-${mode.replace(' ', '-')}.txt`), text)
    // eslint-disable-next-line no-console
    console.log(`GA4-PDF-TEXT ${mode}: ${text.length} chars; product=${text.includes(PRODUCT)}; phrase=${text.includes(PHRASE)}; phrase reversed=${text.includes(reversed(PHRASE))}`)

    expect(text.length, 'PDFKit read no text').toBeGreaterThan(500)
    expect(text, 'the Arabic text layer is reversed').not.toContain(reversed(PHRASE))
    expect(text, 'the Arabic heading is missing from the text layer').toContain(PHRASE)
    if (mode === 'full data') expect(text).toContain(PRODUCT)
    else expect(text, 'a hidden element (the products table) is in the text layer').not.toContain(PRODUCT)
  })
}
