/**
 * NOV-SAFARI-001 — a client-side unique id that does not need `crypto.randomUUID`.
 *
 * `crypto.randomUUID` exists only in a secure context and from Safari 15.4; an internal-network `http://` address or an
 * older iPad answers «is not a function» and the screen that asked for an id simply stops. Used for idempotency keys and
 * local list keys, where uniqueness matters and unpredictability does not.
 */
export function uuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID()

  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}-${Math.random().toString(36).slice(2, 10)}`
}
