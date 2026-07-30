/**
 * Resolve a post-login redirect target safely.
 *
 * Only same-origin, absolute in-app paths are honoured. Anything that could leave the app — protocol-relative
 * `//host`, absolute URLs, or a value that doesn't start with a single `/` — falls back to `fallback`.
 * This prevents an attacker from crafting `/login?redirect=https://evil.example` (open-redirect).
 */
export function safeRedirect(raw: string | null, fallback = '/app/dashboard'): string {
  if (!raw) return fallback
  let value = raw
  try {
    value = decodeURIComponent(raw)
  } catch {
    return fallback
  }
  // Must be an in-app absolute path, and not protocol-relative (`//`) or a `/\` backslash trick.
  if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) return fallback
  // Never bounce back to an auth page — avoids a login → login loop.
  if (/^\/(login|register|forgot-password)(\/|\?|#|$)/.test(value)) return fallback
  return value
}
