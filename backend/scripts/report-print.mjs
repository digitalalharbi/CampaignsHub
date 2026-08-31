// Headless-Chromium report printer. Renders the React print route to a PDF, waiting until the page
// signals that data, fonts, charts and images are all settled. Emits nothing but the PDF path on
// success; exits non-zero with a JSON error on failure so the PHP caller can mark the export Failed.
//
// Usage: node report-print.mjs '<json-config>'
//   config: { url, out, landscape, timeoutMs, chromiumPath, requireBase }
import { createRequire } from 'module'

const cfg = JSON.parse(process.argv[2] || '{}')
const { url, out, landscape = true, timeoutMs = 45000, chromiumPath, requireBase } = cfg

function fail(code, detail) {
  process.stderr.write(JSON.stringify({ error: code, detail: String(detail ?? '') }))
  process.exit(1)
}
if (!url || !out) fail('bad_args', 'url and out are required')

// Resolve playwright-core from the frontend's node_modules (passed as requireBase = its package.json).
const require = createRequire(requireBase || import.meta.url)
let chromium
try { ({ chromium } = require('playwright-core')) } catch (e) { fail('playwright_missing', e) }

const browser = await chromium.launch({
  headless: true,
  executablePath: chromiumPath || undefined,
  args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
}).catch((e) => fail('launch_failed', e))

try {
  const page = await browser.newPage({ deviceScaleFactor: 2 })
  // Fail on real JS errors, but ignore benign resource failures (e.g. the app's /auth/me bootstrap
  // returns 401 on this public print route with no session — that is expected, not a report error).
  const fatalErrors = []
  /*
   * A RESOURCE that fails is not a report error; a script that throws is.
   *
   * The document carries ad thumbnails from provider CDNs, and a signed URL expires, a host refuses,
   * a network cannot resolve it. Chromium reports each of those as a console error, and treating them
   * as fatal fails the whole export over a picture the document already knows how to do without —
   * it prints the platform's own sentence in the thumbnail's place.
   *
   * Real failures still fail: `pageerror` catches thrown exceptions, and the readiness and layout
   * gates below refuse a page that did not render.
   */
  const benign = /status of (401|403|404)|net::ERR_[A-Z_]+|Failed to load resource|favicon/i
  page.on('console', (m) => { if (m.type() === 'error' && !benign.test(m.text())) fatalErrors.push(m.text()) })
  page.on('pageerror', (e) => fatalErrors.push(String(e)))

  /*
   * REPORT-AD-PREVIEW-001 — a picture must not be able to hold an export open.
   *
   * This waited for `networkidle`, which means «no request for 500ms» — and the document now carries
   * ad thumbnails from provider CDNs. One signed URL that has expired, one host that answers slowly,
   * one network that cannot reach it at all, and networkidle never arrives: the render times out and
   * a client's export fails over a decoration.
   *
   * The page's OWN readiness signals are stronger than networkidle anyway — data, charts, images and
   * fonts each report themselves below — so navigation waits only for the document, and the network
   * is given a bounded chance to settle after that. What cannot load is simply not in the picture,
   * which is the same rule the report follows everywhere else.
   */
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs })
  await page.waitForLoadState('networkidle', { timeout: Math.min(8000, timeoutMs) }).catch(() => {})
  if (!resp || !resp.ok()) fail('navigation_failed', `status ${resp ? resp.status() : 'none'}`)

  // Explicit failure signalled by the print route (bad token / API error).
  const errored = await page.evaluate(() => window.__REPORT_ERROR__ === true)
  if (errored) fail('report_error', 'print route reported a data error')

  await page.evaluate(() => document.fonts.ready)
  await page.waitForFunction(() => window.__REPORT_DATA_READY__ === true, { timeout: timeoutMs })
  await page.waitForFunction(() => window.__REPORT_CHARTS_READY__ === true, { timeout: timeoutMs })
  await page.waitForFunction(() => window.__REPORT_IMAGES_READY__ === true, { timeout: timeoutMs })

  if (fatalErrors.length) fail('console_errors', fatalErrors.slice(0, 5).join(' | '))

  // Hard layout gate — no overflowing, horizontally-clipped, or truly empty pages may be printed.
  // Content utilization is measured over real content (not the page background); "sparse" pages are
  // surfaced as warnings for review, not a hard block.
  const layout = await page.evaluate(() => window.__REPORT_LAYOUT__ || [])
  // A slide fails if it overflows, is horizontally clipped, empty, clips important content, or was
  // shrunk below the readable limit — so overflow:hidden / auto-scaling can never HIDE a real defect.
  const bad = layout.filter((p) => p.overflow || p.overflowX || p.empty || p.clippedElements > 0 || p.scaledBelowReadableLimit)
  if (bad.length && !cfg.ignoreLayout) {
    fail('layout_validation_failed', bad.map((p) => `page ${p.page}: ${p.overflow ? 'overflow ' : ''}${p.overflowX ? 'overflowX ' : ''}${p.empty ? 'empty ' : ''}${p.clippedElements > 0 ? `clipped(${p.clippedElements}) ` : ''}${p.scaledBelowReadableLimit ? `unreadable(${p.fitScale})` : ''}`.trim()).join(' | '))
  }

  await page.emulateMedia({ media: 'print' })
  await page.pdf({
    path: out,
    printBackground: true,
    preferCSSPageSize: true,
    landscape,
    format: 'A4',
    margin: { top: '0', bottom: '0', left: '0', right: '0' },
    // Tagged/accessible PDF → logical reading order in the text layer (searchable/copyable Arabic,
    // screen-reader friendly) + a document outline. Addresses the RTL text-layer, not just visuals.
    tagged: true,
    outline: true,
  })
  process.stdout.write(JSON.stringify({ ok: true, out, pages: layout.length, layout }))
  await browser.close()
} catch (e) {
  await browser.close().catch(() => {})
  fail('render_failed', e)
}
