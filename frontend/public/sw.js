/*
 * CampaignsHub service worker — self-contained, no build-time PWA plugin.
 *
 * ## What it caches, and what it can NEVER cache (NOTIF-PWA-001)
 *
 * The product shows other people's money and results, so the rule is stated as a guarantee, in code, rather than as a habit:
 *
 *   1. This worker only ever intercepts a same-origin GET. A POST, a PUT, a DELETE and anything cross-origin (the production API
 *      lives on api.campaignshub.io) goes straight to the network and never reaches a handler.
 *   2. `/api/*`, `/sanctum/*`, `/broadcasting/*`, `/storage/*`, `/oauth/*` and any request that carries an `Authorization`
 *      header are NETWORK-ONLY: the fetch handler returns before `respondWith`, so there is no cache lookup and no cache write.
 *      There is no runtime-caching rule for the API anywhere in this file.
 *   3. The only things ever written to a cache are: the precached shell list below (static files with no user data), the
 *      navigation HTML (the empty app shell — no data is rendered into it), and static assets (script, style, font, image,
 *      manifest) whose response is 200, same-origin, not `no-store` / `private`, and not JSON or HTML.
 *   4. On sign-out the page asks this worker to delete every cache (`CLEAR_CACHES`).
 *
 * `sw.test.ts` runs this very file against a fake `self` and asserts 1–3.
 *
 * ## Strategy
 *   - Navigations (HTML): network-first. Offline → `/offline.html`. A slow network (4 s) → the cached shell, so the app opens
 *     at once and loads its data itself.
 *   - Static assets: stale-while-revalidate — instant from cache, refreshed in the background. Hashed filenames make it safe.
 *   - Update flow: a waiting worker activates on SKIP_WAITING (the page asks the user first).
 */
/*
 * BRAND-CANONICAL-001 — the version is what evicts a stale identity.
 *
 * `activate` deletes every cache whose key does NOT start with VERSION, so a constant version means nothing is ever evicted.
 * The shell below caches `/favicon.svg`, which is UNHASHED — the one asset whose bytes change while its URL does not — so a
 * browser that installed this worker before a rebrand keeps serving the old mark for as long as the cache lives. Bump this
 * whenever a shell asset's bytes change.
 */
const VERSION = 'ch-v3-pwa'
const SHELL_CACHE = `${VERSION}-shell`
const ASSET_CACHE = `${VERSION}-assets`
const OFFLINE_URL = '/offline.html'
const SHELL = ['/', '/index.html', OFFLINE_URL, '/manifest.webmanifest', '/favicon.svg', '/icon-192.png']
const NAVIGATION_PATIENCE_MS = 4000
const MAX_ASSETS = 150

/** Paths that are never served from, or written to, a cache. */
const NETWORK_ONLY = ['/api/', '/sanctum/', '/broadcasting/', '/storage/', '/oauth/']
const STATIC_DESTINATIONS = ['script', 'style', 'font', 'image', 'manifest']

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((c) => Promise.allSettled(SHELL.map((u) => c.add(u)))).catch(() => undefined),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys()
      await Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))
      await self.clients.claim()
    })(),
  )
})

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting()
  if (event.data === 'CLEAR_CACHES') event.waitUntil(caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))))
})

function networkOnly(request, url) {
  return NETWORK_ONLY.some((p) => url.pathname.startsWith(p)) || request.headers.has('authorization')
}

/** A static asset a cache may hold: nothing private, nothing dynamic, nothing that is data. */
function cacheable(response) {
  if (!response || response.status !== 200 || response.type !== 'basic') return false
  const control = (response.headers.get('cache-control') || '').toLowerCase()
  if (control.includes('no-store') || control.includes('private')) return false
  const type = (response.headers.get('content-type') || '').toLowerCase()

  return !type.includes('json') && !type.includes('html') && !type.includes('xml') && !type.includes('csv')
}

async function trim(cache) {
  const keys = await cache.keys()
  await Promise.all(keys.slice(0, Math.max(0, keys.length - MAX_ASSETS)).map((k) => cache.delete(k)))
}

async function navigate(request) {
  const shell = await caches.open(SHELL_CACHE)
  const network = fetch(request).then(async (fresh) => {
    // Only the empty shell is kept, and only when it really is the app's HTML.
    const type = (fresh.headers.get('content-type') || '').toLowerCase()
    if (fresh.ok && fresh.type === 'basic' && type.includes('html') && !(fresh.headers.get('cache-control') || '').toLowerCase().includes('no-store')) {
      await shell.put('/index.html', fresh.clone())
    }

    return fresh
  })
  const patience = new Promise((resolve) => setTimeout(() => resolve(null), NAVIGATION_PATIENCE_MS))

  try {
    const first = await Promise.race([network, patience])
    if (first) return first
    // Slow network: open from the cached shell now, if there is one; otherwise keep waiting for the network.
    return (await shell.match('/index.html')) || (await network)
  } catch {
    // Offline (the network failed outright): a plain page that says so, never a half-loaded app.
    return (await shell.match(OFFLINE_URL)) || Response.error()
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  // Same-origin GET only. Everything else — and every API / auth path — is not intercepted at all.
  if (request.method !== 'GET' || url.origin !== self.location.origin || networkOnly(request, url)) return

  if (request.mode === 'navigate') {
    event.respondWith(navigate(request))
    return
  }

  // Not a static asset (a data download, a JSON document, an unknown destination): not ours to cache.
  if (!STATIC_DESTINATIONS.includes(request.destination)) return

  event.respondWith(
    (async () => {
      const cache = await caches.open(ASSET_CACHE)
      const cached = await cache.match(request)
      const network = fetch(request)
        .then((res) => {
          if (cacheable(res)) cache.put(request, res.clone()).then(() => trim(cache))
          return res
        })
        .catch(() => cached || Response.error())
      return cached || network
    })(),
  )
})
