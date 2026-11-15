import { describe, expect, it, beforeEach, vi } from 'vitest'
import swSource from '../../public/sw.js?raw'

/**
 * NOTIF-PWA-001 — what the service worker may cache, proved by RUNNING public/sw.js against a fake `self`.
 *
 * The guarantee the Owner asked for is «never cache financial or client data». It is asserted three ways: the API and auth paths
 * never reach `respondWith` (so no cache read or write can happen), the only things ever put in a cache are static assets and the
 * empty shell, and a response marked private or that is JSON / HTML is not stored even when it comes from an asset URL.
 */
interface FakeRes { status: number; type: string; ok: boolean; headers: { get: (k: string) => string | null }; clone: () => FakeRes; tag?: string }
const res = (init: { status?: number; type?: string; headers?: Record<string, string>; tag?: string } = {}): FakeRes => {
  const headers = Object.fromEntries(Object.entries(init.headers ?? {}).map(([k, v]) => [k.toLowerCase(), v]))
  const r: FakeRes = {
    status: init.status ?? 200, type: init.type ?? 'basic', ok: (init.status ?? 200) < 300,
    headers: { get: (k) => headers[k.toLowerCase()] ?? null }, clone: () => r, tag: init.tag,
  }

  return r
}

function boot() {
  const handlers = new Map<string, (e: unknown) => void>()
  const stores = new Map<string, Map<string, FakeRes>>()
  const writes: Array<{ cache: string; key: string }> = []
  const open = async (name: string) => {
    const store = stores.get(name) ?? new Map<string, FakeRes>()
    stores.set(name, store)

    return {
      match: async (k: unknown) => store.get(typeof k === 'string' ? k : (k as { url: string }).url),
      put: async (k: unknown, v: FakeRes) => { const key = typeof k === 'string' ? k : (k as { url: string }).url; writes.push({ cache: name, key }); store.set(key, v) },
      add: async (u: string) => { writes.push({ cache: name, key: u }); store.set(u, res({ tag: `shell:${u}` })) },
      keys: async () => [...store.keys()].map((url) => ({ url })),
      delete: async (k: { url: string }) => store.delete(k.url),
    }
  }
  const caches = { open, keys: async () => [...stores.keys()], delete: async (n: string) => stores.delete(n) }
  const self = {
    location: { origin: 'https://app.test' }, clients: { claim: async () => undefined }, skipWaiting: vi.fn(),
    addEventListener: (t: string, h: (e: unknown) => void) => handlers.set(t, h),
  }
  const netCalls: string[] = []
  const state: { net: (url: string) => Promise<FakeRes> } = { net: async () => res({ headers: { 'content-type': 'application/javascript' } }) }
  const fetchFn = (r: { url: string }) => { netCalls.push(r.url); return state.net(r.url) }
  const ResponseFake = { error: () => res({ status: 0, type: 'error', tag: 'error' }) }
  // eslint-disable-next-line no-new-func
  new Function('self', 'caches', 'fetch', 'Response', 'URL', 'setTimeout', swSource)(self, caches, fetchFn, ResponseFake, URL, (fn: () => void, ms: number) => setTimeout(fn, ms))

  const request = (url: string, extra: Record<string, unknown> = {}) => ({
    url: `https://app.test${url}`, method: 'GET', mode: 'cors', destination: '', headers: { has: () => false }, ...extra,
  })
  const fetchEvent = async (req: unknown) => {
    let responded: Promise<FakeRes> | undefined
    handlers.get('fetch')!({ request: req, respondWith: (p: Promise<FakeRes>) => { responded = p } })

    return responded === undefined ? undefined : await responded
  }

  return { handlers, stores, writes, netCalls, state, request, fetchEvent, self }
}

describe('public/sw.js', () => {
  let sw: ReturnType<typeof boot>
  beforeEach(() => { sw = boot() })

  it.each(['/api/v1/notifications', '/api/v1/projects/p-1/budget', '/sanctum/csrf-cookie', '/broadcasting/auth', '/storage/reports/q3.pdf', '/oauth/callback'])(
    'never intercepts %s: no respondWith, so no cache read and no cache write', async (path) => {
      expect(await sw.fetchEvent(sw.request(path))).toBeUndefined()
      expect(await sw.fetchEvent(sw.request(path, { destination: 'script' }))).toBeUndefined()
      expect(sw.writes).toEqual([])
      expect(sw.netCalls).toEqual([])
    })

  it('never intercepts a request that carries an Authorization header, a write, or another origin', async () => {
    expect(await sw.fetchEvent(sw.request('/anything.js', { destination: 'script', headers: { has: (h: string) => h === 'authorization' } }))).toBeUndefined()
    expect(await sw.fetchEvent(sw.request('/x', { method: 'POST' }))).toBeUndefined()
    expect(await sw.fetchEvent({ ...sw.request('/x.js'), url: 'https://api.campaignshub.io/api/v1/me', destination: 'script' })).toBeUndefined()
    expect(sw.writes).toEqual([])
  })

  it('does not handle a destination that is not a static asset (a document, a download, fetch())', async () => {
    expect(await sw.fetchEvent(sw.request('/reports/export.csv', { destination: '' }))).toBeUndefined()
    expect(await sw.fetchEvent(sw.request('/data.json', { destination: 'document' }))).toBeUndefined()
    expect(sw.writes).toEqual([])
  })

  it('caches a static asset, and only a static asset', async () => {
    await sw.fetchEvent(sw.request('/assets/app-abc.js', { destination: 'script' }))
    await new Promise((r) => setTimeout(r, 0))

    expect(sw.writes.filter((w) => w.cache.endsWith('-assets')).map((w) => w.key)).toEqual(['https://app.test/assets/app-abc.js'])
  })

  it.each([
    ['no-store', { 'cache-control': 'no-store', 'content-type': 'application/javascript' }],
    ['private', { 'cache-control': 'private, max-age=60', 'content-type': 'image/png' }],
    ['JSON', { 'content-type': 'application/json' }],
    ['HTML', { 'content-type': 'text/html' }],
    ['CSV', { 'content-type': 'text/csv' }],
  ])('does not store a %s response even from an asset URL', async (_label, headers) => {
    sw.state.net = async () => res({ headers })
    await sw.fetchEvent(sw.request('/assets/leak.png', { destination: 'image' }))
    await new Promise((r) => setTimeout(r, 0))

    expect(sw.writes).toEqual([])
  })

  it('does not store a redirected / opaque / failed response', async () => {
    for (const r of [res({ type: 'opaque' }), res({ status: 500 }), res({ type: 'cors' })]) {
      sw.state.net = async () => r
      await sw.fetchEvent(sw.request('/assets/x.css', { destination: 'style' }))
    }
    await new Promise((r) => setTimeout(r, 0))

    expect(sw.writes).toEqual([])
  })

  it('opens offline to the offline page, never to a half-loaded app, and keeps only the empty shell when online', async () => {
    sw.handlers.get('install')!({ waitUntil: (p: Promise<unknown>) => p })
    await new Promise((r) => setTimeout(r, 0))

    sw.state.net = async () => res({ headers: { 'content-type': 'text/html; charset=utf-8' }, tag: 'live' })
    const live = await sw.fetchEvent(sw.request('/agency/dashboard', { mode: 'navigate' }))
    expect(live?.tag).toBe('live')
    expect([...sw.stores.get('ch-v3-pwa-shell')!.keys()]).toContain('/index.html')

    sw.state.net = async () => { throw new TypeError('offline') }
    const offline = await sw.fetchEvent(sw.request('/agency/dashboard', { mode: 'navigate' }))
    expect(offline?.tag).toBe('shell:/offline.html')
  })

  it('does not store a navigation answer that is not HTML (a login redirect body, JSON error)', async () => {
    sw.state.net = async () => res({ headers: { 'content-type': 'application/json' } })
    await sw.fetchEvent(sw.request('/agency/dashboard', { mode: 'navigate' }))

    expect(sw.writes.filter((w) => w.key === '/index.html')).toEqual([])
  })

  it('deletes every cache on CLEAR_CACHES (sign-out) and activates a waiting worker on SKIP_WAITING', async () => {
    sw.handlers.get('install')!({ waitUntil: (p: Promise<unknown>) => p })
    await new Promise((r) => setTimeout(r, 0))
    expect(sw.stores.size).toBeGreaterThan(0)

    let pending: Promise<unknown> = Promise.resolve()
    sw.handlers.get('message')!({ data: 'CLEAR_CACHES', waitUntil: (p: Promise<unknown>) => { pending = p } })
    await pending
    expect(sw.stores.size).toBe(0)

    sw.handlers.get('message')!({ data: 'SKIP_WAITING' })
    expect(sw.self.skipWaiting).toHaveBeenCalled()
  })

  it('contains no runtime caching rule for the API in its source', () => {
    // The strings an API caching rule would need. The NETWORK_ONLY list is the only place these paths appear.
    const code = swSource.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1')
    const outsideList = code.replace(/const NETWORK_ONLY = \[[^\]]*\]/, '')
    expect(outsideList).not.toMatch(/\/api\//)
    expect(swSource).not.toMatch(/caches\.open\([^)]*api/i)
  })
})
