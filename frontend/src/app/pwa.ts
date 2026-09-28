/*
 * Service-worker registration + update flow. Registered only for production builds — in dev, Vite's module
 * server and HMR must not be intercepted by a cache. When a new worker is waiting, we show a small,
 * dismissible banner so the user chooses when to reload into the new version (never a surprise refresh).
 */
export function registerServiceWorker(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) return

  /* Whether a worker was already driving this page when it loaded — see the listener below. */
  const hadController = Boolean(navigator.serviceWorker.controller)

  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js')
      .then((reg) => {
        // A worker is already waiting (previous visit installed an update).
        if (reg.waiting) promptUpdate(reg.waiting)

        reg.addEventListener('updatefound', () => {
          const installing = reg.installing
          if (!installing) return
          installing.addEventListener('statechange', () => {
            // Installed + an active controller present ⇒ this is an update, not a first install.
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
              promptUpdate(installing)
            }
          })
        })
      })
      .catch(() => undefined)

    /*
     * Reload when an UPDATE takes control — never when the first worker claims the page.
     *
     * `sw.js` calls `clients.claim()` in its `activate` handler, so a first install fires
     * `controllerchange` too. This reloaded on that, which means every first-time visitor to
     * production got an automatic refresh — the one thing the note at the top of this file promises
     * does not happen. There is nothing to reload INTO on a first install: the page is already
     * running the version the worker just cached.
     *
     * Found by the E2E gate the day it started serving a built app instead of a dev server, where
     * `import.meta.env.PROD` is false and none of this runs at all: seventeen specs failed with
     * «Execution context was destroyed, most likely because of a navigation», which is what a page
     * reloading underneath a test looks like.
     *
     * Read BEFORE `register()` resolves, because by then the new worker may already control the page
     * and the question «was there one before?» can no longer be asked.
     */
    let reloaded = false
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (!hadController || reloaded) return
      reloaded = true
      window.location.reload()
    })
  })
}

function promptUpdate(worker: ServiceWorker): void {
  if (document.getElementById('pwa-update-banner')) return

  const isArabic = document.documentElement.lang !== 'en'
  const banner = document.createElement('div')
  banner.id = 'pwa-update-banner'
  banner.setAttribute('role', 'status')
  banner.dir = isArabic ? 'rtl' : 'ltr'
  banner.style.cssText =
    'position:fixed;inset-inline:0;bottom:0;z-index:2147483647;display:flex;gap:12px;' +
    /*
     * The token, not the hex. This banner is raw DOM outside React and Tailwind, which is exactly
     * how a second copy of the brand colour survives a rebrand: nothing here is scanned by the style
     * pipeline, so the value simply stayed. `var()` reaches it the same as any other element.
     */
    'align-items:center;justify-content:center;padding:12px 16px;background:var(--brand-600);color:#fff;' +
    'font:500 14px/1.4 system-ui,sans-serif;box-shadow:0 -2px 12px rgba(0,0,0,.2)'

  const text = document.createElement('span')
  text.textContent = isArabic ? 'يتوفّر تحديث جديد للتطبيق.' : 'A new version is available.'

  const reload = document.createElement('button')
  reload.textContent = isArabic ? 'تحديث' : 'Update'
  reload.style.cssText =
    'background:#fff;color:var(--brand-600);border:0;border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer'
  reload.onclick = () => worker.postMessage('SKIP_WAITING')

  const dismiss = document.createElement('button')
  dismiss.setAttribute('aria-label', isArabic ? 'إغلاق' : 'Dismiss')
  dismiss.textContent = '✕'
  dismiss.style.cssText = 'background:transparent;color:#fff;border:0;font-size:16px;cursor:pointer'
  dismiss.onclick = () => banner.remove()

  banner.append(text, reload, dismiss)
  document.body.appendChild(banner)
}
