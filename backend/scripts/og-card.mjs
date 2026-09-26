// SHARE-PREVIEW-CARD-001 — photograph the link-preview card.
//
// Opens a LOCAL html file (no origin, no network) in headless Chromium and writes a 1200×630 PNG.
// Emits the path on success; exits non-zero with a JSON error so the PHP caller can fall back to a
// card with no image rather than pointing a crawler at a broken URL.
//
// Deliberately NOT report-print.mjs. That script waits on four readiness signals the React print
// route raises, gates page layout, and prints A4 — every one of which is wrong here, and bending it
// to serve both would put the client PDF's fail-closed path at the mercy of a decoration.
//
// Usage: node og-card.mjs '<json-config>'
//   config: { html, out, width, height, timeoutMs, chromiumPath, requireBase }
import { createRequire } from 'module'
import { pathToFileURL } from 'url'

const cfg = JSON.parse(process.argv[2] || '{}')
const { html, out, width = 1200, height = 630, timeoutMs = 20000, chromiumPath, requireBase } = cfg

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
  // deviceScaleFactor 1: the card IS 1200×630, which is the size every crawler wants. Rendering at 2x
  // and shipping 2400×1260 only costs the client's phone bandwidth on a picture nobody zooms.
  const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 })

  const resp = await page.goto(pathToFileURL(html).href, { waitUntil: 'domcontentloaded', timeout: timeoutMs })
  if (!resp) fail('navigation_failed', 'no response from the local document')

  /*
   * Fonts, then the mark.
   *
   * `document.fonts.ready` is what makes the Arabic correct: screenshot before it resolves and the
   * card is photographed in the fallback face, which for Arabic is frequently a face that does not
   * join. The mark is waited for separately because it is a data URI — decoding is not instant for a
   * large PNG, and an `<img>` mid-decode photographs as a blank box.
   */
  await page.evaluate(() => document.fonts.ready)
  await page.evaluate(() => Promise.all(
    Array.from(document.images).map((img) => (img.complete ? null : img.decode().catch(() => null))),
  ))

  await page.screenshot({ path: out, type: 'png', clip: { x: 0, y: 0, width, height } })
  process.stdout.write(JSON.stringify({ ok: true, out, width, height }))
  await browser.close()
} catch (e) {
  await browser.close().catch(() => {})
  fail('render_failed', e)
}
