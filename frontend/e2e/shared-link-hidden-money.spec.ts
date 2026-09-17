import { expect, test, type Page } from '@playwright/test'
import { inflateRawSync } from 'node:zlib'
import { API_HEADERS, AUTH, csrfHeaders } from './helpers'

/**
 * SHARED-PDF-HIDE-FLAGS-001 — the browser guard: a link hiding spend or revenue shows neither, nor
 * anything derived from them, in what a client can SEE, in any response the page loads, or in a file.
 *
 * ## The oracle is the same report shared without hiding
 *
 * Every money figure an OPEN link carries (spend, revenue, ROAS, CPA, cost per result and per funnel
 * stage, blended figures, budget spend, and figures written into findings, notes and reasons) is
 * collected and spelled every way a surface prints one — raw, two decimals, grouped, whole, compact
 * «K». None may appear on the HIDING link's page text in any mode, in any `/reports/shared/…` response
 * the page loads, or in its CSV and XLSX. A spelling that any non-hidden figure also produces is
 * excluded, so a click count cannot fail the guard by coincidence.
 *
 * The PDF is downloaded and must be a real PDF; what Chromium prints into it is asserted figure by
 * figure in `SharedLinkHiddenMoneyGuardTest`, over the print payload for both layouts, because a PDF's
 * text layer cannot be read reliably from here.
 *
 * The key lists are written here, not imported: a guard reading the implementation's list cannot catch
 * the key the implementation forgot.
 */
const SPEND_DERIVED = [
  'spend', 'spend_original', 'cpc', 'cpm', 'cpa', 'cpl', 'cpi', 'cpe', 'cac', 'cost_per_view', 'cost_per_lpv',
  'cost_per_result', 'cost_per', 'funnel_spend', 'blended_cpa', 'roas', 'blended_roas', 'attributed_roas',
  'spent', 'remaining', 'daily_average', 'over_under', 'projected_spend', 'excluded_spend', 'includes_non_sales_spend',
]
const REVENUE_DERIVED = ['revenue', 'revenue_original', 'gross_revenue', 'attributed_revenue', 'roas', 'blended_roas', 'attributed_roas', 'aov']

test.use({ storageState: AUTH.owner })

type Json = unknown

function spellings(v: number): string[] {
  const two = v.toFixed(2)
  const out = [two, v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }), String(Number(two))]
  if (Math.abs(v) >= 100) out.push(String(Math.round(v)), Math.round(v).toLocaleString('en-US'))
  if (Math.abs(v) >= 1000) out.push(`${Number((v / 1000).toFixed(1))}K`)
  if (Math.abs(v) >= 1_000_000) out.push(`${Number((v / 1_000_000).toFixed(2))}M`)
  /*
   * Too little to be evidence. «1.5» is a CTR as easily as a ROAS, and a bare whole number under ten
   * thousand is an impression count, a day's clicks or a row index as easily as an amount — a figure
   * counts when it carries decimals, grouping or a scale, or has five digits of its own.
   */
  return [...new Set(out)].filter((s) => {
    const digits = s.replace(/\D/g, '').replace(/^0+/, '').length
    return digits >= 3 && (/[.,KM]/.test(s) || digits >= 5)
  })
}

function proseStates(node: Record<string, unknown>, text: string, money: string[]): boolean {
  if (text.includes('×')) return money.includes('roas')
  if (!text.includes('SAR')) return false
  const kpi = typeof node.kpi === 'string' ? node.kpi : null
  if (kpi === 'الإيرادات') return money.includes('revenue')
  if (kpi === null || kpi === 'الإنفاق') return money.includes('spend')
  return money.includes('cpa')
}

function collect(node: Json, money: string[], figures: Set<number>, others: Set<number>, moneyContext = false) {
  if (!node || typeof node !== 'object') return
  const obj = node as Record<string, unknown>
  for (const [key, value] of Object.entries(obj)) {
    const isMoney = money.includes(key)
    if (typeof value === 'number') {
      if (isMoney || moneyContext) figures.add(value)
      else others.add(value)
    } else if (typeof value === 'string' && ['strengths', 'weaknesses', 'value', 'reason', 'detail'].includes(key) && proseStates(obj, value, money)) {
      for (const n of value.match(/\d[\d,]*(?:\.\d+)?/g) ?? []) figures.add(Number(n.replace(/,/g, '')))
    } else if (Array.isArray(value) && ['strengths', 'weaknesses'].includes(key)) {
      for (const sentence of value) {
        if (typeof sentence === 'string' && proseStates(obj, sentence, money)) {
          for (const n of sentence.match(/\d[\d,]*(?:\.\d+)?/g) ?? []) figures.add(Number(n.replace(/,/g, '')))
        }
      }
    }
    if (value && typeof value === 'object') collect(value, money, figures, others, moneyContext || (isMoney && typeof value === 'object'))
  }
}

/** The text of every XML part of an XLSX, read from the zip's central directory with node's zlib. */
function xlsxText(buf: Buffer): string {
  let text = ''
  let eocd = buf.length - 22
  while (eocd >= 0 && buf.readUInt32LE(eocd) !== 0x06054b50) eocd--
  const entries = buf.readUInt16LE(eocd + 10)
  let at = buf.readUInt32LE(eocd + 16)
  for (let i = 0; i < entries; i++) {
    const method = buf.readUInt16LE(at + 10)
    const size = buf.readUInt32LE(at + 20)
    const nameLen = buf.readUInt16LE(at + 28)
    const extraLen = buf.readUInt16LE(at + 30)
    const commentLen = buf.readUInt16LE(at + 32)
    const local = buf.readUInt32LE(at + 42)
    const name = buf.toString('utf8', at + 46, at + 46 + nameLen)
    const dataAt = local + 30 + buf.readUInt16LE(local + 26) + buf.readUInt16LE(local + 28)
    const raw = buf.subarray(dataAt, dataAt + size)
    if (name.endsWith('.xml')) {
      const xml = (method === 8 ? inflateRawSync(raw) : raw).toString('utf8')
      // The cell VALUES only: a shared-string cell's <v> is an index into the string table, not a figure.
      text += name.endsWith('sharedStrings.xml')
        ? xml.replace(/<[^>]+>/g, ' ')
        : [...xml.matchAll(/<c\b(?![^>]*t="s")[^>]*>(?:<f>[^<]*<\/f>)?<v>([^<]*)<\/v>/g)].map((m) => m[1]).join(' ')
    }
    at += 46 + nameLen + extraLen + commentLen
  }
  return text
}

async function reportWithMoney(page: Page) {
  const projects = (await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json()).data as Array<{ id: string }>
  for (const p of projects) {
    const reports = ((await (await page.request.get(`/api/v1/projects/${p.id}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status: string }>
    for (const r of reports.filter((x) => x.status === 'completed')) {
      const detail = (await (await page.request.get(`/api/v1/projects/${p.id}/reports/${r.id}`, { headers: API_HEADERS })).json()).data
      if (Number(detail?.data?.kpis?.spend ?? 0) > 0 && Number(detail?.data?.kpis?.revenue ?? 0) > 0) return { project: p.id, report: r.id }
    }
  }
  throw new Error('no generated report with spend and revenue to share, so this proves nothing')
}

for (const [label, flags, money] of [
  ['spend', { hide_spend: true }, SPEND_DERIVED],
  ['revenue', { hide_revenue: true }, REVENUE_DERIVED],
] as const) {
  test(`a link hiding ${label} shows none of it, or anything derived from it, anywhere a client can reach`, async ({ page, browser }) => {
    test.setTimeout(420_000)
    const { project, report } = await reportWithMoney(page)
    const link = async (body: Record<string, unknown>) => {
      const res = await page.request.post(`/api/v1/projects/${project}/reports/${report}/shares`, { headers: await csrfHeaders(page.request), data: body })
      expect(res.status(), await res.text()).toBe(201)
      return (await res.json()).data.token as string
    }

    // The oracle: what the same report discloses when nothing is hidden.
    const figures = new Set<number>()
    const others = new Set<number>()
    for (const mode of ['snapshot', 'live'] as const) {
      const open = await link({ mode, allow_download: true })
      collect(await (await page.request.get(`/api/v1/reports/shared/${open}`, { headers: API_HEADERS })).json(), [...money], figures, others)
      if (mode === 'live') collect(await (await page.request.get(`/api/v1/reports/shared/${open}/live`, { headers: API_HEADERS })).json(), [...money], figures, others)
    }
    const innocent = new Set([...others].flatMap((v) => [...spellings(v), ...spellings(v * 100)]))
    const wanted = [...figures].filter((v) => Math.abs(v) >= 1).flatMap(spellings).filter((s) => !innocent.has(s))
    expect(wanted.length, 'the open link carries almost no money, so the hidden link proves nothing').toBeGreaterThan(10)

    const leaks: string[] = []
    const hunt = (where: string, raw: string) => {
      // Identifiers are not figures: content keys, request ids and uuids are hex that happens to hold digits.
      const text = raw.replace(/\b[0-9a-f]{16,}\b|req_[0-9a-z]+|[0-9a-f]{8}-[0-9a-f-]{27}/gi, ' ')
      for (const s of new Set(wanted)) {
        // «397» inside «397K», or «3.28» inside «3.28%», is a different figure.
        const m = new RegExp(`(?<![\\d.,])${s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\dKM%]|\\.\\d)`).exec(text)
        if (m) leaks.push(`${where}: «${s}» … ${text.slice(Math.max(0, m.index - 70), m.index + 40).replace(/\s+/g, ' ')}`)
      }
    }

    for (const mode of ['snapshot', 'live'] as const) {
      const token = await link({ mode, allow_download: true, ...flags })
      const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } })
      const client = await ctx.newPage()
      const pending: Promise<void>[] = []
      client.on('response', (r) => {
        if (r.url().includes('/api/v1/reports/shared/') && !r.url().includes('/download/')) {
          pending.push(r.text().then((t) => hunt(`${mode} response ${new URL(r.url()).pathname.split('/').slice(-2).join('/')}`, t)).catch(() => {}))
        }
      })

      const views = mode === 'live' ? ['', '?view=summary', '?view=platforms', '?view=content'] : ['']
      for (const view of views) {
        await client.goto(`/r/${token}${view}`)
        await expect(client.locator(mode === 'live' ? '[data-testid="live-report"]' : 'main, body').first()).toBeVisible({ timeout: 30_000 })
        await client.waitForLoadState('networkidle').catch(() => {})
        hunt(`${mode} page ${view || 'default'}`, await client.locator('body').innerText())
        const tile = client.locator('[data-testid="live-content-tile"]').first()
        if (mode === 'live' && await tile.count()) {
          await tile.click()
          await client.waitForLoadState('networkidle').catch(() => {})
          hunt(`${mode} content dialog ${view}`, await client.locator('body').innerText())
          await client.keyboard.press('Escape')
        }
      }
      if (mode === 'snapshot') {
        for (let i = 0; i < 25; i++) {
          await client.keyboard.press('ArrowLeft').catch(() => {})
          hunt(`snapshot slide ${i}`, await client.locator('body').innerText())
        }
      }
      await Promise.all(pending)

      const csv = await ctx.request.get(`/api/v1/reports/shared/${token}/download/csv`)
      expect(csv.status()).toBe(200)
      hunt(`${mode} CSV`, await csv.text())
      const xlsx = await ctx.request.get(`/api/v1/reports/shared/${token}/download/xlsx`)
      expect(xlsx.status()).toBe(200)
      hunt(`${mode} XLSX`, xlsxText(await xlsx.body()))
      const pdf = await ctx.request.get(`/api/v1/reports/shared/${token}/download/pdf`, { timeout: 180_000 })
      expect(pdf.status(), `${mode}: the PDF of a link hiding ${label} could not be produced`).toBe(200)
      expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF')
      await ctx.close()
    }

    expect([...new Set(leaks)], `a hidden ${label} figure reached a client`).toEqual([])
  })
}
