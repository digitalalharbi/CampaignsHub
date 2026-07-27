/*
 * CampaignsHub service worker — self-contained, no build-time PWA plugin.
 *
 * Strategy, chosen for correctness over aggressive caching:
 *   - API / auth (/api/*, /sanctum/*) and every non-GET: ALWAYS network, never cached. Tenant data, auth,
 *     and honest delivery states must never be served stale from a cache.
 *   - Navigations (HTML): network-first, falling back to the cached app shell when offline, so the installed
 *     app opens without a connection.
 *   - Same-origin static assets (hashed JS/CSS/fonts/images): stale-while-revalidate — instant from cache,
 *     refreshed in the background. Hashed filenames make this safe.
 *   - Update flow: a waiting worker activates immediately on SKIP_WAITING (the page prompts the user first).
 */
const VERSION = 'ch-v1'
const SHELL_CACHE = `${VERSION}-shell`
const ASSET_CACHE = `${VERSION}-assets`
const SHELL = ['/', '/index.html', '/manifest.webmanifest', '/favicon.svg']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL_CACHE).then((c) => c.addAll(SHELL)).catch(() => undefined))
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
})

function isApi(url) {
  return url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')
}

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  // Only handle same-origin GET. Everything else (POST, API, cross-origin) goes straight to the network.
  if (request.method !== 'GET' || url.origin !== self.location.origin || isApi(url)) return

  if (request.mode === 'navigate') {
    event.respondWith(
      (async () => {
        try {
          const fresh = await fetch(request)
          const cache = await caches.open(SHELL_CACHE)
          cache.put('/index.html', fresh.clone())
          return fresh
        } catch {
          const cache = await caches.open(SHELL_CACHE)
          return (await cache.match('/index.html')) || (await cache.match('/')) || Response.error()
        }
      })(),
    )
    return
  }

  event.respondWith(
    (async () => {
      const cache = await caches.open(ASSET_CACHE)
      const cached = await cache.match(request)
      const network = fetch(request)
        .then((res) => {
          if (res && res.status === 200 && res.type === 'basic') cache.put(request, res.clone())
          return res
        })
        .catch(() => cached || Response.error())
      return cached || network
    })(),
  )
})
