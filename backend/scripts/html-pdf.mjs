// Headless-Chromium PDF of a LOCAL html file — INV-PDF-001.
//
// Opens a document the caller already composed (no origin, no network, no token), waits for fonts and
// images, and prints A4 portrait. Emits nothing but a JSON line on success; exits non-zero with a JSON
// error so the PHP caller never ships a partial file.
//
// Deliberately NOT report-print.mjs: that script waits on the four readiness signals the React print route
// raises and gates the slide layout, all of which are wrong for a one-document invoice.
//
// Usage: node html-pdf.mjs '<json-config>'
//   config: { html, out, landscape, timeoutMs, chromiumPath, requireBase }
import { createRequire } from 'module'
import { pathToFileURL } from 'url'

const cfg = JSON.parse(process.argv[2] || '{}')
const { html, out, landscape = false, timeoutMs = 30000, chromiumPath, requireBase } = cfg

function fail(code, detail) {
  process.stderr.write(JSON.stringify({ error: code, detail: String(detail ?? '') }))
  process.exit(1)
}
if (!html || !out) fail('bad_args', 'html and out are required')

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
  const resp = await page.goto(pathToFileURL(html).href, { waitUntil: 'domcontentloaded', timeout: timeoutMs })
  if (!resp) fail('navigation_failed', 'no response from the local document')

  // Fonts first: printing before they resolve photographs the fallback face, which for Arabic may not join.
  await page.evaluate(() => document.fonts.ready)
  await page.evaluate(() => Promise.all(
    Array.from(document.images).map((img) => (img.complete ? null : img.decode().catch(() => null))),
  ))

  await page.emulateMedia({ media: 'print' })
  await page.pdf({
    path: out,
    printBackground: true,
    preferCSSPageSize: true,
    landscape,
    format: 'A4',
    margin: { top: '0', bottom: '0', left: '0', right: '0' },
    tagged: true,
    outline: true,
  })
  process.stdout.write(JSON.stringify({ ok: true, out }))
  await browser.close()
} catch (e) {
  await browser.close().catch(() => {})
  fail('render_failed', e)
}
