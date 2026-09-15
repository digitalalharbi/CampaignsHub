/**
 * MKT-FIX-001 — measure what still moves on a genuinely cold load of production.
 *
 * Not a test: a measuring instrument. Every context is brand new (no storage, no service worker)
 * and the cache is disabled at the CDP/route level, because the owner's own warning is that a warm
 * cache hides this entirely — a warm reload measured 0.0000 while the phone visibly moved.
 */
import { chromium, firefox, webkit } from '@playwright/test'

const URL = process.env.TARGET ?? 'https://campaignshub.io/'
const WIDTHS = [390, 393]

async function measure(browserType, name, width, prefs) {
  const browser = await browserType.launch()
  const context = await browser.newContext({
    viewport: { width, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
    colorScheme: prefs.scheme,
    locale: prefs.locale,
    bypassCSP: false,
  })

  // A cold load means the bytes are fetched, not replayed. Playwright contexts start with an empty
  // cache, and this refuses any conditional revalidation that would still serve from disk.
  await context.route('**/*', (route) => route.continue({ headers: { ...route.request().headers(), 'cache-control': 'no-cache', pragma: 'no-cache' } }))

  const page = await context.newPage()

  await page.addInitScript(() => {
    window.__shifts = []
    window.__firstPaintDoc = null
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) {
        if (!e.hadRecentInput) window.__shifts.push({ value: e.value, start: e.startTime, sources: (e.sources ?? []).map((s) => ({ node: s.node?.nodeName, id: s.node?.id, cls: typeof s.node?.className === 'string' ? s.node.className.slice(0, 60) : null, from: s.previousRect, to: s.currentRect })) })
      }
    }).observe({ type: 'layout-shift', buffered: true })
  })

  const served = {}
  page.on('response', async (r) => {
    if (r.url().replace(/\/$/, '') === URL.replace(/\/$/, '') && served.html === undefined) {
      try { served.html = (await r.text()).slice(0, 1200) } catch { /* redirect bodies */ }
    }
  })

  await page.goto(URL, { waitUntil: 'load' })
  await page.waitForTimeout(3500)

  const result = await page.evaluate(() => {
    const root = document.documentElement
    const shifts = window.__shifts ?? []
    return {
      lang: root.getAttribute('lang'), dir: root.getAttribute('dir'), theme: root.getAttribute('data-theme'),
      cls: shifts.reduce((t, s) => t + s.value, 0),
      count: shifts.length,
      shifts: shifts.slice(0, 6),
      overflowX: root.scrollWidth - window.innerWidth,
      title: document.title,
      fonts: [...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family).filter((v, i, a) => a.indexOf(v) === i),
    }
  })

  await browser.close()
  return { browser: name, width, ...prefs, ...result, servedHead: (served.html ?? '').includes('data-theme') }
}

const out = []
for (const [type, name] of [[chromium, 'chromium'], [firefox, 'firefox'], [webkit, 'webkit']]) {
  for (const width of WIDTHS) {
    for (const prefs of [{ locale: 'ar-SA', scheme: 'dark' }, { locale: 'en-US', scheme: 'light' }]) {
      try { out.push(await measure(type, name, width, prefs)) } catch (e) { out.push({ browser: name, width, ...prefs, error: String(e).slice(0, 160) }) }
    }
  }
}
console.log(JSON.stringify(out, null, 1))
