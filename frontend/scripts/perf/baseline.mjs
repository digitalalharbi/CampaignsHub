/**
 * PERF-BASELINE-WEBVITALS-001 — a LOCAL, repeatable baseline of Analytics → GA4 (the Owner's screenshot).
 *
 *   node scripts/perf/baseline.mjs --dist <built dir> --label <name> [--port 4311] [--latency 400] [--runs 5]
 *
 * What it is: one built bundle, served by a throwaway static server that ALSO answers /api/v1 from a
 * fixed in-memory fixture with a fixed per-request latency. The same fixture, latency, browser and
 * viewport are used for every bundle, so two runs differ only by the code under test.
 *
 * What it is NOT: a Production measurement, a real-network measurement or a backend measurement. The
 * latency is a constant (default 400ms) standing in for a read endpoint; server p50/p95 come from the
 * Production diagnostics, not from here. Numbers are medians of N runs and are comparable only to each
 * other, on this machine.
 *
 * Measured per run (Chromium, 1440x900):
 *  - cold open: ms to the first KPI figure, LCP, CLS, long tasks, API requests (total / duplicates)
 *  - period change: frames in which the KPI row was GONE (emptied), ms to the new figures, requests
 *  - revisit after the reader's cache lifetime: whether the figures were on the FIRST frame
 */
import http from 'node:http'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { join, extname } from 'node:path'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const { chromium } = require('@playwright/test')

const arg = (k, d) => { const i = process.argv.indexOf(`--${k}`); return i > 0 ? process.argv[i + 1] : d }
const DIST = arg('dist'); const LABEL = arg('label', 'run'); const PORT = Number(arg('port', 4311))
const LATENCY = Number(arg('latency', 400)); const RUNS = Number(arg('runs', 5))
if (!DIST) throw new Error('--dist is required')

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff' }

const user = { id: 'u1', name: 'Perf', email: 'perf@example.test', tenant_id: 't1', is_platform_admin: false, created_at: null, email_verified: true,
  account: { account_type: 'agency', workspace_kind: 'company', portal: 'agency', enabled_modules: [], module_switcher: false, nav: [], subscription_plan: 'pro', onboarding: { completed: true, step: 'done' } },
  permissions: ['campaigns.view', 'campaigns.manage', 'analytics.view', 'projects.view', 'reports.view', 'integrations.view', 'clients.view'] }
const membership = { id: 'm1', portal: 'agency', portal_path: '/agency', landing_path: '/agency/dashboard', role: 'owner', is_default: true, is_active: true,
  tenant: { id: 't1', name: 'Perf Agency', slug: 'perf' }, client_scope_ids: [], project_scope_ids: [] }
const project = { id: 'p1', client_workspace_id: 'c1', name: 'Demo Store', status: 'active', setup_completion: 100, account_manager_id: null, created_at: '2026-01-01T00:00:00Z' }

function ga4(from, to) {
  const days = Math.max(1, Math.round((Date.parse(to) - Date.parse(from)) / 864e5) + 1)
  const trend = Array.from({ length: Math.min(days, 90) }, (_, i) => ({ date: new Date(Date.parse(from) + i * 864e5).toISOString().slice(0, 10), sessions: 900 + i * 7, engaged_sessions: 500, purchases: 20 + (i % 5), revenue: 4000 + i * 11 }))
  const rows = (n) => ({ rows: Array.from({ length: n }, (_, i) => ({ dimension_1: `source-${i}`, dimension_2: 'cpc', sessions: 500 - i * 30, engaged_sessions: 300, key_events: 10, add_to_carts: 8, checkouts: 5, purchases: 4, revenue: 800 - i * 50, engagement_rate: 0.55, conversion_rate: 0.02 })), more: 0 })
  return { state: 'ready', period: { from, to }, property: { id: '111', name: 'Demo Store', timezone: 'Asia/Riyadh' }, last_synced_at: new Date().toISOString(), currency: 'SAR',
    totals: { sessions: days * 1000, engaged_sessions: days * 560, engagement_rate: 0.56, purchases: days * 30, key_events: days * 31, revenue: days * 5500, users_daily_sum: days * 1400 },
    trend, funnel: [{ stage: 'sessions', count: days * 1000, step_rate: null, exceeds_previous: false }, { stage: 'purchases', count: days * 30, step_rate: 0.03, exceeds_previous: false }],
    paid: { available: false }, breakdowns: { source_medium: rows(8), campaign: rows(8), landing_page: rows(8), campaign_landing: rows(8), device: rows(3), country: rows(8), user_type: rows(2) },
    events: { rows: [{ event: 'purchase', event_count: 30, key_events: 30, is_key_event: true }], more: 0 } }
}

const requests = []
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`)
  if (url.pathname.startsWith('/api/v1')) {
    const path = url.pathname.slice('/api/v1'.length)
    requests.push({ t: Date.now(), path, search: url.search })
    await new Promise((r) => setTimeout(r, LATENCY))
    let data = []
    if (path === '/auth/me') data = { user }
    else if (path.startsWith('/auth/memberships')) data = { memberships: [membership], current: membership, destination: '/agency/dashboard', needs_switcher: false, requested_portal: null, requested_portal_held: null, pending_application: null }
    else if (path.endsWith('/disclaimer')) data = { sections: {}, enabled: {} }
    else if (path === '/projects') data = [project]
    else if (path === '/projects/p1/measurement/analytics') data = ga4(url.searchParams.get('from'), url.searchParams.get('to'))
    else if (/\/(summary|freshness|normalization|attribution|budget|funnel)$/.test(path)) data = path.endsWith('summary') ? {} : []
    res.writeHead(200, { 'content-type': 'application/json' })
    res.end(JSON.stringify({ data, meta: {} }))
    return
  }
  let file = join(DIST, url.pathname)
  if (!existsSync(file) || statSync(file).isDirectory()) file = join(DIST, 'index.html')
  res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' })
  res.end(readFileSync(file))
})

const PROBE = `
  window.__perf = { lcp: 0, cls: 0, longTasks: 0, longMs: 0, gone: 0, frames: 0, seen: false };
  try { new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__perf.lcp = e.startTime }).observe({ type: 'largest-contentful-paint', buffered: true }) } catch {}
  try { new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__perf.cls += e.value }).observe({ type: 'layout-shift', buffered: true }) } catch {}
  try { new PerformanceObserver((l) => { for (const e of l.getEntries()) { window.__perf.longTasks++; window.__perf.longMs += e.duration } }).observe({ type: 'longtask', buffered: true }) } catch {}
  window.__watchKpi = (on) => {
    window.__perf.watching = on; window.__perf.gone = 0; window.__perf.frames = 0
    const tick = () => { if (!window.__perf.watching) return; window.__perf.frames++; if (!document.querySelector('[data-testid="site-kpi-sessions"]')) window.__perf.gone++; requestAnimationFrame(tick) }
    requestAnimationFrame(tick)
  };
`

const median = (xs) => xs.length ? [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)] : null
const dupes = (list) => { const seen = new Map(); for (const r of list) { const k = r.path + r.search; seen.set(k, (seen.get(k) ?? 0) + 1) } return [...seen.values()].reduce((n, c) => n + (c > 1 ? c - 1 : 0), 0) }

async function once(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' })
  await ctx.addInitScript(PROBE)
  await ctx.addInitScript(() => { localStorage.setItem('campaign-hub-project-storage', JSON.stringify({ state: { currentProjectId: 'p1' }, version: 0 })); localStorage.setItem('campaign-hub-locale', 'en') })
  const page = await ctx.newPage()
  if (process.env.PERF_DEBUG) { page.on('pageerror', (e) => console.error('PAGEERROR', e.message.slice(0, 300))); page.on('console', (m) => m.type() === 'error' && console.error('CONSOLE', m.text().slice(0, 300))) }
  await page.clock.install()
  const out = {}

  // 1. cold open
  requests.length = 0
  const t0 = Date.now()
  await page.goto(`http://localhost:${PORT}/agency/analytics?tab=site&days=30`)
  try { await page.waitForSelector('[data-testid="site-kpi-sessions-value"]', { timeout: 15000 }) } catch (e) { if (process.env.PERF_DEBUG) { await page.screenshot({ path: '/private/tmp/claude-501/perf-debug.png' }); console.error(page.url(), JSON.stringify(requests.map((r) => r.path))) } throw e }
  out.coldFirstKpiMs = Date.now() - t0
  await page.waitForTimeout(800)
  out.coldRequests = requests.length
  if (process.env.PERF_TIMELINE) console.error(requests.map((r) => `${r.t - t0}ms ${r.path}`).join('\n'))
  out.coldDuplicateRequests = dupes(requests)
  out.coldSiteRequests = requests.filter((r) => r.path.includes('/measurement/analytics')).length
  const p = await page.evaluate(() => window.__perf)
  out.lcp = Math.round(p.lcp); out.cls = Math.round(p.cls * 1000) / 1000; out.longTasks = p.longTasks; out.longMs = Math.round(p.longMs)

  // 2. period change (30 -> 7 days) through the real chips
  requests.length = 0
  await page.evaluate(() => window.__watchKpi(true))
  const t1 = Date.now()
  await page.getByText('7 days', { exact: true }).first().click()
  await page.waitForFunction(() => document.querySelector('[data-testid="site-kpi-sessions-value"]')?.textContent?.includes('7,000'), null, { timeout: 20000 })
  out.periodChangeNewFiguresMs = Date.now() - t1
  const w = await page.evaluate(() => { window.__perf.watching = false; return { gone: window.__perf.gone, frames: window.__perf.frames } })
  out.periodChangeFramesWithoutKpi = w.gone
  out.periodChangeFrames = w.frames
  out.periodChangeRequests = requests.length

  // 3. revisit after the 5-minute library default would have evicted the entry: leave, wait 10 min of page time, return
  await page.locator('a[href="/agency/dashboard"]').first().click()
  await page.waitForURL('**/agency/dashboard**')
  await page.waitForTimeout(500)
  await page.clock.fastForward('10:00')
  requests.length = 0
  await page.evaluate(() => window.__watchKpi(true))
  await page.goBack()
  const t2 = Date.now()
  await page.waitForSelector('[data-testid="site-analytics"], [data-testid="site-skeleton"]', { timeout: 20000 })
  out.revisitFirstFrame = await page.evaluate(() => document.querySelector('[data-testid="site-analytics"]') ? 'figures' : 'skeleton')
  await page.waitForSelector('[data-testid="site-kpi-sessions-value"]', { timeout: 20000 })
  out.revisitFiguresMs = Date.now() - t2
  out.revisitRequests = requests.length
  await ctx.close()
  return out
}

await new Promise((r) => server.listen(PORT, r))
const browser = await chromium.launch()
const runs = []
for (let i = 0; i < RUNS; i++) runs.push(await once(browser))
await browser.close()
server.close()

const keys = Object.keys(runs[0])
const summary = Object.fromEntries(keys.map((k) => [k, typeof runs[0][k] === 'number' ? median(runs.map((r) => r[k])) : [...new Set(runs.map((r) => r[k]))].join('/')]))
console.log(JSON.stringify({ label: LABEL, latencyMs: LATENCY, runs: RUNS, median: summary, all: runs }, null, 1))
