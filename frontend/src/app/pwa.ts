/*
 * Service-worker registration + update flow. Registered only for production builds — in dev, Vite's module
 * server and HMR must not be intercepted by a cache. When a new worker is waiting, we show a small,
 * dismissible banner so the user chooses when to reload into the new version (never a surprise refresh).
 */
export function registerServiceWorker(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) return

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

    // Reload once the new worker takes control.
    let reloaded = false
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (reloaded) return
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
    'align-items:center;justify-content:center;padding:12px 16px;background:#0d8a6f;color:#fff;' +
    'font:500 14px/1.4 system-ui,sans-serif;box-shadow:0 -2px 12px rgba(0,0,0,.2)'

  const text = document.createElement('span')
  text.textContent = isArabic ? 'يتوفّر تحديث جديد للتطبيق.' : 'A new version is available.'

  const reload = document.createElement('button')
  reload.textContent = isArabic ? 'تحديث' : 'Update'
  reload.style.cssText =
    'background:#fff;color:#0d8a6f;border:0;border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer'
  reload.onclick = () => worker.postMessage('SKIP_WAITING')

  const dismiss = document.createElement('button')
  dismiss.setAttribute('aria-label', isArabic ? 'إغلاق' : 'Dismiss')
  dismiss.textContent = '✕'
  dismiss.style.cssText = 'background:transparent;color:#fff;border:0;font-size:16px;cursor:pointer'
  dismiss.onclick = () => banner.remove()

  banner.append(text, reload, dismiss)
  document.body.appendChild(banner)
}
